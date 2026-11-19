<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Cacti MCP plugin: remote MCP endpoint over the Abilities API

- Date: 2026-11-19
- Status: Draft, awaiting approval
- Repositories: `somethingwithproof/plugin_mcp` (renamed from `plugin_abilities`), `somethingwithproof/cacti-mcp`

## Intent

Cacti users and plugin authors upstream should be able to connect ChatGPT,
Claude, and GitHub Copilot to a Cacti install. No AI client, and no plugin
that wants to serve one, should query Cacti's database directly. Every
operation goes through a registered ability that declares its input,
checks the caller's Cacti permissions, runs through Cacti's own APIs, and is
audited. This is the model of the WordPress Abilities API, where the MCP
Adapter is a thin layer that publishes registered abilities as MCP tools.

The plugin already implements the registry half: `AbilitiesRegistry` in
`lib/registry.php`, built-in providers in `lib/providers.php`, the
`abilities_register` hook for other plugins, permission-filtered discovery,
and an audit table. What it lacks is a transport that hosted clients can reach.
`cacti-mcp` speaks MCP only over stdio, which works for Claude Desktop, Claude
Code, and Copilot in VS Code but not for ChatGPT connectors or claude.ai custom
connectors. Those require a remote HTTPS MCP server, normally with OAuth.

## Goals

1. Rename the plugin to the Cacti MCP plugin (slug `mcp`).
2. Serve MCP over Streamable HTTP from the plugin, so an admin can give users
   one URL to paste into ChatGPT, claude.ai, Claude Desktop, Claude Code, or
   Copilot in VS Code.
3. Authorize hosted clients with OAuth 2.1 as the MCP authorization spec
   requires, with Cacti acting as both the resource server and the
   authorization server and Cacti's login deciding who the user is.
4. Keep the existing personal tokens for clients that take a static bearer
   header and for the stdio adapter.
5. Bring the ability contract close to the WordPress Abilities API so plugin
   authors recognize it: output schema, annotations, category, label.
6. Fix `cacti-mcp` for the mcp 2.x Python SDK and keep it as the optional
   stdio bridge.
7. Follow Google's API Improvement Proposals wherever the API is
   resource-shaped, matching the core REST API's conventions, while the PHP
   stays idiomatic Cacti plugin code.

## Non-goals

- Write, repair, or Doctor abilities. `docs/plan.md` requires a separate
  approval contract first. This design keeps the registry read-only and makes
  that rule explicit in code.
- Moving the registry into Cacti core. That is a later upstream proposal, made
  once the plugin has proven the contract.
- A separately deployed Python HTTP service.
- MCP resources, prompts, sampling, or elicitation. Tools only.

## Anti-goals

- The MCP layer runs SQL against Cacti data or calls provider internals. It
  only calls `discover()` and `execute()` on the registry.
- A Cacti browser session authorizes an MCP request. The browser session is
  used only to sign in and approve an OAuth grant.
- An OAuth grant gives a client more than the approving user could do in
  Cacti, or survives that user's disablement or realm removal.
- A hosted client can make Cacti fetch arbitrary URLs (client metadata
  documents are fetched under strict limits, see below).

## Constraints

- Cacti 1.2.31 or later, PHP 8.2 or later, as in `INFO` and CI.
- Runs on the main poller only (`$config['poller_id'] === 1`), as `api.php`
  does today.
- No Composer dependencies today. Cacti plugins are installed by copying a
  directory, so any dependency must be vendored or avoided.
- Cacti is commonly installed under a path such as `/cacti/`, not at the web
  root. OAuth discovery documents (RFC 9728, RFC 8414) live under
  `/.well-known/` at the host root, which a plugin cannot serve by itself.
- The latest MCP revision is 2026-07-28. Hosted clients may still send
  2025-11-25 or earlier. Client support for each revision has to be checked at
  build time.
- Cacti-facing text must follow the Cacti publication rules in the
  maintainer's guide.

## Approaches

