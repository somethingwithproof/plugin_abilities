<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/aip.php';
require_once __DIR__ . '/../setup.php';

/* Ported from the core REST API resources.php devices definition (HOST_* constants). */
const MCP_DEVICE_STATES = [0 => 'UNKNOWN', 1 => 'DOWN', 2 => 'RECOVERING', 3 => 'UP', 4 => 'ERROR'];

/* Filter and order fields for devices, in the mcp_filter_sql() format. Columns
 * are written for the outer h1 alias; get_allowed_devices() applies the WHERE
 * fragment inside a subquery that aliases host as h, so filters swap the alias
 * (see mcp_device_filter_fields()). */
const MCP_DEVICE_FIELDS = [
	'displayName'    => ['column' => 'h1.description', 'type' => 'string', 'filter' => true, 'order' => true],
	'hostname'       => ['column' => 'h1.hostname', 'type' => 'string', 'filter' => true, 'order' => true],
	'disabled'       => ['column' => 'h1.disabled', 'type' => 'bool', 'filter' => true],
	'site'           => ['column' => 'h1.site_id', 'type' => 'reference', 'collection' => 'sites', 'filter' => true],
	'poller'         => ['column' => 'h1.poller_id', 'type' => 'reference', 'collection' => 'pollers', 'filter' => true],
	'deviceTemplate' => ['column' => 'h1.host_template_id', 'type' => 'reference', 'collection' => 'deviceTemplates', 'filter' => true],
	'externalId'     => ['column' => 'h1.external_id', 'type' => 'string', 'filter' => true],
	'state'          => ['column' => 'h1.status', 'type' => 'enum', 'values' => MCP_DEVICE_STATES, 'filter' => true],
	'name'           => ['column' => 'h1.id', 'type' => 'int', 'order' => true],
];

function mcp_registry() : AbilitiesRegistry {
	$registry = new AbilitiesRegistry();
	$registry->setExposure(fn (string $id, string $namespace) => mcp_is_exposed($id, $namespace, mcp_exposure_map()));

	$registry->registerCategory('inventory', 'Inventory');
	$registry->registerCategory('system', 'System');
	mcp_register_builtins($registry);
	mcp_dispatch_ability_hooks($registry);

	return $registry;
}

