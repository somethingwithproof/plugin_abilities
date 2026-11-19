<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

/* Pure helpers behind the console page, kept apart from Cacti's page functions so they can be tested. */

require_once __DIR__ . '/storage.php';

/** Realm IDs the built-in abilities check; keep in step with lib/providers.php. Plugin abilities declare their own. */
const MCP_ADMIN_BUILTIN_REALMS = ['cacti-get-system-info' => 15, 'cacti-list-devices' => 3, 'cacti-get-device' => 3];

/** Display name of the realm an ability needs, from Cacti's realm names; blank when the registry cannot say. */
function mcp_admin_realm_name(array $ability, array $realmNames) : string {
	$realm = MCP_ADMIN_BUILTIN_REALMS[$ability['id']] ?? null;

	return $realm === null ? '' : (string) ($realmNames[$realm] ?? '');
}

/**
 * Rows for the abilities table.
 *
 * @param list<array> $all AbilitiesRegistry::all()
 * @param array<string, bool> $exposureMap mcp_exposure_map()
 * @param callable(array): string $realmName display name of the realm an ability needs
 * @return list<array{id: string, label: string, category: string, namespace: string, realm: string, annotations: array, exposed: bool}>
 */
function mcp_admin_ability_rows(array $all, array $exposureMap, callable $realmName) : array {
	$rows = [];

	foreach ($all as $ability) {
		$rows[] = [
			'id'          => $ability['id'],
			'label'       => $ability['label'],
			'category'    => $ability['category'],
			'namespace'   => $ability['namespace'],
			'realm'       => $realmName($ability),
			'annotations' => $ability['annotations'],
			'exposed'     => mcp_is_exposed($ability['id'], $ability['namespace'], $exposureMap)
		];
	}

	return $rows;
}

/**
 * Read a bulk action from a POST. Cacti's actions dropdown posts drp_action; the
 * rows are checkboxes named chk_<id>. Every ID is validated for the action.
 *
 * @return array{action: string, ids: list<string>|list<int>}
 * @throws InvalidArgumentException for an unknown action
 */
function mcp_admin_parse_action(array $post) : array {
	$action = $post['drp_action'] ?? $post['action'] ?? '';

	if (!is_string($action) || !in_array($action, ['expose', 'hide', 'revoke'], true)) {
		throw new InvalidArgumentException('Unknown action');
	}
	$ids = [];

	foreach (array_keys($post) as $key) {
		$key = (string) $key;

		if (!str_starts_with($key, 'chk_')) {
			continue;
		}
		$id = substr($key, 4);

		if ($action === 'revoke') {
			// Digits only, no leading zero, short enough to be a safe integer.
			if (preg_match('/^[1-9][0-9]{0,14}$/D', $id) === 1) {
				$ids[] = (int) $id;
			}
		} elseif (AbilitiesRegistry::isValidId($id)) {
			$ids[] = $id;
		}
	}

	return ['action' => $action, 'ids' => array_values(array_unique($ids))];
}

/**
 * Whether an admin may create a token for another user. A token lets its holder read Cacti
 * as that user, so creating one for someone else needs the User Management realm too.
 */
function mcp_admin_may_create_for(int $actor, int $target, bool $hasUserAdmin) : bool {
	return $actor > 0 && $target > 0 && ($target === $actor || $hasUserAdmin);
}

/** Issue a one-time form nonce into $session (Cacti's session array). It is not the token. */
function mcp_admin_nonce_issue(array &$session) : string {
	return $session['mcp_create_nonce'] = bin2hex(random_bytes(16));
}

/** Consume the nonce: true once for the issued value, false for a reused, missing, or wrong one. */
function mcp_admin_nonce_consume(array &$session, string $given) : bool {
	$issued = $session['mcp_create_nonce'] ?? null;
	unset($session['mcp_create_nonce']);

	return is_string($issued) && $given !== '' && hash_equals($issued, $given);
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

/** Shown by the console and the self-service page when a token is refused for its owner. */
function mcp_token_ineligible_text() : string {
	return __('Tokens cannot be created for the guest account or disabled users.', 'mcp');
}

/**
 * Create a token for the signed-in user from the self-service page, audited as that user.
 * The value is returned once. An unaudited token is revoked and not handed out.
 *
 * @throws InvalidArgumentException for an ineligible user or a bad lifetime
 * @throws RuntimeException when the token or its audit row cannot be stored
 */
function mcp_self_create(int $userId, int $days) : string {
	$token = mcp_token_create($userId, $days, $userId);
	$id    = mcp_token_id($token);

	try {
		mcp_audit_admin($userId, $id, 'token-create');
	} catch (RuntimeException $error) {
		mcp_token_revoke([$id], $userId);

		throw $error;
	}

	return $token;
}

/**
 * Revoke some of a user's own tokens and audit each one actually revoked. Tokens owned by anyone
 * else, and tokens already revoked, are skipped without a trace.
 *
 * @param list<int> $ids
 * @return int tokens revoked
 */
function mcp_self_revoke(int $userId, array $ids) : int {
	$ids = mcp_token_revocable($ids, $userId);

	if ($ids === []) {
		return 0;
	}
	$changed = mcp_token_revoke($ids, $userId);

	foreach ($ids as $id) {
		mcp_audit_admin($userId, $id, 'token-revoke');
	}

	return $changed;
}

/**
 * What a token of this user could list: the registry's discovery for the same principal
 * mcp_authenticate builds, so exposure and realm checks match.
 *
 * @return list<array{id: string, label: string, description: string}>
 */
function mcp_available_tools(AbilitiesRegistry $registry, int $userId) : array {
	return array_map(fn (array $ability) => [
		'id'          => $ability['id'],
		'label'       => $ability['label'],
		'description' => $ability['description']
	], $registry->discover(mcp_user_principal($userId, 0)));
}
