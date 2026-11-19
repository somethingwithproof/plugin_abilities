<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/registry.php';

/** Files the MCP and Abilities API realm covers; one realm row holds the whole list. */
const MCP_REALM_FILES = 'api.php,mcp.php,mcp_tokens.php';

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
			revoked TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by INT UNSIGNED NOT NULL,
			last_used_at BIGINT UNSIGNED NULL
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
	$id = db_fetch_cell_prepared('SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?', ['mcp', MCP_REALM_FILES]);

	return $id !== false && (int) $id > 0 && is_realm_allowed((int) $id + 100, $userId);
}

function mcp_token_hash(string $token) : ?string {
	return preg_match('/^cmcp_pat_[a-f0-9]{64}$/D', $token) === 1 ? hash('sha256', $token) : null;
}

/**
 * The guest account's user ID, or 0 when none is configured. The setting holds a username or an ID
 * (get_guest_account() matches both, and no 1.2.x upgrade step converts old values), so it is resolved
 * the same way. get_guest_account() reads the cached option, so $fresh is for session-bearing console
 * paths, where a stale cache would still treat an account made the guest after sign-in as ordinary.
 */
function mcp_guest_user_id(bool $fresh = false) : int {
	$setting = read_config_option('guest_user', $fresh);

	if ($setting === '' || $setting === null || $setting === false || $setting === 0 || $setting === '0') {
		return 0;
	}
	$id = db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ? OR id = ?', [$setting, $setting]);

	return empty($id) ? 0 : (int) $id;
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

	// A guest account never holds a personal token; one minted before the account became the guest stops working.
	$guestId = mcp_guest_user_id();

	if ($guestId > 0 && $userId === $guestId) {
		throw new AbilitiesError('UNAUTHENTICATED', 'INVALID_TOKEN');
	}

	if (!mcp_has_realm($userId)) {
		throw new AbilitiesError('PERMISSION_DENIED', 'REALM_REQUIRED');
	}

	// Personal tokens carry exactly one scope; anything else is a corrupt or foreign row.
	if (json_decode($row['scopes'], true, 16) !== ['abilities:read']) {
		throw new AbilitiesError('UNAUTHENTICATED', 'INVALID_TOKEN');
	}

	mcp_touch_token((int) $row['id']);

	return mcp_user_principal($userId, (int) $row['id']);
}

/** The principal a personal token of this user carries. The self-service page passes token 0 to preview it. */
function mcp_user_principal(int $userId, int $tokenId) : array {
	return ['token_id' => $tokenId, 'token_kind' => 'pat', 'user_id' => $userId, 'scopes' => ['abilities:read']];
}

/** Record use of a token at most once a minute. Bookkeeping only: a failure never fails authentication. */
function mcp_touch_token(int $id) : void {
	$now = time();

	try {
		db_execute_prepared('UPDATE plugin_mcp_token SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
			[$now, $id, $now - 60]);
	} catch (Throwable $error) {
		// The token was already verified; a missing timestamp is not a reason to refuse it.
	}
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
		mcp_log_failure($name, 'AUDIT_UNAVAILABLE');

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
	$ids = array_values(array_unique($ids));

	if ($ids === []) {
		return;
	}
	$now    = time();
	$params = [];

	foreach ($ids as $id) {
		array_push($params, $id, $exposed ? 1 : 0, $now);
	}

	// One statement, so a bulk change is applied whole or not at all.
	try {
		if (!db_execute_prepared('INSERT INTO plugin_mcp_ability (id, exposed, updated_at) VALUES '
			. implode(', ', array_fill(0, count($ids), '(?, ?, ?)'))
			. ' ON DUPLICATE KEY UPDATE exposed = VALUES(exposed), updated_at = VALUES(updated_at)', $params)) {
			throw new RuntimeException('MCP exposure update failed');
		}
	} finally {
		mcp_exposure_map(true);
	}
}

const MCP_MAX_PAGE = 1000000;
const MCP_AUDIT_OUTCOMES = ['started', 'succeeded', 'failed', 'denied', 'rejected'];

/**
 * One page of personal tokens, newest first. Never returns the hash.
 *
 * @param array{user_id?: int} $filter
 * @return array{rows: array, total: int}
 */
function mcp_token_list(array $filter, int $page, int $rows) : array {
	$where  = '';
	$params = [];

	if (isset($filter['user_id']) && (int) $filter['user_id'] > 0) {
		$where    = ' WHERE t.user_id = ?';
		$params[] = (int) $filter['user_id'];
	}

	return mcp_admin_page('SELECT COUNT(*) FROM plugin_mcp_token AS t' . $where,
		'SELECT t.id, u.username, c.username AS created_by_name, t.created_by, t.created_at, t.expires_at, t.last_used_at, t.revoked
		FROM plugin_mcp_token AS t LEFT JOIN user_auth AS u ON u.id = t.user_id
		LEFT JOIN user_auth AS c ON c.id = t.created_by' . $where . ' ORDER BY t.id DESC',
		$params, $page, $rows);
}

