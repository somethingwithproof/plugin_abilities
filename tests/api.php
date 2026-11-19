<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/aip.php';
require_once __DIR__ . '/../lib/api_http.php';

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

$logs = [];

function cacti_log($message, $output = false, $facility = '') {
	global $logs;
	$logs[] = [$message, $output, $facility];
}

function fixture_registry(bool $permitted = true) : AbilitiesRegistry {
	$registry = new AbilitiesRegistry();
	$registry->registerCategory('fixtures', 'Fixtures');
	$registry->registerCategory('other', 'Other');
	$schema = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
		'required' => ['id'], 'additionalProperties' => false];
	$output = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'additionalProperties' => false];
	$base   = ['label' => 'Get thing', 'description' => 'Fixture ability', 'category' => 'fixtures',
		'input_schema' => $schema, 'output_schema' => $output,
		'annotations'  => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
		'permission'   => fn (int $user) => $permitted,
		'execute'      => fn (int $user, stdClass $args) => ['id' => $args->id]];
	$registry->register('fixture-get-thing', $base);
	$registry->register('fixture-other-thing', ['category' => 'other'] + $base);

	return $registry;
}

function call(string $method, string $path, array $query = [], string $body = '', bool $permitted = true) : array {
	$principal = ['token_id' => 1, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']];
	$events    = [];

	return mcp_api_handle($method, $path, $query, $body, fixture_registry($permitted), $principal,
		function ($id, $name, $outcome) use (&$events) { $events[] = $outcome; });
}

function reason(array $response) : string {
	return $response['body']['error']['details'][0]['reason'] ?? '';
}

// api_list
$response = call('GET', '/v1/abilities');
check($response['status'] === 200, 'List status');
check($response['body']['abilities'][0]['name'] === 'abilities/fixture-get-thing', 'List name');
check(count($response['body']['abilities']) === 2 && $response['body']['nextPageToken'] === null, 'List shape');

// api_list_filter_and_paging
$response = call('GET', '/v1/abilities', ['filter' => 'category = "other"']);
check($response['status'] === 200 && count($response['body']['abilities']) === 1
	&& $response['body']['abilities'][0]['name'] === 'abilities/fixture-other-thing', 'Category filter');
$first = call('GET', '/v1/abilities', ['pageSize' => '1']);
check(count($first['body']['abilities']) === 1 && is_string($first['body']['nextPageToken']), 'First page');
$second = call('GET', '/v1/abilities', ['pageSize' => '1', 'pageToken' => $first['body']['nextPageToken']]);
check($second['body']['abilities'][0]['name'] === 'abilities/fixture-other-thing' && $second['body']['nextPageToken'] === null, 'Second page');
$wrong = call('GET', '/v1/abilities', ['pageSize' => '1', 'pageToken' => $first['body']['nextPageToken'], 'filter' => 'category = "other"']);
check($wrong['status'] === 400 && reason($wrong) === 'INVALID_PAGE_TOKEN', 'Token bound to filter');
foreach (['abc', '-1', '1.5', '', '0x10', ' 5'] as $bad) {
	$response = call('GET', '/v1/abilities', ['pageSize' => $bad]);
	check($response['status'] === 400 && reason($response) === 'INVALID_ARGUMENTS'
		&& $response['body']['error']['details'][1]['fieldViolations'][0]['field'] === 'pageSize', 'Bad pageSize ' . $bad);
}
check(call('GET', '/v1/abilities', ['pageSize' => ['1']])['status'] === 400, 'Array pageSize');
check(call('GET', '/v1/abilities', ['pageSize' => '0'])['status'] === 200, 'Zero pageSize means default');
check(call('GET', '/v1/abilities', ['pageSize' => '99999999999999999999'])['status'] === 200, 'Huge pageSize is capped');
foreach (['name = "x"', 'category = fixtures', 'category = "a" AND category = "b"'] as $bad) {
	$response = call('GET', '/v1/abilities', ['filter' => $bad]);
	check($response['status'] === 400 && reason($response) === 'INVALID_FILTER', 'Bad filter ' . $bad);
}

// api_get
$response = call('GET', '/v1/abilities/fixture-get-thing');
check($response['status'] === 200 && array_keys($response['body']) === ['name', 'displayName', 'description', 'category',
	'inputSchema', 'outputSchema', 'annotations'], 'Get shape');

// api_get_unpermitted_is_not_found
$response = call('GET', '/v1/abilities/fixture-get-thing', [], '', false);
check($response['status'] === 404 && reason($response) === 'ABILITY_NOT_FOUND', 'Unpermitted get');
check(call('GET', '/v1/abilities/fixture-missing-thing')['status'] === 404, 'Missing get');

// api_execute
$response = call('POST', '/v1/abilities/fixture-get-thing:execute', [], '{"arguments":{"id":5}}');
check($response['status'] === 200 && preg_match('/^[0-9a-f]{32}$/D', $response['body']['requestId']) === 1
	&& $response['body']['result'] === ['id' => 5], 'Execute');
check(call('POST', '/v1/abilities/fixture-get-thing:execute', [], '{"arguments":{"id":500}}')['status'] === 400, 'Schema violation');
$response = call('POST', '/v1/abilities/fixture-get-thing:execute', [], '{"arguments":{"id":5}}', false);
check($response['status'] === 404 && reason($response) === 'ABILITY_NOT_FOUND', 'Unpermitted execute is not found');
$missing = call('POST', '/v1/abilities/fixture-missing-thing:execute', [], '{"arguments":{}}');
check($missing['status'] === 404 && $missing['body'] === $response['body'], 'Unknown and unpermitted execute are identical');

// api_execute_rejects_extra_body_fields
foreach (['{"arguments":{},"x":1}', '{}', '[]', '{"arguments":[]}', '{"arguments":"x"}', 'not json', '', '"x"'] as $bad) {
	$response = call('POST', '/v1/abilities/fixture-get-thing:execute', [], $bad);
	check($response['status'] === 400 && reason($response) === 'INVALID_ARGUMENTS', 'Bad body ' . $bad);
}

// api_missing_path_info
$response = call('GET', '');
check($response['status'] === 404 && reason($response) === 'UNKNOWN_PATH', 'Missing PATH_INFO');
check(str_contains($response['body']['error']['message'], "''"), 'Message names the empty path');
$response = call('GET', '/v2/nothing');
check(str_contains($response['body']['error']['message'], '/v2/nothing'), 'Message names the path');
foreach ([['POST', '/v1/abilities'], ['GET', '/v1/abilities/fixture-get-thing:execute'], ['POST', '/v1/abilities/fixture-get-thing'],
	['DELETE', '/v1/abilities'], ['GET', '/v1/abilities/'], ['GET', '/v1/abilities/a/b']] as [$method, $path]) {
	check(reason(call($method, $path)) === 'UNKNOWN_PATH', 'Unknown route ' . $method . ' ' . $path);
}

// api_error_shape
foreach ([call('GET', ''), call('GET', '/v1/abilities/fixture-get-thing', [], '', false), call('GET', '/v1/abilities', ['pageSize' => 'x']),
	call('POST', '/v1/abilities/fixture-get-thing:execute', [], '{}')] as $response) {
	check($response['status'] !== 200 && is_string($response['body']['error']['status'])
		&& $response['body']['error']['details'][0]['domain'] === 'cacti.net'
		&& $response['body']['error']['code'] === $response['status'], 'Error shape');
}

// internal_errors_do_not_leak
$registry = fixture_registry();
$registry->register('fixture-broken-thing', ['label' => 'Broken', 'description' => 'x', 'category' => 'fixtures',
	'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
	'output_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
	'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
	'permission' => fn (int $user) => true,
	'execute' => function (int $user, stdClass $args) { throw new RuntimeException('secret database detail'); }]);
$response = mcp_api_handle('POST', '/v1/abilities/fixture-broken-thing:execute', [], '{"arguments":{}}', $registry,
	['token_id' => 1, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']], fn () => null);
check($response['status'] === 500 && !str_contains(json_encode($response['body']), 'secret'), 'Internal error leaked');
check($logs === [['MCP: ability fixture-broken-thing failed: INTERNAL_ERROR (RuntimeException)', false, 'MCP']], 'Internal error is logged by class only');
$logs = [];
call('GET', '/v1/abilities/no-such-thing');
check($logs === [], 'Expected errors are not logged');

// unauthenticated_adds_challenge
$response = mcp_api_error(new AbilitiesError('UNAUTHENTICATED', 'INVALID_TOKEN'));
check($response['status'] === 401 && $response['headers']['WWW-Authenticate'] === 'Bearer', 'WWW-Authenticate');

echo "api tests passed\n";
