<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/storage.php';

$row = null;

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function rejects(callable $call, string $reason) : void {
	try {
		$call();
	} catch (AbilitiesError $error) {
		check($error->reason === $reason, 'Expected ' . $reason . ', got ' . $error->reason);

		return;
	}

	throw new RuntimeException('Expected ' . $reason);
}

function read_config_option($name, $force = false) {
	return 1;
}

function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false) {
	return 9;
}

function is_realm_allowed($realm, $user = false) {
	return true;
}

function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false) {
	global $row;

	return $row === null ? false : $row + ['token_hash' => $params[0]];
}

$token = 'cmcp_pat_' . str_repeat('ab12', 16);

check(preg_match('/^[a-f0-9]{64}$/D', (string) mcp_token_hash($token)) === 1, 'hash_valid_pat');
check(mcp_token_hash($token) === hash('sha256', $token), 'Hash covers the whole token');
check(mcp_token_hash(str_repeat('ab12', 16)) === null, 'hash_rejects_old_format');
check(mcp_token_hash('cmcp_pat_' . str_repeat('A', 64)) === null, 'Uppercase hex rejected');
check(mcp_token_hash($token . "\n") === null, 'Trailing newline rejected');
check(mcp_token_hash('cmcp_pat_' . str_repeat('a', 63)) === null, 'Short token rejected');

rejects(fn () => mcp_authenticate('Bearer ' . str_repeat('ab12', 16)), 'INVALID_TOKEN');
rejects(fn () => mcp_authenticate('Bearer cmcp_pat_' . str_repeat('A', 64)), 'INVALID_TOKEN');
rejects(fn () => mcp_authenticate($token), 'INVALID_TOKEN');

$row       = ['id' => '4', 'user_id' => '7', 'scopes' => '["abilities:read"]'];
$principal = mcp_authenticate('Bearer ' . $token);
check($principal === ['token_id' => 4, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']], 'authenticate_scope_fixed');

foreach (['["cacti.devices.get"]', '["abilities:read","cacti.devices.get"]', '[]', '{"a":"abilities:read"}', 'not json'] as $scopes) {
	$row['scopes'] = $scopes;
	rejects(fn () => mcp_authenticate('Bearer ' . $token), 'INVALID_TOKEN');
}

$row = null;
rejects(fn () => mcp_authenticate('Bearer ' . $token), 'INVALID_TOKEN');

echo "Token format and authentication checks passed.\n";
