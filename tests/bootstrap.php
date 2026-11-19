<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

require_once __DIR__ . '/../lib/bootstrap.php';

function check(bool $ok, string $message) : void {
	if (!$ok) {
		throw new RuntimeException($message);
	}
}

function rejects(callable $action, string $reason, int $code) : void {
	try {
		$action();
	} catch (AbilitiesError $error) {
		check($error->reason === $reason, 'Unexpected reason ' . $error->reason);
		check($error->httpStatus() === $code, 'Unexpected status ' . $error->httpStatus());

		return;
	}

	throw new RuntimeException('Expected rejection: ' . $reason);
}

$token   = 'Bearer cmcp_pat_' . str_repeat('ab', 32);
$options = ['methods' => ['POST'], 'content_type' => 'application/json', 'max_bytes' => 65536, 'bearer' => true];
$post    = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '10'];

// strip_identity
$_GET    = ['filter' => 'name = "x"'];
$postVar = ['__csrf_magic' => 'sid:abc,123'];
$request = ['__csrf_magic' => 'sid:abc,123', 'action' => 'save'];
$cookie  = ['Cacti' => 'session-id', 'CactiTab' => '1'];
mcp_strip_identity($postVar, $request, $cookie);
check($postVar === [] && $request === [] && $cookie === [], 'Identity arrays not emptied');
check($_GET === ['filter' => 'name = "x"'], '$_GET changed');

// hide_post_method
$server = ['REQUEST_METHOD' => 'POST'];
mcp_hide_post_method($server);
check($server['REQUEST_METHOD'] === 'MCP_POST', 'Method not rewritten');
check($server['MCP_ORIGINAL_METHOD'] === 'POST', 'Original method not kept');

// authorization_header_sources
check(mcp_authorization_header(['HTTP_AUTHORIZATION' => $token], null) === $token, 'HTTP_AUTHORIZATION ignored');
check(mcp_authorization_header(['REDIRECT_HTTP_AUTHORIZATION' => $token], null) === $token, 'REDIRECT_ fallback ignored');
check(mcp_authorization_header(['HTTP_AUTHORIZATION' => ''], ['aUtHoRiZaTiOn' => $token]) === $token,
	'Apache header fallback is not case-insensitive');
check(mcp_authorization_header(['HTTP_AUTHORIZATION' => 'Bearer a'], ['Authorization' => $token]) === 'Bearer a',
	'Server variable must take precedence');
check(mcp_authorization_header([], null) === '', 'Missing header must be empty');

// authorization_never_from_query_body_or_cookie
$elsewhere = 'authorization=' . rawurlencode($token);
check(mcp_authorization_header([
	'QUERY_STRING' => $elsewhere,
	'REQUEST_URI'  => '/plugins/mcp/mcp.php?' . $elsewhere,
	'HTTP_COOKIE'  => $elsewhere,
], ['Cookie' => $elsewhere, 'Content-Type' => 'application/json']) === '', 'Header read from query or cookie');
check(mcp_authorization_header(['HTTP_AUTHORIZATION' => ['x']], ['Authorization' => ['x']]) === '',
	'Non-string header accepted');

// validate_accepts
mcp_bootstrap_validate($options, $post, $token);
mcp_bootstrap_validate($options, ['CONTENT_TYPE' => 'Application/JSON; charset=utf-8'] + $post, $token);
mcp_bootstrap_validate(['bearer' => false] + $options, $post, '');
mcp_bootstrap_validate(['methods' => ['GET', 'POST']] + $options, ['REQUEST_METHOD' => 'GET'], $token);

