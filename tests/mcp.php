<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/aip.php';
require_once __DIR__ . '/../lib/mcp_server.php';

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function fixture_registry(bool $permitted = true) : AbilitiesRegistry {
	$registry = new AbilitiesRegistry();
	$registry->registerCategory('fixtures', 'Fixtures');
	$input  = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
		'required' => [], 'additionalProperties' => false];
	$output = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => [], 'additionalProperties' => false];
	$empty  = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	$base   = ['label' => 'Get thing', 'description' => 'Fixture ability', 'category' => 'fixtures',
		'input_schema' => $input, 'output_schema' => $output,
		'annotations'  => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
		'permission'   => fn (int $user) => $permitted,
		'execute'      => fn (int $user, stdClass $args) => ['id' => $args->id ?? 1]];
	$registry->register('fixture-get-thing', $base);
	$registry->register('fixture-empty-thing', ['input_schema' => $empty, 'output_schema' => $empty,
		'execute' => fn (int $user, stdClass $args) => []] + $base);
	$registry->register('fixture-missing-thing', ['execute' => function (int $user, stdClass $args) {
		throw new AbilitiesError('NOT_FOUND', 'DEVICE_NOT_FOUND');
	}] + $base);
	$registry->register('fixture-broken-thing', ['execute' => function (int $user, stdClass $args) {
		throw new RuntimeException('secret detail');
	}] + $base);

	return $registry;
}

const MODERN_META = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'];

/** Send one request; $message is the JSON-RPC message, headers default to the modern set when the message is modern. */
function rpc(array|string $message, array $headers = [], string $method = 'POST', bool $permitted = true, array &$events = []) : array {
	$principal = ['token_id' => 1, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']];
	$body      = is_string($message) ? $message : json_encode($message);

	return mcp_rpc_handle($method, $headers, $body, fixture_registry($permitted), $principal,
		function ($id, $name, $outcome) use (&$events) { $events[] = $outcome; },
		['name' => 'cacti-mcp', 'version' => '0.1.0']);
}

function modern(string $method, array $params = [], array $headers = [], int $id = 1) : array {
	$params['_meta'] = MODERN_META;
	$headers         = $headers + ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => $method];

	return rpc(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $headers);
}

function legacy(string $method, array $params = [], int $id = 1) : array {
	return rpc(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
}

/** Round trip so stdClass and arrays compare as plain data. */
function plain(?array $body) : mixed {
	return json_decode(json_encode($body), true);
}

// modern_discover
$r = modern('server/discover');
check($r['status'] === 200, 'discover status');
$result = plain($r['body'])['result'];
check($result['supportedVersions'] === ['2026-07-28', '2025-11-25'], 'discover supportedVersions');
check($result['resultType'] === 'complete', 'discover resultType');
check($result['cacheScope'] === 'private' && $result['ttlMs'] === 0, 'discover cache fields');
check($result['capabilities'] === ['tools' => []] && json_encode($r['body']['result']['capabilities']) === '{"tools":{}}', 'discover capabilities');
check($result['_meta']['io.modelcontextprotocol/serverInfo'] === ['name' => 'cacti-mcp', 'version' => '0.1.0'], 'discover serverInfo');
check($r['body']['id'] === 1 && $r['body']['jsonrpc'] === '2.0', 'discover envelope');

// modern_header_mismatch
$r = rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['_meta' => MODERN_META, 'name' => 'fixture-get-thing']],
	['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list']);
check($r['status'] === 400 && $r['body']['error']['code'] === -32020, 'mcp-method mismatch');
$r = rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['_meta' => MODERN_META, 'name' => 'fixture-get-thing']],
	['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => 'other']);
check($r['status'] === 400 && $r['body']['error']['code'] === -32020, 'mcp-name mismatch');
$r = modern('tools/list', [], ['mcp-protocol-version' => '2025-11-25']);
check($r['status'] === 400 && $r['body']['error']['code'] === -32020, 'protocol header mismatch');
$r = rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => MODERN_META]], []);
check($r['status'] === 400 && $r['body']['error']['code'] === -32020, 'missing headers');
$name = '=?base64?' . base64_encode('fixture-get-thing') . '?=';
$r    = modern('tools/call', ['name' => 'fixture-get-thing'], ['mcp-name' => $name]);
check($r['status'] === 200 && !isset($r['body']['error']), 'base64 mcp-name accepted');

