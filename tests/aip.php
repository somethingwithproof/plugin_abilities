<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/aip.php';

function db_qstr(string $value) : string {
	return "'" . addslashes($value) . "'";
}

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function rejects(callable $action, string $reason) : void {
	try {
		$action();
	} catch (AbilitiesError $error) {
		check($error->reason === $reason, 'Unexpected reason ' . $error->reason);

		return;
	}

	throw new RuntimeException('Expected rejection: ' . $reason);
}

$fields = [
	'hostname'    => ['column' => 'h1.hostname', 'type' => 'string', 'filter' => true, 'order' => true],
	'displayName' => ['column' => 'h1.description', 'type' => 'string', 'filter' => true, 'order' => true],
	'disabled'    => ['column' => 'h1.disabled', 'type' => 'bool', 'filter' => true],
	'count'       => ['column' => 'h1.count', 'type' => 'int', 'filter' => true],
	'site'        => ['column' => 'h1.site_id', 'type' => 'reference', 'collection' => 'sites', 'filter' => true],
	'status'      => ['column' => 'h1.status', 'type' => 'enum', 'values' => [3 => 'up', 1 => 'down'], 'filter' => true],
	'secret'      => ['column' => 'h1.snmp_community', 'type' => 'string'],
];

// error_body_shape
$body = mcp_error_body(new AbilitiesError('NOT_FOUND', 'DEVICE_NOT_FOUND'));
check($body['error']['code'] === 404, 'Error code');
check($body['error']['status'] === 'NOT_FOUND', 'Error status');
check($body['error']['message'] !== '', 'Default message');
check($body['error']['details'][0]['domain'] === 'cacti.net', 'ErrorInfo domain');
check($body['error']['details'][0]['reason'] === 'DEVICE_NOT_FOUND', 'ErrorInfo reason');
check(count($body['error']['details']) === 1, 'No BadRequest without violations');
$body = mcp_error_body(new AbilitiesError('INVALID_ARGUMENT', 'INVALID_FILTER', 'bad', [['field' => 'filter', 'description' => 'oops']]));
check($body['error']['message'] === 'bad', 'Explicit message');
check($body['error']['details'][1]['fieldViolations'][0]['field'] === 'filter', 'BadRequest');
check((new AbilitiesError('INVALID_ARGUMENT', 'X', '', [], 413))->httpStatus() === 413, 'httpCode override');
check((new AbilitiesError('UNAVAILABLE', 'X'))->httpStatus() === 503, 'Unavailable map');

// page_roundtrip, bound to query, clamped
check(mcp_page((object) ['pageToken' => mcp_page_token(50, 'f1')], 'f1')['offset'] === 50, 'Page roundtrip');
check(mcp_page_token(50, 'f1') === rtrim(strtr(base64_encode('{"o":50,"f":"f1"}'), '+/', '-_'), '='), 'Token encoding');
rejects(fn () => mcp_page((object) ['pageToken' => mcp_page_token(50, 'f1')], 'f2'), 'INVALID_PAGE_TOKEN');
rejects(fn () => mcp_page((object) ['pageToken' => '!!!'], 'f1'), 'INVALID_PAGE_TOKEN');
check(mcp_page((object) ['pageSize' => 500], 'f1')['size'] === 100, 'Page size clamped');
check(mcp_page((object) ['pageSize' => 0], 'f1')['size'] === 50, 'Zero page size');
check(mcp_page(new stdClass(), 'f1') === ['size' => 50, 'offset' => 0], 'Defaults');
rejects(fn () => mcp_page((object) ['pageSize' => -1], 'f1'), 'INVALID_ARGUMENTS');

// filters
check(mcp_filter_sql('hostname = "a" AND disabled = true', $fields) === "h1.hostname = 'a' AND h1.disabled = 'on'", 'Filter basic');
check(mcp_filter_sql('', $fields) === '', 'Empty filter');
check(mcp_filter_sql('count >= 5 AND site = "sites/7" AND status = "up"', $fields) === "h1.count >= 5 AND h1.site_id = 7 AND h1.status = 3", 'Typed filter');
check(mcp_filter_sql('hostname = "a\'b"', $fields) === "h1.hostname = 'a\\'b'", 'Filter escaping');
rejects(fn () => mcp_filter_sql('nope = "a"', $fields), 'INVALID_FILTER');
rejects(fn () => mcp_filter_sql('secret = "a"', $fields), 'INVALID_FILTER');
rejects(fn () => mcp_filter_sql('hostname = 5 OR', $fields), 'INVALID_FILTER');
rejects(fn () => mcp_filter_sql('disabled < true', $fields), 'INVALID_FILTER');
rejects(fn () => mcp_filter_sql('hostname = true', $fields), 'INVALID_FILTER');
rejects(fn () => mcp_filter_sql('status = "sideways"', $fields), 'INVALID_FILTER');
try {
	mcp_filter_sql('nope = 1', $fields);
} catch (AbilitiesError $e) {
	check($e->violations[0]['field'] === 'filter', 'Filter violation field');
}

// order
check(mcp_order_sql('displayName desc', $fields, 'h1.id') === 'h1.description DESC', 'Order desc');
check(mcp_order_sql('', $fields, 'h1.id') === 'h1.id', 'Order default');
check(mcp_order_sql('hostname, displayName asc', $fields, 'h1.id') === 'h1.hostname ASC, h1.description ASC', 'Order multi');
rejects(fn () => mcp_order_sql('disabled', $fields, 'h1.id'), 'INVALID_ARGUMENTS');
rejects(fn () => mcp_order_sql('bogus', $fields, 'h1.id'), 'INVALID_ARGUMENTS');

print "AIP error, paging, filter, and order checks passed.\n";
