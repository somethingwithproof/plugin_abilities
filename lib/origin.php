<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

/**
 * Split an absolute http(s) URL into scheme, host, effective port and path.
 * Returns null for anything that is not a plain origin-plus-path URL.
 *
 * @return array{scheme: string, host: string, port: int, path: string}|null
 */
function mcp_parse_url(string $url) : ?array {
	if (!preg_match('~^(https?)://([^/?#]*)([^?#]*)(?:\?[^#]*)?(?:#.*)?$~i', $url, $parts)) {
		return null;
	}

	$scheme    = strtolower($parts[1]);
	$authority = $parts[2];
	$path      = $parts[3];

	// Userinfo would let "https://cacti.example.com@evil.example" pass a prefix check.
	if (!preg_match('~^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]+))?$~', $authority, $hostParts)) {
		return null;
	}

	$host = strtolower($hostParts[1]);

	if ($host[0] === '[') {
		if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
			return null;
		}
	} elseif (!preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$~', $host)) {
		return null;
	}

	$defaultPort = $scheme === 'https' ? 443 : 80;
	$port        = $defaultPort;

	if (($hostParts[2] ?? '') !== '') {
		$port = (int) $hostParts[2];

		if ($port < 1 || $port > 65535) {
			return null;
		}
	}

	if ($path !== '' && !preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#', $path)) {
		return null;
	}

	return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => rtrim($path, '/')];
}

function mcp_is_loopback_host(string $host) : bool {
	return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

function mcp_origin_string(array $parts) : string {
	$defaultPort = $parts['scheme'] === 'https' ? 443 : 80;

	return $parts['scheme'] . '://' . $parts['host']
		. ($parts['port'] === $defaultPort ? '' : ':' . $parts['port']);
}

/**
 * Normalize a configured public URL: lowercase scheme and host, default port
 * dropped, no trailing slash, query or fragment. HTTPS is required except for
 * loopback hosts. Returns null when the value is not acceptable.
 */
function mcp_normalize_public_url(string $value) : ?string {
	$parts = mcp_parse_url(trim($value));

	if ($parts === null) {
		return null;
	}

	if ($parts['scheme'] !== 'https' && !mcp_is_loopback_host($parts['host'])) {
		return null;
	}

	return mcp_origin_string($parts) . $parts['path'];
}

function mcp_public_url() : ?string {
	$value = read_config_option('mcp_public_url');

	return is_string($value) ? mcp_normalize_public_url($value) : null;
}

function mcp_canonical_uri(string $publicUrl) : string {
	return $publicUrl . '/plugins/mcp/mcp.php';
}

/**
 * An absent Origin header (non-browser client) is allowed. Otherwise the
 * header must be exactly the scheme, host and effective port of the public URL.
 */
function mcp_origin_allowed(?string $origin, string $publicUrl) : bool {
	if ($origin === null) {
		return true;
	}

	$allowed = mcp_parse_url($publicUrl);
	$given   = mcp_parse_url($origin);

	// A browser Origin never carries a path, so one means the value is not an origin.
	if ($allowed === null || $given === null || $given['path'] !== '' || str_ends_with($origin, '/')) {
		return false;
	}

	return $given['scheme'] === $allowed['scheme']
		&& $given['host'] === $allowed['host']
		&& $given['port'] === $allowed['port'];
}
