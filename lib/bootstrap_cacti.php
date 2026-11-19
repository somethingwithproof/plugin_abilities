<?php
/* SPDX-FileCopyrightText: 2026 Thomas Vincent
 * SPDX-License-Identifier: GPL-2.0-or-later */

/* Load Cacti for a token-only endpoint. Use it from the endpoint's file scope:
 *
 *   $mcp_bootstrap_options = ['methods' => ['POST'], ...];
 *   $mcp_request = require __DIR__ . '/lib/bootstrap_cacti.php';
 *
 * This file must not be required from inside a function. PHP runs an included
 * file in the scope of its includer, and global.php defines $config and the
 * rest of Cacti's state as top-level variables that Cacti functions read with
 * `global`. See mcp_bootstrap_preflight() for the CSRF workaround. */

require_once __DIR__ . '/bootstrap.php';

$mcp_request      = mcp_bootstrap_preflight($mcp_bootstrap_options ?? []);
$mcp_buffer_level = ob_get_level();
ob_start();

require __DIR__ . '/../../../include/global.php';

// Discard page output from global.php, including csrf-magic's rewrite buffer on 1.2.x.
while (ob_get_level() > $mcp_buffer_level) {
	ob_end_clean();
}
mcp_bootstrap_finish($mcp_request);

return $mcp_request;
