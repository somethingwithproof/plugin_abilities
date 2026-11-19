<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/registry.php';

function mcp_install_schema() : void {
	// Cacti's table registration also schedules tables for removal on uninstall.
	// These tables intentionally survive uninstall for token and audit retention.
	$queries = [
		'CREATE TABLE IF NOT EXISTS plugin_mcp_token (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			user_id INT UNSIGNED NOT NULL,
			token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
			scopes TEXT NOT NULL,
			expires_at BIGINT UNSIGNED NOT NULL,
			revoked TINYINT UNSIGNED NOT NULL DEFAULT 0
		) ENGINE=InnoDB',
		'CREATE TABLE IF NOT EXISTS plugin_mcp_audit (
			request_id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
			token_id BIGINT UNSIGNED NOT NULL,
			user_id INT UNSIGNED NOT NULL,
			ability VARCHAR(64) NOT NULL,
			outcome VARCHAR(16) NOT NULL,
			started_at BIGINT UNSIGNED NOT NULL,
			finished_at BIGINT UNSIGNED NULL
		) ENGINE=InnoDB'
	];

	foreach ($queries as $query) {
		if (!db_execute($query)) {
			throw new RuntimeException('MCP schema creation failed');
		}
	}
}

function mcp_has_realm(int $userId) : bool {
	// Cacti's realm helper allows everything when core authentication is off.
	if ((int) read_config_option('auth_method', true) === 0) {
		return false;
	}
	// Refresh the mode used by Cacti's device helper, not a prior session copy.
	read_config_option('graph_auth_method', true);
	$id = db_fetch_cell_prepared('SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?', ['mcp', 'api.php,mcp.php']);

	return $id !== false && (int) $id > 0 && is_realm_allowed((int) $id + 100, $userId);
}

function mcp_authenticate(string $header) : array {
	if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $matches)) {
		throw new AbilitiesError(401, 'invalid_token');
	}
	$hash = hash('sha256', $matches[1]);
	$row  = db_fetch_row_prepared('SELECT t.id, t.user_id, t.token_hash, t.scopes
		FROM plugin_mcp_token AS t
		JOIN user_auth AS u ON u.id = t.user_id
		WHERE t.token_hash = ? AND t.revoked = 0 AND t.expires_at > ? AND u.enabled = ?',
		[$hash, time(), 'on']);

	if (!$row || !hash_equals($row['token_hash'], $hash)) {
		throw new AbilitiesError(401, 'invalid_token');
	}
	$userId = (int) $row['user_id'];

	if (!mcp_has_realm($userId)) {
		throw new AbilitiesError(403, 'realm_required');
	}
	$scopes = json_decode($row['scopes'], true, 16, JSON_THROW_ON_ERROR);

	if (!is_array($scopes) || !array_is_list($scopes)) {
		throw new AbilitiesError(401, 'invalid_token');
	}

	return ['token_id' => (int) $row['id'], 'user_id' => $userId, 'scopes' => $scopes];
}

function mcp_audit(array $principal, string $id, string $name, string $outcome) : void {
	if ($outcome === 'started') {
		$ok = db_execute_prepared('INSERT INTO plugin_mcp_audit
			(request_id, token_id, user_id, ability, outcome, started_at) VALUES (?, ?, ?, ?, ?, ?)',
			[$id, $principal['token_id'], $principal['user_id'], $name, $outcome, time()]);
	} else {
		$ok = db_execute_prepared('UPDATE plugin_mcp_audit SET outcome = ?, finished_at = ? WHERE request_id = ?',
			[$outcome, time(), $id]);
	}

	if (!$ok) {
		throw new AbilitiesError(503, 'audit_unavailable');
	}
}
