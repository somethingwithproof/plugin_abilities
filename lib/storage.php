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
		) ENGINE=InnoDB',
		'CREATE TABLE IF NOT EXISTS plugin_mcp_ability (
			id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
			exposed TINYINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL
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

function mcp_token_hash(string $token) : ?string {
	return preg_match('/^cmcp_pat_[a-f0-9]{64}$/D', $token) === 1 ? hash('sha256', $token) : null;
}

function mcp_authenticate(string $header) : array {
	if (!preg_match('/^Bearer (cmcp_pat_[a-f0-9]{64})$/D', $header, $matches)) {
		throw new AbilitiesError('UNAUTHENTICATED', 'INVALID_TOKEN');
	}
	$hash = mcp_token_hash($matches[1]);
	$row  = db_fetch_row_prepared('SELECT t.id, t.user_id, t.token_hash, t.scopes
		FROM plugin_mcp_token AS t
		JOIN user_auth AS u ON u.id = t.user_id
		WHERE t.token_hash = ? AND t.revoked = 0 AND t.expires_at > ? AND u.enabled = ?',
		[$hash, time(), 'on']);

	if (!$row || !hash_equals($row['token_hash'], $hash)) {
		throw new AbilitiesError('UNAUTHENTICATED', 'INVALID_TOKEN');
	}
	$userId = (int) $row['user_id'];

	if (!mcp_has_realm($userId)) {
		throw new AbilitiesError('PERMISSION_DENIED', 'REALM_REQUIRED');
	}

	// Personal tokens carry exactly one scope; anything else is a corrupt or foreign row.
	if (json_decode($row['scopes'], true, 16) !== ['abilities:read']) {
		throw new AbilitiesError('UNAUTHENTICATED', 'INVALID_TOKEN');
	}

	return ['token_id' => (int) $row['id'], 'token_kind' => 'pat', 'user_id' => $userId, 'scopes' => ['abilities:read']];
}

function mcp_audit(array $principal, string $id, string $name, string $outcome) : void {
	if ($outcome === 'started') {
		$ok = db_execute_prepared('INSERT INTO plugin_mcp_audit
			(request_id, token_id, user_id, ability, outcome, started_at) VALUES (?, ?, ?, ?, ?, ?)',
			[$id, $principal['token_id'], $principal['user_id'], $name, $outcome, time()]);
	} elseif ($outcome === 'denied' || $outcome === 'rejected') {
		// A refused attempt has no earlier 'started' row; it is one row, started and finished together.
		$now = time();
		$ok  = db_execute_prepared('INSERT INTO plugin_mcp_audit
			(request_id, token_id, user_id, ability, outcome, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
			[$id, $principal['token_id'], $principal['user_id'], $name, $outcome, $now, $now]);
	} else {
		$ok = db_execute_prepared('UPDATE plugin_mcp_audit SET outcome = ?, finished_at = ? WHERE request_id = ?',
			[$outcome, time(), $id]);
	}

	if (!$ok) {
		throw new AbilitiesError('UNAVAILABLE', 'AUDIT_UNAVAILABLE');
	}
}

/**
 * Stored exposure flags by ability ID, read once per request.
 *
 * @return array<string, bool>
 */
function mcp_exposure_map(bool $refresh = false) : array {
	static $map = null;

	if ($map === null || $refresh) {
		$map  = [];
		$rows = db_fetch_assoc_prepared('SELECT id, exposed FROM plugin_mcp_ability', []);

		// A failed read must not fall back to defaults, which could re-expose abilities an admin hid.
		// '*' is not a valid ability ID, so it can only come from this sentinel.
		if ($rows === false) {
			return $map = ['*' => false];
		}

		foreach ($rows as $row) {
			$map[(string) $row['id']] = (int) $row['exposed'] === 1;
		}
	}

	return $map;
}

/** A stored flag wins; otherwise built-in (cacti) abilities are exposed and plugin abilities are hidden. */
function mcp_is_exposed(string $id, string $namespace, array $map) : bool {
	if (($map['*'] ?? true) === false) {
		return false;
	}

	return $map[$id] ?? $namespace === 'cacti';
}

/** Expose or hide abilities. Every ID is validated before anything is written. */
function mcp_set_exposure(array $ids, bool $exposed) : void {
	foreach ($ids as $id) {
		if (!is_string($id) || !AbilitiesRegistry::isValidId($id)) {
			throw new InvalidArgumentException('Invalid ability ID');
		}
	}
	$now = time();

	try {
		foreach (array_unique($ids) as $id) {
			if (!db_execute_prepared('INSERT INTO plugin_mcp_ability (id, exposed, updated_at) VALUES (?, ?, ?)
				ON DUPLICATE KEY UPDATE exposed = VALUES(exposed), updated_at = VALUES(updated_at)',
				[$id, $exposed ? 1 : 0, $now])) {
				throw new RuntimeException('MCP exposure update failed');
			}
		}
	} finally {
		mcp_exposure_map(true);
	}
}