// validate_rejects
rejects(fn () => mcp_bootstrap_validate($options, ['REQUEST_METHOD' => 'GET'] + $post, $token), 'METHOD_NOT_ALLOWED', 405);
rejects(fn () => mcp_bootstrap_validate($options, ['REQUEST_METHOD' => 'post'] + $post, $token), 'METHOD_NOT_ALLOWED', 405);
rejects(fn () => mcp_bootstrap_validate($options, ['REQUEST_METHOD' => 'MCP_POST'] + $post, $token), 'METHOD_NOT_ALLOWED', 405);
rejects(fn () => mcp_bootstrap_validate($options, ['CONTENT_TYPE' => 'text/plain'] + $post, $token), 'UNSUPPORTED_MEDIA_TYPE', 415);
rejects(fn () => mcp_bootstrap_validate($options, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $post, $token),
	'UNSUPPORTED_MEDIA_TYPE', 415);
rejects(fn () => mcp_bootstrap_validate($options, array_diff_key($post, ['CONTENT_TYPE' => 1]), $token), 'UNSUPPORTED_MEDIA_TYPE', 415);
rejects(fn () => mcp_bootstrap_validate($options, ['CONTENT_LENGTH' => '65537'] + $post, $token), 'REQUEST_TOO_LARGE', 413);
rejects(fn () => mcp_bootstrap_validate($options, $post, ''), 'INVALID_TOKEN', 401);
rejects(fn () => mcp_bootstrap_validate($options, $post, $token . "\n"), 'INVALID_TOKEN', 401);
rejects(fn () => mcp_bootstrap_validate($options, $post, 'Bearer cmcp_pat_' . str_repeat('AB', 32)), 'INVALID_TOKEN', 401);
rejects(fn () => mcp_bootstrap_validate($options, $post, 'Basic dXNlcjpwYXNz'), 'INVALID_TOKEN', 401);

// method_not_allowed_status
try {
	mcp_bootstrap_validate($options, ['REQUEST_METHOD' => 'GET'] + $post, $token);
	throw new RuntimeException('Expected rejection');
} catch (AbilitiesError $error) {
	check($error->status === 'UNIMPLEMENTED' && $error->httpStatus() === 405, '405 must map to UNIMPLEMENTED');
}

// read_body_cap
$file = tempnam(sys_get_temp_dir(), 'mcp');
file_put_contents($file, str_repeat('x', 65536));
check(mcp_read_body($file, 65536) === str_repeat('x', 65536), 'Body at the limit rejected');
file_put_contents($file, str_repeat('x', 65537));
rejects(fn () => mcp_read_body($file, 65536), 'REQUEST_TOO_LARGE', 413);
unlink($file);

// error_messages_defined
foreach (['METHOD_NOT_ALLOWED', 'UNSUPPORTED_MEDIA_TYPE', 'REQUEST_TOO_LARGE', 'PLUGIN_UNAVAILABLE'] as $reason) {
	check(isset(MCP_ERROR_MESSAGES[$reason]), 'No default message for ' . $reason);
}

/* The include runs in a separate PHP process inside a fixture layout:
 * <root>/include/global.php stands in for Cacti, and the plugin's lib files
 * are copied to <root>/plugins/mcp/lib. */