**A. Cacti serves MCP from the plugin (chosen).** A new `mcp.php` endpoint
translates JSON-RPC into registry calls. Users paste one URL and approve
access in Cacti. The cost is implementing a tools-only MCP server and a small
OAuth 2.1 authorization server in PHP.

**B. Add HTTP transport to the Python adapter.** Reuses the official Python
SDK. Rejected because every Cacti admin would have to deploy, secure, and
update a second service, which works against upstream adoption.

**C. Put the registry and endpoint in Cacti core now.** Gives plugin authors a
guaranteed API. Deferred: it needs maintainer agreement and would collide
with the unmerged core REST API token work. The plugin keeps the contract
stable so it can move later.

## Design

### 1. Rename

- Directory and repository `plugin_mcp`, `INFO` name `mcp`, longname
  "Cacti MCP". Lifecycle functions become `plugin_mcp_install`,
  `plugin_mcp_uninstall`, `plugin_mcp_check_config`, `plugin_mcp_upgrade`,
  `plugin_mcp_version`. Translation domain `mcp`.
- Tables become `plugin_mcp_token` and `plugin_mcp_audit`, plus the OAuth
  tables below. There is no release, so no migration from
  `plugin_abilities_*` is needed.
- The plugin-author contract keeps the Abilities name: the
  `abilities_register` hook, `AbilitiesRegistry`, and the `abilities_*`
  helpers. The plugin is named for its transport; the API it hosts stays the
  Abilities API.
- Update README, logo alt text, homepage, and the cross-links in
  `cacti-mcp`.

### 2. Ability contract v2

Registration takes one array, close to `wp_register_ability()`:

```php
$registry->register('cacti-get-device', [
	'label'         => __('Get device', 'mcp'),
	'description'   => __('Read one permitted device. Credentials are excluded.', 'mcp'),
	'category'      => 'inventory',
	'input_schema'  => [...],
	'output_schema' => [...],
	'annotations'   => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
	'permission'    => fn (int $user) => is_realm_allowed(3, $user),
	'execute'       => fn (int $user, stdClass $args) => ...,
]);
```

- Ability IDs match `^[a-z][a-z0-9]*(-[a-z0-9]+){1,7}$` and are at most 64
  characters. The first segment is the namespace: `cacti` for built-ins, the
  plugin's slug for abilities registered through the hook, which the registry
  enforces. The rest follows AIP method naming: `cacti-list-devices`,
  `cacti-get-device`, `cacti-get-system-info`, `thold-list-thresholds`.
  These IDs are also valid AIP-122 resource IDs and valid tool names for
  OpenAI and Anthropic models, which reject dots.

- `register()` rejects an ability whose annotations are not read-only,
  non-destructive, and idempotent. This enforces the non-goal until a write
  contract exists.
- Output is validated against `output_schema` with the same validator as
  input. A mismatch is an `invalid_result` error, never a partial result.
- Categories are registered names (`inventory`, `system`) so plugins can add
  their own.
- The existing five-argument `register()` signature is removed, since nothing
  has been released.

### 3. API conventions

Google's AIPs govern everything that is resource-shaped: the HTTP Abilities
API and the arguments and results of each ability. The MCP endpoint is
JSON-RPC defined by the MCP spec, so the AIPs do not apply to its wire format.
The model to copy is the core REST API on `feature/rest-api-tokens`
(`api/openapi.json`, `api/include/http.php`, ADR 0001 Amendment 1), so a
client written for Cacti's REST API reads ability results the same way.

**HTTP Abilities API (`api.php`).** `abilities` is a read-only collection:

| Method | Request | AIP |
| --- | --- | --- |
| List | `GET api.php/v1/abilities?pageSize=&pageToken=&filter=` | 132, 158, 160 |
| Get | `GET api.php/v1/abilities/{ability}` | 131 |
| Execute | `POST api.php/v1/abilities/{ability}:execute` with JSON `{"arguments": {...}}` | 136 |