// modern_unsupported_version
$r = rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list',
	'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1900-01-01']]],
	['mcp-protocol-version' => '1900-01-01', 'mcp-method' => 'tools/list']);
check($r['status'] === 400 && $r['body']['error']['code'] === -32022, 'unsupported version code');
check($r['body']['error']['data'] === ['supported' => ['2026-07-28', '2025-11-25'], 'requested' => '1900-01-01'], 'unsupported version data');

// modern_unknown_method
$r = modern('resources/list');
check($r['status'] === 404 && $r['body']['error']['code'] === -32601, 'modern unknown method');

// modern_ping
$r = modern('ping');
check($r['status'] === 200 && plain($r['body'])['result']['resultType'] === 'complete', 'modern ping');

// tools_list_mapping
$r    = modern('tools/list');
$list = plain($r['body'])['result'];
check($list['resultType'] === 'complete' && $list['cacheScope'] === 'private' && $list['ttlMs'] === 0, 'modern list required fields');
check(count($list['tools']) === 4, 'list count');
$tool = $list['tools'][0];
check($tool['name'] === 'fixture-get-thing' && $tool['title'] === 'Get thing' && $tool['description'] === 'Fixture ability', 'tool names');
check($tool['annotations'] === ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], 'tool annotations');
check($tool['inputSchema']['type'] === 'object' && $tool['outputSchema']['type'] === 'object', 'tool schemas');
check(str_contains(json_encode($r['body']), '"properties":{}'), 'empty properties encode as an object');
check(!str_contains(json_encode($r['body']), '"properties":[]'), 'empty properties must not be a list');
$legacyList = plain(legacy('tools/list')['body'])['result'];
check(count($legacyList['tools']) === 4 && !isset($legacyList['resultType']), 'legacy list has no modern fields');
$hidden = rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [], 'POST', false);
check(plain($hidden['body'])['result']['tools'] === [], 'unpermitted abilities are not listed');

// tools_call_structured
$events = [];
$r      = rpc(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'fixture-get-thing', 'arguments' => ['id' => 5]]],
	[], 'POST', true, $events);
$result = plain($r['body'])['result'];
check($result['structuredContent'] === ['id' => 5], 'structuredContent');
check($result['content'] === [['type' => 'text', 'text' => '{"id":5}']], 'text content');
check(!isset($result['isError']) && $events === ['started', 'succeeded'], 'success is audited');
$r = modern('tools/call', ['name' => 'fixture-get-thing', 'arguments' => ['id' => 6]], ['mcp-name' => 'fixture-get-thing']);
check(plain($r['body'])['result']['resultType'] === 'complete', 'modern call resultType');
$r = legacy('tools/call', ['name' => 'fixture-get-thing', 'arguments' => 'bogus']);
check(plain($r['body'])['result']['structuredContent'] === ['id' => 1], 'non-object arguments are {}');
$r = legacy('tools/call', ['name' => 'fixture-empty-thing']);
check(json_encode($r['body']['result']['structuredContent']) === '{}', 'empty result is an object');

// tools_call_ability_error
$r      = legacy('tools/call', ['name' => 'fixture-missing-thing']);
$result = plain($r['body'])['result'];
check($r['status'] === 200 && $result['isError'] === true, 'ability error isError');
check($result['structuredContent']['error']['status'] === 'NOT_FOUND', 'ability error status');
check($result['content'][0]['type'] === 'text' && $result['content'][0]['text'] !== '', 'ability error text');
$r = legacy('tools/call', ['name' => 'no-such-tool']);
check($r['status'] === 200 && plain($r['body'])['result']['isError'] === true && !isset($r['body']['error']), 'unknown tool is a tool error');
$r = legacy('tools/call', ['name' => 'fixture-get-thing', 'arguments' => ['id' => 999]]);
check(plain($r['body'])['result']['structuredContent']['error']['status'] === 'INVALID_ARGUMENT', 'invalid arguments');
$r = legacy('tools/call', ['name' => 'fixture-broken-thing']);
check(plain($r['body'])['result']['structuredContent']['error']['status'] === 'INTERNAL'
	&& !str_contains(json_encode($r['body']), 'secret detail'), 'unexpected failure does not leak');
