<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../setup.php';
require_once __DIR__ . '/../lib/storage.php';

$registered = null;
$lookups    = [];
$allowed    = [];

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function __($text, ...$args) {
	return $text;
}

function db_table_exists($table, $log = true, $db_conn = false) {
	return true;
}

function db_column_exists($table, $column, $log = true, $db_conn = false) {
	return true;
}

function db_add_column($table, $column, $log = true, $db_conn = false) {
	return true;
}

function db_execute($sql, $log = true, $db_conn = false) {
	return true;
}

function api_plugin_register_hook($plugin, $hook, $function, $file) {
}

function api_plugin_register_realm($plugin, $file, $display, $admin = true) {
	global $registered;

	// The admin realm is covered by tests/lifecycle.php; this test follows the API realm.
	if ($file !== 'mcp_admin.php') {
		$registered = [$plugin, $file];
	}
}

function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false) {
	global $lookups;
	$lookups[] = $params;

	return 9;
}

function read_config_option($name, $force = false) {
	return 1;
}

function is_realm_allowed($realm, $user = false) {
	global $allowed;
	$allowed[] = [$realm, $user];

	return true;
}

plugin_mcp_install();
$lookups = [];
check(mcp_has_realm(7), 'Realm holder must be accepted');
check($lookups === [$registered], 'Realm lookup must use the plugin and file list registered by setup');
check($registered === ['mcp', 'api.php,mcp.php,mcp_tokens.php'], 'Realm must cover api.php, mcp.php, and mcp_tokens.php');
check($allowed === [[109, 7]], 'Realm check must use the stored id plus 100 for the user');

echo "Realm registration and lookup checks passed.\n";
