<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/storage.php';
require_once __DIR__ . '/../lib/admin.php';

/* A small in-memory stand-in for the three tables and the Cacti helpers the self-service code uses. */
$config       = ['auth_method' => 1, 'guest_user' => 3];
$users        = [2 => 'on', 3 => 'on', 4 => '', 5 => 'on', 6 => 'on'];
$realmHolders = [2, 3, 4, 6];
$tokens       = [];
$audit        = [];
$affected     = 0;
$optionReads  = [];
$failAudit    = false;

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
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

function __($text, ...$args) {
	return $text;
}

function read_config_option($name, $force = false) {
	global $config, $optionReads;
	$optionReads[] = $name;

	return $config[$name] ?? 0;
}

function is_realm_allowed($realm, $user = false) {
	global $realmHolders;

	return $realm === 109 && in_array($user, $realmHolders, true);
}

function db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true, $db_conn = false) {
	global $tokens;

	if (str_contains($sql, 'FROM plugin_realms')) {
		return 9;
	}

	if (str_starts_with($sql, 'SELECT id FROM plugin_mcp_token WHERE token_hash')) {
		foreach ($tokens as $row) {
			if ($row['token_hash'] === $params[0]) {
				return $row['id'];
			}
		}
	}

	return false;
}

function db_fetch_row_prepared($sql, $params = [], $log = true, $db_conn = false) {
	global $tokens, $users;

	if (str_contains($sql, 'FROM user_auth WHERE id = ?')) {
		return isset($users[$params[0]]) ? ['id' => (string) $params[0], 'enabled' => $users[$params[0]]] : false;
	}

	foreach ($tokens as $row) {
		if ($row['token_hash'] === $params[0] && $row['revoked'] === '0' && ($users[(int) $row['user_id']] ?? '') === 'on') {
			return $row;
		}
	}

	return false;
}

function db_fetch_assoc_prepared($sql, $params = []) {
	global $tokens;

	// SELECT id FROM plugin_mcp_token WHERE id IN (...) AND revoked = 0 [AND user_id = ?]
	check(str_starts_with($sql, 'SELECT id FROM plugin_mcp_token WHERE id IN ('), 'Unexpected select: ' . $sql);
	$owned = str_contains($sql, 'AND user_id = ?') ? (string) array_pop($params) : null;
	$found = [];

	foreach ($tokens as $row) {
		if (in_array((int) $row['id'], $params, true) && $row['revoked'] === '0' && ($owned === null || $row['user_id'] === $owned)) {
			$found[] = ['id' => $row['id']];
		}
	}

	return $found;
}

function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
	global $tokens, $audit, $affected;

	if (str_starts_with($sql, 'INSERT INTO plugin_mcp_token')) {
		$tokens[] = ['id' => (string) (count($tokens) + 1), 'user_id' => (string) $params[0], 'token_hash' => $params[1],
			'scopes' => $params[2], 'expires_at' => $params[3], 'created_at' => $params[4], 'created_by' => $params[5], 'last_used_at' => null, 'revoked' => '0'];

		return true;
	}

	if (str_starts_with($sql, 'INSERT INTO plugin_mcp_audit')) {
		if ($GLOBALS['failAudit']) {
			return false;
		}
		$audit[] = ['token_id' => $params[1], 'user_id' => $params[2], 'ability' => $params[3], 'outcome' => $params[4]];

		return true;
	}

	if (str_starts_with($sql, 'UPDATE plugin_mcp_token SET revoked = 1 WHERE id IN (')) {
		$owned    = str_contains($sql, 'AND user_id = ?') ? (string) array_pop($params) : null;
		$affected = 0;

		foreach ($tokens as &$row) {
			if (in_array((int) $row['id'], $params, true) && $row['revoked'] === '0' && ($owned === null || $row['user_id'] === $owned)) {
				$row['revoked'] = '1';
				$affected++;
			}
		}
		unset($row);

		return true;
	}

	return true;
}

function db_affected_rows($db_conn = false) {
	global $affected;

	return $affected;
}

// eligible_rules
check(mcp_token_eligible(['id' => '2', 'enabled' => 'on'], 3) === true, 'A normal user with the realm is eligible');
check(mcp_token_eligible(['id' => '4', 'enabled' => ''], 3) === false, 'A disabled user is not eligible');
check(mcp_token_eligible(['id' => '4', 'enabled' => 'off'], 3) === false, 'Only the value on counts as enabled');
check(mcp_token_eligible(['id' => '3', 'enabled' => 'on'], 3) === false, 'The guest account is not eligible');
check(mcp_token_eligible(['id' => '5', 'enabled' => 'on'], 3) === false, 'A user without the realm is not eligible');
check(mcp_token_eligible(['id' => '2', 'enabled' => 'on'], 0) === true, 'No guest account configured excludes nobody');
check(mcp_token_eligible(['id' => '0', 'enabled' => 'on'], 0) === false, 'An unknown user is not eligible');
check(mcp_token_eligible(['enabled' => 'on'], 0) === false, 'A row without an ID is not eligible');

// create_refuses_guest, disabled, no realm, unknown
foreach ([3, 4, 5, 99] as $ineligible) {
	throws(fn () => mcp_token_create($ineligible, 90), InvalidArgumentException::class);
}
check($tokens === [], 'A refused creation stores nothing');
$token = mcp_token_create(2, 90);
check(count($tokens) === 1 && $tokens[0]['user_id'] === '2', 'An eligible user gets a token');