$denied = rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'fixture-get-thing']], [], 'POST', false);
check(plain($denied['body'])['result']['isError'] === true, 'unpermitted ability cannot be called');
$r = legacy('tools/call', []);
check($r['status'] === 200 && $r['body']['error']['code'] === -32602, 'legacy call without name');

// legacy_initialize
foreach (['2025-11-25', '2025-06-18', '2024-11-05'] as $asked) {
	$r = legacy('initialize', ['protocolVersion' => $asked, 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'x', 'version' => '1']]);
	check($r['body']['result']['protocolVersion'] === '2025-11-25', 'initialize version for ' . $asked);
}
check(json_encode($r['body']['result']['capabilities']) === '{"tools":{}}', 'initialize capabilities');
check($r['body']['result']['serverInfo'] === ['name' => 'cacti-mcp', 'version' => '0.1.0'], 'initialize serverInfo');
check(!array_key_exists('mcp-session-id', array_change_key_case($r['headers'])), 'no session id header');

// legacy_notification_202
$r = rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
check($r['status'] === 202 && $r['body'] === null, 'notification 202');
$r = rpc(['jsonrpc' => '2.0', 'method' => 'notifications/whatever', 'params' => new stdClass()]);
check($r['status'] === 202 && $r['body'] === null, 'any notification 202');

// legacy_get_delete_session
foreach (['GET', 'DELETE', 'PUT'] as $verb) {
	$r = rpc('', [], $verb);
	check($r['status'] === 405 && $r['headers']['Allow'] === 'POST' && $r['body'] === null, $verb . ' is 405');
}
$r = rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['mcp-session-id' => 'abc']);
check($r['status'] === 200 && !array_key_exists('mcp-session-id', array_change_key_case($r['headers'])), 'session id ignored');
check(json_encode($r['body']['result']) === '{}', 'legacy ping is an empty object');
$r = legacy('resources/list');
check($r['status'] === 200 && $r['body']['error']['code'] === -32601, 'legacy unknown method');

// malformed requests
$r = rpc('{not json');
check($r['status'] === 400 && $r['body']['error']['code'] === -32700 && !array_key_exists('id', $r['body']), 'parse error');
foreach (['[]', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]', '"x"', '5', 'null', '{}',
	'{"jsonrpc":"1.0","id":1,"method":"ping"}', '{"jsonrpc":"2.0","id":1}', '{"jsonrpc":"2.0","id":1,"method":5}',
	'{"jsonrpc":"2.0","id":1.5,"method":"ping"}', '{"jsonrpc":"2.0","id":null,"method":"ping"}',
	'{"jsonrpc":"2.0","id":[1],"method":"ping"}', '{"jsonrpc":"2.0","id":true,"method":"ping"}'] as $bad) {
	$r = rpc($bad);
	check($r['status'] === 400 && $r['body']['error']['code'] === -32600 && !array_key_exists('id', $r['body']), 'invalid request: ' . $bad);
}
$r = rpc('{"jsonrpc":"2.0","id":"abc","method":"ping"}');
check($r['status'] === 200 && $r['body']['id'] === 'abc', 'string ids are echoed');