Routing uses `PATH_INFO` on the plugin script, so no rewrite rules are needed
on a default Apache or nginx PHP setup. An Ability resource has `name`
(`abilities/cacti-get-device`), `displayName`, `description`, `category`,
`inputSchema`, `outputSchema`, and `annotations`. Execute takes a JSON body and
a bearer token, which replaces today's form-encoded body and the CSRF token
handshake. The response is the ability's result object.
The contract is `openapi.json` (OpenAPI 3.1) in the plugin root, enforced by a
contract test as in core.

**Ability arguments and results.**

- JSON fields are lowerCamelCase, as in the core spec.
- List abilities take `pageSize`, `pageToken`, `filter`, and `orderBy` and
  return the plural collection field plus `nextPageToken`. Page tokens are
  opaque and bound to the query, using the core encoding. This replaces
  `limit` and `offset` on `cacti.devices.list`.
- Get abilities take a resource `name` such as `devices/123`.
- Device, site, and other resource representations are field-for-field the
  core REST API schemas, restricted to read fields. Credentials are never
  included.
- `filter` supports the same subset of AIP-160 the core API implements, with
  an allow-list of fields per ability. Unknown fields are `INVALID_ARGUMENT`.

**Errors.** AIP-193 everywhere: `code`, `message`, `status`, and `details`
with `google.rpc.ErrorInfo` (`reason`, domain `cacti.net`) and
`google.rpc.BadRequest` for invalid fields. In MCP, a failed tool call
returns `isError: true` whose `structuredContent` is that error object. The
`message` never contains SQL, paths, or credentials.

**What stays Cacti idiomatic.**

- Lifecycle in `setup.php` with `plugin_mcp_*` functions. Hooks through
  `api_plugin_register_hook()`, realms through `api_plugin_register_realm()`,
  settings through the `config_settings` hook, user pages through Cacti's
  form and `html_start_box()` helpers with `__('...', 'mcp')` strings.
- Request variables in console pages through `get_filter_request_var()`.
  The JSON endpoints validate with the registry's schema validator instead.
- Database access only through `db_*_prepared()`, only in plugin storage code
  and provider callbacks. Providers call Cacti's own helpers (such as the
  allowed-device helper) rather than writing their own permission SQL.
- Tabs, single quotes, short arrays, SPDX headers as the repository uses now.
- Failures logged with `cacti_log()` at the plugin's log level, never with
  token values.

**CSRF workaround (temporary).** Cacti's CSRF guard runs on every web POST
once `include/global.php` loads, and hosted MCP and OAuth clients cannot send a
Cacti CSRF token. Neither supported Cacti version offers a switch for this:
`$no_http_headers` stops any web request with "This file can only be called
from the command line" on both 1.2.31 and develop, and the
`CACTI_API_REQUEST` exemption exists only on the unmerged core REST API
branch. Both guards, csrf-magic's `csrf_check()` on 1.2.31 and
`include/csrf.php` on develop, check only requests whose
`$_SERVER['REQUEST_METHOD']` is exactly `POST`.

Until core has a supported exemption, the token-only endpoints (`mcp.php`,
`api.php`, and the OAuth `token` and `register` endpoints) do this before they
include `include/global.php`, in one shared bootstrap function:

1. Reject the request unless it is a POST with the expected content type and
   size, and, for `mcp.php` and `api.php`, a syntactically valid bearer token.
2. Read the body from `php://input` and keep the original method.
3. Empty `$_POST`, `$_REQUEST`, and `$_COOKIE`, then set
   `$_SERVER['REQUEST_METHOD']` to `MCP_POST`, so the guard skips the request
   and Cacti finds no browser session to attach.
4. Include `include/global.php`, then authenticate with the bearer token
   only.

This is safe because CSRF defends cookie-authenticated requests, and these
endpoints never accept a cookie or a session as identity. The OAuth consent
page and every console page stay normal Cacti pages with full CSRF
protection. The workaround is confined to that one bootstrap function, with a
comment pointing to the core change. A test asserts that a console POST
without a CSRF token still fails. When core ships a supported exemption, the
bootstrap uses it if present and keeps the workaround only for older Cacti
versions. The core change is proposed upstream separately.

### 4. MCP endpoint