/**
 * Create a personal token for a user. The value is returned once; only its hash is stored.
 *
 * @throws InvalidArgumentException for a bad user ID or lifetime
 * @throws RuntimeException when the row cannot be stored
 */
function mcp_token_create(int $userId, int $days, ?int $createdBy = null) : string {
	$createdBy ??= $userId;

	if ($userId < 1 || $createdBy < 1 || $days < 1 || $days > 365) {
		throw new InvalidArgumentException('Invalid token owner or lifetime');
	}
	mcp_token_assert_eligible($userId);
	$token = 'cmcp_pat_' . bin2hex(random_bytes(32));
	$now   = time();

	if (!db_execute_prepared('INSERT INTO plugin_mcp_token (user_id, token_hash, scopes, expires_at, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
		[$userId, mcp_token_hash($token), '["abilities:read"]', $now + $days * 86400, $now, $createdBy])) {
		throw new RuntimeException('MCP token creation failed');
	}

	return $token;
}

/**
 * Refuse an owner who could not use a token: unknown, disabled, the guest account, or without the realm.
 *
 * @throws InvalidArgumentException when the user is not eligible
 */
function mcp_token_assert_eligible(int $userId) : void {
	$user = db_fetch_row_prepared('SELECT id, enabled FROM user_auth WHERE id = ?', [$userId]);

	// Forced read: a console session caches options, so an account made the guest after sign-in
	// would otherwise still pass. The endpoints have no session and keep the cached read.
	if (!is_array($user) || !mcp_token_eligible($user, mcp_guest_user_id(true))) {
		throw new InvalidArgumentException('User is not eligible for a token');
	}
}

/**
 * Whether a user may hold a personal token: enabled, not the guest account, and holding the
 * MCP and Abilities API realm. A guest token would let anyone who knows the shared account read Cacti.
 *
 * @param array{id?: mixed, enabled?: mixed} $user a user_auth row
 */
function mcp_token_eligible(array $user, int $guestUserId) : bool {
	$id = (int) ($user['id'] ?? 0);

	return $id > 0
		&& ($user['enabled'] ?? '') === 'on'
		&& !($guestUserId > 0 && $id === $guestUserId)
		&& mcp_has_realm($id);
}

/**
 * Create a token and audit its creation by $actor as one unit: a token whose audit row cannot be
 * written is revoked and not handed out. The value is returned once.
 *
 * @throws InvalidArgumentException for an ineligible owner or a bad lifetime
 * @throws RuntimeException when the token or its audit row cannot be stored
 */
function mcp_token_create_audited(int $userId, int $days, int $actor) : string {
	$token = mcp_token_create($userId, $days, $actor);
	$id    = mcp_token_id($token);

	try {
		if ($id < 1) {
			throw new RuntimeException('MCP token lookup failed');
		}
		mcp_audit_admin($actor, $id, 'token-create');
	} catch (RuntimeException $error) {
		// By value, not ID: the lookup that failed is the reason an ID may be missing.
		db_execute_prepared('UPDATE plugin_mcp_token SET revoked = 1 WHERE token_hash = ?', [mcp_token_hash($token)]);

		throw $error;
	}

	return $token;
}

/** ID of the token with this value, for the audit row written when an admin creates it. */
function mcp_token_id(string $token) : int {
	$hash = mcp_token_hash($token);

	return $hash === null ? 0 : (int) db_fetch_cell_prepared('SELECT id FROM plugin_mcp_token WHERE token_hash = ?', [$hash]);
}

/**
 * Audit a console token action: one complete row, attributed to the acting admin. The token
 * value never appears. $action is token-create or token-revoke, recorded as the ability name.
 *
 * @throws RuntimeException when the row cannot be written
 */
