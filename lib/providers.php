<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/registry.php';

function abilities_registry() : AbilitiesRegistry {
	$registry = new AbilitiesRegistry();
	$registry->register('cacti.system.info', 'Read Cacti and abilities API versions.', [
		'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false
	], fn(int $user) => is_realm_allowed(15, $user), function () {
		global $config;
		return ['cactiVersion' => $config['cacti_version'], 'apiVersion' => '1', 'pluginVersion' => '0.1.0'];
	});
	$registry->register('cacti.devices.list', 'List permitted devices, up to 100 per page. Credentials are excluded.', [
		'type' => 'object',
		'properties' => [
			'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]
		],
		'additionalProperties' => false
	], fn(int $user) => is_realm_allowed(3, $user), function (int $user, stdClass $args) {
		$offset = $args->offset ?? 0;
		$limit = $args->limit ?? 50;
		$total = -1;
		$rows = get_allowed_devices('', 'h1.id', $offset . ',' . $limit, $total, $user);
		return ['devices' => array_map('abilities_device_fields', array_values($rows)),
			'nextOffset' => count($rows) === $limit ? $offset + $limit : null];
	});
	$registry->register('cacti.devices.get', 'Read one permitted device. Credentials are excluded.', [
		'type' => 'object',
		'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647]],
		'required' => ['id'], 'additionalProperties' => false
	], fn(int $user) => is_realm_allowed(3, $user), function (int $user, stdClass $args) {
		$total = -1;
		$rows = get_allowed_devices('', 'h1.id', '1', $total, $user, $args->id);
		if (!$rows) {
			throw new AbilitiesError(404, 'device_unavailable');
		}
		return ['device' => abilities_device_fields(reset($rows))];
	});
	// Only enabled plugins registered with this Cacti hook are loaded.
	api_plugin_hook_function('abilities_register', $registry);
	return $registry;
}

function abilities_device_fields(array $row) : array {
	return [
		'id' => (int) $row['id'],
		'description' => $row['description'],
		'hostname' => $row['hostname'],
		'disabled' => $row['disabled'] === 'on',
		'status' => (int) $row['status']
	];
}
