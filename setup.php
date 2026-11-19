<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

function plugin_mcp_install() {
	if (version_compare(PHP_VERSION, '8.2', '<')) {
		throw new RuntimeException('MCP requires PHP 8.2 or later');
	}
	require_once __DIR__ . '/lib/storage.php';
	mcp_install_schema();

	// One realm row covers the endpoints and the self-service page. Cacti matches an existing row by
	// file and updates its file list, so a reinstall keeps the users who already hold the realm.
	api_plugin_register_realm('mcp', MCP_REALM_FILES, __('MCP and Abilities API', 'mcp'), false);

	// The console page needs its own realm; the installing admin gets it.
	api_plugin_register_realm('mcp', 'mcp_admin.php', __('MCP Administration', 'mcp'), true);

	api_plugin_register_hook('mcp', 'config_settings', 'mcp_config_settings', 'setup.php');
	api_plugin_register_hook('mcp', 'config_arrays', 'mcp_config_arrays', 'setup.php');
	api_plugin_register_hook('mcp', 'draw_navigation_text', 'mcp_draw_navigation_text', 'setup.php');

	if (!plugin_mcp_check_config()) {
		throw new RuntimeException(__('MCP installation is incomplete', 'mcp'));
	}
}

function mcp_config_settings() {
	global $tabs, $settings;

	$tabs['mcp']     = __('MCP', 'mcp');
	$settings['mcp'] = [
		'mcp_public_url' => [
			'friendly_name' => __('Public Cacti URL', 'mcp'),
			'description'   => __('The address MCP clients use to reach this Cacti server, for example https://cacti.example.com/cacti. Required before the MCP endpoint answers.', 'mcp'),
			'method'        => 'textbox',
			'default'       => '',
			'max_length'    => 255,
			'size'          => 60
		]
	];
}

function mcp_config_arrays() {
	global $menu;

	$menu[__('Management')]['plugins/mcp/mcp_admin.php'] = __('MCP', 'mcp');

	// Cacti's menu hides an item unless the user holds the realm of its file, and the realm
	// row lists mcp_tokens.php, so only holders of the MCP and Abilities API realm see this.
	$menu[__('Management')]['plugins/mcp/mcp_tokens.php'] = __('My MCP Tokens', 'mcp');
}

function mcp_draw_navigation_text($nav) {
	$nav['mcp_admin.php:']        = ['title' => __('MCP Administration', 'mcp'), 'mapping' => 'index.php:', 'url' => 'mcp_admin.php', 'level' => '1'];
	$nav['mcp_admin.php:actions'] = ['title' => __('Actions', 'mcp'), 'mapping' => 'index.php:,mcp_admin.php:', 'url' => '', 'level' => '2'];

	$nav['mcp_tokens.php:'] = ['title' => __('My MCP Tokens', 'mcp'), 'mapping' => 'index.php:', 'url' => 'mcp_tokens.php', 'level' => '1'];

	return $nav;
}

function plugin_mcp_uninstall() {
	// Preserve audit history, but reinstalling must not reactivate old tokens.
	if (db_table_exists('plugin_mcp_token')
		&& !db_execute('UPDATE plugin_mcp_token SET revoked = 1')) {
		throw new RuntimeException('MCP token revocation failed');
	}
}

function plugin_mcp_check_config() {
	require_once __DIR__ . '/lib/storage.php';

	return version_compare(PHP_VERSION, '8.2', '>=')
		&& db_table_exists('plugin_mcp_token')
		&& db_table_exists('plugin_mcp_audit')
		&& db_table_exists('plugin_mcp_ability')
		&& (int) db_fetch_cell_prepared(
			'SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?',
			['mcp', MCP_REALM_FILES]
		) > 0;
}

function plugin_mcp_upgrade() {
	return false;
}

function plugin_mcp_version() {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	return is_array($info) && isset($info['info']) ? $info['info'] : [];
}
