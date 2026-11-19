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

$spec = json_decode((string) file_get_contents(__DIR__ . '/../openapi.json'), true, 64, JSON_THROW_ON_ERROR);

check(($spec['openapi'] ?? '') === '3.1.0', 'OpenAPI version');
check(array_keys($spec['paths']) === ['/v1/abilities', '/v1/abilities/{ability}', '/v1/abilities/{ability}:execute'], 'Path set');
check(isset($spec['components']['securitySchemes']['bearerToken']) && $spec['security'] === [['bearerToken' => []]], 'Bearer security');

$registry = new AbilitiesRegistry();
$registry->registerCategory('fixtures', 'Fixtures');
$registry->register('fixture-get-thing', ['label' => 'Get thing', 'description' => 'Fixture', 'category' => 'fixtures',
	'input_schema'  => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
	'output_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
	'annotations'   => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
	'permission'    => fn (int $user) => true,
	'execute'       => fn (int $user, stdClass $args) => []]);
$principal = ['token_id' => 1, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']];
$audit     = fn () => null;

$get = mcp_api_handle('GET', '/v1/abilities/fixture-get-thing', [], '', $registry, $principal, $audit);
$properties = array_keys($spec['components']['schemas']['Ability']['properties']);
check($properties === array_keys($get['body']), 'Ability schema properties differ from emitted keys');

$list = mcp_api_handle('GET', '/v1/abilities', [], '', $registry, $principal, $audit);
check(array_keys($spec['components']['schemas']['ListAbilitiesResponse']['properties']) === array_keys($list['body']), 'List keys');

$run = mcp_api_handle('POST', '/v1/abilities/fixture-get-thing:execute', [], '{"arguments":{}}', $registry, $principal, $audit);
check(array_keys($spec['components']['schemas']['ExecuteAbilityResponse']['properties']) === array_keys($run['body']), 'Execute keys');
check(array_keys($spec['components']['schemas']['ExecuteAbilityRequest']['properties']) === ['arguments']
	&& $spec['components']['schemas']['ExecuteAbilityRequest']['additionalProperties'] === false, 'Execute request');

$error = mcp_api_handle('GET', '', [], '', $registry, $principal, $audit);
$errorSchema = $spec['components']['schemas']['Status']['properties']['error'];
check(array_keys($errorSchema['properties']) === array_keys($error['body']['error']), 'Error keys');
check($errorSchema['required'] === ['code', 'message', 'status', 'details'], 'Error required');

echo "contract tests passed\n";
