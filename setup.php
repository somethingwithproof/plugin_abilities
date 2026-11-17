<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

function plugin_abilities_install() {
	if (version_compare(PHP_VERSION, '8.2', '<')) {
		throw new RuntimeException('Abilities requires PHP 8.2 or later');
	}
	require_once __DIR__ . '/lib/storage.php';
	abilities_install_schema();
	api_plugin_register_realm('abilities', 'api.php', __('Abilities API', 'abilities'), false);
}

function plugin_abilities_uninstall() {
	// Preserve audit history, but reinstalling must not reactivate old tokens.
	if (!db_execute('UPDATE plugin_abilities_token SET revoked = 1')) {
		throw new RuntimeException('Abilities token revocation failed');
	}
}

function plugin_abilities_check_config() {
	return version_compare(PHP_VERSION, '8.2', '>=');
}

function plugin_abilities_upgrade() {
	return false;
}

function plugin_abilities_version() {
	return parse_ini_file(__DIR__ . '/INFO', true)['info'];
}
