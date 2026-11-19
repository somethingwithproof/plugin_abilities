<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/registry.php';

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function rejects(callable $action, string $code) : void {
	try {
		$action();
	} catch (AbilitiesError $error) {
		check($error->reason === $code, 'Unexpected error: ' . $error->reason . ' (wanted ' . $code . ')');

		return;
	}

	throw new RuntimeException('Expected rejection: ' . $code);
}

function throws_logic(callable $action, string $message) : void {
	try {
		$action();
	} catch (LogicException $expected) {
		return;
	}

	throw new RuntimeException($message);
}

const READ_ONLY = ['readOnly' => true, 'destructive' => false, 'idempotent' => true];

$registry = new AbilitiesRegistry();
$allowed  = true;
$calls    = 0;
$events   = [];
$schema   = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'required' => ['id'], 'additionalProperties' => false];
$output   = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'additionalProperties' => false];

function definition(array $override = []) : array {
	global $schema, $output;

	return $override + [
		'label'         => 'Fixture',
		'description'   => 'Fixture ability',
		'category'      => 'fixtures',
		'input_schema'  => $schema,
		'output_schema' => $output,
		'annotations'   => READ_ONLY,
		'permission'    => fn (int $user) => true,
		'execute'       => fn (int $user, stdClass $args) => ['id' => $args->id]
	];
}

$registry->registerCategory('fixtures', 'Fixtures');
$registry->register('cacti-fixture-read', definition([
	'permission' => function (int $user) use (&$allowed) { return $allowed && $user === 7; },
	'execute'    => function (int $user, stdClass $args) use (&$calls) {
		$calls++;

		return ['id' => $args->id];
	}
]));
$principal = ['token_id' => 1, 'token_kind' => 'pat', 'user_id' => 7, 'scopes' => ['abilities:read']];
$trail     = [];
$audit     = function ($id, $name, $outcome) use (&$events, &$trail) { $events[] = $outcome; $trail[] = [$id, $name, $outcome]; };
$found     = $registry->discover($principal);
check(count($found) === 1, 'Discovery');
check(array_keys($found[0]) === ['id', 'label', 'description', 'category', 'inputSchema', 'outputSchema', 'annotations']
	&& $found[0]['id'] === 'cacti-fixture-read' && $found[0]['annotations'] === READ_ONLY, 'Descriptor shape');
check($registry->discover(['user_id' => 8] + $principal) === [], 'Realm isolation');
check($registry->discover(['scopes' => ['*']] + $principal) === [], 'No wildcard scopes');

// scope_required
check($registry->discover(['scopes' => []] + $principal) === [], 'scope_required');
check($registry->get(['scopes' => []] + $principal, 'cacti-fixture-read') === null, 'scope_required get');
rejects(fn () => $registry->execute(['scopes' => []] + $principal, 'cacti-fixture-read', (object) ['id' => 2], $audit), 'ABILITY_NOT_FOUND');

// get_hides_unpermitted
check($registry->get($principal, 'cacti-fixture-read')['id'] === 'cacti-fixture-read', 'get permitted');
check($registry->get($principal, 'cacti-missing-thing') === null, 'get unknown');
check($registry->get(['user_id' => 8] + $principal, 'cacti-fixture-read') === null, 'get_hides_unpermitted');

$result = $registry->execute($principal, 'cacti-fixture-read', (object) ['id' => 2], $audit);
check($result['result'] === ['id' => 2] && strlen($result['requestId']) === 32 && $calls === 1 && array_slice($events, -2) === ['started', 'succeeded'], 'Successful audit');
$allowed = false;
rejects(fn () => $registry->execute($principal, 'cacti-fixture-read', (object) ['id' => 2], $audit), 'ABILITY_NOT_FOUND');
$allowed = true;

