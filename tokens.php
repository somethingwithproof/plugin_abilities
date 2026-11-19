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
		. "Lifetime is 1 to 365 days. Creation reads a token of the form cmcp_pat_ plus 64 lowercase hex characters from\n"
		. "CACTI_MCP_TOKEN. Generate one with:\n"
		. "  printf 'cmcp_pat_%s\\n' \"$(openssl rand -hex 32)\"\n"
		. "Tokens carry the abilities:read scope. Cacti realms decide what each user can see.\n"
		. "Users can also manage their own tokens at Management > My MCP Tokens (mcp_tokens.php).\n"
		. "Tokens are refused for disabled users and the guest account.\n"
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
		print json_encode(db_fetch_assoc('SELECT id, user_id, scopes, expires_at, created_at, created_by, last_used_at, revoked FROM plugin_mcp_token ORDER BY id'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
	} elseif (isset($options['revoke'])) {
		$id = filter_var($options['revoke'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		$owner = $id === false ? 0 : (int) db_fetch_cell_prepared('SELECT user_id FROM plugin_mcp_token WHERE id = ? AND revoked = 0', [$id]);

		if ($owner < 1) {
			throw new RuntimeException('Unknown or already revoked token ID');
		}

		try {
			// The CLI has no Cacti user, so the audit row names the token's owner as the actor.
			mcp_token_revoke_audited($owner, [$id]);
		} catch (McpRevokeAuditError $error) {
			throw new RuntimeException('Token revoked, but the audit entry could not be written');
		}
		print "Token revoked.\n";
	} else {
		$token = getenv('CACTI_MCP_TOKEN');
		$days = filter_var($options['days'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
		$hash = is_string($token) ? mcp_token_hash($token) : null;

		if ($hash === null || $days === false) {
			throw new RuntimeException('Supply CACTI_MCP_TOKEN and an expiry of 1 to 365 days');
		}
		$user = (int) db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ? AND enabled = ?', [$options['create'], 'on']);

		try {
			// The same rules as the console: enabled, not the guest account, and holding the realm.
			mcp_token_assert_eligible($user);
		} catch (InvalidArgumentException $error) {
			throw new RuntimeException('User must be enabled, not the guest account, and granted the MCP and Abilities API realm');
		}

		if (db_fetch_cell_prepared('SELECT id FROM plugin_mcp_token WHERE token_hash = ?', [$hash])) {
			throw new RuntimeException('Token already exists; generate a new value');
		}

		try {
			// The CLI has no Cacti user, so the owner is both creator and actor in the audit row.
			// The value comes from the environment so it never appears on a command line.
			mcp_token_create_audited($user, $days, $user, $token);
		} catch (InvalidArgumentException $error) {
			throw new RuntimeException('Token creation refused');
		}
		$id = mcp_token_id($token);
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