function mcp_register_builtins(AbilitiesRegistry $registry) : void {
	$annotations = ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
	$nullable    = fn (string $type) => ['type' => [$type, 'null']];
	$device      = [
		'type'       => 'object',
		'properties' => [
			'name'               => ['type' => 'string'],
			'displayName'        => ['type' => 'string'],
			'hostname'           => ['type' => 'string'],
			'deviceTemplate'     => $nullable('string'),
			'site'               => $nullable('string'),
			'poller'             => $nullable('string'),
			'externalId'         => $nullable('string'),
			'location'           => $nullable('string'),
			'notes'              => $nullable('string'),
			'disabled'           => ['type' => 'boolean'],
			'snmpVersion'        => $nullable('integer'),
			'snmpPort'           => $nullable('integer'),
			'snmpTimeout'        => $nullable('integer'),
			'snmpRetries'        => $nullable('integer'),
			'maxOids'            => $nullable('integer'),
			'bulkWalkSize'       => $nullable('integer'),
			'deviceThreads'      => $nullable('integer'),
			'availabilityMethod' => $nullable('integer'),
			'pingMethod'         => $nullable('integer'),
			'pingPort'           => $nullable('integer'),
			'pingTimeout'        => $nullable('integer'),
			'pingRetries'        => $nullable('integer'),
			'state'              => $nullable('string'),
			'availability'       => $nullable('number'),
			'lastError'          => $nullable('string'),
			'failTime'           => $nullable('string'),
			'recoveryTime'       => $nullable('string'),
			'createTime'         => $nullable('string'),
		],
		'additionalProperties' => false
	];
	$device['required'] = array_keys($device['properties']);

	$registry->register('cacti-get-system-info', [
		'label'         => 'Get system information',
		'description'   => 'Read the Cacti, plugin, and abilities API versions.',
		'category'      => 'system',
		'input_schema'  => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'cactiVersion'        => ['type' => 'string'],
				'pluginVersion'       => ['type' => 'string'],
				'abilitiesApiVersion' => ['type' => 'string']
			],
			'required'             => ['cactiVersion', 'pluginVersion', 'abilitiesApiVersion'],
			'additionalProperties' => false
		],
		'annotations' => $annotations,
		'permission'  => fn (int $user) => is_realm_allowed(15, $user),
		'execute'     => function () {
			global $config;
			$info = plugin_mcp_version();

			return [
				'cactiVersion'        => $config['cacti_version'],
				'pluginVersion'       => $info['version'],
				'abilitiesApiVersion' => '2'
			];
		}
	]);
	$registry->register('cacti-list-devices', [
		'label'        => 'List devices',
		'description'  => 'List permitted devices, up to 100 per page, with AIP-160 filter and AIP-132 orderBy. Credentials are excluded.',
		'category'     => 'inventory',
		'input_schema' => [
			'type'       => 'object',
			'properties' => [
				'pageSize'  => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
				'pageToken' => ['type' => 'string', 'maxLength' => 512],
				'filter'    => ['type' => 'string', 'maxLength' => 1024],
				'orderBy'   => ['type' => 'string', 'maxLength' => 128]
			],
			'additionalProperties' => false
		],
		'output_schema' => [
			'type'       => 'object',
			'properties' => [
				'devices'       => ['type' => 'array', 'items' => $device, 'maxItems' => MCP_PAGE_SIZE_MAX],
				'nextPageToken' => ['type' => ['string', 'null']]
			],
			'required'             => ['devices', 'nextPageToken'],
			'additionalProperties' => false
		],
		'annotations' => $annotations,
		'permission'  => fn (int $user) => is_realm_allowed(3, $user),
		'execute'     => function (int $user, stdClass $args) {
			$filter      = $args->filter ?? '';
			$orderBy     = $args->orderBy ?? '';
			$fingerprint = sha1($filter . "\0" . $orderBy);
			$page        = mcp_page($args, $fingerprint);
			$where       = mcp_filter_sql($filter, mcp_device_filter_fields());
			// The id tie-breaker keeps offset paging stable when the requested order has ties.
			$order = $orderBy === '' ? 'h1.id ASC' : mcp_order_sql($orderBy, MCP_DEVICE_FIELDS, '') . ', h1.id ASC';
			$total = -1;
			// One extra row tells whether another page exists.
			$rows = array_values(get_allowed_devices($where, $order, $page['offset'] . ',' . ($page['size'] + 1), $total, $user));
			$more = count($rows) > $page['size'];

			return [
				'devices'       => array_map('mcp_device_resource', array_slice($rows, 0, $page['size'])),
				'nextPageToken' => $more ? mcp_page_token($page['offset'] + $page['size'], $fingerprint) : null
			];
		}
	]);
	$registry->register('cacti-get-device', [
		'label'        => 'Get device',
		'description'  => 'Read one permitted device by resource name, for example devices/12. Credentials are excluded.',
		'category'     => 'inventory',
		'input_schema' => [
			'type'                 => 'object',
			'properties'           => ['name' => ['type' => 'string', 'pattern' => '^devices/[1-9][0-9]{0,9}$', 'maxLength' => 32]],
			'required'             => ['name'],
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
			$rows  = get_allowed_devices('', 'h1.id ASC', '1', $total, $user, (int) substr($args->name, strlen('devices/')));

			if (!$rows) {
				throw new AbilitiesError('NOT_FOUND', 'DEVICE_NOT_FOUND');
			}

			return ['device' => mcp_device_resource(reset($rows))];
		}
	]);
}

/**
 * Let enabled plugins register abilities through the abilities_register hook.
 *
 * Each plugin registers into a scratch copy that is adopted only when the hook
 * function returns, so a failing plugin leaves nothing behind.
 */