// unified_not_found: unknown, unpermitted, and hidden abilities are the same error
$notFound = [];
$hide     = new AbilitiesRegistry();
$hide->registerCategory('fixtures', 'Fixtures');
$hide->register('cacti-fixture-read', definition());
$hide->setExposure(fn (string $id, string $namespace) => false);
foreach ([[$registry, 'cacti-missing-thing', $principal], [$registry, 'cacti-fixture-read', ['user_id' => 8] + $principal],
	[$hide, 'cacti-fixture-read', $principal]] as [$target, $id, $who]) {
	try {
		$target->execute($who, $id, (object) ['id' => 2], $audit);
	} catch (AbilitiesError $error) {
		check($error->status === 'NOT_FOUND' && $error->reason === 'ABILITY_NOT_FOUND' && $error->httpStatus() === 404 && $error->getMessage() === '', 'Unified not found');
		$notFound[] = [$error->status, $error->reason, $error->getMessage(), $error->violations, $error->httpStatus()];

		continue;
	}

	throw new RuntimeException('Expected not found for ' . $id);
}
check(count($notFound) === 3 && $notFound[0] === $notFound[1] && $notFound[0] === $notFound[2], 'Unknown, unpermitted, and hidden errors are identical');

// audit_sequences
$trail = [];
$ok    = $registry->execute($principal, 'cacti-fixture-read', (object) ['id' => 2], $audit);
check(array_column($trail, 2) === ['started', 'succeeded'] && $trail[0][0] === $ok['requestId'] && $trail[1][0] === $ok['requestId']
	&& $trail[0][1] === 'cacti-fixture-read', 'Audit sequence for success');
$trail = [];
rejects(fn () => $registry->execute($principal, 'cacti-missing-thing', (object) [], $audit), 'ABILITY_NOT_FOUND');
check(count($trail) === 1 && $trail[0][2] === 'denied' && strlen($trail[0][0]) === 32, 'Audit sequence for denied');
$trail = [];
rejects(fn () => $registry->execute(['user_id' => 8] + $principal, 'cacti-fixture-read', (object) ['id' => 2], $audit), 'ABILITY_NOT_FOUND');
check(array_column($trail, 2) === ['denied'] && $trail[0][1] === 'cacti-fixture-read', 'Audit denied for unpermitted');
$trail = [];
rejects(fn () => $registry->execute($principal, 'cacti-fixture-read', (object) ['id' => 'secret-value'], $audit), 'INVALID_ARGUMENTS');
check(array_column($trail, 2) === ['rejected'] && !str_contains(json_encode($trail), 'secret-value'), 'Audit sequence for rejected, no argument values');
$trail = [];
// An over-long or odd identifier is not written to the audit log verbatim.
rejects(fn () => $registry->execute($principal, str_repeat('x', 300), (object) [], $audit), 'ABILITY_NOT_FOUND');
check($trail[0][2] === 'denied' && strlen($trail[0][1]) <= 64, 'Denied audit name is bounded');
// A failing audit write must not turn a denial into a different error.
rejects(fn () => $registry->execute($principal, 'cacti-missing-thing', (object) [], function () { throw new AbilitiesError('UNAVAILABLE', 'AUDIT_UNAVAILABLE'); }), 'ABILITY_NOT_FOUND');

foreach ([(object) [], (object) ['id' => '2'], (object) ['id' => true], (object) ['id' => 0], (object) ['id' => 101], (object) ['id' => 2, 'extra' => 1]] as $args) {
	rejects(fn () => $registry->execute($principal, 'cacti-fixture-read', $args, $audit), 'INVALID_ARGUMENTS');
}
check($calls === 2, 'Invalid calls must not execute');
$badAudit = function () { throw new AbilitiesError('UNAVAILABLE', 'AUDIT_UNAVAILABLE'); };
rejects(fn () => $registry->execute($principal, 'cacti-fixture-read', (object) ['id' => 2], $badAudit), 'AUDIT_UNAVAILABLE');
check($calls === 2, 'Audit outage must prevent execution');
$registry->register('cacti-fixture-fail', definition([
	'execute' => function () { throw new AbilitiesError('NOT_FOUND', 'DEVICE_NOT_FOUND'); }
]));
rejects(fn () => $registry->execute($principal, 'cacti-fixture-fail', (object) ['id' => 2], $audit), 'DEVICE_NOT_FOUND');
check(array_slice($events, -2) === ['started', 'failed'], 'Failure audit');
$trail = [];
rejects(fn () => $registry->execute($principal, 'cacti-fixture-fail', (object) ['id' => 2], $audit), 'DEVICE_NOT_FOUND');
check(array_column($trail, 2) === ['started', 'failed'] && $trail[0][0] === $trail[1][0], 'Audit sequence for failure');

