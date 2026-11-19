<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/storage.php';
require_once __DIR__ . '/../lib/admin.php';

$tokens     = [];
$writes     = [];
$queries    = [];
$total      = 0;
$failUpdate = false;
$failInsert = false;
$throwUsage = false;

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function __($text, ...$args) {
	return $text;
}

function read_config_option($name, $force = false) {
	return 1;
}

function is_realm_allowed($realm, $user = false) {
	return true;
}

function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
	global $tokens, $writes, $failUpdate, $failInsert, $throwUsage;
	$writes[] = [$sql, $params];

	if (str_starts_with($sql, 'INSERT INTO plugin_mcp_token')) {
		if ($failInsert) {
			return false;
		}
		$tokens[] = ['id' => (string) (count($tokens) + 1), 'user_id' => (string) $params[0], 'token_hash' => $params[1],
			'scopes' => $params[2], 'expires_at' => $params[3], 'created_at' => $params[4], 'created_by' => $params[5], 'last_used_at' => null, 'revoked' => '0'];

		return true;
	}

	if (str_contains($sql, 'last_used_at')) {
		if ($throwUsage) {
			throw new RuntimeException('database went away');
		}

		return !$failUpdate;
	}

	return true;
}

function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false) {
	global $tokens;

	foreach ($tokens as $row) {
		if ($row['token_hash'] === $params[0]) {
			return $row;
		}
	}

	return false;
}

function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false) {
	global $queries, $total, $tokens;

	if (str_starts_with($sql, 'SELECT id FROM plugin_mcp_token WHERE token_hash')) {
		foreach ($tokens as $row) {
			if ($row['token_hash'] === $params[0]) {
				return $row['id'];
			}
		}

		return false;
	}

	if (str_contains($sql, 'COUNT(*)')) {
		$queries[] = [$sql, $params];

		return $total;
	}

	return 9;
}

function db_fetch_assoc_prepared($sql, $params = []) {
	global $queries;
	$queries[] = [$sql, $params];

	return [['id' => '1']];
}

function throws(callable $call, string $class) : void {
	try {
		$call();
	} catch (Throwable $error) {
		check($error instanceof $class, 'Expected ' . $class . ', got ' . $error::class);

		return;
	}

	throw new RuntimeException('Expected ' . $class);
}

// ability_rows_show_exposure
$all = [
	['id' => 'cacti-list-devices', 'label' => 'List devices', 'description' => '', 'category' => 'inventory', 'annotations' => ['readOnly' => true], 'namespace' => 'cacti'],
	['id' => 'thold-list-thresholds', 'label' => 'List thresholds', 'description' => '', 'category' => 'inventory', 'annotations' => ['readOnly' => true], 'namespace' => 'thold'],
	['id' => 'thold-get-threshold', 'label' => 'Get threshold', 'description' => '', 'category' => 'inventory', 'annotations' => ['readOnly' => true], 'namespace' => 'thold'],
];
$rows = mcp_admin_ability_rows($all, ['thold-get-threshold' => true], fn (array $ability) => 'realm for ' . $ability['id']);
check(count($rows) === 3, 'One row per ability');
check($rows[0]['exposed'] === true && $rows[0]['namespace'] === 'cacti', 'Built-ins are exposed by default');
check($rows[1]['exposed'] === false && $rows[1]['namespace'] === 'thold', 'A hidden plugin ability is not exposed');
check($rows[2]['exposed'] === true, 'A stored flag wins');
check($rows[1]['realm'] === 'realm for thold-list-thresholds' && $rows[1]['label'] === 'List thresholds'
	&& $rows[1]['category'] === 'inventory' && $rows[1]['annotations'] === ['readOnly' => true], 'Row carries realm, label, category, annotations');
$unreadable = mcp_admin_ability_rows($all, ['*' => false], fn () => '');
check(array_column($unreadable, 'exposed') === [false, false, false], 'An unreadable exposure map shows everything hidden');

// parse_action_validates_ids
$parsed = mcp_admin_parse_action(['drp_action' => 'expose', 'chk_cacti-get-device' => 'on', 'chk_bad.id' => 'on', 'chk_Cacti-get-device' => 'on', 'other' => 'on']);
check($parsed === ['action' => 'expose', 'ids' => ['cacti-get-device']], 'Only well-formed ability IDs survive');
$parsed = mcp_admin_parse_action(['action' => 'hide', 'chk_thold-list-thresholds' => 'on']);
check($parsed['action'] === 'hide' && $parsed['ids'] === ['thold-list-thresholds'], 'The action field is accepted');
$parsed = mcp_admin_parse_action(['drp_action' => 'revoke', 'chk_4' => 'on', 'chk_0' => 'on', 'chk_-3' => 'on', 'chk_5x' => 'on', 'chk_007' => 'on', 'chk_12' => 'on']);
check($parsed === ['action' => 'revoke', 'ids' => [4, 12]], 'Token IDs are positive integers');

