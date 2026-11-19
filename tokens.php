<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$options = getopt('', ['create:', 'days:', 'revoke:', 'list', 'help']);

if (isset($options['help']) || !$options) {
	print "Usage: php tokens.php --create=USERNAME --days=30\n"
		. "       php tokens.php --revoke=ID | --list\n"
		. "Creation reads a token of the form cmcp_pat_ plus 64 lowercase hex characters from\n"
		. "CACTI_MCP_TOKEN. Generate one with:\n"
		. "  printf 'cmcp_pat_%s\\n' \"$(openssl rand -hex 32)\"\n"
		. "Tokens carry the abilities:read scope. Cacti realms decide what each user can see.\n"
		. "Only its SHA-256 hash is stored. Token values are never printed.\n";
	exit;
}

require __DIR__ . '/../../include/cli_check.php';
require_once __DIR__ . '/lib/storage.php';

try {
	if (!api_plugin_is_enabled('mcp') || (int) $config['poller_id'] !== 1) {
		throw new RuntimeException('Enable the plugin on the main poller first');
	}

	if (count(array_intersect(array_keys($options), ['create', 'revoke', 'list'])) !== 1) {
		throw new RuntimeException('Choose exactly one command');
	}

	if (isset($options['list'])) {
		print json_encode(db_fetch_assoc('SELECT id, user_id, scopes, expires_at, revoked FROM plugin_mcp_token ORDER BY id'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
	} elseif (isset($options['revoke'])) {
		$id = filter_var($options['revoke'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($id === false || !db_fetch_cell_prepared('SELECT id FROM plugin_mcp_token WHERE id = ?', [$id])) {
			throw new RuntimeException('Unknown token ID');
		}

		if (!db_execute_prepared('UPDATE plugin_mcp_token SET revoked = 1 WHERE id = ?', [$id])) {
			throw new RuntimeException('Revocation failed');
		}
		print "Token revoked.\n";
	} else {
		$token = getenv('CACTI_MCP_TOKEN');
		$days = filter_var($options['days'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
		$hash = is_string($token) ? mcp_token_hash($token) : null;

		if ($hash === null || $days === false) {
			throw new RuntimeException('Supply CACTI_MCP_TOKEN and an expiry of 1 to 90 days');
		}
		$user = (int) db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ? AND enabled = ?', [$options['create'], 'on']);

		if ($user <= 0 || !mcp_has_realm($user)) {
			throw new RuntimeException('User must be enabled and granted the MCP and Abilities API realm');
		}

		if (db_fetch_cell_prepared('SELECT id FROM plugin_mcp_token WHERE token_hash = ?', [$hash])) {
			throw new RuntimeException('Token already exists; generate a new value');
		}

		if (!db_execute_prepared('INSERT INTO plugin_mcp_token (user_id, token_hash, scopes, expires_at) VALUES (?, ?, ?, ?)',
			[$user, $hash, '["abilities:read"]', time() + $days * 86400])) {
			throw new RuntimeException('Token creation failed');
		}
		$id = db_fetch_cell_prepared('SELECT id FROM plugin_mcp_token WHERE token_hash = ?', [$hash]);
		print 'Created token ID ' . $id . PHP_EOL;
	}
} catch (AbilitiesError $error) {
	// AbilitiesError carries no message by default; print its reason code only.
	fwrite(STDERR, 'Token command failed: ' . $error->reason . PHP_EOL);
	exit(1);
} catch (Throwable $error) {
	fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage() . PHP_EOL : "Token command failed.\n");
	exit(1);
}
