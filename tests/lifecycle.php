<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../setup.php';

$tables      = [];
$realm       = false;
$realmWrites = [];
$revoked     = false;
$failCreate  = false;
$failRealm   = false;
$failRevoke  = false;

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function __($text, ...$args) {
	return $text;
}

function db_table_exists($table, $log = true, $db_conn = false) {
	global $tables;

	return isset($tables[$table]);
}

function db_execute($sql, $log = true, $db_conn = false) {
	global $tables, $revoked, $failCreate, $failRevoke;

	if (preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $matches)) {
		if ($failCreate) {
			return false;
		}
		$tables[$matches[1]] ??= [];

		return true;
	}
	check($sql === 'UPDATE plugin_abilities_token SET revoked = 1', 'Unexpected lifecycle query');

	if ($failRevoke) {
		return false;
	}
	$revoked = true;

	return true;
}

function api_plugin_register_realm($plugin, $file, $display, $admin = true) {
	global $realm, $realmWrites, $failRealm;
	$realmWrites[] = [$plugin, $file, $display, $admin];

	if (!$failRealm) {
		$realm = 9;
	}
}

function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false) {
	global $realm;
	check($params === ['abilities', 'api.php'], 'Unexpected realm lookup');

	return $realm;
}

check(!plugin_abilities_check_config(), 'A missing schema must block enable');
plugin_abilities_install();
check(plugin_abilities_check_config(), 'Fresh installation must be ready');
check($realmWrites[0] === ['abilities', 'api.php', 'Abilities API', false], 'Realm must not be granted automatically');
$tables['plugin_abilities_audit']['retained'] = 'audit fixture';
plugin_abilities_install();
check($tables['plugin_abilities_audit']['retained'] === 'audit fixture', 'Repeated setup must retain audit data');
plugin_abilities_uninstall();
check($revoked, 'Uninstall must revoke tokens');
check($tables['plugin_abilities_audit']['retained'] === 'audit fixture', 'Uninstall must retain audit data');
plugin_abilities_install();
check($revoked, 'Reinstall must not reactivate tokens');
unset($tables['plugin_abilities_audit']);
check(!plugin_abilities_check_config(), 'A missing audit table must block enable');
$realm     = false;
$failRealm = true;

try {
	plugin_abilities_install();

	throw new LogicException('Realm registration failure must fail installation');
} catch (RuntimeException $error) {
	check($error->getMessage() === 'Abilities installation is incomplete', 'Unexpected setup error');
}
$failRealm  = false;
$failCreate = true;

try {
	plugin_abilities_install();

	throw new LogicException('Schema failure must fail installation');
} catch (RuntimeException $error) {
	check($error->getMessage() === 'Abilities schema creation failed', 'Unexpected schema error');
}
$failRevoke = true;

try {
	plugin_abilities_uninstall();

	throw new LogicException('Token revocation failure must fail uninstall');
} catch (RuntimeException $error) {
	check($error->getMessage() === 'Abilities token revocation failed', 'Unexpected revoke error');
}
unset($tables['plugin_abilities_token']);
plugin_abilities_uninstall();
check(plugin_abilities_version()['name'] === 'abilities', 'Native version callback');
print "Plugin installation, enable readiness, retention, and failure checks passed.\n";
