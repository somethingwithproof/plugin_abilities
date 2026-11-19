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
$hooks       = [];

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
	check($sql === 'UPDATE plugin_mcp_token SET revoked = 1', 'Unexpected lifecycle query');

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

function api_plugin_register_hook($plugin, $hook, $function, $file) {
	global $hooks;
	$hooks[] = [$plugin, $hook, $function, $file];
}

function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false) {
	global $realm;
	check($params === ['mcp', 'api.php,mcp.php'], 'Unexpected realm lookup');

	return $realm;
}

check(!plugin_mcp_check_config(), 'A missing schema must block enable');
plugin_mcp_install();
check(plugin_mcp_check_config(), 'Fresh installation must be ready');
check($realmWrites[0] === ['mcp', 'api.php,mcp.php', 'MCP and Abilities API', false], 'Realm must not be granted automatically');
check(in_array(['mcp', 'config_settings', 'mcp_config_settings', 'setup.php'], $hooks, true), 'Install must register the config_settings hook');
$tabs     = [];
$settings = [];
mcp_config_settings();
check($tabs['mcp'] === 'MCP', 'Settings tab');
check($settings['mcp']['mcp_public_url']['method'] === 'textbox' && $settings['mcp']['mcp_public_url']['max_length'] === 255, 'Public URL field');
$tables['plugin_mcp_audit']['retained'] = 'audit fixture';
plugin_mcp_install();
check($tables['plugin_mcp_audit']['retained'] === 'audit fixture', 'Repeated setup must retain audit data');
plugin_mcp_uninstall();
check($revoked, 'Uninstall must revoke tokens');
check($tables['plugin_mcp_audit']['retained'] === 'audit fixture', 'Uninstall must retain audit data');
plugin_mcp_install();
check($revoked, 'Reinstall must not reactivate tokens');
check(isset($tables['plugin_mcp_ability']), 'Install creates the exposure table');
$tables['plugin_mcp_ability']['retained'] = 'exposure fixture';
plugin_mcp_install();
plugin_mcp_uninstall();
check($tables['plugin_mcp_ability']['retained'] === 'exposure fixture', 'Exposure choices survive reinstall and uninstall');
$exposure = $tables['plugin_mcp_ability'];
unset($tables['plugin_mcp_ability']);
check(!plugin_mcp_check_config(), 'A missing exposure table must block enable');
$tables['plugin_mcp_ability'] = $exposure;
unset($tables['plugin_mcp_audit']);
check(!plugin_mcp_check_config(), 'A missing audit table must block enable');
$realm     = false;
$failRealm = true;

try {
	plugin_mcp_install();

	throw new LogicException('Realm registration failure must fail installation');
} catch (RuntimeException $error) {
	check($error->getMessage() === 'MCP installation is incomplete', 'Unexpected setup error');
}
$failRealm  = false;
$failCreate = true;

try {
	plugin_mcp_install();

	throw new LogicException('Schema failure must fail installation');
} catch (RuntimeException $error) {
	check($error->getMessage() === 'MCP schema creation failed', 'Unexpected schema error');
}
$failRevoke = true;

try {
	plugin_mcp_uninstall();

	throw new LogicException('Token revocation failure must fail uninstall');
} catch (RuntimeException $error) {
	check($error->getMessage() === 'MCP token revocation failed', 'Unexpected revoke error');
}
unset($tables['plugin_mcp_token']);
plugin_mcp_uninstall();
check(plugin_mcp_version()['name'] === 'mcp', 'Native version callback');
print "Plugin installation, enable readiness, retention, and failure checks passed.\n";
