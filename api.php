<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/lib/registry.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
	$method = $_SERVER['REQUEST_METHOD'] ?? '';

	if (!in_array($method, ['GET', 'POST'], true)) {
		header('Allow: GET, POST');

		throw new AbilitiesError('INVALID_ARGUMENT', 'METHOD_NOT_ALLOWED', '', [], 405);
	}
	$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

	if (!preg_match('/^Bearer [a-f0-9]{64}$/D', $authorization)) {
		throw new AbilitiesError('UNAUTHENTICATED', 'INVALID_TOKEN');
	}

	if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
		throw new AbilitiesError('INVALID_ARGUMENT', 'REQUEST_TOO_LARGE', '', [], 413);
	}
	// Cacti handles its normal session and CSRF checks, including POST failures.
	$bufferLevel = ob_get_level();
	ob_start();
	require __DIR__ . '/../../include/global.php';

	while (ob_get_level() > $bufferLevel) {
		ob_end_clean();
	}
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	require_once __DIR__ . '/lib/storage.php';
	require_once __DIR__ . '/lib/providers.php';

	if (!api_plugin_is_enabled('mcp') || (int) $config['poller_id'] !== 1) {
		throw new AbilitiesError('UNAVAILABLE', 'PLUGIN_UNAVAILABLE');
	}
	// Browser session identity never grants API access. All helpers get this UID.
	$principal = mcp_authenticate($authorization);
	$registry  = mcp_registry();

	if ($method === 'GET') {
		$response = ['apiVersion' => '1', 'abilities' => $registry->discover($principal),
			'csrfToken'              => csrf_get_tokens()];
	} else {
		$contentType = explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0];

		if ($contentType !== 'application/x-www-form-urlencoded') {
			throw new AbilitiesError('INVALID_ARGUMENT', 'UNSUPPORTED_MEDIA_TYPE', '', [], 415);
		}
		$input = $_POST['request'] ?? '';

		if (!is_string($input) || strlen($input) > 16384) {
			throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_REQUEST');
		}

		try {
			$request = json_decode($input, false, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_REQUEST');
		}

		if (!$request instanceof stdClass || !is_string($request->name ?? null)
			|| !($request->arguments ?? null) instanceof stdClass
			|| array_diff(array_keys(get_object_vars($request)), ['name', 'arguments'])) {
			throw new AbilitiesError('INVALID_ARGUMENT', 'INVALID_REQUEST');
		}
		$response = $registry->execute($principal, $request->name, $request->arguments,
			fn ($id, $name, $outcome) => mcp_audit($principal, $id, $name, $outcome));
	}
	print json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
	$status = $error instanceof AbilitiesError ? $error->httpStatus() : 500;
	$code   = $error instanceof AbilitiesError ? $error->reason : 'INTERNAL_ERROR';
	http_response_code($status);

	if ($status === 401) {
		header('WWW-Authenticate: Bearer');
	}
	print json_encode(['error' => ['code' => $code]]);
}