function mcp_dispatch_ability_hooks(AbilitiesRegistry $registry) : void {
	global $plugins_integrated;

	$hooks = db_fetch_assoc_prepared('SELECT name, file, function FROM plugin_hooks WHERE hook = ? AND status = 1',
		['abilities_register']);

	foreach ($hooks ?: [] as $hook) {
		$name = (string) $hook['name'];

		if (in_array($name, $plugins_integrated ?? [], true) || !api_plugin_is_enabled($name)) {
			continue;
		}

		// The cacti namespace belongs to the built-ins, which are exposed by default.
		if ($name === 'cacti') {
			cacti_log('MCP: ability registration skipped for plugin cacti: reserved namespace', false, 'MCP');

			continue;
		}

		if (str_contains($hook['file'], '..') || str_contains($name, '..')) {
			cacti_log('MCP: ability registration skipped for plugin ' . $name . ': invalid hook file', false, 'MCP');

			continue;
		}
		$scratch = clone $registry;

		try {
			$file = mcp_plugin_file($name, $hook['file']);

			if ($file !== false && is_file($file)) {
				include_once $file;
			}

			if (!function_exists($hook['function'])) {
				continue;
			}
			($hook['function'])(new AbilitiesRegistrar($scratch, $name));
			$registry->adopt($scratch);
		} catch (Throwable $error) {
			cacti_log('MCP: ability registration failed for plugin ' . $name, false, 'MCP');
		}
	}
}

/** Resolve a hook file inside the plugin directory, as api_plugin_hook_function() does. */
function mcp_plugin_file(string $plugin, string $file) : string|false {
	global $config;

	// 1.2.31 provides cacti_plugin_path(); develop uses CACTI_PATH_PLUGINS directly.
	if (function_exists('cacti_plugin_path')) {
		return cacti_plugin_path($plugin, $file);
	}

	return defined('CACTI_PATH_PLUGINS') ? CACTI_PATH_PLUGINS . '/' . $plugin . '/' . $file
		: $config['base_path'] . '/plugins/' . $plugin . '/' . $file;
}

/** Device filter fields with columns for the WHERE subquery, where host is aliased h. */
function mcp_device_filter_fields() : array {
	return array_map(fn ($spec) => ['column' => preg_replace('/^h1\./', 'h.', $spec['column'])] + $spec, MCP_DEVICE_FIELDS);
}

/** A resource name such as sites/3, or null when the reference is unset or the column is missing. */
function mcp_reference(array $row, string $column, string $collection) : ?string {
	return (int) ($row[$column] ?? 0) > 0 ? $collection . '/' . (int) $row[$column] : null;
}

/** RFC 3339 UTC time, or null when unset; ported from core rest_time(). */
function mcp_time(mixed $value) : ?string {
	if (empty($value) || str_starts_with((string) $value, '0000-00-00')) {
		return null;
	}
	$time = is_int($value) || ctype_digit((string) $value) ? (int) $value : strtotime((string) $value);

	return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
}

/** The core Device read fields, without credentials. A column missing on this Cacti version gives null. */
function mcp_device_resource(array $row) : array {
	$int = fn (string $column) => isset($row[$column]) ? (int) $row[$column] : null;
	$str = fn (string $column) => isset($row[$column]) ? (string) $row[$column] : null;

	return [
		'name'               => 'devices/' . (int) $row['id'],
		'displayName'        => (string) $row['description'],
		'hostname'           => (string) $row['hostname'],
		'deviceTemplate'     => mcp_reference($row, 'host_template_id', 'deviceTemplates'),
		'site'               => mcp_reference($row, 'site_id', 'sites'),
		'poller'             => mcp_reference($row, 'poller_id', 'pollers'),
		'externalId'         => $str('external_id'),
		'location'           => $str('location'),
		'notes'              => $str('notes'),
		'disabled'           => ($row['disabled'] ?? '') === 'on',
		'snmpVersion'        => $int('snmp_version'),
		'snmpPort'           => $int('snmp_port'),
		'snmpTimeout'        => $int('snmp_timeout'),
		'snmpRetries'        => $int('snmp_retries'),
		'maxOids'            => $int('max_oids'),
		'bulkWalkSize'       => $int('bulk_walk_size'),
		'deviceThreads'      => $int('device_threads'),
		'availabilityMethod' => $int('availability_method'),
		'pingMethod'         => $int('ping_method'),
		'pingPort'           => $int('ping_port'),
		'pingTimeout'        => $int('ping_timeout'),
		'pingRetries'        => $int('ping_retries'),
		'state'              => isset($row['status']) ? (MCP_DEVICE_STATES[(int) $row['status']] ?? MCP_DEVICE_STATES[0]) : null,
		'availability'       => isset($row['availability']) ? (float) $row['availability'] : null,
		'lastError'          => $str('status_last_error'),
		'failTime'           => mcp_time($row['status_fail_date'] ?? null),
		'recoveryTime'       => mcp_time($row['status_rec_date'] ?? null),
		'createTime'         => mcp_time($row['created'] ?? null)
	];
}
