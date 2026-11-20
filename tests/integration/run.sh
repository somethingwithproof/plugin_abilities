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
# Phases, in order: clone, build, install, plugin, checks, inspector, db-down, logs.
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

# Pinned image used for host-side cleanup of files a container created as root.
cli_image="$(sed -n 's/^ *image: \(php:8\.4-cli@sha256:[0-9a-f]*\)$/\1/p' "$here/compose.yaml")"

current=init
failed_phases=()

compose() {
	docker compose -p "$project" -f "$here/compose.yaml" "$@"
}

# Run a command in the web container as root, without a TTY.
in_web() {
	compose exec -T web "$@"
}

setup() {
	in_web bash /opt/mcp-it/setup.sh "$@"
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

collect_logs() {
	mkdir -p "$out"
	compose --profile '*' logs --no-color > "$out/compose.log" 2>&1 || echo "could not collect compose logs" >&2
	if [ -f "$src/log/cacti.log" ]; then
		cp "$src/log/cacti.log" "$out/cacti.log" || echo "could not copy cacti.log" >&2
	fi
	if [ "$web" = nginx ]; then
		in_web cat /var/log/nginx/access.log > "$out/nginx-access.log" 2>/dev/null || echo "could not read the nginx access log" >&2
		in_web cat /var/log/nginx/error.log > "$out/nginx-error.log" 2>/dev/null || echo "could not read the nginx error log" >&2
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

	if [ "$current" != init ] || [ -d "$out" ]; then
		collect_logs
	fi

	if [ "${KEEP:-0}" = 1 ]; then
		echo "KEEP=1: stack $project left running"
	elif ! compose --profile '*' down -v --remove-orphans > /dev/null 2>&1; then
		echo "could not remove the stack $project" >&2
		[ "$rc" -ne 0 ] || rc=1
	fi

	if [ "${#failed_phases[@]}" -gt 0 ]; then
		printf 'FAILED_PHASE=%s\n' "${failed_phases[0]}" >> "$out/summary.md" 2>/dev/null
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
rm -rf "$out"
mkdir -p "$out"

phase_clone() {
	if [ -e "$src" ]; then
		# Files a container wrote as root may not be removable from the host.
		rm -rf "$src" 2> /dev/null || docker run --rm -v "$here/src:/w" "$cli_image" rm -rf "/w/$ref"
	fi
	mkdir -p "$here/src"
	git clone --quiet --depth 1 --branch "$branch" https://github.com/Cacti/cacti "$src"
	printf 'CACTI_COMMIT=%s\nCACTI_REF=%s\n' "$(git -C "$src" rev-parse HEAD)" "$ref" > "$out/summary.md"

	if [ -z "$tarball" ]; then
		tarball="$out/mcp.tar.gz"
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
	compose run --rm -T checks php /checks/checks.php --ref="$ref" --phase=smoke 2>&1 | tee "$out/checks.log"
}

phase_inspector() {
	echo "inspector: not implemented yet"
}

phase_db_down() {
	echo "db-down: not implemented yet"
}

phase_logs() {
	echo "logs: not implemented yet"
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
soft_phase inspector
soft_phase db-down
soft_phase logs

[ "${#failed_phases[@]}" -eq 0 ]