// parse_action_rejects_unknown_action
foreach (['drop', '0', '', 'actions', 'EXPOSE'] as $bad) {
	throws(fn () => mcp_admin_parse_action(['action' => $bad, 'chk_cacti-get-device' => 'on']), InvalidArgumentException::class);
}
throws(fn () => mcp_admin_parse_action([]), InvalidArgumentException::class);

// token_create_shape
$before = time();
$token  = mcp_token_create(7, 90);
check(mcp_token_hash($token) !== null, 'Created token has the personal token format');
check(count($tokens) === 1 && $tokens[0]['token_hash'] === hash('sha256', $token), 'Only the SHA-256 hash is stored');
check($tokens[0]['scopes'] === '["abilities:read"]' && $tokens[0]['user_id'] === '7', 'Scope and owner are stored');
check($tokens[0]['expires_at'] >= $before + 90 * 86400 && $tokens[0]['expires_at'] <= time() + 90 * 86400, 'Expiry is days from now');
check($tokens[0]['created_at'] >= $before && $tokens[0]['created_at'] <= time(), 'Creation time is stored');
check($tokens[0]['created_by'] === 7, 'Self-service creator defaults to the owner');
check(!str_contains(json_encode($writes), $token), 'The token itself is never written');
check(mcp_token_create(7, 90) !== $token, 'Tokens are random');
mcp_token_create(8, 30, 2);
check($tokens[2]['user_id'] === '8' && $tokens[2]['created_by'] === 2, 'An admin-created token records its creator');
check(mcp_token_id($token) === 1 && mcp_token_id('nope') === 0, 'Token ID lookup by value');
throws(fn () => mcp_token_create(8, 30, 0), InvalidArgumentException::class);
array_pop($tokens);

