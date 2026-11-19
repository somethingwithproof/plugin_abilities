<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/providers.php';

$config = ['cacti_version' => '1.2.31'];
$realms = [7 => [3, 15], 8 => []];
$calls  = [];
$rows   = [
	['id' => '1', 'description' => 'First', 'hostname' => 'first.example', 'disabled' => '', 'status' => '3', 'snmp_community' => 'private-fixture'],
	['id' => '2', 'description' => 'Second', 'hostname' => 'second.example', 'disabled' => 'on', 'status' => '1', 'snmp_community' => 'private-fixture']
];

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function is_realm_allowed($realm, $check_user = false) {
	global $realms;

	return in_array($realm, $realms[$check_user] ?? [], true);
}

function get_allowed_devices($sql_where = '', $sql_order = 'description', $sql_limit = '', &$total_rows = 0, $user_id = 0, $device_id = 0) {
	global $rows, $calls;
	check($user_id === 7, 'Device helper must receive the token owner explicitly');
	check($sql_where === '' && $sql_order === 'h1.id', 'Stable native device query');
	$calls[]    = [$sql_limit, $user_id, $device_id];
	$total_rows = count($rows);

	if ($device_id) {
		return array_values(array_filter($rows, fn ($row) => (int) $row['id'] === $device_id));
	}
	[$offset, $limit] = array_map('intval', explode(',', $sql_limit));

	return array_slice($rows, $offset, $limit);
}

function api_plugin_hook_function($name, $parm = null) {
	check($name === 'abilities_register', 'Native extension hook');
	$parm->register('fixture.version', 'Installed provider', [
		'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false
	], fn ($user) => $user === 7, fn () => ['version' => 'fixture']);

	return $parm;
}

$registry  = mcp_registry();
$principal = ['user_id' => 7, 'scopes' => ['cacti.system.info', 'cacti.devices.list', 'cacti.devices.get', 'fixture.version']];
$audit     = function ($id, $name, $outcome) {};
check(count($registry->discover($principal)) === 4, 'Built-in and hook providers must be discoverable');
check($registry->discover(['user_id' => 8, 'scopes' => $principal['scopes']]) === [], 'Provider realm isolation');
$result = $registry->execute($principal, 'cacti.system.info', new stdClass(), $audit);
check($result['result']->pluginVersion === plugin_mcp_version()['version'], 'Reported version must come from INFO');
$result = $registry->execute($principal, 'cacti.devices.list', (object) ['limit' => 1], $audit);
check($result['result']->nextOffset === 1 && $result['result']->devices[0]['id'] === 1, 'Native pagination');
check(array_keys($result['result']->devices[0]) === ['id', 'description', 'hostname', 'disabled', 'status'], 'Provider must return only allowed fields');
$result = $registry->execute($principal, 'cacti.devices.list', (object) ['limit' => 2, 'offset' => 1], $audit);
check($result['result']->nextOffset === null && $result['result']->devices[0]['disabled'] === true, 'Final page and disabled state');
$result = $registry->execute($principal, 'cacti.devices.get', (object) ['id' => 2], $audit);
check($result['result']->device['id'] === 2 && end($calls) === ['1', 7, 2], 'Native single-device lookup');

try {
	$registry->execute($principal, 'cacti.devices.get', (object) ['id' => 99], $audit);

	throw new LogicException('An unavailable device must be rejected');
} catch (AbilitiesError $error) {
	check($error->reason === 'DEVICE_NOT_FOUND', 'Unavailable device error');
}
$result = $registry->execute($principal, 'fixture.version', new stdClass(), $audit);
check($result['result']->version === 'fixture', 'Installed hook provider execution');
print "Native provider permissions, pagination, metadata, and extension checks passed.\n";
