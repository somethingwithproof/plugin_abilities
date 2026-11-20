#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Vincent
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Set up Cacti and the plugin inside the web container:
#   docker compose exec -T web bash /opt/mcp-it/setup.sh <subcommand>
#
#   install               write config.php, run install_cacti.php
#   plugin                unpack the plugin archive, install and enable it
#   fixtures              users, realms, Public Cacti URL, tokens, devices, admin password
#   guest-on | guest-off  set Cacti's guest_user to guestu's id, or to 0
#   count-tokens <user>   print how many token rows the user owns
#
# Generated values are written only to /run/mcp-it (a tmpfs volume) and are never printed.
set -euo pipefail

CACTI=/var/www/cacti
SECRETS=/run/mcp-it
ARCHIVE=/opt/mcp-it/mcp.tar.gz

cd "$CACTI"
export MYSQL_PWD="${DB_PASSWORD:?}"

sql() {
	mariadb -h "${DB_HOST:?}" -u "${DB_USER:?}" "${DB_NAME:?}" -N -B "$@"
}

# Write a value to the shared tmpfs volume, readable by root only.
write_secret() {
	(umask 077 && printf '%s' "$2" > "$SECRETS/$1")
}

# The id of the realm that covers api.php and mcp.php. Cacti stores plugin realms as
# plugin_realms.id + 100.
api_realm() {
	local id
	id="$(sql -e "SELECT id + 100 FROM plugin_realms WHERE plugin = 'mcp' AND file LIKE '%api.php%'")"
	[[ "$id" =~ ^[0-9]+$ ]] || { echo "MCP API realm not found in plugin_realms" >&2; return 1; }
	printf '%s\n' "$id"
}

cmd_install() {
	# Cacti's installer needs the time zone tables, and the last init step on the db
	# service is the grant that makes them readable. Wait for it.
	local i count=0
	for i in $(seq 1 60); do
		count="$(sql -e 'SELECT COUNT(*) FROM mysql.time_zone_name' 2>/dev/null || echo 0)"
		[ "${count:-0}" -gt 0 ] && break
		sleep 2
	done
	[ "${count:-0}" -gt 0 ] || { echo "database time zone tables never became readable" >&2; return 1; }

	php <<'PHP'
<?php
$config = file_get_contents('include/config.php.dist');
$values = [
	'database_hostname' => getenv('DB_HOST'),
	'database_default'  => getenv('DB_NAME'),
	'database_username' => getenv('DB_USER'),
	'database_password' => getenv('DB_PASSWORD'),
];

foreach ($values as $name => $value) {
	$config = preg_replace_callback('/^(\$' . $name . '\s*=\s*).*;[ \t]*$/m', fn ($m) => $m[1] . var_export($value, true) . ';', $config, 1, $count);

	if ($count !== 1) {
		fwrite(STDERR, "config.php.dist has no \$$name line\n");
		exit(1);
	}
}
file_put_contents('include/config.php', $config);
PHP

	# The CLI installer expects the base schema to be loaded already, as in Cacti's install guide.
	sql < "$CACTI/cacti.sql"
	php cli/install_cacti.php --accept-eula --install

	# The installer reports problems in its output and can still exit 0, so check the result
	# through Cacti itself: it must read its version from the database with config.php's credentials.
	local version
	version="$(php -r '$no_http_headers = true; require "include/cli_check.php"; echo db_fetch_cell("SELECT cacti FROM version");' 2>&1)"
	[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] \
		|| { echo "Cacti cannot read its own database after install_cacti.php" >&2; return 1; }
	[ "$(sql -e "SELECT COUNT(*) FROM user_auth WHERE username = 'admin'")" = 1 ] \
		|| { echo "install_cacti.php left no admin user" >&2; return 1; }

	# The web server serves as www-data and needs to write to these.
	chown -R www-data:www-data log rra cache resource scripts
}

cmd_plugin() {
	rm -rf plugins/mcp
	tar -xzf "$ARCHIVE" -C plugins
	[ -f plugins/mcp/setup.php ] || { echo "archive did not unpack to plugins/mcp" >&2; return 1; }
	chown -R www-data:www-data plugins/mcp

	php cli/plugin_manage.php --plugin=mcp --install --enable --allperms

	# status 1 is enabled in plugin_config.
	[ "$(sql -e "SELECT status FROM plugin_config WHERE directory = 'mcp'")" = 1 ] \
		|| { echo "plugin mcp is not installed and enabled" >&2; return 1; }
}

# Create a token for a user with tokens.php and save the raw value to $SECRETS/token-$2.
# The value goes through the environment, never a command line. Prints the token id.
make_token() {
	local user="$1" name="$2" token out
	token="$(printf 'cmcp_pat_%s' "$(openssl rand -hex 32)")"
	out="$(CACTI_MCP_TOKEN="$token" php plugins/mcp/tokens.php --create="$user" --days=1)"
	write_secret "token-$name" "$token"
	[[ "$out" =~ ^Created\ token\ ID\ ([0-9]+)$ ]] || { echo "tokens.php did not report a token id" >&2; return 1; }
	printf '%s\n' "${BASH_REMATCH[1]}"
}

