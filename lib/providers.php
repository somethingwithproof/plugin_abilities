<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/../setup.php';

function mcp_registry() : AbilitiesRegistry {
	$registry    = new AbilitiesRegistry();
	$annotations = ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
	$empty       = ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false];
	$device      = [
		'type'       => 'object',
		'properties' => [
			'id'          => ['type' => 'integer'],
			'description' => ['type' => 'string'],
			'hostname'    => ['type' => 'string'],
			'disabled'    => ['type' => 'boolean'],
			'status'      => ['type' => 'integer']
		],
		'required'             => ['id', 'description', 'hostname', 'disabled', 'status'],
		'additionalProperties' => false
	];

	$registry->registerCategory('inventory', 'Inventory');
	$registry->registerCategory('system', 'System');
	$registry->register('cacti-get-system-info', [
		'label'         => 'Get system information',
		'description'   => 'Read Cacti and abilities API versions.',
		'category'      => 'system',
		'input_schema'  => $empty,
		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'cactiVersion'  => ['type' => 'string'],
				'apiVersion'    => ['type' => 'string'],
				'pluginVersion' => ['type' => 'string']
			],
			'required'             => ['cactiVersion', 'apiVersion', 'pluginVersion'],
			'additionalProperties' => false
		],
		'annotations' => $annotations,
		'permission'  => fn (int $user) => is_realm_allowed(15, $user),
		'execute'     => function () {
			global $config;
			$info = plugin_mcp_version();

			return [
				'cactiVersion'  => $config['cacti_version'],
				'apiVersion'    => '1',
				'pluginVersion' => $info['version']
			];
		}
	]);
	$registry->register('cacti-list-devices', [
		'label'        => 'List devices',
		'description'  => 'List permitted devices, up to 100 per page. Credentials are excluded.',
		'category'     => 'inventory',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
				'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]
			],
			'additionalProperties' => false
		],
		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'devices'    => ['type' => 'array', 'items' => $device, 'maxItems' => 100],
				'nextOffset' => ['type' => ['integer', 'null']]
			],
			'required'             => ['devices', 'nextOffset'],
			'additionalProperties' => false
		],
		'annotations' => $annotations,
		'permission'  => fn (int $user) => is_realm_allowed(3, $user),
		'execute'     => function (int $user, stdClass $args) {
			$offset = $args->offset ?? 0;
			$limit  = $args->limit ?? 50;
			$total  = -1;
			$rows   = get_allowed_devices('', 'h1.id', $offset . ',' . $limit, $total, $user);

			return [
				'devices'    => array_map('mcp_device_fields', array_values($rows)),
				'nextOffset' => count($rows) === $limit ? $offset + $limit : null
			];
		}
	]);
	$registry->register('cacti-get-device', [
		'label'        => 'Get device',
		'description'  => 'Read one permitted device. Credentials are excluded.',
		'category'     => 'inventory',
		'input_schema' => [
			'type'                 => 'object',
			'properties'           => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647]],
			'required'             => ['id'],
			'additionalProperties' => false
		],
		'output_schema' => [
			'type'                 => 'object',
			'properties'           => ['device' => $device],
			'required'             => ['device'],
			'additionalProperties' => false
		],
		'annotations' => $annotations,
		'permission'  => fn (int $user) => is_realm_allowed(3, $user),
		'execute'     => function (int $user, stdClass $args) {
			$total = -1;
			$rows  = get_allowed_devices('', 'h1.id', '1', $total, $user, $args->id);

			if (!$rows) {
				throw new AbilitiesError('NOT_FOUND', 'DEVICE_NOT_FOUND');
			}

			return ['device' => mcp_device_fields(reset($rows))];
		}
	]);
	// Only enabled plugins registered with this Cacti hook are loaded.
	api_plugin_hook_function('abilities_register', $registry);

	return $registry;
}

function mcp_device_fields(array $row) : array {
	return [
		'id'          => (int) $row['id'],
		'description' => $row['description'],
		'hostname'    => $row['hostname'],
		'disabled'    => $row['disabled'] === 'on',
		'status'      => (int) $row['status']
	];
}
