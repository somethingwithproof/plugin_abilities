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
		check($error->reason === $code, 'Unexpected error');

		return;
	}

	throw new RuntimeException('Expected rejection: ' . $code);
}

$registry = new AbilitiesRegistry();
$allowed  = true;
$calls    = 0;
$events   = [];
$schema   = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], 'required' => ['id'], 'additionalProperties' => false];
$registry->register('fixture.read', 'Fixture', $schema,
	function (int $user) use (&$allowed) { return $allowed && $user === 7; },
	function (int $user, stdClass $args) use (&$calls) {
		$calls++;

		return ['id' => $args->id];
	});
$principal = ['user_id' => 7, 'scopes' => ['fixture.read']];
$audit     = function ($id, $name, $outcome) use (&$events) { $events[] = $outcome; };
check(count($registry->discover($principal)) === 1, 'Discovery');
check($registry->discover(['user_id' => 8, 'scopes' => ['fixture.read']]) === [], 'Realm isolation');
check($registry->discover(['user_id' => 7, 'scopes' => ['*']]) === [], 'No wildcard scopes');
$result = $registry->execute($principal, 'fixture.read', (object) ['id' => 2], $audit);
check($result['result']->id === 2 && $calls === 1 && $events === ['started', 'succeeded'], 'Successful audit');
$allowed = false;
rejects(fn () => $registry->execute($principal, 'fixture.read', (object) ['id' => 2], $audit), 'ABILITY_UNAVAILABLE');
$allowed = true;

foreach ([(object) [], (object) ['id' => '2'], (object) ['id' => true], (object) ['id' => 0], (object) ['id' => 101], (object) ['id' => 2, 'extra' => 1]] as $args) {
	rejects(fn () => $registry->execute($principal, 'fixture.read', $args, $audit), 'INVALID_ARGUMENTS');
}
check($calls === 1, 'Invalid calls must not execute');
$badAudit = function () { throw new AbilitiesError('UNAVAILABLE', 'AUDIT_UNAVAILABLE'); };
rejects(fn () => $registry->execute($principal, 'fixture.read', (object) ['id' => 2], $badAudit), 'AUDIT_UNAVAILABLE');
check($calls === 1, 'Audit outage must prevent execution');
$registry->register('fixture.fail', 'Failure', $schema, fn () => true,
	function () { throw new AbilitiesError('NOT_FOUND', 'DEVICE_NOT_FOUND'); });
$principal['scopes'][] = 'fixture.fail';
rejects(fn () => $registry->execute($principal, 'fixture.fail', (object) ['id' => 2], $audit), 'DEVICE_NOT_FOUND');
check(array_slice($events, -2) === ['started', 'failed'], 'Failure audit');

try {
	abilities_check_schema(['type' => 'string', 'pattern' => '.*']);

	throw new RuntimeException('Unsupported keyword accepted');
} catch (LogicException $expected) {
}
abilities_validate('é', ['type' => 'string', 'maxLength' => 1, 'enum' => ['é']]);
rejects(fn () => abilities_validate('éé', ['type' => 'string', 'maxLength' => 1]), 'INVALID_ARGUMENTS');
rejects(fn () => abilities_validate('other', ['type' => 'string', 'enum' => ['one']]), 'INVALID_ARGUMENTS');
rejects(fn () => abilities_validate([], ['type' => 'object']), 'INVALID_ARGUMENTS');
abilities_validate(true, ['type' => 'boolean']);
rejects(fn () => abilities_validate(1, ['type' => 'boolean']), 'INVALID_ARGUMENTS');
print "Registry authorization, schema, and audit checks passed.\n";