cmd_fixtures() {
	local api
	api="$(api_realm)"

	ADMIN_PW="$(openssl rand -hex 16)" GUEST_PW="$(openssl rand -hex 16)" \
	OTHER_PW="$(openssl rand -hex 16)" MCPUSER_PW="$(openssl rand -hex 16)" API_REALM="$api" php <<'PHP'
<?php
// Users are copies of the admin row, so every column the running Cacti version needs
// has a valid value, then get their own name, a hashed password and an explicit realm list.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db  = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_NAME'));
$api = (int) getenv('API_REALM');

$users = [
	'mcpuser' => [$api, 3, 15, 8],
	'apionly' => [$api],
	'norealm' => [$api],
	'guestu'  => [$api, 3, 15],
];
$ids = [];

foreach ($users as $name => $realms) {
	$hash = password_hash(getenv(['guestu' => 'GUEST_PW', 'mcpuser' => 'MCPUSER_PW'][$name] ?? 'OTHER_PW'), PASSWORD_DEFAULT);
	$db->query("DROP TEMPORARY TABLE IF EXISTS user_tpl");
	$db->query("CREATE TEMPORARY TABLE user_tpl AS SELECT * FROM user_auth WHERE username = 'admin'");
	$stmt = $db->prepare("UPDATE user_tpl SET id = 0, username = ?, full_name = ?, password = ?, realm = 0, enabled = 'on', must_change_password = ''");
	$stmt->bind_param('sss', $name, $name, $hash);
	$stmt->execute();
	$db->query('INSERT INTO user_auth SELECT * FROM user_tpl');
	$ids[$name] = $db->insert_id;

	foreach ($realms as $realm) {
		$db->query('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (' . (int) $ids[$name] . ', ' . (int) $realm . ')');
	}
}

// The admin password becomes a generated value, with the forced change cleared so the
// scripted login does not stop at the change-password page.
$hash = password_hash(getenv('ADMIN_PW'), PASSWORD_DEFAULT);
$stmt = $db->prepare("UPDATE user_auth SET password = ?, must_change_password = '' WHERE username = 'admin'");
$stmt->bind_param('s', $hash);
$stmt->execute();

// mcp.php answers 400 until the Public Cacti URL is set. lib/origin.php accepts http only on localhost.
$db->query("REPLACE INTO settings (name, value) VALUES ('mcp_public_url', 'http://localhost/cacti')");

umask(0077);
file_put_contents('/run/mcp-it/admin-password', getenv('ADMIN_PW'));
file_put_contents('/run/mcp-it/guestu-password', getenv('GUEST_PW'));
file_put_contents('/run/mcp-it/mcpuser-password', getenv('MCPUSER_PW'));
file_put_contents('/run/mcp-it/ids.json', json_encode(['guestu' => $ids['guestu'], 'mcpuser' => $ids['mcpuser']]));
PHP

	make_token mcpuser mcpuser > /dev/null
	make_token apionly apionly > /dev/null
	make_token norealm norealm > /dev/null

	# A second mcpuser token, revoked straight away, for the 401 check.
	local revoked
	revoked="$(make_token mcpuser revoked)"
	php plugins/mcp/tokens.php --revoke="$revoked"

	# norealm's token exists, but its owner no longer holds the realm.
	sql -e "DELETE FROM user_auth_realm WHERE realm_id = $api AND user_id = (SELECT id FROM user_auth WHERE username = 'norealm')"

	# Availability checks off, so adding a device does not wait on an unreachable address.
	local n=21 name
	for name in it-alpha it-beta; do
		php cli/add_device.php --description="$name" --ip="192.0.2.$n" --template=0 --avail=none --version=0
		n=$((n + 1))
	done
	[ "$(sql -e "SELECT COUNT(*) FROM host WHERE description LIKE 'it-%'")" = 2 ] \
		|| { echo "devices were not created" >&2; return 1; }
}

cmd_guest() {
	local id="$1"
	sql -e "REPLACE INTO settings (name, value) VALUES ('guest_user', '$id')"
}

cmd_count_tokens() {
	local user="${1:?usage: count-tokens <username>}"
	sql -e "SELECT COUNT(*) FROM plugin_mcp_token WHERE user_id = (SELECT id FROM user_auth WHERE username = '$user')"
}

case "${1:-}" in
	install) cmd_install ;;
	plugin) cmd_plugin ;;
	fixtures) cmd_fixtures ;;
	guest-on) cmd_guest "$(php -r 'echo json_decode(file_get_contents("/run/mcp-it/ids.json"), true)["guestu"];')" ;;
	guest-off) cmd_guest 0 ;;
	count-tokens) cmd_count_tokens "${2:-}" ;;
	*) echo "usage: setup.sh install|plugin|fixtures|guest-on|guest-off|count-tokens <username>" >&2; exit 2 ;;
esac
