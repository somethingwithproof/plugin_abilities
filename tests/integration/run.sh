#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Vincent
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Run the live integration tests against a real Cacti.
#
#   tests/integration/run.sh <1.2.31|develop> [path/to/mcp.tar.gz]
#
# Needs Docker with Compose v2, git and openssl on the host. With no archive
# argument it builds one from HEAD the way ci.yml does. KEEP=1 leaves the stack
# running for debugging; otherwise everything, volumes included, is removed.
#
# Phases, in order: clone, build, install, plugin, checks, d3, inspector, db-down, logs.
# It prints "PHASE <name>" before each and "FAILED PHASE <name>" for the one that
# fails. A setup phase failure stops the run; the later check phases each run even
# if an earlier one failed, so one run reports as much as it can.
set -euo pipefail

usage() {
	echo "usage: $0 <1.2.31|develop> [path/to/mcp.tar.gz]" >&2
	exit 2
}

ref="${1:-}"
case "$ref" in
	1.2.31) branch=release/1.2.31 web=apache project=mcp-it-1231 ;;
	develop) branch=develop web=nginx project=mcp-it-develop ;;
	*) usage ;;
esac
[ $# -le 2 ] || usage

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
out="$here/out/$ref"
src="$here/src/$ref"
# Raw output (unredacted logs, checks.log, Inspector files) stays here, outside out/. Only
# redacted copies are written into out/, so nothing a workflow uploads can hold a token
# whatever happens to the run. src/ is gitignored.
raw="$here/src/raw-$ref"

# Pinned image used for host-side cleanup of files a container created as root.
cli_image="$(sed -n 's/^ *image: \(php:8\.4-cli@sha256:[0-9a-f]*\)$/\1/p' "$here/compose.yaml")"
if [ -z "$cli_image" ]; then
	echo "could not read the pinned php:8.4-cli image from $here/compose.yaml" >&2
	exit 2
fi

current=init
cacti_commit=unknown
failed_phases=()

compose() {
	docker compose -p "$project" -f "$here/compose.yaml" "$@"
}

# Run a command in the web container as root, without a TTY.
in_web() {
	compose exec -T web "$@"
}

# Every setup step's output is also kept in raw/setup.log, so the L1 scan covers warnings
# from plugin code run under the CLI (plugin_manage.php, tokens.php). tee passes stdout
# through, so callers that capture a value still get it.
setup() {
	in_web bash /opt/mcp-it/setup.sh "$@" > >(tee -a "$raw/setup.log") 2> >(tee -a "$raw/setup.log" >&2)
}

phase() {
	current="$1"
	echo "PHASE $1"
}

# Run one phase that does not stop the run. The subshell turns set -e back on, so the
# first failing command ends the phase; the parent has it off so the run can go on.
soft_phase() {
	local name="$1" rc
	phase "$name"
	set +e
	( set -e; "phase_${name//-/_}" )
	rc=$?
	set -e
	if [ "$rc" -ne 0 ]; then
		echo "FAILED PHASE $name"
		failed_phases+=("$name")
	fi
}

on_exit() {
	local rc=$?
	trap - EXIT
	set +e

	if [ "$rc" -ne 0 ] && [ "${#failed_phases[@]}" -eq 0 ]; then
		echo "FAILED PHASE $current"
		failed_phases+=("$current")
	fi

	# The logs phase writes summary.md. If it never got that far (a setup phase failed,
	# or it broke midway), do its work here so a failed run still leaves redacted logs.
	if { [ "$current" != init ] || [ -d "$out" ]; } && [ ! -f "$out/summary.md" ]; then
		( set -e; phase_logs ) || rc=1
	fi

	if [ "${KEEP:-0}" = 1 ]; then
		echo "KEEP=1: stack $project left running"
	elif ! compose --profile '*' down -v --remove-orphans > /dev/null 2>&1; then
		echo "could not remove the stack $project" >&2
		[ "$rc" -ne 0 ] || rc=1
	fi

	if [ "${#failed_phases[@]}" -gt 0 ]; then
		[ "$rc" -ne 0 ] || rc=1
	fi
	exit "$rc"
}

# Compose reads these for every command, including down.
export MCP_IT_REF="$ref" MCP_IT_WEB="$web"
MCP_IT_DB_PASSWORD="$(openssl rand -hex 16)"
MCP_IT_DB_ROOT_PASSWORD="$(openssl rand -hex 16)"
export MCP_IT_DB_PASSWORD MCP_IT_DB_ROOT_PASSWORD

if [ -n "${2:-}" ]; then
	[ -f "$2" ] || { echo "plugin archive not found: $2" >&2; exit 2; }
	tarball="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
else
	tarball=""
fi

trap on_exit EXIT

# A stack left over from an earlier run could pass on stale volumes and tokens.
if ! compose --profile '*' down -v --remove-orphans > /dev/null 2>&1; then
	echo "warning: could not remove an earlier $project stack" >&2
fi
echo "docker server $(docker version --format '{{.Server.Version}}'), client $(docker version --format '{{.Client.Version}}')"
docker compose version
rm -rf "$out" "$raw"
mkdir -p "$out" "$raw"
: > "$raw/checks.log"
: > "$raw/setup.log"

phase_clone() {
	if [ -e "$src" ]; then
		# Files a container wrote as root may not be removable from the host.
		rm -rf "$src" 2> /dev/null || docker run --rm -v "$here/src:/w" "$cli_image" rm -rf "/w/$ref"
	fi
	mkdir -p "$here/src"
	git clone --quiet --depth 1 --branch "$branch" https://github.com/Cacti/cacti "$src"
	cacti_commit="$(git -C "$src" rev-parse HEAD)"
	echo "Cacti commit: $cacti_commit"

	if [ -z "$tarball" ]; then
		tarball="$here/src/mcp-$ref.tar.gz"
		git -C "$repo" archive --format=tar.gz --prefix=mcp/ HEAD > "$tarball"
	fi
	export MCP_IT_TARBALL="$tarball"
}

phase_build() {
	compose build web
	if [ "$ref" = develop ]; then
		# develop keeps its PHP dependencies in vendor/, which the checkout lacks.
		compose run --rm --no-deps -T -e COMPOSER_ALLOW_SUPERUSER=1 --entrypoint composer web \
			install --no-dev --no-interaction --no-progress --working-dir=/var/www/cacti
	fi
	compose up -d --wait web
}

phase_install() {
	setup install
}

phase_plugin() {
	setup plugin
	setup fixtures

	# In CI every token is masked before anything could print it.
	if [ "${GITHUB_ACTIONS:-}" = true ]; then
		local name token
		for name in mcpuser apionly norealm revoked; do
			token="$(in_web cat "/run/mcp-it/token-$name")"
			echo "::add-mask::$token"
		done
	fi
}

phase_checks() {
	compose run --rm --no-deps -T checks php /checks/checks.php --ref="$ref" --phase=smoke 2>&1 | tee "$raw/checks.log"
	compose run --rm --no-deps -T checks php /checks/checks.php --ref="$ref" --phase=main 2>&1 | tee -a "$raw/checks.log"
}

# MCP Inspector, run in the inspector service against the live endpoint. The token is
# read from the shared tmpfs inside sh -c, so it is not in this script, the Compose
# command line or any trace. Inspector 2.16.0 is pinned and bumped by hand.
# --no-deps: without it Compose would recreate web and db and empty the secrets volume.
inspector_run() {
	local outfile="$1" errfile="$2"
	shift 2
	compose run --rm --no-deps -T inspector sh -c 'T=$(cat /run/mcp-it/token-mcpuser); exec npx -y @modelcontextprotocol/inspector@2.16.0 --cli http://localhost/cacti/plugins/mcp/mcp.php --transport http --header "Authorization: Bearer $T" "$@"' sh "$@" < /dev/null > "$outfile" 2> "$errfile"
}

# Read JSON on stdin in the inspector container (Node is only there) and run a check.
# The check prints a reason on failure and never the document itself.
inspector_check() {
	local file="$1" script="$2"
	compose run --rm --no-deps -T inspector node -e "$script" < "$file"
}

phase_inspector() {
	local rc=0 reason=""
	local list_js='
let s = ""; process.stdin.on("data", d => s += d).on("end", () => {
  let j; try { j = JSON.parse(s); } catch (e) { console.log("output is not JSON"); process.exit(1); }
  const names = (j.tools || []).map(t => t.name);
  const missing = ["cacti-list-devices", "cacti-get-device", "cacti-get-system-info"].filter(n => !names.includes(n));
  if (missing.length) { console.log("missing tools: " + missing.join(", ")); process.exit(1); }
});'
	local call_js='
let s = ""; process.stdin.on("data", d => s += d).on("end", () => {
  let j; try { j = JSON.parse(s); } catch (e) { console.log("output is not JSON"); process.exit(1); }
  if (j.isError === true) { console.log("isError is true"); process.exit(1); }
  const text = (j.content || []).filter(c => c.type === "text").map(c => c.text).join("\n");
  const missing = ["it-alpha", "it-beta"].filter(n => !text.includes(n));
  if (missing.length) { console.log("result lacks: " + missing.join(", ")); process.exit(1); }
});'

	if inspector_run "$raw/inspector-list.json" "$raw/inspector-list.err" --method tools/list \
		&& reason="$(inspector_check "$raw/inspector-list.json" "$list_js")"; then
		echo "PASS I1 inspector tools/list" | tee -a "$raw/checks.log"
	else
		echo "FAIL I1 inspector tools/list: ${reason:-inspector exited with an error}" | tee -a "$raw/checks.log"
		rc=1
	fi

	reason=""
	if inspector_run "$raw/inspector-call.json" "$raw/inspector-call.err" --method tools/call --tool-name cacti-list-devices \
		&& reason="$(inspector_check "$raw/inspector-call.json" "$call_js")"; then
		echo "PASS I2 inspector tools/call" | tee -a "$raw/checks.log"
	else
		echo "FAIL I2 inspector tools/call: ${reason:-inspector exited with an error}" | tee -a "$raw/checks.log"
		rc=1
	fi
	return "$rc"
}

# D3: sessions opened before guest_user changes must not be able to create a token for it.
# guest-off runs whatever happens, so the later phases see a normal guest setting.
phase_d3() {
	local rc=0 before after
	before="$(setup count-tokens guestu | tr -d '\r\n')"
	[[ "$before" =~ ^[0-9]+$ ]] || { echo "FAIL D3 count: could not count guestu's tokens" >&2; return 1; }

	compose run --rm --no-deps -T checks php /checks/checks.php --ref="$ref" --phase=d3-open 2>&1 | tee -a "$raw/checks.log" || rc=1
	setup guest-on || rc=1
	compose run --rm --no-deps -T checks php /checks/checks.php --ref="$ref" --phase=d3-finish 2>&1 | tee -a "$raw/checks.log" || rc=1
	after="$(setup count-tokens guestu | tr -d '\r\n')" || rc=1
	setup guest-off || rc=1

	if [[ "$after" =~ ^[0-9]+$ ]] && [ "$after" -le "$before" ]; then
		echo "PASS D3 token rows for guestu unchanged ($before)" | tee -a "$raw/checks.log"
	else
		echo "FAIL D3 token rows for guestu: before $before, after ${after:-unknown}" | tee -a "$raw/checks.log"
		rc=1
	fi
	return "$rc"
}

# D2: stop the database, check both endpoints, and start it again even if a check fails.
phase_db_down() {
	local rc=0 i status=""
	compose run --rm --no-deps -T checks php /checks/checks.php --ref="$ref" --phase=db-down-prep 2>&1 | tee -a "$raw/checks.log" || rc=1
	compose stop db
	compose run --rm --no-deps -T checks php /checks/checks.php --ref="$ref" --phase=db-down 2>&1 | tee -a "$raw/checks.log" || rc=1
	compose start db

	for i in $(seq 1 60); do
		status="$(docker inspect -f '{{.State.Health.Status}}' "$(compose ps -q db)" 2> /dev/null || true)"
		[ "$status" = healthy ] && break
		sleep 2
	done
	[ "$status" = healthy ] || { echo "FAIL D2 the database did not become healthy again" | tee -a "$raw/checks.log"; return 1; }

	compose run --rm --no-deps -T checks php /checks/checks.php --ref="$ref" --phase=db-up 2>&1 | tee -a "$raw/checks.log" || rc=1
	return "$rc"
}

# Copy the logs out of the web container. A log that cannot be read is a failure: it
# would otherwise let a broken run look clean. Returns 1 if any read failed.
collect_logs() {
	local rc=0 grc apache_access='^[^ ]+ [^ ]+ [^ ]+ \[[0-9]+/[A-Za-z]+/[0-9]+:'
	compose --profile '*' logs --no-color > "$raw/compose.log" 2>&1 \
		|| { echo "FAIL L1 log read error: compose logs"; rc=1; }
	# Everything the web container writes to stdout and stderr: PHP's error log, and on
	# develop PHP-FPM's. Apache's access and error logs go there too.
	compose logs --no-color --no-log-prefix web > "$raw/php.log" \
		|| { echo "FAIL L1 log read error: web container output"; rc=1; }
	# exec cat as root, not a host copy: the container chowns log/ to www-data.
	in_web cat /var/www/cacti/log/cacti.log > "$raw/cacti.log" \
		|| { echo "FAIL L1 log read error: cacti.log"; rc=1; }
	if [ "$web" = nginx ]; then
		in_web cat /var/log/nginx/access.log > "$raw/web-access.log" \
			|| { echo "FAIL L1 log read error: nginx access log"; rc=1; }
		in_web cat /var/log/nginx/error.log > "$raw/web-error.log" \
			|| { echo "FAIL L1 log read error: nginx error log"; rc=1; }
	else
		# Apache logs to the container's stdout and stderr, so split what compose gave us.
		grc=0
		grep -E "$apache_access" "$raw/php.log" > "$raw/web-access.log" || grc=$?
		[ "$grc" -le 1 ] || { echo "FAIL L1 log read error: apache access log"; rc=1; }
		grc=0
		grep -vE "$apache_access" "$raw/php.log" > "$raw/web-error.log" || grc=$?
		[ "$grc" -le 1 ] || { echo "FAIL L1 log read error: apache error log"; rc=1; }
	fi
	return "$rc"
}

# Write a redacted copy of every file under raw/ to the same relative path under out/,
# replacing each token with cmcp_pat_REDACTED. LC_ALL=C and a pipe through sed handle
# binary content on BSD and GNU sed alike. Any failure returns 1.
redact_to_out() {
	local list f rel
	list="$(mktemp)"
	find "$raw" -type f -print0 > "$list" || { rm -f "$list"; return 1; }
	while IFS= read -r -d '' f; do
		rel="${f#"$raw"/}"
		mkdir -p "$(dirname "$out/$rel")" || { rm -f "$list"; return 1; }
		LC_ALL=C sed -E 's/cmcp_pat_[A-Za-z0-9_-]+/cmcp_pat_REDACTED/g' "$f" > "$out/$rel" || { rm -f "$list"; return 1; }
	done < "$list"
	rm -f "$list"
}

# Any cmcp_pat_ value other than the placeholder left in out/ is a leak. -a makes grep
# search binary files as text; without it a file with a NUL byte matches silently. grep
# exits 1 for no match, which is the pass case; above 1 is an error and also fails.
scan_out_for_tokens() {
	local found rest rc=0
	found="$(mktemp)"
	rest="$(mktemp)"
	LC_ALL=C grep -a -rhoE 'cmcp_pat_[A-Za-z0-9_-]+' "$out" > "$found" || rc=$?
	if [ "$rc" -le 1 ]; then
		rc=0
		LC_ALL=C grep -a -vx cmcp_pat_REDACTED "$found" > "$rest" || rc=$?
	fi
	rm -f "$found" "$rest"
	case "$rc" in
		0) echo "FAIL R1 token left in artifacts"; return 1 ;;
		1) echo "PASS R1 no token left in artifacts" ;;
		*) echo "FAIL R1 token scan error"; return 1 ;;
	esac
}

