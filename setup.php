<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

function plugin_mcp_install() {
	if (version_compare(PHP_VERSION, '8.2', '<')) {
		throw new RuntimeException('MCP requires PHP 8.2 or later');
	}
	require_once __DIR__ . '/lib/storage.php';
	mcp_install_schema();
	api_plugin_register_realm('mcp', 'api.php,mcp.php', __('MCP and Abilities API', 'mcp'), false);

	api_plugin_register_hook('mcp', 'config_settings', 'mcp_config_settings', 'setup.php');

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

function plugin_mcp_uninstall() {
	// Preserve audit history, but reinstalling must not reactivate old tokens.
	if (db_table_exists('plugin_mcp_token')
		&& !db_execute('UPDATE plugin_mcp_token SET revoked = 1')) {
		throw new RuntimeException('MCP token revocation failed');
	}
}

function plugin_mcp_check_config() {
	return version_compare(PHP_VERSION, '8.2', '>=')
		&& db_table_exists('plugin_mcp_token')
		&& db_table_exists('plugin_mcp_audit')
		&& db_table_exists('plugin_mcp_ability')
		&& (int) db_fetch_cell_prepared(
			'SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?',
			['mcp', 'api.php,mcp.php']
		) > 0;
}

function plugin_mcp_upgrade() {
	return false;
}

function plugin_mcp_version() {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	return is_array($info) && isset($info['info']) ? $info['info'] : [];
}
