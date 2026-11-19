<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

/* AIP-style HTTP API for abilities. Authenticates with a personal token only;
 * see openapi.json for the routes. */

ini_set('display_errors', '0');
require_once __DIR__ . '/lib/bootstrap.php';

$mcp_bootstrap_options = ['methods' => ['GET', 'POST'], 'content_type' => 'application/json', 'max_bytes' => 65536, 'bearer' => true];
$mcp_request           = require __DIR__ . '/lib/bootstrap_cacti.php';

require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/providers.php';
require_once __DIR__ . '/lib/api_http.php';

try {
	$mcp_principal = mcp_authenticate($mcp_request['authorization']);
	$mcp_response  = mcp_api_handle($mcp_request['method'], $_SERVER['PATH_INFO'] ?? '', $_GET, $mcp_request['body'],
		mcp_registry(), $mcp_principal,
		fn ($id, $name, $outcome) => mcp_audit($mcp_principal, $id, $name, $outcome));
	$mcp_json = json_encode($mcp_response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
	$mcp_response = mcp_api_error($error);
	$mcp_json     = json_encode($mcp_response['body'], JSON_UNESCAPED_SLASHES);
}

http_response_code($mcp_response['status']);

foreach ($mcp_response['headers'] as $name => $value) {
	header($name . ': ' . $value);
}
print $mcp_json;