`mcp.php` at the plugin root. The canonical server URI is
`https://<host>/<cacti path>/plugins/mcp/mcp.php`.

- POST only. Accepts `application/json`, answers with `application/json`.
  No SSE stream is needed because every tool call returns one result. GET
  returns 405, as the transport permits.
- Methods: `initialize`, `ping`, `tools/list`, `tools/call`. Notifications
  get 202. Anything else is JSON-RPC `-32601`.
- Supports protocol revisions 2025-11-25 and 2026-07-28. The server keeps no
  per-session state, so the 2025-11-25 `Mcp-Session-Id` is issued but carries
  no authority.
- `tools/list` maps each discovered ability to a tool: name, title from
  `label`, description, `inputSchema`, `outputSchema`, and annotations
  (`readOnlyHint`, `destructiveHint`, `idempotentHint`).
- `tools/call` calls `$registry->execute()` and returns `structuredContent`
  plus a JSON text block. Ability errors become tool results with
  `isError: true` and the error code only.
- Validates `Origin` when present against the configured Cacti URL to block
  DNS rebinding. Request size, nesting depth, and response size keep the
  limits `api.php` uses today.
- `api.php` remains for the stdio adapter until `cacti-mcp` moves to
  `mcp.php`. The builder may then retire it.

### 5. Authorization

Every request needs `Authorization: Bearer`. Two token types are accepted,
both issued by this plugin and stored only as SHA-256 hashes:

- **Personal tokens**, the existing `plugin_mcp_token` rows created by
  `tokens.php` or a new user page. For Copilot, Claude Code, and the stdio
  adapter.
- **OAuth access tokens** bound to a user, client, scope, and the canonical
  URI as audience. Lifetime one hour. Refresh tokens rotate on use and are
  revoked as a family if one is replayed.

Both resolve to the same principal, and the registry rechecks realms on every
call, so disabling a user or removing a realm takes effect on the next
request.

The plugin acts as the authorization server:

- RFC 9728 protected resource metadata and RFC 8414 authorization server
  metadata are served by plugin scripts. The 401 `WWW-Authenticate` header
  points `resource_metadata` at the plugin's metadata URL directly, which
  works without root access. For clients that also probe `/.well-known/` at
  the host root, the plugin's settings page shows the Apache and nginx
  rewrite lines for the admin to add. The settings page reports whether
  discovery works from outside.
- Authorization code with PKCE S256 only. `resource` (RFC 8707) is required
  and must equal the canonical URI. `iss` is returned in authorization
  responses (RFC 9207).
- Client registration: Client ID Metadata Documents, which the spec prefers,
  plus Dynamic Client Registration for clients that still use it. Fetching
  a metadata document requires HTTPS, refuses private and loopback
  addresses, follows no redirects, and caps time and size. An admin setting
  restricts registration to an allowlist of client hosts, off by default.
- The consent page runs inside Cacti's normal login and CSRF handling. It
  shows the client name and host, the redirect host, and the scope, and it
  requires the plugin realm. The user can deny.
- One scope for now, `abilities:read`. Per-ability permission still comes
  from Cacti realms, not from scopes.
- Users see and revoke their OAuth grants and personal tokens on a "Connected
  apps" page. Admins see all grants and can revoke any of them.

New tables: `plugin_mcp_oauth_client`, `plugin_mcp_oauth_code`,
`plugin_mcp_oauth_grant` (access and refresh token hashes). Like the token and
audit tables, they survive uninstall; uninstall revokes every grant.

### 6. Python adapter (`cacti-mcp`)

- Port `server.py` to the mcp 2.x SDK server API so CI passes again.
- Repoint the adapter at the new `api.php` routes. Proxying to `mcp.php` is a
  follow-up.
- Update names and links for the rename.

### 7. Overlap with core REST API tokens

The unmerged core branch `feature/rest-api-tokens` adds its own
`user_auth_api_token` table. If it merges, the plugin should accept core
tokens instead of keeping a second token system. This design keeps the
plugin's tokens behind one `mcp_authenticate()` function so the swap
touches one place. The decision is made before any upstream proposal.

