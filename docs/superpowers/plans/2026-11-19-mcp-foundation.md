<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Cacti MCP Plugin Foundation Implementation Plan (Plan 1 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the plugin to `mcp`, upgrade the Abilities API to the v2 contract with AIP conventions, and serve abilities over a Streamable HTTP MCP endpoint authenticated with personal tokens, so Claude Code, Claude Desktop, and Copilot in VS Code can connect over HTTPS. Plan 2 adds OAuth for ChatGPT and claude.ai.

**Architecture:** Endpoint scripts (`api.php`, `mcp.php`) are thin: they call one shared bootstrap (CSRF workaround, Cacti load, bearer authentication) and hand a parsed request to a pure handler function in `lib/` that returns `[status, headers, body]`. Handlers only call `AbilitiesRegistry::discover()`, `get()`, and `execute()`. Providers are the only code that reads Cacti data, through Cacti helpers.

**Tech Stack:** PHP 8.2+ Cacti plugin with no Composer dependencies, plain-PHP test scripts run in Docker by CI. Python 3.12+ adapter with uv, ruff, pytest, `mcp>=2.3,<3`, httpx.

**Spec:** `docs/superpowers/specs/2026-11-19-mcp-endpoint-design.md` (read it first; this plan argues from it).

**Out of scope here (Plan 2):** OAuth authorization server, protected resource metadata, consent page, connected apps page, CIMD/DCR, `cmcp_at_`/`cmcp_rt_` tokens.

## Global Constraints

- Cacti 1.2.31 or later; PHP 8.2 or later; CI matrix PHP 8.2, 8.3, 8.4, 8.5.
- No Composer dependencies in the plugin.
- Endpoints run only on the main poller: `(int) $config['poller_id'] === 1`, else `UNAVAILABLE` / `PLUGIN_UNAVAILABLE`.
- Plugin slug `mcp`, directory `plugin_mcp`, longname `Cacti MCP`, translation domain `mcp`.
- Plugin-author contract keeps the Abilities name: hook `abilities_register`, class `AbilitiesRegistry`.
- Ability ID regex `^[a-z][a-z0-9]*(-[a-z0-9]+){1,7}$`, max 64 characters; first segment `cacti` for built-ins or the registering plugin's slug.
- Only abilities annotated `readOnly: true, destructive: false, idempotent: true` may register.
- JSON field names lowerCamelCase. Errors are AIP-193 with ErrorInfo domain `cacti.net`.
- Personal token format `cmcp_pat_` followed by 64 lowercase hex characters; stored as SHA-256 of the whole token; scope column holds exactly `["abilities:read"]`.
- Database access only through `db_*_prepared()` in `lib/storage.php`, except the device filter, which builds a `get_allowed_devices()` WHERE fragment escaped with `db_qstr()` from allow-listed columns.
- PHP style: tabs, single quotes, short arrays, SPDX header `SPDX-FileCopyrightText: 2026 Thomas Vincent` and `SPDX-License-Identifier: GPL-2.0-or-later` on every new file.
- Follow the Cacti publication boundary in `~/.config/ai-agents/cacti.md` for all authored text.
- Conventional Commits, `git commit -s`, stage named files only.
- Supported MCP protocol revisions: `2026-07-28` (modern) and `2025-11-25` (legacy).

## Review Focus

1. Nginx or Apache setups that don't pass `PATH_INFO` to `api.php`: the request should get an AIP `NOT_FOUND` that names the missing path, not a PHP warning or an empty 200. Covered in Task 7 (`api_missing_path_info`).
2. Filter string literals containing quotes, backslashes, or SQL keywords: these should be matched as one literal value, never as SQL. Covered in Task 5 (`filter_literal_escaped`).
3. A third-party plugin whose `abilities_register` callback throws or registers an invalid ability: built-in abilities and other plugins should keep working, and the failure should be logged. Covered in Task 3 (`plugin_registration_isolated`).
4. Devices with no site or template (`site_id = 0`, `host_template_id = 0`): these should come back as `null` references, not `sites/0`. Covered in Task 5 (`device_zero_references_null`).
5. Legacy MCP clients sending `GET`, `DELETE`, or `Mcp-Session-Id`: these should get 405 for GET and DELETE, with the session header ignored and never echoed. Covered in Task 9 (`legacy_get_delete_session`).

---

## File Structure (plugin_mcp)

| File | Responsibility |
| --- | --- |
| `INFO`, `setup.php` | Lifecycle, realm, `config_settings` hook for the Public Cacti URL setting |
| `lib/registry.php` | `AbilitiesError`, schema dialect, `AbilitiesRegistry`, `AbilitiesRegistrar` |
| `lib/aip.php` | AIP-193 error bodies, AIP-158 paging, AIP-160 filter and AIP-132 order translation |
| `lib/storage.php` | Schema install, realm check, token authentication, audit |
| `lib/providers.php` | Built-in abilities, device resource mapping, plugin hook dispatch |
| `lib/bootstrap.php` | CSRF workaround and Cacti load for token-only endpoints |
| `lib/api_http.php` | Pure handler for the HTTP Abilities API |
| `lib/mcp_server.php` | Pure handler for MCP JSON-RPC over Streamable HTTP |
| `api.php`, `mcp.php` | Thin entry scripts |
| `tokens.php` | Personal token CLI |
| `openapi.json` | OpenAPI 3.1 contract for `api.php` |
| `lib/admin.php`, `mcp_admin.php` | Admin console page: ability exposure, tokens, audit |
| `tests/*.php` | One plain-PHP test script per lib file, plus `tests/contract.php` |

Every test script follows the existing pattern in `tests/registry.php`: a `check(bool, string)` helper, Cacti functions faked at file scope, exit code non-zero on failure. Each task that adds a test script also adds `php tests/<name>.php` to the "Registry, lifecycle, and native provider tests" step in `.github/workflows/ci.yml`.

Work on branch `feat/mcp-foundation`, created from `docs/mcp-endpoint-design`.

---

### Task 1: Rename the plugin to `mcp`

**Files:**
- Modify: `INFO`, `setup.php`, `lib/storage.php`, `lib/providers.php`, `api.php`, `tokens.php`, `README.md`, `.github/workflows/ci.yml`, `tests/lifecycle.php`, `tests/providers.php`
- Rename: `images/abilities_logo.png` → `images/mcp_logo.png`, `images/abilities_logo_dark.png` → `images/mcp_logo_dark.png` (use `git mv`; check `ls images` for the exact dark-variant filename)

**Interfaces:**
- Produces: `plugin_mcp_install()`, `plugin_mcp_uninstall()`, `plugin_mcp_check_config()`, `plugin_mcp_upgrade()`, `plugin_mcp_version()`; tables `plugin_mcp_token`, `plugin_mcp_audit`; realm registered as `api_plugin_register_realm('mcp', 'api.php,mcp.php', __('MCP and Abilities API', 'mcp'), false)`; storage helpers renamed `mcp_install_schema()`, `mcp_has_realm(int $userId): bool`, `mcp_authenticate(string $header): array`, `mcp_audit(array $principal, string $id, string $name, string $outcome): void`. `mcp_has_realm()` looks the realm up with `file = 'api.php,mcp.php'`; confirm on 1.2.31 that `api_plugin_register_realm()` stores a comma-separated file list as one row and that `is_realm_allowed()` then covers both scripts.

- [ ] **Step 1: Update `tests/lifecycle.php`** to expect the new function names, table names `plugin_mcp_token` / `plugin_mcp_audit`, and realm `('mcp', 'api.php,mcp.php')`.
- [ ] **Step 2: Run** `php tests/lifecycle.php`. Expected: fails with "Call to undefined function plugin_mcp_install".
- [ ] **Step 3: Rename** everything listed above. `INFO`: `name = mcp`, `longname = Cacti MCP`, `homepage = https://github.com/somethingwithproof/plugin_mcp`. Every `__()` domain becomes `'mcp'`; `api_plugin_is_enabled('mcp')`. CI package job: archive prefix `mcp/`, artifact `mcp-plugin`, checks `mcp/images/mcp_logo.png`. README: title "Cacti MCP", logo paths, CI badge URL, and a one-line statement that the plugin hosts the Abilities API. Leave the README's other sections for Task 12.
- [ ] **Step 4: Run** `grep -rn -i 'abilities' --include='*.php' --include=INFO --include='*.yml' .`. Expected: matches only for `abilities_register`, `AbilitiesRegistry`, `AbilitiesError`, `abilities_check_schema`, `abilities_validate`, and the Abilities API name in strings. Then run all three test scripts. Expected: pass.
- [ ] **Step 5: Commit** `refactor: rename the plugin to mcp`.

### Task 2: AIP helpers and the v2 error type

**Files:**
- Modify: `lib/registry.php` (replace `AbilitiesError`)
- Create: `lib/aip.php`, `tests/aip.php`

**Interfaces:**
- Produces:
  - `class AbilitiesError extends RuntimeException { __construct(public string $status, public string $reason, string $message = '', public array $violations = [], public ?int $httpCode = null); httpStatus(): int }`. `httpStatus()` returns `$httpCode` when set, otherwise the map `INVALID_ARGUMENT|FAILED_PRECONDITION|OUT_OF_RANGE → 400, UNAUTHENTICATED → 401, PERMISSION_DENIED → 403, NOT_FOUND → 404, ALREADY_EXISTS|ABORTED → 409, RESOURCE_EXHAUSTED → 429, UNIMPLEMENTED → 501, UNAVAILABLE → 503, INTERNAL → 500`.
  - `mcp_error_body(AbilitiesError $e): array`. Returns `['error' => ['code' => int, 'message' => string, 'status' => string, 'details' => [ErrorInfo, BadRequest?]]]`. ErrorInfo is `['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => $reason, 'domain' => 'cacti.net']`. BadRequest is present only when there are violations: `['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => ..., 'description' => ...]]]`. When `$message === ''` it uses a fixed developer-facing message per reason.
  - `mcp_page(stdClass $args, string $fingerprint, int $default = 50, int $max = 100): array{size: int, offset: int}`. It throws `INVALID_ARGUMENT` / `INVALID_PAGE_TOKEN` for a token that fails to decode or whose fingerprint differs, and `INVALID_ARGUMENT` / `INVALID_ARGUMENTS` for a `pageSize` below 0. A `pageSize` of 0 or absent means `$default`; anything above `$max` is clamped.
  - `mcp_page_token(int $offset, string $fingerprint): string`, using the same encoding as core: base64url of `{"o":offset,"f":fingerprint}` with no padding.
  - `mcp_filter_sql(string $filter, array $fields): string` and `mcp_order_sql(string $orderBy, array $fields, string $default): string`. `$fields` maps an API field to `['column' => 'h1.description', 'type' => 'string'|'int'|'bool'|'reference'|'enum', 'collection' => 'sites', 'values' => [stored => api], 'filter' => bool, 'order' => bool]`. The grammar is core's: comparisons `= != < <= > >=` joined by `AND`, with values a double-quoted string, a number, `true`, or `false`. The returned SQL is `''` for an empty filter, otherwise clauses joined by ` AND `, each value passed through `db_qstr()` or `(int)`. Errors are `INVALID_ARGUMENT` / `INVALID_FILTER` with a violation on field `filter`.

- [ ] **Step 1: Write `tests/aip.php`.** Fake `db_qstr($s)` as `"'" . addslashes($s) . "'"`. Tests:
  - `error_body_shape`: `mcp_error_body(new AbilitiesError('NOT_FOUND', 'DEVICE_NOT_FOUND'))['error']['code'] === 404` and `details[0]['domain'] === 'cacti.net'`.
  - `page_roundtrip`: `mcp_page((object)['pageToken' => mcp_page_token(50, 'f1')], 'f1')['offset'] === 50`.
  - `page_token_bound_to_query`: the same token with fingerprint `'f2'` throws reason `INVALID_PAGE_TOKEN`.
  - `page_size_clamped`: `pageSize: 500` gives `size === 100`.
  - `filter_basic`: `mcp_filter_sql('hostname = "a" AND disabled = true', $fields) === "h1.hostname = 'a' AND h1.disabled = 'on'"`.
  - `filter_unknown_field`: throws `INVALID_FILTER`.
  - `order_desc`: `mcp_order_sql('displayName desc', $fields, 'h1.id') === 'h1.description DESC'`.
- [ ] **Step 2: Run** `php tests/aip.php`. Expected: fails with "Call to undefined function mcp_error_body".
- [ ] **Step 3: Implement** `lib/aip.php`. Port the filter grammar and value conversion from `rest_filter()` / `rest_filter_value()` in the core branch `api/include/http.php`, changing the output from prepared params to `db_qstr()`-escaped SQL. Map `bool` to `'on'` / `''`, `reference` from `collection/N` to `N`, and `enum` through `values`. Update the three existing `throw new AbilitiesError(...)` sites in `lib/registry.php` and `lib/storage.php` to the new constructor (`UNAUTHENTICATED`/`INVALID_TOKEN`, `PERMISSION_DENIED`/`REALM_REQUIRED`, `PERMISSION_DENIED`/`ABILITY_UNAVAILABLE`, `INVALID_ARGUMENT`/`INVALID_ARGUMENTS`, `INTERNAL`/`INVALID_RESULT`, `UNAVAILABLE`/`AUDIT_UNAVAILABLE`).
- [ ] **Step 4: Run** `php tests/aip.php && php tests/registry.php`. Expected: pass. Update the `rejects()` helper in `tests/registry.php` to compare `$error->reason`.
- [ ] **Step 5: Commit** `feat: add AIP-193 errors, paging, and filter helpers`.

### Task 3: Ability contract v2

**Files:**
- Modify: `lib/registry.php`, `tests/registry.php`

**Interfaces:**
- Consumes: `AbilitiesError` (Task 2).
- Produces:
  - `AbilitiesRegistry::registerCategory(string $id, string $label): void`, with `$id` matching `^[a-z][a-z0-9-]{0,31}$`.
  - `AbilitiesRegistry::register(string $id, array $definition): void`, with keys `label`, `description`, `category`, `input_schema`, `output_schema`, `annotations`, `permission` (`callable(int $userId): bool`), `execute` (`callable(int $userId, stdClass $args): array`). It throws `LogicException` for: a bad ID, a duplicate, more than 256 abilities, a description over 4096 bytes, an unknown category, an invalid schema, or annotations other than exactly read-only, non-destructive, and idempotent.
  - `AbilitiesRegistry::discover(array $principal): array` returns a list of descriptors `['id', 'label', 'description', 'category', 'inputSchema', 'outputSchema', 'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true]]`.
  - `AbilitiesRegistry::get(array $principal, string $id): ?array` returns one descriptor, or null when the ability is unknown or not permitted.
  - `AbilitiesRegistry::execute(array $principal, string $id, stdClass $args, callable $audit): array{requestId: string, result: array}`.
  - `class AbilitiesRegistrar { __construct(AbilitiesRegistry $registry, string $namespace); register(string $id, array $definition): void; registerCategory(string $id, string $label): void }`. `register()` throws `LogicException` unless `$id` starts with `$namespace . '-'`.
  - A principal is `['token_id' => int, 'token_kind' => 'pat', 'user_id' => int, 'scopes' => list<string>]`. It is allowed iff `in_array('abilities:read', $scopes, true)` and `permission($userId) === true`.

- [ ] **Step 1: Rewrite `tests/registry.php`** for the v2 API, keeping the existing discovery, realm isolation, audit, and argument tests. Add:
  - `rejects_write_annotations`: `annotations: ['readOnly' => false, ...]` throws `LogicException`.
  - `rejects_dotted_id`: `'cacti.devices.get'` throws.
  - `output_schema_enforced`: an execute callback returning an undeclared field throws reason `INVALID_RESULT`, and the audit records `failed`.
  - `scope_required`: a principal with `scopes: []` gets an empty `discover()`.
  - `get_hides_unpermitted`: `get()` returns null for a forbidden ability.
  - `registrar_namespace`: `(new AbilitiesRegistrar($r, 'thold'))->register('cacti-x-y', ...)` throws, and `'thold-list-thresholds'` succeeds.
  - `nullable_and_arrays`: an output schema with `['type' => ['string', 'null']]` and `['type' => 'array', 'items' => ..., 'maxItems' => 100]` validates `null` and a list.
- [ ] **Step 2: Run** `php tests/registry.php`. Expected: fails on the first v2 call.
- [ ] **Step 3: Implement.** Extend the schema dialect:
  - `number` with `minimum` / `maximum`.
  - `array` with `items` / `maxItems` (required).
  - `description` allowed on every type.
  - `type` may be a two-element list ending in `'null'`.

  Output validation JSON-encodes the result and decodes it to `stdClass` before calling `abilities_validate()`, so it reuses the input validator. The JSON size limit stays at 1,000,000 bytes.
- [ ] **Step 4: Run** `php tests/registry.php`. Expected: pass.
- [ ] **Step 5: Commit** `feat: adopt the v2 ability contract with annotations and output schemas`.

### Task 4: Personal token format

**Files:**
- Modify: `lib/storage.php`, `tokens.php`, `tests/lifecycle.php`
- Create: `tests/storage.php`

**Interfaces:**
- Consumes: `AbilitiesError`.
- Produces: `mcp_token_hash(string $token): ?string`, which returns `hash('sha256', $token)` when `$token` matches `^cmcp_pat_[a-f0-9]{64}$` and null otherwise. `mcp_authenticate(string $header): array` accepts only `Bearer cmcp_pat_<64 hex>` and returns a principal with `token_kind => 'pat'` and `scopes => ['abilities:read']`.

- [ ] **Step 1: Write `tests/storage.php`.** Fake `db_fetch_row_prepared` and `read_config_option`. Tests:
  - `hash_valid_pat`: a valid token returns 64 hex characters.
  - `hash_rejects_old_format`: a bare 64-hex string returns null.
  - `authenticate_rejects_query_style`: `'Bearer cmcp_pat_' . str_repeat('A', 64)` throws `INVALID_TOKEN`.
  - `authenticate_scope_fixed`: a row with `scopes = '["abilities:read"]'` yields `scopes === ['abilities:read']`.
  - `authenticate_rejects_other_scope`: a row with `'["cacti.devices.get"]'` throws `INVALID_TOKEN`.
- [ ] **Step 2: Run** `php tests/storage.php`. Expected: fails with an undefined `mcp_token_hash`.
- [ ] **Step 3: Implement.** In `tokens.php`, drop `--scopes`: `--create=USERNAME --days=N` reads `CACTI_MCP_TOKEN`, which must pass `mcp_token_hash()`. Rows are inserted with `scopes = '["abilities:read"]'`. Update the help text to show the format and a generator: `printf 'cmcp_pat_%s\n' "$(openssl rand -hex 32)"`.
- [ ] **Step 4: Run** `php tests/storage.php && php tests/lifecycle.php`. Expected: pass.
- [ ] **Step 5: Commit** `feat: use prefixed personal tokens with the abilities:read scope`.

### Task 5: Built-in abilities on AIP resources

**Files:**
- Modify: `lib/providers.php`, `tests/providers.php`

**Interfaces:**
- Consumes: Tasks 2 and 3.
- Produces:
  - `mcp_registry(): AbilitiesRegistry` registers categories `inventory` and `system`, the three built-ins, then plugin abilities through `mcp_dispatch_ability_hooks()`.
  - `mcp_dispatch_ability_hooks(AbilitiesRegistry $registry): void` reads `SELECT name, file, function FROM plugin_hooks WHERE hook = ? AND status = 1` with `['abilities_register']`. It skips plugins where `api_plugin_is_enabled(name)` is false, `include_once`s each file from the plugin directory, and calls `function(new AbilitiesRegistrar($registry, $name))` inside `try`. On `Throwable` it calls `cacti_log('MCP: ability registration failed for plugin ' . $name, false, 'MCP')`, discards only that plugin's abilities (register into a scratch registry first, then merge), and continues. Verify the `plugin_hooks` columns on 1.2.31 and develop before relying on them.
  - `MCP_DEVICE_FIELDS` constant: the filter and order field map for devices, in the `mcp_filter_sql()` format:
    - `displayName` maps to `h1.description` (string; filter and order).
    - `hostname` maps to `h1.hostname` (string; filter and order).
    - `disabled` (bool; filter).
    - `site` maps to `h1.site_id` (reference `sites`; filter).
    - `poller` maps to `h1.poller_id` (reference `pollers`; filter).
    - `deviceTemplate` maps to `h1.host_template_id` (reference `deviceTemplates`; filter).
    - `externalId` maps to `h1.external_id` (string; filter).
    - `state` maps to `h1.status` (enum; filter), using core's state values.
    - `name` maps to `h1.id` (order).
  - `mcp_device_resource(array $row): array` returns the core `Device` read fields minus credentials:
    - Identity and placement: `name` (`devices/{id}`), `displayName`, `hostname`, `deviceTemplate`, `site`, `poller` (each `collection/N`, or `null` when the ID is 0), `externalId`, `location`, `notes`, `disabled`.
    - Polling settings: `snmpVersion`, `snmpPort`, `snmpTimeout`, `snmpRetries`, `maxOids`, `bulkWalkSize`, `deviceThreads`, `availabilityMethod`, `pingMethod`, `pingPort`, `pingTimeout`, `pingRetries`.
    - Status: `state`, `availability`, `lastError`, `failTime`, `recoveryTime`, `createTime`.

    Copy the state mapping and the timestamp formatting from the core branch `api/include/resources.php` `devices` definition. Never include `snmpCommunity`, `snmpUsername`, `snmpPassword`, `snmpAuthProtocol`, `snmpPrivPassphrase`, `snmpPrivProtocol`, `snmpEngineId`, `snmpContext`, or `etag`. When a column is missing on the installed Cacti version, the field is `null`.
  - The abilities:
    - `cacti-list-devices` (category `inventory`, realm 3). Input: `pageSize` (int 0–1000), `pageToken` (string, max 512), `filter` (string, max 1024), `orderBy` (string, max 128). Output: `['devices' => list, 'nextPageToken' => string|null]`. The page fingerprint is `sha1($filter . "\0" . $orderBy)`.
    - `cacti-get-device` (`inventory`, realm 3). Input: required `name` (string matching `^devices/[1-9][0-9]{0,9}$`; add a `pattern` keyword to the string dialect for this). Output: `['device' => object]`. Not found or not permitted: `NOT_FOUND` / `DEVICE_NOT_FOUND`.
    - `cacti-get-system-info` (`system`, realm 15). Output: `['cactiVersion', 'pluginVersion', 'abilitiesApiVersion' => '2']`.

- [ ] **Step 1: Update `tests/providers.php`.** Keep the existing fakes. Assert the helper receives the token owner, and that no credential reaches output (the fixture row keeps `snmp_community` and adds `snmp_password`, `snmp_username`). Add:
  - `device_resource_fields`: output keys equal the allowed list exactly.
  - `device_zero_references_null`: `site_id = '0'` gives `site === null`, and `host_template_id = '5'` gives `deviceTemplate === 'deviceTemplates/5'`.
  - `filter_literal_escaped`: `filter: 'displayName = "x\" OR 1=1"'` reaches the fake `get_allowed_devices` as `$sql_where === "h1.description = 'x\\\" OR 1=1'"` (per the fake `db_qstr`).
  - `list_next_page_token`: a full page yields a token whose decoded offset is offset + size.
  - `plugin_registration_isolated`: a fake hook function that registers one valid ability and then throws leaves only the three built-ins, and `cacti_log` was called once.
- [ ] **Step 2: Run** `php tests/providers.php`. Expected: fails.
- [ ] **Step 3: Implement** `lib/providers.php` per the interfaces.
- [ ] **Step 4: Run** all test scripts. Expected: pass.
- [ ] **Step 5: Commit** `feat: serve devices and system info as AIP resources`.

### Task 6: CSRF workaround bootstrap

**Files:**
- Create: `lib/bootstrap.php`, `tests/bootstrap.php`

**Interfaces:**
- Produces:
  - `mcp_prepare_request(array &$server, array &$post, array &$request, array &$cookie): void` empties the three arrays, sets `$server['MCP_ORIGINAL_METHOD'] = $server['REQUEST_METHOD']` and `$server['REQUEST_METHOD'] = 'MCP_POST'`.
  - `mcp_bootstrap(array $options): array{method: string, body: string, authorization: string}` with `$options = ['methods' => list<string>, 'content_type' => 'application/json', 'max_bytes' => 65536, 'bearer' => true]`. Its order:
    1. Set the security headers (`Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, JSON content type).
    2. Reject a method not in `methods` with 405 and an `Allow` header.
    3. For POST, reject a wrong content type (415) or an oversize `CONTENT_LENGTH` (413).
    4. When `bearer` is set, check the header shape only (`^Bearer cmcp_pat_[a-f0-9]{64}$`) and reject with 401.
    5. Read `php://input` with a cap of `max_bytes + 1`.
    6. For POST only, call `mcp_prepare_request($_SERVER, $_POST, $_REQUEST, $_COOKIE)`.
    7. `require` `../../include/global.php` inside an output buffer, as `api.php` does today.
    8. `header_remove('Set-Cookie')`.
    9. Restore `$_SERVER['REQUEST_METHOD']` from `MCP_ORIGINAL_METHOD`.
    10. Check `api_plugin_is_enabled('mcp')` and the main poller.

    Errors before Cacti loads use `mcp_error_body()`. The function contains the comment block explaining why the workaround exists and that it goes away once core has a supported exemption.

- [ ] **Step 1: Write `tests/bootstrap.php`.** Test `prepare_strips_identity`: after `mcp_prepare_request` on arrays holding a `Cacti` cookie, a `__csrf_magic` POST field, and request vars, all three are empty, `REQUEST_METHOD === 'MCP_POST'`, and `MCP_ORIGINAL_METHOD === 'POST'`.
- [ ] **Step 2: Run** `php tests/bootstrap.php`. Expected: fails.
- [ ] **Step 3: Implement** `lib/bootstrap.php`.
- [ ] **Step 4: Run** `php tests/bootstrap.php`. Expected: pass.
- [ ] **Step 5: Commit** `feat: bootstrap token-only endpoints past the CSRF guard`.

The live checks on both Cacti versions are in Task 13.

### Task 7: HTTP Abilities API

**Files:**
- Create: `lib/api_http.php`, `openapi.json`, `tests/api.php`, `tests/contract.php`
- Modify: `api.php` (becomes a thin entry script)

**Interfaces:**
- Consumes: Tasks 2, 3, 5, 6; `mcp_authenticate()`, `mcp_audit()`.
- Produces: `mcp_api_handle(string $method, string $pathInfo, array $query, string $body, AbilitiesRegistry $registry, array $principal, callable $audit): array{status: int, headers: array<string, string>, body: array}`. Its routes:
  - `GET /v1/abilities`: List with `pageSize` (default 50, max 100), `pageToken`, and `filter` (only `category = "..."`). Returns `['abilities' => [Ability], 'nextPageToken' => ?string]`.
  - `GET /v1/abilities/{id}`: Get. Returns an Ability or `NOT_FOUND` / `ABILITY_NOT_FOUND`.
  - `POST /v1/abilities/{id}:execute` with body `{"arguments": {...}}`, a closed object. Returns `['requestId' => string, 'result' => object]`.
  - The Ability resource is `['name' => 'abilities/' . $id, 'displayName', 'description', 'category', 'inputSchema', 'outputSchema', 'annotations']`.
  - Any other path or method gives `NOT_FOUND` / `UNKNOWN_PATH`, and the message includes the path received (`''` when `PATH_INFO` is absent).
  - Every error body comes from `mcp_error_body()`.

  `api.php` calls `mcp_bootstrap(['methods' => ['GET', 'POST'], ...])` and `mcp_authenticate()`, then `mcp_api_handle(..., $_SERVER['PATH_INFO'] ?? '', $_GET, ...)`, and emits the result. A 401 adds `WWW-Authenticate: Bearer`.

- [ ] **Step 1: Write `tests/api.php`** against a registry with one fixture ability. Tests:
  - `api_list`: status 200, `abilities[0].name === 'abilities/fixture-get-thing'`.
  - `api_get_unpermitted_is_not_found`: status 404.
  - `api_execute`: status 200 with a 32-hex `requestId`.
  - `api_execute_rejects_extra_body_fields`: `{"arguments":{},"x":1}` gives status 400 with reason `INVALID_ARGUMENTS`.
  - `api_missing_path_info`: path `''` gives status 404, reason `UNKNOWN_PATH`.
  - `api_error_shape`: every non-200 body has `error.status` and `error.details[0].domain === 'cacti.net'`.
- [ ] **Step 2: Write `tests/contract.php`.** It loads `openapi.json` and asserts the path set equals `['/v1/abilities', '/v1/abilities/{ability}', '/v1/abilities/{ability}:execute']`, and that the `Ability` schema's property names equal the keys `mcp_api_handle` emits for the fixture.
- [ ] **Step 3: Run** both. Expected: fail.
- [ ] **Step 4: Implement** `lib/api_http.php`, rewrite `api.php`, and write `openapi.json` (OpenAPI 3.1, bearer security scheme, AIP-193 `Error` schema copied from the core branch's `api/openapi.json`).
- [ ] **Step 5: Run** all test scripts. Expected: pass.
- [ ] **Step 6: Commit** `feat: expose abilities through an AIP-style HTTP API`.

### Task 8: Public Cacti URL setting

**Files:**
- Modify: `setup.php`, `tests/lifecycle.php`
- Create: `lib/origin.php`, `tests/origin.php`

**Interfaces:**
- Produces:
  - Setting `mcp_public_url`, registered through `api_plugin_register_hook('mcp', 'config_settings', 'mcp_config_settings', 'setup.php')` in install, under a "MCP" tab. Text field, max 255. Description: "The address MCP clients use to reach this Cacti server, for example https://cacti.example.com/cacti. Required before the MCP endpoint answers."
  - `mcp_public_url(): ?string` returns a normalized value (lowercase scheme and host, no trailing slash, no query or fragment, scheme `https`, or `http` only for `localhost`/`127.0.0.1`/`::1`) or null when unset or invalid.
  - `mcp_canonical_uri(string $publicUrl): string` is `$publicUrl . '/plugins/mcp/mcp.php'`.
  - `mcp_origin_allowed(?string $origin, string $publicUrl): bool` is true when `$origin` is null, or when it equals the scheme://host[:port] of `$publicUrl`, ignoring the default port.

- [ ] **Step 1: Write `tests/origin.php`.** Tests:
  - `normalizes`: `'HTTPS://Cacti.Example.com/cacti/'` becomes `'https://cacti.example.com/cacti'`.
  - `rejects_http_public`: `'http://cacti.example.com'` gives null.
  - `origin_match`: `mcp_origin_allowed('https://cacti.example.com', 'https://cacti.example.com/cacti') === true`.
  - `origin_mismatch`: `'https://evil.example'` gives false.
  - `default_port`: `'https://cacti.example.com:443'` gives true.

  In `tests/lifecycle.php`, assert that install registers the `config_settings` hook.
- [ ] **Step 2: Run** `php tests/origin.php`. Expected: fails.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** all test scripts. Expected: pass.
- [ ] **Step 5: Commit** `feat: add the Public Cacti URL setting and origin checks`.

### Task 9: MCP endpoint

**Files:**
- Create: `lib/mcp_server.php`, `mcp.php`, `tests/mcp.php`

**Interfaces:**
- Consumes: Tasks 3, 6, 8; `mcp_authenticate()`, `mcp_audit()`, `mcp_error_body()`.
- Produces: `mcp_rpc_handle(string $httpMethod, array $headers, string $body, AbilitiesRegistry $registry, array $principal, callable $audit, array $serverInfo): array{status: int, headers: array<string, string>, body: ?array}`. `$headers` holds lowercase names. `$serverInfo` is `['name' => 'cacti-mcp', 'version' => plugin version]`. Behavior, in order:
  1. A GET or DELETE gets 405 with `Allow: POST`.
  2. A body that is not a single JSON object gets 400, JSON-RPC `-32700` or `-32600`, with no `id`.
  3. **Modern** requests are those whose `params._meta` has `io.modelcontextprotocol/protocolVersion`:
     - The version must be `2026-07-28`; otherwise 400 with `-32022` and `data: {supported: ['2026-07-28', '2025-11-25'], requested}`.
     - The `mcp-protocol-version` header must equal it, `mcp-method` must equal `method`, and for `tools/call` `mcp-name` must equal `params.name` (decoding `=?base64?...?=`). Otherwise 400 with `-32020`.
     - Methods: `server/discover` returns `supportedVersions`, `capabilities: {tools: {}}`, and `_meta['io.modelcontextprotocol/serverInfo']`; plus `tools/list`, `tools/call`, and `ping`.
     - An unknown method gets 404 with `-32601`.
     - Check every modern result's required fields (for example `resultType`) against the published 2026-07-28 `schema.json` before finishing this task, and note the check in the commit body.
  4. **Legacy** requests are everything else:
     - `initialize` returns `protocolVersion: '2025-11-25'` when the client asks for `2025-11-25` or `2025-06-18`, and `2025-11-25` otherwise, plus `capabilities: {tools: {}}` and `serverInfo`.
     - `notifications/initialized` and any other notification get 202 with no body.
     - `tools/list`, `tools/call`, and `ping` work, and an unknown method gets 200 with `-32601`.
     - The server never sends `Mcp-Session-Id` and ignores it when received.
  5. Tool mapping: `name` is the ability `id`, `title` is the `label`, and the tool carries `description`, `inputSchema`, `outputSchema`, and `annotations: {readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false}`. `tools/call` success returns `{content: [{type: 'text', text: <JSON of result>}], structuredContent: result}`. An `AbilitiesError` returns `{isError: true, content: [{type: 'text', text: <message>}], structuredContent: mcp_error_body($e)}`. Arguments that are missing or not an object are treated as `{}`.

  `mcp.php` order:
  1. Call `mcp_bootstrap(['methods' => ['POST', 'GET', 'DELETE'], ...])`, with `bearer` true for POST only.
  2. Require `mcp_public_url()`; when it is missing, return 503 `FAILED_PRECONDITION` / `PUBLIC_URL_REQUIRED` as a JSON-RPC error with no `id`.
  3. Check `mcp_origin_allowed()`; a mismatch gets 403 with a JSON-RPC error and no `id`.
  4. Call `mcp_authenticate()`. A 401 adds `WWW-Authenticate: Bearer`, which Plan 2 extends with `resource_metadata`.
  5. Call `mcp_rpc_handle()` and emit the result.

- [ ] **Step 1: Write `tests/mcp.php`** against a registry with one fixture ability. Tests:
  - `modern_discover`: status 200, `supportedVersions === ['2026-07-28', '2025-11-25']`.
  - `modern_header_mismatch`: `mcp-method: tools/list` with a body method of `tools/call` gives 400 and `-32020`.
  - `modern_unsupported_version`: `1900-01-01` gives 400, `-32022`, and `data.requested`.
  - `modern_unknown_method`: 404 and `-32601`.
  - `tools_list_mapping`: the tool `name`, `title`, and annotations match the mapping above.
  - `tools_call_structured`: `structuredContent` equals the fixture result.
  - `tools_call_ability_error`: `isError === true` and `structuredContent.error.status === 'NOT_FOUND'`.
  - `legacy_initialize`: `result.protocolVersion === '2025-11-25'`, with no `mcp-session-id` key in the headers.
  - `legacy_notification_202`: status 202, body null.
  - `legacy_get_delete_session`: GET gives 405, DELETE gives 405, and a POST carrying `mcp-session-id` succeeds without echoing it.
- [ ] **Step 2: Run** `php tests/mcp.php`. Expected: fails.
- [ ] **Step 3: Implement** `lib/mcp_server.php` and `mcp.php`.
- [ ] **Step 4: Run** all test scripts. Expected: pass.
- [ ] **Step 5: Commit** `feat: serve abilities as MCP tools over Streamable HTTP`.

### Task 10: Ability exposure

**Files:**
- Modify: `lib/registry.php`, `lib/storage.php`, `lib/providers.php`, `setup.php`, `tests/registry.php`, `tests/lifecycle.php`
- Create: none

**Interfaces:**
- Consumes: Tasks 3 and 5.
- Produces:
  - Table `plugin_mcp_ability`: `id VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY`, `exposed TINYINT UNSIGNED NOT NULL`, and `updated_at BIGINT UNSIGNED NOT NULL`. `mcp_install_schema()` creates it idempotently, `plugin_mcp_check_config()` requires it, and uninstall keeps it.
  - `AbilitiesRegistry::setExposure(callable $isExposed): void`, where `$isExposed` is `callable(string $id, string $namespace): bool`. `discover()`, `get()`, and `execute()` treat a hidden ability exactly like an unpermitted one: it is absent, `get()` returns null, and `execute()` throws `NOT_FOUND` / `ABILITY_NOT_FOUND`. The registry keeps every ability's namespace (`cacti` for built-ins, the plugin slug otherwise).
  - `AbilitiesRegistry::all(): array` returns every registered ability's descriptor plus `namespace`, ignoring principal and exposure. Only the admin page uses it.
  - `mcp_exposure_map(): array<string, bool>` reads all rows once per request. `mcp_is_exposed(string $id, string $namespace, array $map): bool` returns the stored value when present, otherwise `$namespace === 'cacti'`.
  - `mcp_set_exposure(array $ids, bool $exposed): void` upserts with `db_execute_prepared` (`INSERT ... ON DUPLICATE KEY UPDATE`).
  - `mcp_registry()` calls `setExposure()` with `mcp_is_exposed()` bound to `mcp_exposure_map()`.

- [ ] **Step 1: Add tests.** In `tests/registry.php`:
  - `hidden_ability_absent`: a hidden ability is missing from `discover()`, `get()` returns null, and `execute()` throws `ABILITY_NOT_FOUND`.
  - `exposure_never_grants`: an exposed ability whose `permission` returns false is still absent.
  - `all_ignores_exposure`: `all()` lists hidden abilities with their namespace.

  Add a pure test of `mcp_is_exposed()`: `plugin_default_hidden` (`mcp_is_exposed('thold-list-thresholds', 'thold', []) === false`), `builtin_default_exposed`, and `stored_value_wins`. In `tests/lifecycle.php`, install creates `plugin_mcp_ability` and check_config requires it.
- [ ] **Step 2: Run** `php tests/registry.php`. Expected: fails.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** all test scripts. Expected: pass.
- [ ] **Step 5: Commit** `feat: let admins choose which abilities are exposed`.

### Task 11: Admin console page

**Files:**
- Create: `mcp_admin.php`, `lib/admin.php`, `tests/admin.php`
- Modify: `setup.php`, `lib/storage.php`, `tests/lifecycle.php`

**Interfaces:**
- Consumes: Tasks 4 and 10 (`mcp_token_hash()`, `AbilitiesRegistry::all()`, `mcp_exposure_map()`, `mcp_set_exposure()`).
- Produces:
  - A second realm, `api_plugin_register_realm('mcp', 'mcp_admin.php', __('MCP Administration', 'mcp'), true)`, which grants the installing admin.
  - Install registers hooks `config_arrays` (`mcp_config_arrays`, which adds `$menu[__('Management')]['plugins/mcp/mcp_admin.php'] = __('MCP', 'mcp')`) and `draw_navigation_text` (`mcp_draw_navigation_text`). Follow the pattern in the sibling plugin `~/Developer/github.com/Cacti/plugin_fabric/setup.php` (its `fabric_config_arrays` and `fabric_draw_navigation_text`).
  - `mcp_admin.php` is a normal Cacti console page: `include('../../include/auth.php')`, `top_header()` / `bottom_footer()`, tabs `abilities` (default), `tokens`, and `audit` selected with `get_filter_request_var('tab', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^(abilities|tokens|audit)$/']])`. Actions are POSTs with Cacti's CSRF, using `form_actions` and `html_start_box()`. Do not use `mcp_bootstrap()` here.
  - Storage helpers:
    - `mcp_token_list(array $filter, int $page, int $rows): array{rows: array, total: int}`, with columns id, username, created_at, expires_at, last_used_at, and revoked.
    - `mcp_token_create(int $userId, int $days): string`, which generates `'cmcp_pat_' . bin2hex(random_bytes(32))`, stores only the hash, and returns the token once.
    - `mcp_token_revoke(array $ids): void`.
    - `mcp_audit_list(array $filter, int $page, int $rows): array{rows: array, total: int}`, filtering on `user_id`, `ability`, and `outcome`, ordered by `started_at DESC`.
    - Add `created_at` and `last_used_at` columns to `plugin_mcp_token` if Task 4 left them out. `mcp_authenticate()` updates `last_used_at` at most once per minute per token.
  - `lib/admin.php` holds pure helpers the page renders from, so they can be tested:
    - `mcp_admin_ability_rows(array $all, array $exposureMap, callable $realmName): array` returns rows of `id`, `label`, `category`, `namespace`, `realm`, `annotations`, and `exposed`.
    - `mcp_admin_parse_action(array $post): array{action: string, ids: list<string>|list<int>}`. Actions are `expose`, `hide`, and `revoke`. IDs come from Cacti's `chk_` checkbox convention and are validated: ability IDs against the ID regex, token IDs as positive ints.
  - Abilities tab: a table with an Exposed column and bulk expose and hide actions. Tokens tab: a table, a revoke action, and a create form (user select from enabled `user_auth` rows, expiry days 1 to 365, default 90) that shows the new token once in a copyable read-only field with the text "Copy this token now. It is not shown again." Audit tab: a filter row (user, ability, outcome) and `html_nav_bar` paging. All strings use `__('...', 'mcp')`.

- [ ] **Step 1: Write `tests/admin.php`.**
  - `ability_rows_show_exposure`: a hidden plugin ability has `exposed === false` and `namespace === 'thold'`.
  - `parse_action_validates_ids`: `chk_cacti-get-device` and `chk_bad.id` yield only `cacti-get-device`.
  - `parse_action_rejects_unknown_action`: `action = drop` throws `InvalidArgumentException`.
  - `token_create_shape`: with fake DB functions, the returned token passes `mcp_token_hash()`, and the stored hash equals `hash('sha256', token)`.

  In `tests/lifecycle.php`, install registers the admin realm and both hooks.
- [ ] **Step 2: Run** `php tests/admin.php`. Expected: fails.
- [ ] **Step 3: Implement** `lib/admin.php`, the storage helpers, `setup.php` hooks, and `mcp_admin.php`.
- [ ] **Step 4: Run** all test scripts and `php -l mcp_admin.php`. Expected: pass.
- [ ] **Step 5: Commit** `feat: add console pages for abilities, tokens, and audit`.

### Task 12: Documentation

**Files:**
- Modify: `README.md`, `docs/plan.md`

- [ ] **Step 1: Rewrite the README sections** for the rename:
  - The abilities table with new IDs and realms.
  - The ability v2 registration example for plugin authors, using `AbilitiesRegistrar` through the `abilities_register` hook.
  - The HTTP API routes, and the token format with its generator command.
  - The Public Cacti URL setting.
  - The Management > MCP console page: exposing abilities (plugin abilities start hidden), creating and revoking tokens, and reading the audit log.
  - Client setup for Claude Code (`claude mcp add --transport http cacti https://<host>/<path>/plugins/mcp/mcp.php --header "Authorization: Bearer $CACTI_MCP_TOKEN"`) and VS Code (`.vscode/mcp.json` with `type: http`, `url`, and `headers`).
  - The nginx `fastcgi_split_path_info ^(.+\.php)(/.+)$;` and `fastcgi_param PATH_INFO $fastcgi_path_info;` lines needed for `api.php` routes.
  - A short "Security model" list: bearer only, cookies ignored, the CSRF workaround and why it is safe, read-only only, and the audit table.

  Verify the `claude mcp add` flags with `claude mcp add --help` before writing them.
- [ ] **Step 2: Update `docs/plan.md`** to name the package `plugin_mcp` and point to the spec and both plans.
- [ ] **Step 3: Check** `grep -n -i 'plugin_abilities\|abilities.php\|cacti\.devices' README.md docs/plan.md`. Expected: no matches.
- [ ] **Step 4: Commit** `docs: document the MCP endpoint and ability v2 contract`.

### Task 13: Live verification on Cacti 1.2.31 and develop

No new files unless a defect is found; a defect goes back to the task that owns it.

- [ ] **Step 1:** Install the plugin into a Cacti 1.2.31 instance and a Cacti develop instance (any local Docker or VM setup the implementer has). Enable it, grant a test user the plugin realm plus realm 3, set the Public Cacti URL, and create a token with `tokens.php`.
- [ ] **Step 2:** For each instance, run this check and record the results in the PR description:
  - `curl` a POST with JSON `tools/list` to `mcp.php` with the bearer token. Expected: 200 with three tools.
  - The same request with the Cacti session cookie and no bearer token. Expected: 401.
  - A console form POST without a CSRF token (for example `host.php` with `action=save`). Expected: the CSRF failure page, which proves the guard is still active.
  - `GET api.php/v1/abilities`. Expected: 200, or the documented PATH_INFO 404 on a server not configured for it.
  - On the Management > MCP page, hide `cacti-get-device`. Expected: it disappears from `tools/list`. Expose it again.
  - Create a token from the Tokens tab and use it for the next check.
  - The MCP Inspector (`npx @modelcontextprotocol/inspector`), connected over Streamable HTTP with the header. Expected: tools list, and `cacti-list-devices` returns devices.
- [ ] **Step 3:** Connect Claude Code with the README command and call `cacti-get-system-info`. Record which protocol revision it used (from the access log or a temporary `cacti_log` line that is removed afterwards).

---
### Task 16: Self-service token page and guest guard

Runs after Task 13. Views follow Cacti's own permissions; this task adds no new roles or realms.

**Files:**
- Create: `mcp_tokens.php`, `tests/self_service.php`
- Modify: `setup.php`, `lib/admin.php`, `lib/storage.php`, `mcp_admin.php`, `tokens.php`, `README.md`, `tests/admin.php`, `tests/lifecycle.php`, `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: Task 11 (`mcp_token_create(int $userId, int $days, ?int $createdBy)`, `mcp_token_list()`, `mcp_token_revoke()`, `mcp_audit_list()`, `mcp_admin_nonce_issue()` / `mcp_admin_nonce_consume()`, `mcp_admin_may_create_for()`).
- Produces:
  - `mcp_token_eligible(array $user, int $guestUserId): bool` in `lib/admin.php`. It returns false when the user is disabled (`enabled !== 'on'`), when `id === $guestUserId` and `$guestUserId > 0`, or when the user lacks the MCP and Abilities API realm. The guest id comes from `read_config_option('guest_user')`.
  - `mcp_token_create()` throws `InvalidArgumentException` for an ineligible user. The console, `tokens.php`, and `mcp_tokens.php` all go through it. `mcp_authenticate()` also refuses a token whose owner is the guest user, so old tokens stop working if an account becomes the guest.
  - `mcp_token_revoke(array $ids, ?int $ownerId = null): int` revokes only tokens owned by `$ownerId` when it is given, and returns the number of rows changed. Audit rows are written only for revoked ids.
  - `mcp_tokens.php` is a console page under the existing `api.php,mcp.php` realm, reached from the user menu as "My MCP Tokens". The menu entry is added through the `config_arrays` hook, and the realm row gains `mcp_tokens.php` in its file list. Its three sections act only on `$_SESSION['sess_user_id']`:
    - Tokens: list, create (expiry 1 to 365 days, default 90, nonce, shown once), and revoke, all scoped to the owner.
    - Activity: that user's audit rows, paged.
    - Available tools: a read-only list of `mcp_registry()->discover($principal)` for that user, with each ability's `id`, `label`, and `description`.
  - The Management > MCP tokens tab and `mcp_tokens.php` show "Tokens cannot be created for the guest account or disabled users." when the target is ineligible.

- [ ] **Step 1: Write tests.**
  - `tests/self_service.php`:
    - `eligible_rules`: covers disabled, guest, missing realm, and a normal user.
    - `create_refuses_guest`: `mcp_token_create($guestId, 90)` throws.
    - `revoke_scoped_to_owner`: user A revoking user B's token changes 0 rows and writes no audit row.
    - `available_tools_follow_principal`: a hidden or unpermitted ability is absent.
    - `authenticate_refuses_guest_token`.
  - `tests/admin.php`: the console create path refuses a guest target.
  - `tests/lifecycle.php`: the realm file list includes `mcp_tokens.php`, and the menu hook is registered.
- [ ] **Step 2: Run** `php tests/self_service.php`. Expected: fails.
- [ ] **Step 3: Implement.** Verify `guest_user` and the user-menu array on 1.2.31 and develop before relying on them.
- [ ] **Step 4: Run** all test scripts and `php -l mcp_tokens.php`. Expected: pass. Then repeat the Task 13 console checks for this page on both instances.
- [ ] **Step 5: Update the README** with a "My MCP Tokens" section and the guest rule, then commit `feat: add self-service MCP tokens and refuse guest tokens`.


## cacti-mcp tasks

Repository `somethingwithproof/cacti-mcp`, branch `fix/mcp-2-sdk` from `develop` for Task 14, then `feat/mcp-plugin-api` for Task 15. Run `uv sync` first; that installs the project's locked dependencies.

### Task 14: Port to the mcp 2.x SDK

**Files:**
- Modify: `src/cacti_mcp/server.py`

**Interfaces:**
- Produces: `create_server(client: AbilitiesClient) -> Server`, built as `Server('cacti-mcp', version=__version__, on_list_tools=..., on_call_tool=...)`. The handlers are `async (ctx: ServerRequestContext, params) -> ListToolsResult | CallToolResult` per the SDK 2.x migration guide. `Tool` uses `input_schema=`, and `CallToolResult` uses `is_error=` and `structured_content=`. Check the field names against the installed SDK with `uv run python -c "import mcp.types as t; print(t.Tool.model_fields.keys(), t.CallToolResult.model_fields.keys())"`. Confirm how `stdio_server()` and `server.run()` are called in 2.x the same way.

- [ ] **Step 1: Run** `uv run pytest`. Expected: `test_sdk_discovery_execution_validation_and_revocation` fails with `'Server' object has no attribute 'list_tools'`. This is the existing failing test.
- [ ] **Step 2: Port** `create_server()` and `serve()`.
- [ ] **Step 3: Run** `uv run pytest && uv run ruff check && uv run ruff format --check`. Expected: 32 passed, lint clean.
- [ ] **Step 4: Commit** `fix: port the server to the mcp 2.x SDK`. Open the PR to `develop` when the user asks.

### Task 15: Use the plugin's v2 HTTP API

**Files:**
- Modify: `src/cacti_mcp/client.py`, `src/cacti_mcp/server.py`, `tests/test_client.py`, `tests/test_stdio.py`, `tests/test_cli.py`, `README.md`

**Interfaces:**
- Consumes: the Task 7 routes and response shapes; the Task 4 token format.
- Produces:
  - `AbilitiesClient(endpoint, token, allow_loopback_http=False)`. The endpoint must end with `/plugins/mcp/api.php`, and the token must match `^cmcp_pat_[a-f0-9]{64}$`, otherwise `ValueError`.
  - `discover() -> list[dict]` follows `nextPageToken` across `GET {endpoint}/v1/abilities`, up to 256 items. Descriptors are validated against the v2 shape: `name` matches `^abilities/[a-z][a-z0-9]*(-[a-z0-9]+){1,7}$`, and annotations are exactly read-only, non-destructive, and idempotent.
  - `execute(name, arguments) -> dict` sends `POST {endpoint}/v1/abilities/{id}:execute` with JSON `{"arguments": ...}` and returns `result`. There is no cookie jar and no CSRF step. An error body's `error.status` and `error.details[0].reason` go into `AbilitiesError`'s message; the HTTP body is never shown otherwise.
  - The server maps `title`, `outputSchema`, and annotations from the descriptor, and returns `structured_content`.

- [ ] **Step 1: Update tests.**
  - `test_client.py`:
    - Replace the CSRF handshake test with `test_bearer_only_no_cookies`, which asserts no `Cookie` header on the second request even after the server sends `Set-Cookie`.
    - Add `test_discover_follows_page_tokens`.
    - Add `test_rejects_dotted_ability_name`.
    - Update `ENDPOINT` and the unsafe-endpoint cases to `/plugins/mcp/api.php`.
  - `test_stdio.py`: update the fixture HTTP server to the v2 routes.
  - `test_cli.py`: help text names `/plugins/mcp/api.php`.
- [ ] **Step 2: Run** `uv run pytest`. Expected: the new and updated tests fail.
- [ ] **Step 3: Implement.** Update the README for the rename and the new token format, and say the plugin also serves MCP over HTTP directly.
- [ ] **Step 4: Run** `uv run pytest && uv run ruff check && uv run ruff format --check`. Expected: pass.
- [ ] **Step 5: Commit** `feat: target the Cacti MCP plugin's v2 Abilities API`.

## Follow-up plans

Out of scope for this plan. Each entry below becomes its own plan, in this order, after Task 15 and the final review.

1. **Plan 2: OAuth 2.1 and connected apps.** Hosted ChatGPT and Claude connectors cannot send a personal access token, so this plan is what lets most target users connect.
   - RFC 9728 protected resource metadata, and `WWW-Authenticate` with `resource_metadata`.
   - Client ID Metadata Documents preferred; dynamic client registration only as a deprecated fallback.
   - RFC 8707 resource binding to the Public Cacti URL, and RFC 9207 `iss`.
   - `cmcp_at_` and `cmcp_rt_` tokens.
   - A console page for connected apps, with revoke.
2. **Graph data ability.** `cacti-get-graph-data` takes a graph or data source, a time range and a consolidation function. It returns RRD series through Cacti's fetch layer, with a cap on points and series. Device lists alone answer few real questions.
3. **Request limits.** Per-token rate limits, a maximum result size, and an execution timeout, each returned as AIP-193 `RESOURCE_EXHAUSTED` or `DEADLINE_EXCEEDED`, so a runaway agent loop cannot load the poller database.
4. **Core CSRF exemption.** Upstream a supported exemption for token-only endpoints (building on `CACTI_API_REQUEST` from `feature/rest-api-tokens`), then remove the Task 6 workaround once a released Cacti carries it.
5. **More read abilities.** Data sources, graph trees, poller status, a filtered Cacti log tail, and threshold breaches through thold's own `abilities_register` hook.
6. **Narrower tokens.** A token limited to chosen abilities or a device subset, with a short default expiry for shared assistants.
7. **Plugin author kit.** A documented `abilities_register` example, a sample plugin, and a check script that validates schemas, the id prefix and annotations.
8. **Write abilities (deferred).** Acknowledge an alert, set a maintenance window, disable a device. Each needs a separate `abilities:write` scope, is off by default, sets `destructiveHint`, and records before and after values in the audit. Start only after the read-only surface has shipped and been adopted.
9. **MCP prompts and resources (optional).** Prompts such as "triage a down device", and graph images as resources. Low priority because client support varies.