function mcp_audit_admin(int $actor, int $tokenId, string $action) : void {
	if (!in_array($action, ['token-create', 'token-revoke'], true)) {
		throw new InvalidArgumentException('Invalid admin action');
	}
	$now = time();

	if (!db_execute_prepared('INSERT INTO plugin_mcp_audit
		(request_id, token_id, user_id, ability, outcome, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
		[bin2hex(random_bytes(16)), $tokenId, $actor, 'mcp-' . $action, 'succeeded', $now, $now])) {
		throw new RuntimeException('MCP audit write failed');
	}
}

/**
 * Revoke tokens. With $ownerId only that user's tokens change, so a forged ID cannot touch anyone else's.
 *
 * @param list<int> $ids
 * @return int tokens changed; already revoked tokens do not count
 * @throws InvalidArgumentException for a malformed ID
 * @throws RuntimeException when the update fails
 */
function mcp_token_revoke(array $ids, ?int $ownerId = null) : int {
	mcp_token_check_ids($ids);

	if ($ids === []) {
		return 0;
	}
	$params = array_values($ids);
	$owner  = '';

	if ($ownerId !== null) {
		$owner    = ' AND user_id = ?';
		$params[] = $ownerId;
	}

	if (!db_execute_prepared('UPDATE plugin_mcp_token SET revoked = 1 WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')' . $owner, $params)) {
		throw new RuntimeException('MCP token revocation failed');
	}

	return (int) db_affected_rows();
}

/** The tokens were revoked but at least one audit row could not be written. */
class McpRevokeAuditError extends RuntimeException {
	public function __construct(public int $revoked) {
		parent::__construct('MCP audit write failed after revocation');
	}
}

/**
 * Revoke the active tokens among $ids and audit each one actually revoked, attributed to $actor.
 * Unknown and already revoked IDs are skipped without a trace. With $ownerId only that user's tokens change.
 *
 * @param list<int> $ids
 * @return int tokens revoked
 * @throws McpRevokeAuditError when the tokens were revoked but an audit row could not be written
 * @throws InvalidArgumentException for a malformed ID
 * @throws RuntimeException when the lookup or update fails
 */
function mcp_token_revoke_audited(int $actor, array $ids, ?int $ownerId = null) : int {
	$ids = mcp_token_revocable($ids, $ownerId);

	if ($ids === []) {
		return 0;
	}
	$changed = mcp_token_revoke($ids, $ownerId);

	try {
		foreach ($ids as $id) {
			mcp_audit_admin($actor, $id, 'token-revoke');
		}
	} catch (RuntimeException $error) {
		throw new McpRevokeAuditError($changed);
	}

	return $changed;
}

/** @param list<int> $ids */
function mcp_token_check_ids(array $ids) : void {
	foreach ($ids as $id) {
		if (!is_int($id) || $id < 1) {
			throw new InvalidArgumentException('Invalid token ID');
		}
	}
}

/**
 * The given tokens that are active and, when $ownerId is set, owned by that user.
 *
 * @param list<int> $ids
 * @return list<int>
 */
function mcp_token_revocable(array $ids, ?int $ownerId = null) : array {
	mcp_token_check_ids($ids);

	if ($ids === []) {
		return [];
	}
	$params = array_values($ids);
	$owner  = '';

	if ($ownerId !== null) {
		$owner    = ' AND user_id = ?';
		$params[] = $ownerId;
	}
	$found = db_fetch_assoc_prepared('SELECT id FROM plugin_mcp_token WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') AND revoked = 0' . $owner, $params);

	if ($found === false) {
		throw new RuntimeException('MCP token lookup failed');
	}

	return array_map('intval', array_column($found, 'id'));
}

/**
 * One page of audit rows, newest first.
 *
 * @param array{user_id?: int, ability?: string, outcome?: string} $filter empty or -1 values mean no condition
 * @return array{rows: array, total: int}
 */
function mcp_audit_list(array $filter, int $page, int $rows) : array {
	$conditions = [];
	$params     = [];
	$userId     = $filter['user_id'] ?? -1;

	if ($userId !== -1) {
		if (!is_int($userId) || $userId < 1) {
			throw new InvalidArgumentException('Invalid user filter');
		}
		$conditions[] = 'a.user_id = ?';
		$params[]     = $userId;
	}
	$ability = $filter['ability'] ?? '';

	if ($ability !== '') {
		if (!is_string($ability) || !AbilitiesRegistry::isValidId($ability)) {
			throw new InvalidArgumentException('Invalid ability filter');
		}
		$conditions[] = 'a.ability = ?';
		$params[]     = $ability;
	}
	$outcome = $filter['outcome'] ?? '';

	if ($outcome !== '') {
		if (!in_array($outcome, MCP_AUDIT_OUTCOMES, true)) {
			throw new InvalidArgumentException('Invalid outcome filter');
		}
		$conditions[] = 'a.outcome = ?';
		$params[]     = $outcome;
	}
	$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

	return mcp_admin_page('SELECT COUNT(*) FROM plugin_mcp_audit AS a' . $where,
		'SELECT a.request_id, a.token_id, a.user_id, u.username, a.ability, a.outcome, a.started_at, a.finished_at
		FROM plugin_mcp_audit AS a LEFT JOIN user_auth AS u ON u.id = a.user_id' . $where . ' ORDER BY a.started_at DESC, a.request_id',
		$params, $page, $rows);
}

/**
 * Count and fetch one page. LIMIT and OFFSET are cast to integers and written into the
 * statement, as Cacti core does, because a bound LIMIT value reaches MySQL as a string.
 *
 * @return array{rows: array, total: int}
 * @throws RuntimeException when the database read fails
 */
function mcp_admin_page(string $countSql, string $listSql, array $params, int $page, int $rows) : array {
	// The cap keeps (page - 1) * rows far inside the integer range.
	if ($page < 1 || $page > MCP_MAX_PAGE || $rows < 1 || $rows > 500) {
		throw new InvalidArgumentException('Invalid page');
	}
	$total = db_fetch_cell_prepared($countSql, $params);
	$found = db_fetch_assoc_prepared($listSql . ' LIMIT ' . $rows . ' OFFSET ' . (($page - 1) * $rows), $params);

	if ($total === false || $found === false) {
		throw new RuntimeException('MCP list query failed');
	}

	return ['rows' => $found, 'total' => (int) $total];
}
