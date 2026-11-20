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

/** One entry per plugin endpoint response, for C8. @var array<int, array{label: string, cookie: bool}> */
$responses = [];

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
 * With $jar, cookies are sent from and saved to that file. Responses from the plugin
 * endpoints are recorded so C8 can assert none of them set a cookie.
 *
 * @param array<int, string> $headers
 * @return array{status: int, headers: array<string, array<int, string>>, body: string}
 */
function http(string $method, string $url, array $headers = [], ?string $body = null, ?string $jar = null) : array {
	global $responses;

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

	if ($jar !== null) {
		curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
		curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
	}
	$response = curl_exec($curl);

	if ($response === false) {
		$error = curl_error($curl);
		curl_close($curl);
		throw new RuntimeException("$method request failed: $error");
	}
	$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	curl_close($curl);

	if (str_contains($url, '/plugins/mcp/')) {
		$responses[] = ['label' => "$method " . substr($url, strlen(BASE)) . " ($status)", 'cookie' => isset($responseHeaders['set-cookie'])];
	}

	return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response];
}

/** A modern-revision (2026-07-28) MCP request with the headers and _meta the server requires. */
function mcp_modern(string $token, string $method, array $params = [], string|int $id = 1, array $extra = []) : array {
	$params['_meta'] = [VERSION_META => MODERN];
	$headers         = [
		'Authorization: Bearer ' . $token,
		'Content-Type: application/json',
		'Accept: application/json, text/event-stream',
		'Mcp-Protocol-Version: ' . MODERN,
		'Mcp-Method: ' . $method,
	];
	$headers = array_merge($headers, $extra);

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

const API_URL = BASE . '/plugins/mcp/api.php';

/** A legacy (2025-11-25) MCP request: no version header and no _meta revision. */
function mcp_legacy(string $token, string $method, array $params = [], string|int $id = 1) : array {
	$message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

	if ($params) {
		$message['params'] = $params;
	}

	return http('POST', MCP_URL, [
		'Authorization: Bearer ' . $token,
		'Content-Type: application/json',
		'Accept: application/json, text/event-stream',
	], json_encode($message, JSON_THROW_ON_ERROR));
}

/** One request to api.php. $path starts with /v1. */
function api(string $token, string $method, string $path, ?string $body = null) : array {
	$headers = ['Authorization: Bearer ' . $token];

	if ($body !== null) {
		$headers[] = 'Content-Type: application/json';
	}

	return http($method, API_URL . $path, $headers, $body);
}

/** The decoded JSON body, or null when it is not JSON. */
function decode(array $response) : mixed {
	return json_decode($response['body'], true);
}

/** The error reason: AIP-193 bodies keep it in error.details[0], JSON-RPC ones in error.data. */
function error_reason(array $response) : ?string {
	$json = decode($response);

	return $json['error']['details'][0]['reason'] ?? $json['error']['data']['reason'] ?? null;
}

function error_status(array $response) : ?string {
	$json = decode($response);

	return $json['error']['status'] ?? $json['error']['data']['status'] ?? null;
}

/** A failure reason when the status differs, else null. */
function want_status(array $response, int $status) : ?string {
	return $response['status'] === $status ? null : 'status ' . $response['status'] . ', expected ' . $status;
}

/** The tool names of a tools/list response. @return array<int, string>|string A failure reason when the response is not a tool list. */
function tool_names(array $response) : array|string {
	$tools = decode($response)['result']['tools'] ?? null;

	if (!is_array($tools)) {
		return 'response has no result.tools';
	}
	$names = array_column($tools, 'name');
	sort($names);

	return $names;
}

/** The hidden CSRF input of a rendered form, found by what it is rather than by a fixed name. @return array{0: string, 1: string} name and value */
function csrf_field(string $html) : array {
	if (preg_match_all('/<input\b[^>]*>/i', $html, $inputs)) {
		foreach ($inputs[0] as $input) {
			if (!preg_match('/\btype\s*=\s*([\'"]?)hidden\1/i', $input)
				|| !preg_match('/\bname\s*=\s*([\'"])([^\'"]*csrf[^\'"]*)\1/i', $input, $name)
				|| !preg_match('/\bvalue\s*=\s*([\'"])(.*?)\1/is', $input, $value)) {
				continue;
			}

			return [$name[2], html_entity_decode($value[2])];
		}
	}

	throw new RuntimeException('no CSRF token on page');
}

/**
 * Log in through the console login form and return the cookie jar path. Fails with a
 * clear reason when the form has no CSRF input or the login is refused. Check D3 reuses it.
 */
function web_login(string $user, string $passwordFile) : string {
	$jar = tempnam(sys_get_temp_dir(), 'jar');
	$page = http('GET', BASE . '/index.php', [], null, $jar);
	[$csrfName, $csrfValue] = csrf_field($page['body']);

	$form = http_build_query(['action' => 'login', 'login_username' => $user, 'login_password' => secret($passwordFile),
		$csrfName => $csrfValue]);
	$login = http('POST', BASE . '/index.php', ['Content-Type: application/x-www-form-urlencoded'], $form, $jar);

	if ($login['status'] !== 302 && $login['status'] !== 200) {
		throw new RuntimeException("login as $user returned status " . $login['status']);
	}
	$after = http('GET', BASE . '/about.php', [], null, $jar);

	if ($after['status'] !== 200 || str_contains($after['body'], 'login_username')) {
		throw new RuntimeException("login as $user was not accepted");
	}

	return $jar;
}

function phase_main(string $ref) : void {
	global $responses;

	$user     = secret('token-mcpuser');
	$apionly  = secret('token-apionly');
	$norealm  = secret('token-norealm');
	$revoked  = secret('token-revoked');
	$builtin  = ['cacti-get-device', 'cacti-get-system-info', 'cacti-list-devices'];

	check('C1', 'server/discover', function () use ($user) {
		$response = mcp_modern($user, 'server/discover');

		if ($reason = want_status($response, 200)) {
			return $reason;
		}
		$result = decode($response)['result'] ?? [];
		$versions = $result['supportedVersions'] ?? [];

		if (!in_array(MODERN, $versions, true) || !in_array('2025-11-25', $versions, true)) {
			return 'supportedVersions was [' . implode(', ', array_map('strval', $versions)) . ']';
		}

		return ($result['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? null) === 'cacti-mcp' ? null : 'result does not name the server';
	});

	check('C2', 'tools/list on 2026-07-28', function () use ($user, $builtin) {
		$response = mcp_modern($user, 'tools/list');

		if ($reason = want_status($response, 200)) {
			return $reason;
		}
		$names = tool_names($response);

		return $names === $builtin ? null : (is_string($names) ? $names : 'tool names were [' . implode(', ', $names) . ']');
	});

	check('C2', 'tools/list on legacy 2025-11-25', function () use ($user, $builtin) {
		$init = mcp_legacy($user, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(),
			'clientInfo' => ['name' => 'mcp-it', 'version' => '1']]);

		if ($reason = want_status($init, 200)) {
			return "initialize: $reason";
		}

		if ((decode($init)['result']['protocolVersion'] ?? null) !== '2025-11-25') {
			return 'initialize did not negotiate 2025-11-25';
		}
		$response = mcp_legacy($user, 'tools/list', [], 2);

		if ($reason = want_status($response, 200)) {
			return $reason;
		}
		$names = tool_names($response);

		return $names === $builtin ? null : (is_string($names) ? $names : 'tool names were [' . implode(', ', $names) . ']');
	});

	$version_ok = fn (string $version) => $ref === '1.2.31' ? $version === '1.2.31' : str_starts_with($version, '1.3');
	$call       = [
		'2026-07-28' => fn (string $name, array $arguments) => mcp_modern($user, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]),
		'2025-11-25' => fn (string $name, array $arguments) => mcp_legacy($user, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]),
	];

	foreach ($call as $revision => $send) {
		check('C3', "cacti-get-system-info on $revision", function () use ($send, $version_ok) {
			$response = $send('cacti-get-system-info', []);

			if ($reason = want_status($response, 200)) {
				return $reason;
			}
			$result = decode($response)['result'] ?? [];

			if (!empty($result['isError'])) {
				return 'result.isError is set';
			}
			$version = $result['structuredContent']['cactiVersion'] ?? null;

			return is_string($version) && $version_ok($version) ? null : 'cactiVersion is not the version under test';
		});

		check('C3', "cacti-list-devices on $revision", function () use ($send) {
			$response = $send('cacti-list-devices', []);

			if ($reason = want_status($response, 200)) {
				return $reason;
			}
			$result = decode($response)['result'] ?? [];

			if (!empty($result['isError'])) {
				return 'result.isError is set';
			}
			$names = array_column($result['structuredContent']['devices'] ?? [], 'displayName');
			$missing = array_diff(['it-alpha', 'it-beta'], $names);

			return $missing ? 'missing devices: ' . implode(', ', $missing) : null;
		});
	}

	check('C4', 'GET /v1/abilities', function () use ($user) {
		$response = api($user, 'GET', '/v1/abilities');

		if ($reason = want_status($response, 200)) {
			return $reason;
		}
		$abilities = decode($response)['abilities'] ?? null;

		return is_array($abilities) && count($abilities) === 3 ? null : 'abilities does not hold 3 entries';
	});

	check('C4', 'GET /v1/abilities/cacti-get-device', function () use ($user) {
		$response = api($user, 'GET', '/v1/abilities/cacti-get-device');

		if ($reason = want_status($response, 200)) {
			return $reason;
		}

		return (decode($response)['name'] ?? null) === 'abilities/cacti-get-device' ? null : 'name is not abilities/cacti-get-device';
	});

	check('C4', ':execute cacti-get-system-info', function () use ($user, $version_ok) {
		$response = api($user, 'POST', '/v1/abilities/cacti-get-system-info:execute', '{"arguments":{}}');

		if ($reason = want_status($response, 200)) {
			return $reason;
		}
		$json = decode($response);

		if (!preg_match('/^[0-9a-f]{32}$/D', (string) ($json['requestId'] ?? ''))) {
			return 'requestId is not 32 hex characters';
		}

		return is_string($json['result']['cactiVersion'] ?? null) && $version_ok($json['result']['cactiVersion']) ? null : 'result.cactiVersion is not the version under test';
	});

	// C5: the same four credentials on both endpoints.
	$jar = null;
	check('C5', 'login as mcpuser for the session-cookie case', function () use (&$jar) {
		$jar = web_login('mcpuser', 'mcpuser-password');

		return null;
	});

	$credentials = [
		'no Authorization'  => [[], null],
		'malformed bearer'  => [['Authorization: Bearer cmcp_pat_bad'], null],
		'revoked token'     => [['Authorization: Bearer ' . $revoked], null],
		'session cookie'    => [[], 'jar'],
	];

	foreach ($credentials as $label => [$headers, $useJar]) {
		foreach (['mcp.php' => 'POST', 'api.php' => 'GET'] as $endpoint => $method) {
			check('C5', "$label on $endpoint", function () use ($headers, $useJar, $endpoint, $method, &$jar) {
				if ($useJar !== null && $jar === null) {
					return 'no session: the login check failed';
				}
				$url  = BASE . '/plugins/mcp/' . $endpoint . ($endpoint === 'api.php' ? '/v1/abilities' : '');
				$body = null;

				if ($method === 'POST') {
					$headers[] = 'Content-Type: application/json';
					$body      = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
				}
				$response = http($method, $url, $headers, $body, $useJar !== null ? $jar : null);

				if ($reason = want_status($response, 401)) {
					return $reason;
				}

				if (!in_array('Bearer', $response['headers']['www-authenticate'] ?? [], true)) {
					return 'WWW-Authenticate is not Bearer';
				}

				return is_array(decode($response)) ? null : 'body is not JSON';
			});
		}
	}

	foreach (['mcp.php' => fn () => mcp_modern($norealm, 'tools/list'), 'api.php' => fn () => api($norealm, 'GET', '/v1/abilities')] as $endpoint => $send) {
		check('C6', "norealm on $endpoint", function () use ($send) {
			$response = $send();

			if ($reason = want_status($response, 403)) {
				return $reason;
			}

			return error_status($response) === 'PERMISSION_DENIED' && error_reason($response) === 'REALM_REQUIRED' ? null : 'error is not PERMISSION_DENIED/REALM_REQUIRED';
		});
	}

	check('C6b', 'apionly tools/list is empty', function () use ($apionly) {
		$response = mcp_modern($apionly, 'tools/list');

		if ($reason = want_status($response, 200)) {
			return $reason;
		}

		return (decode($response)['result']['tools'] ?? null) === [] ? null : 'result.tools is not an empty list';
	});

	check('C6b', 'apionly :execute is ABILITY_NOT_FOUND, same as an unknown id', function () use ($apionly) {
		$denied  = api($apionly, 'POST', '/v1/abilities/cacti-get-device:execute', '{"arguments":{"name":"devices/1"}}');
		$unknown = api($apionly, 'POST', '/v1/abilities/cacti-no-such-ability:execute', '{"arguments":{"name":"devices/1"}}');

		foreach ([$denied, $unknown] as $response) {
			if ($reason = want_status($response, 404)) {
				return $reason;
			}

			if (error_reason($response) !== 'ABILITY_NOT_FOUND') {
				return 'reason is not ABILITY_NOT_FOUND';
			}
		}

		return $denied['body'] === $unknown['body'] ? null : 'the two bodies differ';
	});

	check('C6b', 'apionly tools/call is an isError result', function () use ($apionly) {
		$response = mcp_modern($apionly, 'tools/call', ['name' => 'cacti-get-device', 'arguments' => ['name' => 'devices/1']]);

		if ($reason = want_status($response, 200)) {
			return $reason;
		}

		return (decode($response)['result']['isError'] ?? null) === true ? null : 'result.isError is not true';
	});

	$methods = [
		'GET mcp.php'            => ['GET', MCP_URL],
		'PUT mcp.php'            => ['PUT', MCP_URL],
		'PUT api.php/v1/abilities' => ['PUT', API_URL . '/v1/abilities'],
	];

	foreach ($methods as $label => [$method, $url]) {
		check('C7', $label, function () use ($method, $url, $user) {
			$response = http($method, $url, ['Authorization: Bearer ' . $user]);

			if ($reason = want_status($response, 405)) {
				return $reason;
			}

			if (!preg_match('/\bPOST\b/', implode(',', $response['headers']['allow'] ?? []))) {
				return 'Allow does not contain POST';
			}

			return $response['body'] === '' || is_array(decode($response)) ? null : 'body is neither empty nor JSON';
		});
	}

	// C1-C7 are done; C9 and D1 add more responses that the same rule covers, so C8 runs last of them.
	check('C9', 'no Origin', fn () => want_status(mcp_modern($user, 'tools/list'), 200));
	check('C9', 'Origin http://localhost', fn () => want_status(mcp_modern($user, 'tools/list', [], 1, ['Origin: http://localhost']), 200));
	check('C9', 'Origin https://evil.test', function () use ($user) {
		$response = mcp_modern($user, 'tools/list', [], 1, ['Origin: https://evil.test']);

		if ($reason = want_status($response, 403)) {
			return $reason;
		}

		return (decode($response)['error']['data']['reason'] ?? null) === 'ORIGIN_NOT_ALLOWED' ? null : 'error.data.reason is not ORIGIN_NOT_ALLOWED';
	});
	check('C9', 'Origin null', fn () => want_status(mcp_modern($user, 'tools/list', [], 1, ['Origin: null']), 403));

	if ($ref === 'develop') {
		check('D1', 'api.php/v1/abilities through the nginx block', function () use ($user) {
			$response = api($user, 'GET', '/v1/abilities');

			if ($reason = want_status($response, 200)) {
				return $reason;
			}

			if (!str_starts_with($response['headers']['content-type'][0] ?? '', 'application/json')) {
				return 'Content-Type is not application/json';
			}

			return str_contains($response['body'], '<html') || str_contains($response['body'], 'UNKNOWN_PATH') ? 'body is an HTML page or UNKNOWN_PATH' : null;
		});
	} else {
		print "SKIP D1 nginx only\n";
	}

	check('C8', 'no Set-Cookie on any mcp.php or api.php response', function () use (&$responses) {
		if (!$responses) {
			return 'no responses were recorded';
		}
		$bad = array_column(array_filter($responses, fn ($r) => $r['cookie']), 'label');

		return $bad ? 'Set-Cookie on: ' . implode('; ', array_slice($bad, 0, 5)) : null;
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
		phase_main($ref);
		break;
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