// output_schema_enforced
$registry->register('cacti-fixture-leak', definition([
	'execute' => fn () => ['id' => 1, 'secret' => 'x']
]));
rejects(fn () => $registry->execute($principal, 'cacti-fixture-leak', (object) ['id' => 2], $audit), 'INVALID_RESULT');
check(array_slice($events, -2) === ['started', 'failed'], 'Invalid result audit');
$registry->register('cacti-fixture-wrong', definition([
	'execute' => fn () => ['id' => 'one']
]));
rejects(fn () => $registry->execute($principal, 'cacti-fixture-wrong', (object) ['id' => 2], $audit), 'INVALID_RESULT');

// result_list_rejected: a non-empty list is not an object result
$registry->register('cacti-fixture-list', definition(['execute' => fn () => [1, 2]]));
rejects(fn () => $registry->execute($principal, 'cacti-fixture-list', (object) ['id' => 2], $audit), 'INVALID_RESULT');
check(array_slice($events, -2) === ['started', 'failed'], 'List result audit');
$registry->register('cacti-fixture-empty', definition(['output_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false], 'execute' => fn () => []]));
check($registry->execute($principal, 'cacti-fixture-empty', (object) ['id' => 2], $audit)['result'] === [], 'Empty result is still an object');

// output_schema_must_be_object
foreach ([['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1], ['type' => 'string'], ['type' => ['object', 'null'], 'properties' => [], 'additionalProperties' => false]] as $badOutput) {
	throws_logic(fn () => $registry->register('cacti-fixture-shape', definition(['output_schema' => $badOutput])), 'Non-object output schema accepted');
}

// rejects_write_annotations
foreach ([['readOnly' => false] + READ_ONLY, ['destructive' => true] + READ_ONLY, ['idempotent' => false] + READ_ONLY,
	['readOnly' => true, 'destructive' => false], READ_ONLY + ['extra' => true], []] as $annotations) {
	throws_logic(fn () => $registry->register('cacti-fixture-write', definition(['annotations' => $annotations])), 'Write annotations accepted');
}

// rejects_dotted_id and the other registration rules
foreach (['cacti.devices.get', 'Cacti-fixture-x', 'cacti', 'cacti-fixture-read', 'cacti_fixture_x', '1cacti-x', 'cacti-a-b-c-d-e-f-g-h-i',
	'cacti-' . str_repeat('a', 60)] as $id) {
	throws_logic(fn () => $registry->register($id, definition()), 'Bad ID accepted: ' . $id);
}
throws_logic(fn () => $registry->register('cacti-fixture-cat', definition(['category' => 'unknown'])), 'Unknown category accepted');
throws_logic(fn () => $registry->register('cacti-fixture-desc', definition(['description' => str_repeat('a', 4097)])), 'Long description accepted');
throws_logic(fn () => $registry->register('cacti-fixture-in', definition(['input_schema' => ['type' => 'string', 'pattern' => '.*']])), 'Bad input schema accepted');
throws_logic(fn () => $registry->register('cacti-fixture-out', definition(['output_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => true]])), 'Open output schema accepted');
throws_logic(fn () => $registry->register('cacti-fixture-key', definition(['permission' => 'nonexistent_function_name'])), 'Bad callable accepted');
$missing = definition();
unset($missing['label']);
throws_logic(fn () => $registry->register('cacti-fixture-lab', $missing), 'Missing key accepted');
throws_logic(fn () => $registry->registerCategory('Bad Id', 'Label'), 'Bad category ID accepted');
throws_logic(fn () => $registry->registerCategory('fixtures', 'Again'), 'Duplicate category accepted');

$full = new AbilitiesRegistry();
$full->registerCategory('fixtures', 'Fixtures');

for ($i = 0; $i < 256; $i++) {
	$full->register('cacti-bulk-' . $i, definition());
}
throws_logic(fn () => $full->register('cacti-bulk-over', definition()), '257th ability accepted');

// registrar_namespace
$plugin = new AbilitiesRegistrar($registry, 'thold');
throws_logic(fn () => $plugin->register('cacti-x-y', definition()), 'Foreign namespace accepted');
throws_logic(fn () => $plugin->register('tholdextra-x', definition()), 'Namespace prefix without dash accepted');
$plugin->registerCategory('thresholds', 'Thresholds');
$plugin->register('thold-list-thresholds', definition(['category' => 'thresholds']));
check($registry->get($principal, 'thold-list-thresholds') !== null, 'Registrar registration');

// exposure
$exposure = new AbilitiesRegistry();
$exposure->registerCategory('fixtures', 'Fixtures');
$exposure->register('cacti-fixture-open', definition());
$exposure->register('cacti-fixture-hidden', definition());
$exposure->register('cacti-fixture-denied', definition(['permission' => fn (int $user) => false]));
$thold = new AbilitiesRegistrar($exposure, 'thold');
$thold->registerCategory('thresholds', 'Thresholds');
$thold->register('thold-list-thresholds', definition(['category' => 'thresholds']));
$hidden = ['cacti-fixture-hidden', 'cacti-fixture-denied'];
$exposure->setExposure(fn (string $id, string $namespace) => !in_array($id, $hidden, true));
$ids = array_column($exposure->discover($principal), 'id');
check($ids === ['cacti-fixture-open', 'thold-list-thresholds'], 'hidden_ability_absent discover');
check($exposure->get($principal, 'cacti-fixture-hidden') === null, 'hidden_ability_absent get');
$trail = [];
$collect = function ($i, $n, $o) use (&$trail) { $trail[] = $o; };
rejects(fn () => $exposure->execute($principal, 'cacti-fixture-hidden', (object) ['id' => 1], $collect), 'ABILITY_NOT_FOUND');
check($trail === ['denied'], 'hidden ability execute is denied and audited');
// exposure_never_grants
$hidden = [];
check($exposure->get($principal, 'cacti-fixture-denied') === null, 'exposure_never_grants');
check(!in_array('cacti-fixture-denied', array_column($exposure->discover($principal), 'id'), true), 'exposure_never_grants discover');
// all_ignores_exposure
$hidden = ['cacti-fixture-hidden'];
$all    = $exposure->all();
check(array_column($all, 'id') === ['cacti-fixture-open', 'cacti-fixture-hidden', 'cacti-fixture-denied', 'thold-list-thresholds'], 'all_ignores_exposure');
check(array_column($all, 'namespace') === ['cacti', 'cacti', 'cacti', 'thold'] && $all[0]['label'] === 'Fixture' && isset($all[0]['inputSchema']), 'all lists namespaces');
// The callback receives the namespace, including after a scratch registry is adopted.
$seen = [];
$exposure->setExposure(function (string $id, string $namespace) use (&$seen) { $seen[$id] = $namespace; return true; });
$scratch = clone $exposure;
(new AbilitiesRegistrar($scratch, 'nginx'))->registerCategory('web', 'Web');
(new AbilitiesRegistrar($scratch, 'nginx'))->register('nginx-list-sites', definition(['category' => 'web']));
$exposure->adopt($scratch);
$exposure->discover($principal);
check($seen['nginx-list-sites'] === 'nginx' && $seen['thold-list-thresholds'] === 'thold' && $seen['cacti-fixture-open'] === 'cacti', 'Namespace survives adopt');
check(AbilitiesRegistry::isValidId('cacti-list-devices') && !AbilitiesRegistry::isValidId("cacti-x\n") && !AbilitiesRegistry::isValidId(str_repeat('a', 65)), 'isValidId');

// nullable_and_arrays
$rich = new AbilitiesRegistry();
$rich->registerCategory('fixtures', 'Fixtures');
$richOutput = ['type' => 'object', 'properties' => [
	'next'  => ['type' => ['string', 'null'], 'description' => 'Next cursor'],
	'items' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object', 'properties' => ['n' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10]], 'additionalProperties' => false]]
], 'required' => ['next', 'items'], 'additionalProperties' => false];
$payload    = ['next' => null, 'items' => [['n' => 1.5], ['n' => 2]]];
$rich->register('cacti-rich-out', definition(['output_schema' => $richOutput, 'execute' => function () use (&$payload) { return $payload; }]));
$result = $rich->execute($principal, 'cacti-rich-out', (object) ['id' => 1], $audit);
check($result['result']['next'] === null && count($result['result']['items']) === 2, 'Nullable and array output');
$payload['next'] = 'abc';
$rich->execute($principal, 'cacti-rich-out', (object) ['id' => 1], $audit);

foreach ([['next' => 5, 'items' => []], ['next' => null, 'items' => [['n' => 11]]], ['next' => null, 'items' => [['n' => 'x']]],
	['next' => null, 'items' => array_fill(0, 101, ['n' => 1])], ['next' => null, 'items' => ['k' => ['n' => 1]]]] as $bad) {
	$payload = $bad;
	rejects(fn () => $rich->execute($principal, 'cacti-rich-out', (object) ['id' => 1], $audit), 'INVALID_RESULT');
}
$payload = ['next' => null, 'items' => []];
check($rich->execute($principal, 'cacti-rich-out', (object) ['id' => 1], $audit)['result']['items'] === [], 'Empty array output');

foreach ([
	['type' => 'array', 'items' => ['type' => 'string']],
	['type' => 'array', 'maxItems' => 5],
	['type' => ['null', 'string']],
	['type' => ['string', 'integer']],
	['type' => ['string']],
	['type' => 'number', 'pattern' => 'x'],
	['type' => 'string', 'format' => 'date']
] as $badSchema) {
	throws_logic(fn () => abilities_check_schema($badSchema), 'Unsupported schema accepted: ' . json_encode($badSchema));
}
abilities_validate(null, ['type' => ['integer', 'null']]);
abilities_validate(1.5, ['type' => 'number']);
rejects(fn () => abilities_validate('1', ['type' => 'number']), 'INVALID_ARGUMENTS');
rejects(fn () => abilities_validate(null, ['type' => 'string']), 'INVALID_ARGUMENTS');
rejects(fn () => abilities_validate(11, ['type' => 'number', 'maximum' => 10]), 'INVALID_ARGUMENTS');

// string patterns are anchored by the schema author; invalid or delimiter-breaking patterns fail closed
abilities_check_schema(['type' => 'string', 'pattern' => '^devices/[1-9][0-9]{0,9}$']);
abilities_validate('devices/12', ['type' => 'string', 'pattern' => '^devices/[1-9][0-9]{0,9}$']);

foreach (['devices/0', 'devices/12345678901', "devices/1\n", 'x devices/1'] as $badName) {
	rejects(fn () => abilities_validate($badName, ['type' => 'string', 'pattern' => '^devices/[1-9][0-9]{0,9}$']), 'INVALID_ARGUMENTS');
}

foreach (['(', 'a~b'] as $badPattern) {
	throws_logic(fn () => abilities_check_schema(['type' => 'string', 'pattern' => $badPattern]), 'Bad pattern accepted: ' . $badPattern);
}
throws_logic(fn () => abilities_check_schema(['type' => 'string', 'pattern' => 5]), 'Non-string pattern accepted');
abilities_validate('é', ['type' => 'string', 'maxLength' => 1, 'enum' => ['é'], 'description' => 'Accent']);
rejects(fn () => abilities_validate('éé', ['type' => 'string', 'maxLength' => 1]), 'INVALID_ARGUMENTS');
rejects(fn () => abilities_validate('other', ['type' => 'string', 'enum' => ['one']]), 'INVALID_ARGUMENTS');
rejects(fn () => abilities_validate([], ['type' => 'object']), 'INVALID_ARGUMENTS');
abilities_validate(true, ['type' => 'boolean']);
rejects(fn () => abilities_validate(1, ['type' => 'boolean']), 'INVALID_ARGUMENTS');
print "Registry authorization, schema, and audit checks passed.\n";