function run_fixture(array $server, array $cookie, array $get = [], array $methods = ['POST'],
	string $errorFormat = 'aip', bool $cactiExits = false, int $pollerId = 1) : array {
	$root = sys_get_temp_dir() . '/mcp-bootstrap-' . bin2hex(random_bytes(6));
	mkdir($root . '/include', 0700, true);
	mkdir($root . '/plugins/mcp/lib', 0700, true);
	mkdir($root . '/sessions', 0700);

	foreach (glob(__DIR__ . '/../lib/*.php') as $file) {
		copy($file, $root . '/plugins/mcp/lib/' . basename($file));
	}
	file_put_contents($root . '/include/global.php', <<<'PHP'
		<?php
		$config = ['poller_id' => 1];
		function fixture_config_poller() { global $config; return $config['poller_id'] ?? null; }
		function api_plugin_is_enabled($plugin) { global $config; return $plugin === 'mcp' && isset($config['poller_id']); }
		$fixture_during = [
			'poller'  => fixture_config_poller(),
			'method'  => $_SERVER['REQUEST_METHOD'],
			'cookie'  => $_COOKIE,
			'post'    => $_POST,
			'request' => $_REQUEST,
			'get'     => $_GET,
		];
		session_start();
		$_SESSION['sess_user_id'] = 5;
		print '<html>Cacti page output</html>';
		PHP);

	if ($pollerId !== 1) {
		file_put_contents($root . '/include/global.php', str_replace("['poller_id' => 1]", "['poller_id' => " . $pollerId . ']',
			(string) file_get_contents($root . '/include/global.php')));
	}

	if ($cactiExits) {
		/* Like global.php when the database is down: a nested buffer, an HTML
		 * page, then exit. The flushes push the page through the bootstrap
		 * buffer before shutdown, so its callback must discard it. */
		file_put_contents($root . '/include/global.php', <<<'PHP'
			<?php
			ob_start(fn ($buffer) => $buffer);
			print '<p>FATAL: Connection to Cacti database failed.</p>';
			ob_end_flush();
			ob_flush();
			exit;
			PHP);
	}
	file_put_contents($root . '/plugins/mcp/endpoint.php', <<<'PHP'
		<?php
		ini_set('session.save_path', __DIR__ . '/../../sessions');
		$_SERVER = json_decode($argv[1], true) + $_SERVER;
		$_COOKIE = json_decode($argv[2], true);
		$_GET = json_decode($argv[3], true);
		$_POST = ['__csrf_magic' => 'x'];
		$_REQUEST = $_GET + $_POST + $_COOKIE;
		// Runs after every shutdown function registered later, so it sees the final status.
		register_shutdown_function(fn () => register_shutdown_function(
			fn () => file_put_contents(__DIR__ . '/../../status', (string) http_response_code())));
		$mcp_bootstrap_options = ['methods' => json_decode($argv[4], true), 'content_type' => 'application/json',
			'max_bytes' => 65536, 'bearer' => true, 'error_format' => $argv[5]];
		$mcp_request = require __DIR__ . '/lib/bootstrap_cacti.php';
		print json_encode(['request' => $mcp_request, 'during' => $fixture_during ?? null,
			'method' => $_SERVER['REQUEST_METHOD'], 'session' => session_status(),
			'poller' => fixture_config_poller(), 'sessionData' => $_SESSION ?? [], 'get' => $_GET]);
		PHP);
	$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/plugins/mcp/endpoint.php') . ' '
		. escapeshellarg(json_encode($server)) . ' ' . escapeshellarg(json_encode($cookie))
		. ' ' . escapeshellarg(json_encode($get, JSON_FORCE_OBJECT)) . ' ' . escapeshellarg(json_encode($methods))
		. ' ' . escapeshellarg($errorFormat);
	$output  = (string) shell_exec($command);
	$status  = (int) @file_get_contents($root . '/status');

	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($items as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($root);

	return (json_decode($output, true) ?? []) + ['_raw' => $output, '_status' => $status];
}

// cacti_include_at_file_scope
$result = run_fixture(['HTTP_AUTHORIZATION' => $token] + $post, ['Cacti' => 'session-id']);
check(isset($result['request']), 'Bootstrap failed: ' . json_encode($result));
check($result['request'] === ['method' => 'POST', 'body' => '', 'authorization' => $token], 'Unexpected request array');
check($result['during']['poller'] === 1, 'global.php did not run at file scope');
check($result['poller'] === 1, '$config is not global after the include');
check($result['during']['method'] === 'MCP_POST', 'Cacti saw the POST method');
check($result['during']['cookie'] === [] && $result['during']['post'] === [] && $result['during']['request'] === [],
	'Cacti saw identity input');
check($result['method'] === 'POST', 'REQUEST_METHOD not restored');
check($result['session'] === PHP_SESSION_NONE, 'Session left active');
check($result['sessionData'] === [], '$_SESSION readable after finish');

// get_strips_identity
$result = run_fixture(['REQUEST_METHOD' => 'GET', 'HTTP_AUTHORIZATION' => $token], ['Cacti' => 'session-id', 'CactiTab' => '1'],
	['action' => 'save', 'pageSize' => '5'], ['GET', 'POST']);
check(isset($result['request']), 'GET bootstrap failed: ' . json_encode($result));
check($result['during']['cookie'] === [] && $result['during']['request'] === [] && $result['during']['post'] === [],
	'Cacti saw identity input on GET');
check($result['during']['get'] === ['action' => 'save', 'pageSize' => '5'] && $result['get'] === $result['during']['get'],
	'$_GET not preserved');
check($result['during']['method'] === 'GET' && $result['method'] === 'GET', 'GET method changed');
check($result['session'] === PHP_SESSION_NONE && $result['sessionData'] === [], 'Session left after GET');

// rejected_before_cacti_loads
$result = run_fixture($post, ['Cacti' => 'session-id']);
check(($result['error']['code'] ?? null) === 401, 'Missing token not rejected: ' . json_encode($result));
check($result['error']['details'][0]['reason'] === 'INVALID_TOKEN', 'Wrong reason');
check(!isset($result['during']), 'Cacti loaded for a rejected request');

// cacti_exit_is_json_aip
$result = run_fixture(['HTTP_AUTHORIZATION' => $token] + $post, [], [], ['POST'], 'aip', true);
check($result['_status'] === 503, 'Cacti exit did not give 503: ' . $result['_status']);
check(!str_contains($result['_raw'], '<'), 'HTML leaked: ' . $result['_raw']);
check(($result['error']['code'] ?? null) === 503 && $result['error']['status'] === 'UNAVAILABLE'
	&& $result['error']['details'][0]['reason'] === 'CACTI_UNAVAILABLE', 'Wrong AIP body: ' . $result['_raw']);
check($result['error']['message'] === 'Cacti is not available.', 'Wrong message');

// cacti_exit_is_json_rpc
$result = run_fixture(['HTTP_AUTHORIZATION' => $token] + $post, [], [], ['POST'], 'jsonrpc', true);
check($result['_status'] === 503, 'Cacti exit did not give 503: ' . $result['_status']);
check(!str_contains($result['_raw'], '<'), 'HTML leaked: ' . $result['_raw']);
check(($result['jsonrpc'] ?? null) === '2.0' && !array_key_exists('id', $result) && $result['error']['code'] === -32000
	&& $result['error']['data'] === ['status' => 'UNAVAILABLE', 'reason' => 'CACTI_UNAVAILABLE'], 'Wrong JSON-RPC body: ' . $result['_raw']);

// plugin_unavailable_follows_the_endpoint_format
foreach (['aip', 'jsonrpc'] as $format) {
	$result = run_fixture(['HTTP_AUTHORIZATION' => $token] + $post, [], [], ['POST'], $format, false, 2);
	check($result['_status'] === 503 && !isset($result['request']), 'A remote poller is refused with 503 (' . $format . ')');

	if ($format === 'aip') {
		check($result['error']['status'] === 'UNAVAILABLE' && $result['error']['details'][0]['reason'] === 'PLUGIN_UNAVAILABLE', 'Wrong AIP body: ' . $result['_raw']);
	} else {
		check(($result['jsonrpc'] ?? null) === '2.0' && $result['error']['code'] === -32000
			&& $result['error']['data'] === ['status' => 'UNAVAILABLE', 'reason' => 'PLUGIN_UNAVAILABLE'], 'Wrong JSON-RPC body: ' . $result['_raw']);
	}
}

// success_status_untouched (the CLI reports no status unless one was set)
$result = run_fixture(['HTTP_AUTHORIZATION' => $token] + $post, []);
check($result['_status'] !== 503 && isset($result['request']), 'Completed bootstrap changed: ' . $result['_raw']);

// unavailable_message_defined
check(isset(MCP_ERROR_MESSAGES['CACTI_UNAVAILABLE']), 'No default message for CACTI_UNAVAILABLE');

print "bootstrap tests passed\n";