# The summary names the first phase that failed; "logs" only when nothing before it did.
first_failure() {
	if [ "${#failed_phases[@]}" -gt 0 ]; then
		echo "FAILED PHASE ${failed_phases[0]}"
	else
		echo "FAILED PHASE logs"
	fi
}

# Replace everything in out/ with a fixed summary, so nothing unredacted or unchecked is
# left in the uploaded path. The text is fixed and holds no run output.
withhold_out() {
	local why="$1"
	find "$out" -mindepth 1 -delete
	{
		echo "Result: $(first_failure)"
		echo "Cacti ref: $ref"
		echo
		echo "$why"
	} > "$out/summary.md"
}

# Written to raw/, so it is redacted and scanned with everything else.
write_summary() {
	local result="$1"
	{
		echo "Result: $result"
		echo "Cacti ref: $ref"
		echo "Cacti commit: $cacti_commit"
		echo "Plugin commit: $(git -C "$repo" rev-parse HEAD)"
		echo
		cat "$raw/checks.txt"
	} > "$raw/summary.md"
}

# The run window is the fresh stack itself: every run starts from down -v, so the logs
# hold nothing from an earlier run.
phase_logs() {
	local l1=0 grc=0 result hits

	collect_logs || l1=1

	# checks.txt: the PASS, FAIL and SKIP lines. grep exits 1 when there are none.
	grep -E '^(PASS|FAIL|SKIP) ' "$raw/checks.log" > "$raw/checks.txt" || grc=$?
	[ "$grc" -le 1 ] || { echo "FAIL L1 log read error: checks.log"; l1=1; }

	if [ "$l1" -eq 0 ]; then
		hits="$(mktemp)"
		grc=0
		# Two forms: PHP's own ("PHP Warning:  ... in /.../plugins/mcp/x.php") and Cacti's
		# CactiErrorHandler ("PHP WARNING in  Plugin 'mcp': ... in file: /.../plugins/mcp/x.php").
		# A Cacti core warning that only has the plugin in its Backtrace line is not matched.
		# setup.log holds the CLI steps that run plugin code.
		grep -E -e '(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error).*plugins/mcp/' \
			-e "PHP [A-Za-z_ ]*(WARNING|NOTICE|DEPRECATED|ERROR|PARSE)( in +Plugin 'mcp'|:.* in file: [^ ]*plugins/mcp/)" \
			"$raw/cacti.log" "$raw/php.log" "$raw/web-error.log" "$raw/setup.log" > "$hits" || grc=$?
		case "$grc" in
			0)
				echo "FAIL L1 plugin PHP messages" | tee -a "$raw/checks.txt"
				sed -n '1,50p' "$hits" | sed 's/^/    /' >> "$raw/checks.txt"
				l1=1
				;;
			1) echo "PASS L1 no plugin PHP messages in the logs" | tee -a "$raw/checks.txt" ;;
			*) echo "FAIL L1 log read error: scan" | tee -a "$raw/checks.txt"; l1=1 ;;
		esac
		rm -f "$hits"
	else
		echo "FAIL L1 log read error" | tee -a "$raw/checks.txt"
	fi

	if [ "${#failed_phases[@]}" -gt 0 ]; then
		result="FAILED PHASE ${failed_phases[0]}"
	elif [ "$l1" -ne 0 ]; then
		result="FAILED PHASE logs"
	else
		result=PASS
	fi
	write_summary "$result"

	# Redaction covers every raw file, summary included, and only the redacted copies reach out/.
	if ! redact_to_out; then
		echo "FAIL R1 could not redact the logs"
		withhold_out "FAIL R1 redaction failed; all logs withheld"
		return 1
	fi

	# Last: after every file in out/ is written.
	if ! scan_out_for_tokens; then
		withhold_out "FAIL R1 token left in artifacts (files withheld)"
		return 1
	fi
	[ "$l1" -eq 0 ]
}

# Setup phases: the first failure ends the run, and on_exit reports it.
phase clone
phase_clone
phase build
phase_build
phase install
phase_install
phase plugin
phase_plugin

# Check phases.
soft_phase checks
soft_phase d3
soft_phase inspector
soft_phase db-down
soft_phase logs

[ "${#failed_phases[@]}" -eq 0 ]
