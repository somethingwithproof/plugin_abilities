<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

/* MCP endpoint: serves the abilities a personal token may read as MCP tools
 * over Streamable HTTP. POST only; the bootstrap answers other methods with 405. */

ini_set('display_errors', '0');
require_once __DIR__ . '/lib/bootstrap.php';

$mcp_bootstrap_options = ['methods' => ['POST'], 'content_type' => 'application/json', 'max_bytes' => 65536, 'bearer' => true];
$mcp_request           = require __DIR__ . '/lib/bootstrap_cacti.php';

require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/providers.php';
require_once __DIR__ . '/lib/origin.php';
require_once __DIR__ . '/lib/api_http.php';
require_once __DIR__ . '/lib/mcp_server.php';

try {
	$mcp_public = mcp_public_url();

	if ($mcp_public === null) {
		$mcp_response = mcp_rpc_error(503, -32000, 'The Public Cacti URL is not configured.',
			['status' => 'FAILED_PRECONDITION', 'reason' => 'PUBLIC_URL_REQUIRED']);
	} elseif (!mcp_origin_allowed($_SERVER['HTTP_ORIGIN'] ?? null, $mcp_public)) {
		$mcp_response = mcp_rpc_error(403, -32000, 'The Origin is not allowed.',
			['status' => 'PERMISSION_DENIED', 'reason' => 'ORIGIN_NOT_ALLOWED']);
	} else {
		// Authentication failures keep the AIP-193 body, like the bootstrap rejections.
		try {
			$mcp_principal = mcp_authenticate($mcp_request['authorization']);
		} catch (AbilitiesError $error) {
			$mcp_response = mcp_api_error($error);
		}

		if (!isset($mcp_response)) {
			$mcp_info     = parse_ini_file(__DIR__ . '/INFO', true);
			$mcp_response = mcp_rpc_handle($mcp_request['method'], mcp_request_headers($_SERVER), $mcp_request['body'],
				mcp_registry(), $mcp_principal,
				fn ($id, $name, $outcome) => mcp_audit($mcp_principal, $id, $name, $outcome),
				['name' => 'cacti-mcp', 'version' => (string) ($mcp_info['info']['version'] ?? '0')]);
		}
	}
	$mcp_json = $mcp_response['body'] === null ? '' : json_encode($mcp_response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
	$mcp_response = mcp_api_error($error);
	$mcp_json     = json_encode($mcp_response['body'], JSON_UNESCAPED_SLASHES);
}

http_response_code($mcp_response['status']);

foreach ($mcp_response['headers'] as $name => $value) {
	header($name . ': ' . $value);
}
print $mcp_json;
