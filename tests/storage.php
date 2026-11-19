<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/storage.php';

$row           = null;
$writes        = [];
$exposureRows  = [];
$reads         = 0;
$failWrite     = false;

function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
	global $writes, $failWrite;
	$writes[] = [$sql, $params];

	return !$failWrite;
}

function db_fetch_assoc_prepared($sql, $params = []) {
	global $exposureRows, $reads;

	if (str_starts_with($sql, 'SELECT id FROM user_auth WHERE username = ?')) {
		return [];
	}
	check($sql === 'SELECT id, exposed FROM plugin_mcp_ability' && $params === [], 'Exposure query');
	$reads++;

	return $exposureRows;
}

$logs = [];

function cacti_log($message, $output = false, $facility = '') {
	global $logs;
	$logs[] = [$message, $output, $facility];
}

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

// a row whose stored hash differs from the presented token's hash is refused
$row = ['id' => '4', 'user_id' => '7', 'scopes' => '["abilities:read"]', 'token_hash' => str_repeat('0', 64)];
rejects(fn () => mcp_authenticate('Bearer ' . $token), 'INVALID_TOKEN');

$row = null;
rejects(fn () => mcp_authenticate('Bearer ' . $token), 'INVALID_TOKEN');
$writes = [];

// mcp_is_exposed
check(mcp_is_exposed('thold-list-thresholds', 'thold', []) === false, 'plugin_default_hidden');
check(mcp_is_exposed('cacti-list-devices', 'cacti', []) === true, 'builtin_default_exposed');
check(mcp_is_exposed('thold-list-thresholds', 'thold', ['thold-list-thresholds' => true]) === true, 'stored_value_wins (expose plugin)');
check(mcp_is_exposed('cacti-list-devices', 'cacti', ['cacti-list-devices' => false]) === false, 'stored_value_wins (hide built-in)');

// mcp_audit: one INSERT for started, one UPDATE for finishes, one complete INSERT for refusals
$principal = ['token_id' => 4, 'user_id' => 7];
mcp_audit($principal, str_repeat('a', 32), 'cacti-list-devices', 'started');
mcp_audit($principal, str_repeat('a', 32), 'cacti-list-devices', 'succeeded');
mcp_audit($principal, str_repeat('b', 32), 'cacti-list-devices', 'denied');
mcp_audit($principal, str_repeat('c', 32), 'cacti-list-devices', 'rejected');
check(count($writes) === 4 && str_starts_with($writes[0][0], 'INSERT') && str_starts_with($writes[1][0], 'UPDATE'), 'Audit started and finished statements');

foreach ([2, 3] as $i) {
	[$sql, $params] = $writes[$i];
	check(str_starts_with($sql, 'INSERT INTO plugin_mcp_audit') && str_contains($sql, 'finished_at')
		&& count($params) === 7 && $params[4] === ($i === 2 ? 'denied' : 'rejected') && $params[5] === $params[6], 'Refusal is one complete row');
}
$writes = [];

// mcp_set_exposure
mcp_set_exposure(['cacti-list-devices', 'thold-list-thresholds', 'thold-list-thresholds'], true);
check(count($writes) === 1 && array_slice($writes[0][1], 0, 2) === ['cacti-list-devices', 1] && array_slice($writes[0][1], 3, 2) === ['thold-list-thresholds', 1]
	&& count($writes[0][1]) === 6 && str_contains($writes[0][0], 'ON DUPLICATE KEY UPDATE') && !str_contains($writes[0][0], 'cacti-list-devices'),
	'Exposure upsert is one statement with bound parameters');
mcp_set_exposure(['thold-list-thresholds'], false);
check($writes[1][1][1] === 0, 'Hiding stores zero');
$before = count($writes);
mcp_set_exposure([], true);
check(count($writes) === $before, 'Nothing to change writes nothing');
$writes = [];

foreach ([["x'; DROP TABLE t; --"], ['Cacti-list-devices'], ["cacti-list-devices\n"], ['cacti'], [str_repeat('a', 65)], [''], [5], ['cacti-list-devices', 'bad id']] as $bad) {
	try {
		mcp_set_exposure($bad, true);

		throw new LogicException('Invalid ID accepted');
	} catch (InvalidArgumentException $error) {
		check($writes === [], 'Invalid IDs write nothing, not even the valid ones');
	}
}

// the exposure map is read once and refreshed after a write
$exposureRows = [['id' => 'thold-list-thresholds', 'exposed' => '1'], ['id' => 'cacti-get-device', 'exposed' => '0']];
$reads        = 0;
check(mcp_exposure_map(true) === ['thold-list-thresholds' => true, 'cacti-get-device' => false], 'Map from rows');
mcp_exposure_map();
mcp_exposure_map();
check($reads === 1, 'Map is cached');
mcp_set_exposure(['thold-list-thresholds'], false);
mcp_exposure_map();
check($reads === 2, 'A write resets the cache');
$writes = [];
$failWrite = true;

try {
	mcp_set_exposure(['thold-list-thresholds'], true);

	throw new LogicException('A failed write must throw');
} catch (RuntimeException $error) {
	check($error->getMessage() === 'MCP exposure update failed', 'Write failure surfaces');
}
$failWrite = false;

// an unwritable audit log refuses the call and leaves a trace for the admin
$failWrite = true;
$logs      = [];
rejects(fn () => mcp_audit(['token_id' => 1, 'user_id' => 7], str_repeat('a', 32), 'cacti-list-devices', 'started'), 'AUDIT_UNAVAILABLE');
check($logs === [['MCP: ability cacti-list-devices failed: AUDIT_UNAVAILABLE', false, 'MCP']], 'AUDIT_UNAVAILABLE is logged with the ability and reason only');
$failWrite = false;

// a failed read hides everything rather than falling back to defaults
$exposureRows = false;
$failedMap    = mcp_exposure_map(true);
check(mcp_is_exposed('cacti-list-devices', 'cacti', $failedMap) === false && mcp_is_exposed('thold-list-thresholds', 'thold', $failedMap) === false, 'Failed read exposes nothing');
$exposureRows = [];
check(mcp_is_exposed('cacti-list-devices', 'cacti', mcp_exposure_map(true)) === true, 'An empty table still uses the defaults');

// install creates the exposure table
$created = [];
function db_execute($sql) {
	global $created;
	$created[] = $sql;

	return true;
}
mcp_install_schema();
check(count(array_filter($created, fn ($sql) => str_contains($sql, 'CREATE TABLE IF NOT EXISTS plugin_mcp_ability')
	&& str_contains($sql, 'id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY') && str_contains($sql, 'exposed TINYINT UNSIGNED NOT NULL')
	&& str_contains($sql, 'updated_at BIGINT UNSIGNED NOT NULL'))) === 1, 'Exposure table definition');

echo "Token format and authentication checks passed.\n";
