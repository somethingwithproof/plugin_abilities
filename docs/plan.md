<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Plan

This package is `plugin_mcp`, the Cacti MCP plugin (slug `mcp`, installed as the
`plugins/mcp/` directory). It was renamed from the plugin's earlier working name.

- Design: [MCP endpoint design spec](superpowers/specs/2026-11-19-mcp-endpoint-design.md)
- Plan 1, foundation: [MCP foundation plan](superpowers/plans/2026-11-19-mcp-foundation.md).
  It covers the rename, the ability v2 contract, the HTTP API, the MCP endpoint,
  and the console. The plan's closing section lists the follow-up plans.
- Plan 2, OAuth 2.1 and connected apps: not written yet. It is first in the
  foundation plan's follow-up list. Until it exists, clients authenticate with
  personal tokens only.

For installation and usage, see the [README](../README.md).