// request_headers
check(mcp_request_headers(['HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_NAME' => 'x', 'HTTP_ORIGIN' => 'https://a',
	'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'HTTP_BAD' => ['x'], 'HTTP_X_ONE' => '1'])
	=== ['mcp-protocol-version' => '2026-07-28', 'mcp-name' => 'x', 'origin' => 'https://a', 'x-one' => '1'], 'request headers');

// fix round 1
$throwing = new AbilitiesRegistry();
$throwing->registerCategory('fixtures', 'Fixtures');
$schema = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
$throwing->register('fixture-throwing-thing', ['label' => 'T', 'description' => 'd', 'category' => 'fixtures',
	'input_schema' => $schema, 'output_schema' => $schema,
	'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
	'permission'  => function (int $user) { throw new RuntimeException('secret detail'); },
	'execute'     => fn (int $user, stdClass $args) => []]);
$principal = ['token_id' => 1, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']];
$audit     = function () {};
$info      = ['name' => 'cacti-mcp', 'version' => '0.1.0'];
$r = mcp_rpc_handle('POST', [], '{"jsonrpc":"2.0","id":9,"method":"tools/list"}', $throwing, $principal, $audit, $info);
check($r['status'] === 200 && $r['body']['error']['code'] === -32603 && $r['body']['id'] === 9
	&& !str_contains(json_encode($r['body']), 'secret'), 'legacy throwing permission callback');
$r = mcp_rpc_handle('POST', ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list'],
	json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list', 'params' => ['_meta' => MODERN_META]]), $throwing, $principal, $audit, $info);
check($r['status'] === 500 && $r['body']['error']['code'] === -32603 && $r['body']['id'] === 9
	&& !str_contains(json_encode($r['body']), 'secret'), 'modern throwing permission callback');

// legacy protocol version header
$ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
foreach (['2025-11-25', '2025-06-18', '2025-03-26'] as $v) {
	check(rpc($ping, ['mcp-protocol-version' => $v])['status'] === 200, 'accepted legacy header ' . $v);
}
$r = rpc($ping, ['mcp-protocol-version' => '2024-11-05']);
check($r['status'] === 400 && $r['body']['error']['code'] === -32600 && $r['body']['id'] === 1 && $r['headers'] === [], 'unsupported legacy header');
check(rpc(['method' => 'initialize'] + $ping, ['mcp-protocol-version' => '1900-01-01'])['status'] === 200, 'initialize exempt from header check');
check(rpc($ping)['status'] === 200, 'absent header accepted');

// status rule for legacy -32602
$r = rpc('{"jsonrpc":"2.0","id":1,"method":"ping","params":[1]}');
check($r['status'] === 200 && $r['body']['error']['code'] === -32602, 'legacy params not object is 200');
$r = modern('tools/call', [], ['mcp-name' => 'x']);
check($r['status'] === 400, 'modern call without name is rejected');

// list result is never emitted as a JSON array
$listy = new AbilitiesRegistry();
$listy->registerCategory('fixtures', 'Fixtures');
$listSchema = ['type' => 'object', 'properties' => ['0' => ['type' => 'integer']], 'additionalProperties' => false];
$listy->register('fixture-list-thing', ['label' => 'L', 'description' => 'd', 'category' => 'fixtures',
	'input_schema' => $schema, 'output_schema' => $listSchema,
	'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
	'permission'  => fn (int $user) => true, 'execute' => fn (int $user, stdClass $args) => [5]]);
$r = mcp_rpc_handle('POST', [], '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fixture-list-thing"}}', $listy, $principal, $audit, $info);
check($r['body']['result']['isError'] === true && $r['body']['result']['structuredContent']['error']['status'] === 'INTERNAL'
	&& !isset($r['body']['result']['structuredContent'][0]), 'list result rejected');

// invalid base64 name and modern notification
$r = modern('tools/call', ['name' => 'fixture-get-thing'], ['mcp-name' => '=?base64?###?=']);
check($r['status'] === 400 && $r['body']['error']['code'] === -32020, 'invalid base64 mcp-name');
$r = rpc(['jsonrpc' => '2.0', 'method' => 'notifications/x', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1900-01-01']]]);
check($r['status'] === 202 && $r['body'] === null, 'modern notification with unsupported version');

echo "mcp tests passed\n";