// a created token authenticates with the exact scopes mcp_authenticate requires
$principal = mcp_authenticate('Bearer ' . $token);
check($principal === ['token_id' => 1, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']], 'A created token passes mcp_authenticate');

foreach ([[0, 90], [-1, 90], [7, 0], [7, 366]] as [$user, $days]) {
	throws(fn () => mcp_token_create($user, $days), InvalidArgumentException::class);
}
$failInsert = true;
throws(fn () => mcp_token_create(7, 30), RuntimeException::class);
$failInsert = false;

// last_used_at: one throttled UPDATE, and a failure does not fail authentication
$writes = [];
mcp_authenticate('Bearer ' . $token);
$updates = array_values(array_filter($writes, fn ($w) => str_contains($w[0], 'last_used_at')));
check(count($updates) === 1, 'One last_used_at update per authentication');
[$sql, $params] = $updates[0];
check(str_starts_with($sql, 'UPDATE plugin_mcp_token SET last_used_at = ?') && str_contains($sql, 'WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)'), 'Throttled update statement');
check($params[1] === 1 && $params[0] - $params[2] === 60 && abs($params[0] - time()) <= 2, 'Update binds now, the token ID, and now minus 60');
$failUpdate = true;
check(mcp_authenticate('Bearer ' . $token)['token_id'] === 1, 'A failed last_used_at update does not fail authentication');
$failUpdate = false;
$throwUsage = true;
check(mcp_authenticate('Bearer ' . $token)['token_id'] === 1, 'A throwing last_used_at update does not fail authentication');
$throwUsage = false;
$writes = [];
throws(fn () => mcp_authenticate('Bearer cmcp_pat_' . str_repeat('0', 64)), AbilitiesError::class);
check(array_filter($writes, fn ($w) => str_contains($w[0], 'last_used_at')) === [], 'A rejected token records no use');

// mcp_admin_may_create_for: another user's token needs User Management
check(mcp_admin_may_create_for(2, 2, false) === true, 'Own token needs nothing extra');
check(mcp_admin_may_create_for(2, 5, false) === false, 'Another user needs the user realm');
check(mcp_admin_may_create_for(2, 5, true) === true, 'User admin may create for another user');
check(mcp_admin_may_create_for(0, 0, true) === false && mcp_admin_may_create_for(2, 0, true) === false && mcp_admin_may_create_for(0, 2, true) === false, 'Unknown users are refused');

// one-time form nonce
$session = [];
$nonce   = mcp_admin_nonce_issue($session);
check(preg_match('/^[a-f0-9]{32}$/D', $nonce) === 1 && $nonce !== mcp_admin_nonce_issue($session), 'Nonce is random hex, a new issue replaces the old');
$nonce = mcp_admin_nonce_issue($session);
check(mcp_admin_nonce_consume($session, 'wrong') === false, 'A wrong nonce is refused');
check(mcp_admin_nonce_consume($session, $nonce) === false, 'A wrong attempt also spends the nonce');
$nonce = mcp_admin_nonce_issue($session);
check(mcp_admin_nonce_consume($session, $nonce) === true, 'The issued nonce is accepted once');
check(mcp_admin_nonce_consume($session, $nonce) === false, 'A reused nonce is refused');
check(mcp_admin_nonce_consume($session, '') === false && mcp_admin_nonce_consume($session, 'x') === false, 'A missing nonce is refused');
$session = [];
check(mcp_admin_nonce_consume($session, '') === false, 'Empty session and empty value are refused');

// admin audit rows
$writes = [];
mcp_audit_admin(2, 9, 'token-create');
mcp_audit_admin(2, 9, 'token-revoke');
[$sql, $params] = $writes[0];
check(str_starts_with($sql, 'INSERT INTO plugin_mcp_audit') && preg_match('/^[a-f0-9]{32}$/D', $params[0]) === 1
	&& array_slice($params, 1, 4) === [9, 2, 'mcp-token-create', 'succeeded'] && $params[5] === $params[6], 'Create is audited as one complete row by the actor');
check($writes[1][1][3] === 'mcp-token-revoke' && AbilitiesRegistry::isValidId($writes[1][1][3]), 'Revoke is audited');
throws(fn () => mcp_audit_admin(2, 9, 'token-delete'), InvalidArgumentException::class);

// mcp_token_revoke
$writes = [];
mcp_token_revoke([]);
check($writes === [], 'Revoking nothing writes nothing');
mcp_token_revoke([4, 12]);
check($writes === [['UPDATE plugin_mcp_token SET revoked = 1 WHERE id IN (?, ?)', [4, 12]]], 'Revoke binds every ID');
$writes = [];

foreach ([[0], [-1], ['4'], ['4); DROP TABLE x'], [4, 'x']] as $bad) {
	throws(fn () => mcp_token_revoke($bad), InvalidArgumentException::class);
}
check($writes === [], 'Invalid IDs write nothing');

// mcp_token_list and mcp_audit_list: bound filters, integer paging
$total   = 45;
$queries = [];
$result  = mcp_token_list([], 3, 20);
check($result['total'] === 45 && $result['rows'] === [['id' => '1']], 'Token list returns rows and total');
check(str_contains($queries[1][0], 'LIMIT 20 OFFSET 40') && $queries[1][1] === [], 'Token paging is integer');
check(str_contains($queries[1][0], 'c.username AS created_by_name') && !str_contains($queries[1][0], 'token_hash'), 'Token list shows the creator, never the hash');
$queries = [];
mcp_token_list(['user_id' => 7], 1, 20);
check(str_contains($queries[1][0], 'WHERE t.user_id = ?') && $queries[1][1] === [7], 'Token user filter is bound');

$queries = [];
$result  = mcp_audit_list(['user_id' => 7, 'ability' => 'cacti-get-device', 'outcome' => 'denied'], 2, 25);
check($result['total'] === 45, 'Audit total');
[$sql, $params] = $queries[1];
check(str_contains($sql, 'a.user_id = ?') && str_contains($sql, 'a.ability = ?') && str_contains($sql, 'a.outcome = ?')
	&& $params === [7, 'cacti-get-device', 'denied'] && str_contains($sql, 'ORDER BY a.started_at DESC') && str_contains($sql, 'LIMIT 25 OFFSET 25'), 'Audit filters are bound, newest first');
check(!str_contains($sql, 'cacti-get-device') && !str_contains($sql, 'denied'), 'No filter value is in the SQL text');
$queries = [];
mcp_audit_list(['user_id' => -1, 'ability' => '', 'outcome' => ''], 1, 25);
check(!str_contains($queries[1][0], 'WHERE') && $queries[1][1] === [], 'Empty filters mean no condition');

foreach (['started', 'succeeded', 'failed', 'denied', 'rejected'] as $outcome) {
	mcp_audit_list(['outcome' => $outcome], 1, 10);
}

foreach ([['outcome' => 'done'], ['outcome' => "denied' OR 1=1"], ['ability' => 'Bad Id'], ['ability' => "x' OR '1'='1"], ['ability' => str_repeat('a-', 40)], ['user_id' => 'x'], ['user_id' => 0]] as $filter) {
	throws(fn () => mcp_audit_list($filter, 1, 10), InvalidArgumentException::class);
}
check(mcp_audit_list([], MCP_MAX_PAGE, 500)['total'] === 45, 'The last allowed page works');
check(str_contains($queries[count($queries) - 1][0], 'OFFSET ' . ((MCP_MAX_PAGE - 1) * 500)), 'Offset stays an exact integer');
foreach ([[0, 10], [1, 0], [1, 501], [-1, 10], [MCP_MAX_PAGE + 1, 10], [PHP_INT_MAX, 500]] as [$page, $rowsPerPage]) {
	throws(fn () => mcp_audit_list([], $page, $rowsPerPage), InvalidArgumentException::class);
}

// install adds the columns the console reads
$created = [];
function db_execute($sql) {
	global $created;
	$created[] = $sql;

	return true;
}
mcp_install_schema();
$tokenTable = array_values(array_filter($created, fn ($sql) => str_contains($sql, 'CREATE TABLE IF NOT EXISTS plugin_mcp_token')))[0];
check(str_contains($tokenTable, 'created_at BIGINT UNSIGNED NOT NULL DEFAULT 0') && str_contains($tokenTable, 'created_by INT UNSIGNED NOT NULL') && str_contains($tokenTable, 'last_used_at BIGINT UNSIGNED NULL'), 'Token table has created_at and last_used_at');

echo "Admin console helper checks passed.\n";
