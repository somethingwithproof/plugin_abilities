<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/origin.php';

$options = [];

function read_config_option($name, $force = false) {
	global $options;

	return $options[$name] ?? null;
}

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

// normalizes
check(mcp_normalize_public_url('HTTPS://Cacti.Example.com/cacti/') === 'https://cacti.example.com/cacti', 'normalizes');
check(mcp_normalize_public_url("  https://cacti.example.com \n") === 'https://cacti.example.com', 'whitespace trimmed, empty path allowed');
check(mcp_normalize_public_url('https://cacti.example.com/') === 'https://cacti.example.com', 'root slash dropped');
check(mcp_normalize_public_url('https://cacti.example.com:443/cacti') === 'https://cacti.example.com/cacti', 'https default port dropped');
check(mcp_normalize_public_url('https://cacti.example.com:8443/cacti') === 'https://cacti.example.com:8443/cacti', 'explicit port kept');
check(mcp_normalize_public_url('https://cacti.example.com/cacti?x=1#frag') === 'https://cacti.example.com/cacti', 'query and fragment removed');

// rejects_http_public
check(mcp_normalize_public_url('http://cacti.example.com') === null, 'rejects_http_public');
check(mcp_normalize_public_url('http://localhost:8080/cacti') === 'http://localhost:8080/cacti', 'http localhost');
check(mcp_normalize_public_url('http://127.0.0.1/cacti') === 'http://127.0.0.1/cacti', 'http loopback');
check(mcp_normalize_public_url('http://localhost:80') === 'http://localhost', 'http default port dropped');
check(mcp_normalize_public_url('http://[::1]:8443/cacti') === 'http://[::1]:8443/cacti', 'http IPv6 loopback');
check(mcp_normalize_public_url('https://[::1]:8443/cacti') === 'https://[::1]:8443/cacti', 'https IPv6 loopback');
check(mcp_normalize_public_url('https://[2001:DB8::1]/cacti') === 'https://[2001:db8::1]/cacti', 'IPv6 host lowercased');
check(mcp_normalize_public_url('http://[2001:db8::1]/cacti') === null, 'http non-loopback IPv6');

foreach ([
	'', '   ', 'cacti.example.com', 'ftp://cacti.example.com', 'https://', 'https:///cacti',
	'https://user:pass@cacti.example.com', 'https://user@cacti.example.com',
	'https://cacti.example.com:0', 'https://cacti.example.com:65536', 'https://cacti.example.com:abc',
	'https://bad host.example.com', 'https://[::1', 'https://[nope]/', "https://cacti.example.com/a b",
] as $bad) {
	check(mcp_normalize_public_url($bad) === null, 'must reject: ' . var_export($bad, true));
}

// mcp_public_url reads the setting
check(mcp_public_url() === null, 'unset gives null');
$options['mcp_public_url'] = 'HTTPS://Cacti.Example.com/cacti/';
check(mcp_public_url() === 'https://cacti.example.com/cacti', 'setting normalized');
$options['mcp_public_url'] = 'http://cacti.example.com';
check(mcp_public_url() === null, 'invalid setting gives null');
$options['mcp_public_url'] = false;
check(mcp_public_url() === null, 'non-string setting gives null');

check(mcp_canonical_uri('https://cacti.example.com/cacti') === 'https://cacti.example.com/cacti/plugins/mcp/mcp.php', 'canonical uri');

// origin checks
$public = 'https://cacti.example.com/cacti';
check(mcp_origin_allowed(null, $public) === true, 'absent origin allowed');
check(mcp_origin_allowed('https://cacti.example.com', $public) === true, 'origin_match');
check(mcp_origin_allowed('https://CACTI.example.com', $public) === true, 'origin host case');
check(mcp_origin_allowed('https://evil.example', $public) === false, 'origin_mismatch');
check(mcp_origin_allowed('https://cacti.example.com:443', $public) === true, 'default_port');
check(mcp_origin_allowed('https://cacti.example.com:8443', $public) === false, 'other port');
check(mcp_origin_allowed('http://cacti.example.com', $public) === false, 'scheme mismatch');
check(mcp_origin_allowed('https://cacti.example.com.evil.example', $public) === false, 'suffix host');
check(mcp_origin_allowed('https://cacti.example.com/cacti', $public) === false, 'origin with path');
check(mcp_origin_allowed('https://user@cacti.example.com', $public) === false, 'origin with userinfo');
check(mcp_origin_allowed('null', $public) === false, 'opaque origin');
check(mcp_origin_allowed('', $public) === false, 'empty origin');
check(mcp_origin_allowed('https://cacti.example.com', 'http://cacti.example.com') === false, 'invalid public url');
check(mcp_origin_allowed('https://cacti.example.com:8443', 'https://cacti.example.com:8443/c') === true, 'explicit port match');
check(mcp_origin_allowed('https://cacti.example.com', 'https://cacti.example.com:8443/c') === false, 'explicit port mismatch');
check(mcp_origin_allowed('https://[::1]:8443', 'https://[::1]:8443/cacti') === true, 'IPv6 origin');
check(mcp_origin_allowed('http://[::1]:8443', 'https://[::1]:8443/cacti') === false, 'IPv6 scheme mismatch');
check(mcp_origin_allowed('http://localhost:80', 'http://localhost/cacti') === true, 'http default port');

print "Public URL normalization and origin checks passed.\n";