## Decisions settled during review

These were open after a builder review and are settled here as the author's
calls. The maintainer can override any of them.

- **Built-in abilities in this work:** three only, renamed from today's
  providers: `cacti-list-devices`, `cacti-get-device`, and
  `cacti-get-system-info`. `cacti-get-system-info` keeps today's version
  payload in lowerCamelCase; it does not adopt core's `systemStatus` shape.
  Sites, templates, pollers, and graphs are later abilities.
- **Device fields:** the read fields of the core `Device` schema, minus every
  SNMP and authentication field (`snmpCommunity`, `snmpUsername`,
  `snmpPassword`, `snmpAuthProtocol`, `snmpPrivPassphrase`,
  `snmpPrivProtocol`, `snmpEngineId`, `snmpContext`) and anything else that
  identifies a credential. The output schema lists the allowed fields, and a
  test fails if a provider returns any other field.
- **Core conventions:** the plugin ports the small helpers it needs (page
  token encoding, AIP-160 filter subset, AIP-193 error builder) into its own
  `lib/` and owns them, matching core's behavior. When core ships them, the
  plugin uses core's functions if they exist. The plugin's `openapi.json` is
  authoritative for the plugin.
- **Canonical server URI:** a required admin setting, "Public Cacti URL"
  (scheme, host, optional port, and path), that must be filled in before OAuth
  or `mcp.php` can be enabled. It is never derived from request headers. The
  audience, `resource` match, `Origin` check, and metadata URLs all come from
  it.
- **Tokens and realms:** one plugin realm covers `mcp.php`, `api.php`, the
  consent page, and the connected apps page. Personal tokens and OAuth access
  tokens both carry exactly the scope `abilities:read`. They have different
  visible prefixes (`cmcp_pat_` and `cmcp_at_`, refresh `cmcp_rt_`) so logs
  and revocation pages can tell them apart. All three are hashed with SHA-256
  like core's tokens. The existing `scopes` column stays, holding that one
  value.
- **OAuth endpoints:** the authorization endpoint is a normal Cacti page with
  CSRF protection. The `token` and `register` endpoints are token-only
  endpoints that use the CSRF workaround above.
- **`cacti-mcp`:** in this work it is ported to the mcp 2.x SDK, renamed in
  its docs, and repointed at the new `api.php` routes. Turning it into a thin
  stdio-to-`mcp.php` proxy is a follow-up.

## Testing

- Registry: v2 registration rules, read-only enforcement, output validation,
  plugin hook registration.
- API conventions: ability ID pattern and namespace enforcement; List
  paging with tokens bound to the query; `filter` allow-lists; AIP-193 error
  shape on every failure path; a contract test that fails when `api.php`
  routes or ability schemas drift from the plugin's OpenAPI file.
- CSRF workaround: bearer-only POSTs to `mcp.php` and `api.php`, and POSTs
  to the OAuth `token` and `register` endpoints, succeed on 1.2.31 and
  develop; a console POST without a CSRF token still fails; a request carrying
  a valid Cacti session cookie and no bearer token is refused.
- MCP: JSON-RPC conformance for each method and error, both protocol
  revisions, annotation mapping, Origin rejection, size limits.
- Authorization: 401 with correct `WWW-Authenticate`; wrong audience,
  expired, revoked, replayed refresh token; PKCE failure; `resource`
  mismatch; disabled user; removed realm; CIMD fetch refusals for private
  addresses, redirects, and oversize documents.
- Integration: the official MCP Inspector against a real Cacti 1.2.31 install,
  then a manual connection from ChatGPT developer mode, claude.ai, and
  Copilot in VS Code. Record which protocol revision each client used.

## Left to the builder

- Whether to vendor the official PHP MCP SDK or write the small tools-only
  server by hand. Writing it by hand is the default, because the SDK brings
  Symfony dependencies a copied plugin would have to vendor.
- Table column details, token formats, and the exact settings layout.
- Whether `api.php` is retired in this work or later.
- Order of work, provided the rename and the `cacti-mcp` CI fix land first.
