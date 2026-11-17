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
		throw new AbilitiesError(405, 'method_not_allowed');
	}
	$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
	if (!preg_match('/^Bearer [a-f0-9]{64}$/D', $authorization)) {
		throw new AbilitiesError(401, 'invalid_token');
	}
	if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
		throw new AbilitiesError(413, 'request_too_large');
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
	if (!api_plugin_is_enabled('abilities') || (int) $config['poller_id'] !== 1) {
		throw new AbilitiesError(503, 'plugin_unavailable');
	}
	// Browser session identity never grants API access. All helpers get this UID.
	$principal = abilities_authenticate($authorization);
	$registry = abilities_registry();
	if ($method === 'GET') {
		$response = ['apiVersion' => '1', 'abilities' => $registry->discover($principal),
			'csrfToken' => csrf_get_tokens()];
	} else {
		$contentType = explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0];
		if ($contentType !== 'application/x-www-form-urlencoded') {
			throw new AbilitiesError(415, 'unsupported_media_type');
		}
		$input = $_POST['request'] ?? '';
		if (!is_string($input) || strlen($input) > 16384) {
			throw new AbilitiesError(400, 'invalid_request');
		}
		try {
			$request = json_decode($input, false, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new AbilitiesError(400, 'invalid_request');
		}
		if (!$request instanceof stdClass || !is_string($request->name ?? null)
			|| !($request->arguments ?? null) instanceof stdClass
			|| array_diff(array_keys(get_object_vars($request)), ['name', 'arguments'])) {
			throw new AbilitiesError(400, 'invalid_request');
		}
		$response = $registry->execute($principal, $request->name, $request->arguments,
			fn($id, $name, $outcome) => abilities_audit($principal, $id, $name, $outcome));
	}
	print json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
	$status = $error instanceof AbilitiesError ? $error->status : 500;
	$code = $error instanceof AbilitiesError ? $error->errorCode : 'internal_error';
	http_response_code($status);
	if ($status === 401) {
		header('WWW-Authenticate: Bearer');
	}
	print json_encode(['error' => ['code' => $code]]);
}
