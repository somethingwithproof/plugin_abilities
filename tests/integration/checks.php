<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

/* Live checks against a running Cacti with the plugin installed. run.sh starts
 * this in the checks container, which shares the web container's network so the
 * address http://localhost/cacti matches the Public Cacti URL.
 *
 * Usage: php checks.php --ref=<1.2.31|develop> --phase=<smoke|main|d3-open|d3-finish|db-down|db-up>
 *
 * Every check prints PASS or FAIL and the script keeps going after a failure.
 * Reasons carry statuses and field names only, never a token or a header value
 * that could hold one. Exit status is 1 when any check failed. */

const BASE    = 'http://localhost/cacti';
const SECRETS = '/run/mcp-it';
const MCP_URL = BASE . '/plugins/mcp/mcp.php';
const MODERN  = '2026-07-28';
const VERSION_META = 'io.modelcontextprotocol/protocolVersion';

$failures = 0;

/** The raw value of a secret file, read at use. */
function secret(string $name) : string {
	$value = @file_get_contents(SECRETS . '/' . $name);

	if (!is_string($value) || $value === '') {
		throw new RuntimeException("secret file $name is missing or empty");
	}

	return trim($value);
}

/**
 * One HTTP request. Returns status, lowercase header names mapped to value lists, and the body.
 *
 * @param array<int, string> $headers
 * @return array{status: int, headers: array<string, array<int, string>>, body: string}
 */
function http(string $method, string $url, array $headers = [], ?string $body = null) : array {
	$responseHeaders = [];
	$curl            = curl_init($url);
	curl_setopt_array($curl, [
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders) {
			if (str_contains($line, ':')) {
				[$name, $value] = explode(':', $line, 2);
				$responseHeaders[strtolower(trim($name))][] = trim($value);
			}

			return strlen($line);
		},
	]);

	if ($body !== null) {
		curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
	}
	$response = curl_exec($curl);

	if ($response === false) {
		$error = curl_error($curl);
		curl_close($curl);
		throw new RuntimeException("$method request failed: $error");
	}
	$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	curl_close($curl);

	return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response];
}

/** A modern-revision (2026-07-28) MCP request with the headers and _meta the server requires. */
function mcp_modern(string $token, string $method, array $params = [], string|int $id = 1) : array {
	$params['_meta'] = [VERSION_META => MODERN];
	$headers         = [
		'Authorization: Bearer ' . $token,
		'Content-Type: application/json',
		'Accept: application/json, text/event-stream',
		'Mcp-Protocol-Version: ' . MODERN,
		'Mcp-Method: ' . $method,
	];

	if ($method === 'tools/call') {
		$headers[] = 'Mcp-Name: ' . ($params['name'] ?? '');
	}

	return http('POST', MCP_URL, $headers, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
}

/** Run one check. The closure returns null for a pass or a reason string for a failure. */
function check(string $id, string $name, callable $test) : void {
	global $failures;

	try {
		$reason = $test();
	} catch (Throwable $error) {
		$reason = get_class($error) . ': ' . $error->getMessage();
	}

	if ($reason === null) {
		print "PASS $id $name\n";
	} else {
		$failures++;
		print "FAIL $id $name: $reason\n";
	}
}

function phase_smoke() : void {
	check('S1', 'tools/list', function () {
		$response = mcp_modern(secret('token-mcpuser'), 'tools/list');

		if ($response['status'] !== 200) {
			return 'status ' . $response['status'] . ', expected 200';
		}
		$json = json_decode($response['body'], true);
		$tools = $json['result']['tools'] ?? null;

		if (!is_array($tools)) {
			return 'response has no result.tools';
		}
		$names = array_column($tools, 'name');
		sort($names);
		$expected = ['cacti-get-device', 'cacti-get-system-info', 'cacti-list-devices'];

		return $names === $expected ? null : 'tool names were [' . implode(', ', $names) . ']';
	});
}

$options = getopt('', ['ref:', 'phase:']);
$ref     = $options['ref'] ?? '';
$phase   = $options['phase'] ?? '';

if (!in_array($ref, ['1.2.31', 'develop'], true)) {
	fwrite(STDERR, "usage: checks.php --ref=<1.2.31|develop> --phase=<smoke|main|d3-open|d3-finish|db-down|db-up>\n");
	exit(2);
}

switch ($phase) {
	case 'smoke':
		phase_smoke();
		break;
	case 'main':
	case 'd3-open':
	case 'd3-finish':
	case 'db-down':
	case 'db-up':
		fwrite(STDERR, "phase $phase is not implemented yet\n");
		exit(1);
	default:
		fwrite(STDERR, "unknown phase\n");
		exit(2);
}

exit($failures === 0 ? 0 : 1);