// authenticate_refuses_guest_token: a token minted before the account became the guest stops working
check(mcp_authenticate('Bearer ' . $token)['user_id'] === 2, 'A normal token authenticates');
$tokens[] = ['id' => '2', 'user_id' => '3', 'token_hash' => hash('sha256', 'cmcp_pat_' . str_repeat('a', 64)),
	'scopes' => '["abilities:read"]', 'expires_at' => time() + 600, 'created_at' => time(), 'created_by' => 3, 'last_used_at' => null, 'revoked' => '0'];

try {
	mcp_authenticate('Bearer cmcp_pat_' . str_repeat('a', 64));

	throw new LogicException('A guest-owned token must be refused');
} catch (AbilitiesError $error) {
	check($error->reason === 'INVALID_TOKEN' && $error->status === 'UNAUTHENTICATED', 'Guest token gives the same answer as an unknown token');
}
$config['guest_user'] = 0;
check(mcp_authenticate('Bearer cmcp_pat_' . str_repeat('a', 64))['user_id'] === 3, 'With no guest configured the same token is fine');
$config['guest_user'] = 3;
$optionReads = [];
mcp_authenticate('Bearer ' . $token);
check(array_count_values($optionReads)['guest_user'] === 1, 'The guest account is read once, through the cached option');

// revoke_scoped_to_owner
$tokens = [];
mcp_token_create(2, 30);
mcp_token_create(6, 30);
$audit = [];
check(mcp_self_revoke(2, [2]) === 0, 'User 2 revoking user 6 token changes nothing');
check($tokens[1]['revoked'] === '0' && $audit === [], 'Another owner token stays active and nothing is audited');
check(mcp_token_revoke([2], 2) === 0 && $tokens[1]['revoked'] === '0', 'The storage call is scoped too');
check(mcp_self_revoke(2, [1, 2]) === 1, 'Only the owned token is revoked');
check($tokens[0]['revoked'] === '1' && $tokens[1]['revoked'] === '0', 'Owner token revoked, other untouched');
check($audit === [['token_id' => 1, 'user_id' => 2, 'ability' => 'mcp-token-revoke', 'outcome' => 'succeeded']], 'One audit row, for the revoked token, by its owner');
check(mcp_self_revoke(2, [1]) === 0 && count($audit) === 1, 'Revoking again changes and audits nothing');
check(mcp_token_revoke([2]) === 1 && $tokens[1]['revoked'] === '1', 'The admin path (no owner) revokes any token and returns the count');

// the shared audited create helper audits and never writes the value
$audit  = [];
$tokens = [];
$value  = mcp_token_create_audited(2, 90, 2);
check(mcp_token_hash($value) !== null && count($tokens) === 1 && $tokens[0]['created_by'] === 2, 'Self-service create makes the owner the creator');
check($audit === [['token_id' => 1, 'user_id' => 2, 'ability' => 'mcp-token-create', 'outcome' => 'succeeded']], 'Creation is audited');
$admin = mcp_token_create_audited(6, 30, 2);
check($tokens[1]['user_id'] === '6' && $tokens[1]['created_by'] === 2 && end($audit)['user_id'] === 2, 'An admin create is attributed to the actor');
$failAudit = true;
$before    = count($tokens);
throws(fn () => mcp_token_create_audited(2, 30, 2), RuntimeException::class);
check(count($tokens) === $before + 1 && end($tokens)['revoked'] === '1', 'A token whose audit write fails is revoked and not returned');
$failAudit = false;
throws(fn () => mcp_token_create_audited(3, 90, 3), InvalidArgumentException::class);
throws(fn () => mcp_token_create_audited(2, 366, 2), InvalidArgumentException::class);

// available_tools_follow_principal
$registry = new AbilitiesRegistry();
$registry->registerCategory('fixtures', 'Fixtures');
$definition = fn (callable $permission) => [
	'label' => 'Fixture', 'description' => 'A fixture', 'category' => 'fixtures',
	'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
	'output_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
	'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
	'permission' => $permission, 'execute' => fn (int $user, stdClass $args) => []
];
$registry->register('cacti-open-tool', ['label' => 'Open tool'] + $definition(fn (int $user) => true));
$registry->register('cacti-hidden-tool', $definition(fn (int $user) => true));
$registry->register('cacti-denied-tool', $definition(fn (int $user) => $user === 6));
$registry->setExposure(fn (string $id, string $namespace) => $id !== 'cacti-hidden-tool');
$tools = mcp_available_tools($registry, 2);
check($tools === [['id' => 'cacti-open-tool', 'label' => 'Open tool', 'description' => 'A fixture']], 'Hidden and unpermitted abilities are absent: ' . json_encode($tools));
check(array_column(mcp_available_tools($registry, 6), 'id') === ['cacti-open-tool', 'cacti-denied-tool'], 'The same registry answers per user');
check(mcp_user_principal(2, 0) === ['token_id' => 0, 'token_kind' => 'pat', 'user_id' => 2, 'scopes' => ['abilities:read']], 'The page principal has the token principal shape');

echo "Self-service token checks passed.\n";
