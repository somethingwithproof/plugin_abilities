<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent
SPDX-License-Identifier: GPL-2.0-or-later
-->

<h1 align="center">
	<picture>
		<source media="(prefers-color-scheme: dark)" srcset="images/mcp_logo_dark.png">
		<img src="images/mcp_logo.png" width="360" alt="Cacti MCP: a cactus with a shield. Discover. Authorize. Execute.">
	</picture>
</h1>

<p align="center">
	<a href="https://github.com/somethingwithproof/plugin_mcp/actions/workflows/ci.yml"><img src="https://github.com/somethingwithproof/plugin_mcp/actions/workflows/ci.yml/badge.svg?branch=develop" alt="CI"></a>
	<a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" alt="License: GPL-2.0-or-later"></a>
	<img src="https://img.shields.io/badge/Cacti-1.2.31%2B-2e7d32" alt="Cacti 1.2.31+">
	<img src="https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&amp;logoColor=white" alt="PHP 8.2+">
	<img src="https://img.shields.io/badge/Abilities_API-v2-2e7d32" alt="Abilities API v2">
</p>

Cacti MCP is a Cacti plugin that lets AI clients read your Cacti data through
named, permission-filtered operations called abilities. It serves them two ways
from the plugin itself: an MCP endpoint (`mcp.php`, Streamable HTTP) and an
AIP-style HTTP API (`api.php`). Cacti stays in charge of authorization and
execution. A client never queries the database; it can only call an ability that
an administrator exposed and that the token owner's Cacti realms allow.

Plugin authors can register their own read-only abilities through the
`abilities_register` hook, and they appear to MCP clients as tools with no
protocol code.

**Status: pre-release, version 0.1.0.** There is no tagged release.

Available today:

- Read-only built-in abilities for system versions and device inventory.
- Personal access tokens (`cmcp_pat_...`) as the only credential.
- The Management > MCP console: expose or hide abilities, create and revoke
  tokens, read the audit log.
- MCP over Streamable HTTP, protocol revisions `2026-07-28` and `2025-11-25`,
  tools only.

Not available yet:

- OAuth 2.1 sign-in. Hosted connectors that cannot send a personal token, such as
  ChatGPT and claude.ai custom connectors, cannot connect until it exists. It is
  planned as Plan 2; that plan is not written yet.
- Write abilities. Only read-only abilities can register.

The design is in the
[endpoint design spec](docs/superpowers/specs/2026-11-19-mcp-endpoint-design.md),
and the build sequence is in [docs/plan.md](docs/plan.md).

## Built-in abilities

| ID | Arguments | Cacti realm needed |
| --- | --- | --- |
| `cacti-get-system-info` | none | 15 |
| `cacti-list-devices` | optional `pageSize`, `pageToken`, `filter`, `orderBy` | 3 |
| `cacti-get-device` | required `name`, such as `devices/12` | 3 |

Every call also needs the **MCP and Abilities API** realm for the token owner,
the `abilities:read` scope (every personal token has it), and an exposed
ability. The Abilities tab of the console shows the realm each built-in needs.

- `cacti-list-devices` returns pages of up to 100 devices (default 50) and a
  `nextPageToken`. A page token is valid only for the same `filter` and
  `orderBy`. `filter` is an AIP-160 expression of comparisons joined by `AND`,
  for example `state = "DOWN" AND disabled = false`. Filter fields are
  `displayName`, `hostname`, `disabled`, `site`, `poller`, `deviceTemplate`,
  `externalId`, and `state`. `orderBy` takes `displayName`, `hostname`, or `name`,
  each optionally followed by `desc`.
- Device queries go through Cacti's `get_allowed_devices()`, so a user sees only
  the devices Cacti would show them.
- Device fields exclude SNMP community strings, passphrases, and other
  credentials.

## Install

Requires Cacti 1.2.31 or later, PHP 8.2 or later, and the main poller. Cacti
authentication must be enabled; the plugin refuses to authenticate when it is off.

The plugin directory must be named `mcp`, not `plugin_mcp`. The plugin slug, realm names,
translation domain, and URLs all use `mcp`.

From a source checkout:

```console
cd /path/to/cacti/plugins
git clone https://github.com/somethingwithproof/plugin_mcp.git mcp
```

Until tagged releases exist, you can instead download the `mcp-plugin`
artifact from a successful
[CI run](https://github.com/somethingwithproof/plugin_mcp/actions/workflows/ci.yml).
It contains `mcp.tar.gz` and `SHA256SUMS`. Verify the checksum, then extract
`mcp.tar.gz` inside Cacti's `plugins/` directory so the result is `plugins/mcp/`.
GitHub's automatic source archives use a different top-level directory name.

Then:

1. In Plugin Management, install and enable **Cacti MCP**. The installing
   administrator receives the **MCP Administration** realm automatically.
2. Under Settings > MCP, set **Public Cacti URL** to the address clients use, for
   example `https://cacti.example.com/cacti`. HTTPS is required except for
   `localhost`, `127.0.0.1`, and `[::1]`. `mcp.php` returns `FAILED_PRECONDITION`
   (`PUBLIC_URL_REQUIRED`) until this is set. `api.php` does not read it.
3. Grant the **MCP and Abilities API** realm to each user who will own a token.
   No user receives it automatically.
4. Make sure the web server passes the `Authorization` header and path info to PHP
   (see below).
5. Open Management > MCP and expose the abilities you want clients to see.

**Upgrading from a development build.** The plugin is pre-release, and its tables
changed during development without migrations. If you installed an earlier
build, uninstall it and install again. Reinstalling keeps tables that already
exist, so drop the old `plugin_mcp_token`, `plugin_mcp_audit`, and
`plugin_mcp_ability` tables first (and the equivalent tables from
builds made before the rename). Back them up if you need their history; tokens from
those builds are revoked on uninstall and do not carry over.

### Web server

Apache setups that strip `Authorization` before PHP need this in the relevant
virtual host or directory, followed by a reload:

```apache
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
```

`api.php` routes live in the path after the script name (`/plugins/mcp/api.php/v1/abilities`),
so nginx must split the path info. In the `location ~ \.php$` block that serves Cacti:

```nginx
fastcgi_split_path_info ^(.+\.php)(/.+)$;
fastcgi_param PATH_INFO $fastcgi_path_info;
```

`mcp.php` has no path routes and does not need them.

## Console

Management > MCP has three tabs. You need the **MCP Administration** realm.

- **Abilities** lists every registered ability with its plugin, category, the
  realm it needs, and whether it is exposed. Select rows and choose Expose or
  Hide; you confirm before the change applies. Built-in `cacti-*` abilities are
  exposed by default. Abilities from other plugins start hidden, so installing a
  plugin never publishes anything until an administrator decides to. Exposure
  narrows access only: a user still needs the ability's realm.
- **Tokens** lists tokens with owner, creator, creation, expiry, last use, and
  state (Active, Expired, Revoked). Select rows and choose Revoke to revoke them.
  Revoked tokens cannot be used again. Create Token makes a new one.
- **Audit** lists audit rows, newest first, and filters by user, ability, and
  outcome.

## Tokens

A personal token reads Cacti as its owner. It has the form `cmcp_pat_` followed by
64 lowercase hex characters and carries the single scope `abilities:read`. What it
can see is decided by its owner's Cacti realms and device permissions, not by the
token. The owner must be an enabled user who holds the **MCP and Abilities API**
realm.

Tokens are always created for a user, expire, and can be revoked:

- **Console.** Management > MCP > Tokens > Create Token. Lifetime is 1 to 365 days
  and defaults to 90. The token is generated by the server and shown once on the
  response page, so copy it into your secret manager immediately. You can create a
  token for yourself. Creating one for another user also needs the Users/Groups
  realm, because the token reads Cacti as that user.
- **Command line.** `tokens.php` runs only from the CLI, as an OS account that can
  administer Cacti. It takes the token from the environment so the value never
  appears on a command line or in the process list. Lifetime is 1 to 90 days and
  defaults to 30.

  ```console
  export CACTI_MCP_TOKEN="$(printf 'cmcp_pat_%s\n' "$(openssl rand -hex 32)")"
  # Store this value in your secret manager before you end this shell session.
  php plugins/mcp/tokens.php --create=USERNAME --days=30
  php plugins/mcp/tokens.php --list
  php plugins/mcp/tokens.php --revoke=ID
  ```

The database stores only the SHA-256 hash of the token, so a lost token cannot be
recovered; revoke it and create another. Rotation is the same: create a new token,
switch the client, then revoke the old one. An expired token, a revoked token, a
disabled owner, or a lost realm each fail on the next request. Tokens are accepted only in the `Authorization: Bearer`
header; a token in the query string or body is not accepted.

## Connect a client

Use the MCP endpoint `https://cacti.example.com/cacti/plugins/mcp/mcp.php`, with the
token in an `Authorization: Bearer` header. Keep the token in an environment
variable or secret manager, not in a committed file.

### Claude Code

```console
claude mcp add --transport http cacti https://cacti.example.com/cacti/plugins/mcp/mcp.php \
  --header "Authorization: Bearer $CACTI_MCP_TOKEN"
```

`--scope` chooses where the entry is stored (`local`, `user`, or `project`; the
default is `local`). The shell expands `$CACTI_MCP_TOKEN` when you run the command, so
the token is written into Claude Code's config file for that scope. Keep that file
out of version control, and prefer `local` or `user` over `project`.

### VS Code

Add `.vscode/mcp.json`. VS Code prompts for the token on first use and stores it
securely, so the file holds no secret:

```json
{
  "inputs": [
    {
      "type": "promptString",
      "id": "cacti-mcp-token",
      "description": "Cacti MCP token",
      "password": true
    }
  ],
  "servers": {
    "cacti": {
      "type": "http",
      "url": "https://cacti.example.com/cacti/plugins/mcp/mcp.php",
      "headers": {
        "Authorization": "Bearer ${input:cacti-mcp-token}"
      }
    }
  }
}
```

The `servers` key and `inputs` apply to the editor-managed `.vscode/mcp.json`
format. See the
[VS Code MCP configuration reference](https://code.visualstudio.com/docs/agents/reference/mcp-configuration).

### Clients that only speak stdio

[cacti-mcp](https://github.com/somethingwithproof/cacti-mcp) is an optional adapter
that runs as a local stdio MCP server and calls the plugin's HTTP API. Use it for a
client that cannot connect to a remote HTTP server. It needs the same personal
token and talks to `api.php`.

### MCP behavior

The endpoint serves tools only: each exposed, permitted ability is a tool named by
its ID, with its input and output schemas and read-only annotations. It keeps no
session, does not send `Mcp-Session-Id`, and does not accept JSON-RPC batches.
Only `POST` is allowed. An ability error comes back as a tool result with
`isError: true`, not as a protocol error. Requests using the `2026-07-28`
revision must carry matching `Mcp-Protocol-Version`, `Mcp-Method`, and (for
`tools/call`) `Mcp-Name` headers.

## HTTP API

Base URL: `https://cacti.example.com/cacti/plugins/mcp/api.php`. The contract is
in [openapi.json](openapi.json).

| Method and path | Purpose |
| --- | --- |
| `GET /v1/abilities` | List permitted abilities. Query: `pageSize`, `pageToken`, and `filter` of the form `category = "inventory"`. |
| `GET /v1/abilities/{id}` | Read one ability. |
| `POST /v1/abilities/{id}:execute` | Run it. The body is exactly `{"arguments": {...}}`. The response is `{"requestId": "...", "result": {...}}`. |

```console
curl -sS -H "Authorization: Bearer $CACTI_MCP_TOKEN" \
  https://cacti.example.com/cacti/plugins/mcp/api.php/v1/abilities
curl -sS -H "Authorization: Bearer $CACTI_MCP_TOKEN" -H 'Content-Type: application/json' \
  -d '{"arguments": {"filter": "state = \"DOWN\""}}' \
  'https://cacti.example.com/cacti/plugins/mcp/api.php/v1/abilities/cacti-list-devices:execute'
```

Errors follow AIP-193: an `error` object with `code`, `message`, `status`, and
`details` that carries an `ErrorInfo` with domain `cacti.net` and a stable `reason`.
Request bodies are limited to 64 KB, and results to 1 MB. Both endpoints answer
only on the main poller and return `UNAVAILABLE` (`PLUGIN_UNAVAILABLE`) elsewhere.

## Write an ability (plugin authors)

An enabled Cacti plugin registers abilities from a function it attaches to the
`abilities_register` hook, usually in its `plugin_<slug>_install()`:

```php
api_plugin_register_hook('acme', 'abilities_register', 'acme_abilities_register', 'setup.php');
```

The function receives an `AbilitiesRegistrar` scoped to the plugin. Its return
value is ignored.

```php
function acme_abilities_register(AbilitiesRegistrar $registrar) : void {
	$registrar->registerCategory('acme-widgets', 'Widgets');

	$registrar->register('acme-get-widget-count', [
		'label'         => 'Get widget count',
		'description'   => 'Count the widgets the caller may see.',
		'category'      => 'acme-widgets',
		'input_schema'  => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
		'output_schema' => [
			'type'                 => 'object',
			'properties'           => ['count' => ['type' => 'integer', 'minimum' => 0]],
			'required'             => ['count'],
			'additionalProperties' => false
		],
		'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
		'permission'  => fn (int $userId) => is_realm_allowed(ACME_REALM_ID, $userId),
		'execute'     => fn (int $userId, stdClass $args) => ['count' => acme_count_widgets($userId)]
	]);
}
```

The rules, all enforced at registration:

- **IDs.** An ID matches `^[a-z][a-z0-9]*(-[a-z0-9]+){1,7}$`, is at most 64
  characters, and must start with the plugin's slug and a hyphen: `acme-` above.
  The slug is the plugin directory name, so a directory such as `my_plugin` cannot
  register anything. A plugin directory named `cacti` is refused; that
  namespace belongs to the built-ins.
- **Definition.** Exactly these keys: `label`, `description` (up to 4096 bytes),
  `category`, `input_schema`, `output_schema`, `annotations`, `permission`,
  `execute`. The category must already exist: use a built-in (`inventory`,
  `system`) or register your own with `registerCategory()`, whose ID is unique across
  all plugins.
- **Read-only only.** `annotations` must be exactly `readOnly: true`,
  `destructive: false`, `idempotent: true`. Anything else throws.
- **Schemas.** `input_schema` and `output_schema` must each have top-level
  `type` `object`. The dialect is small and fails closed. Objects must be closed
  (`additionalProperties: false`, with `properties` present); other types are
  `integer`, `number`, `string` (`minLength`, `maxLength`, `enum`, `pattern`),
  `boolean`, and `array` (which needs `items` and `maxItems`). A type may be
  made nullable as `['string', 'null']`. Strings default to at most 4096
  characters. At most 256 abilities can register.
- **`permission(int $userId): bool`.** Must return exactly `true` to allow. The
  user ID is the token owner's; never read session state.
- **`execute(int $userId, stdClass $args): array`.** `$args` is already validated
  against `input_schema`. Enforce object-level permissions yourself. Return an
  associative array that matches `output_schema` exactly; a list, an extra field,
  or more than 1 MB of JSON becomes an `INVALID_RESULT` error. Throw
  `AbilitiesError` for a client-visible failure, such as `NOT_FOUND`.
- **Empty objects.** PHP encodes `[]` as a JSON array. A top-level empty result may
  be `[]`, and the registry treats it as an empty object. A nested field whose
  schema is `object` and that may be empty must be `new stdClass()`; `[]` there
  fails validation with `INVALID_RESULT`. Use `new stdClass()` for an empty
  `properties` map in schemas too, so the schema encodes as an object.
- **Failure isolation.** Each plugin registers into a scratch copy that is kept
  only if your function returns normally. If it throws, none of your abilities
  register, and the failure is logged.

Abilities from plugins start hidden. The administrator exposes them on the
console Abilities tab, and the Required Realm column reads "Set by the plugin"
for them.

## Security model

- **Bearer only.** `api.php` and `mcp.php` accept a personal token in the
  `Authorization` header and nothing else. They drop cookies, `$_POST`, and
  `$_REQUEST` before Cacti loads and destroy the session Cacti starts, so a browser
  login never grants API access.
- **CSRF workaround.** Cacti's CSRF guard rejects a JSON POST that has no Cacti CSRF
  token, which no MCP client can send. For these two endpoints only, the plugin
  renames the request method before Cacti loads and restores it afterward, so the
  guard skips them. This is safe because CSRF protects requests a browser
  authenticates with cookies, and these endpoints accept no cookie identity: a
  cross-site page cannot attach the bearer token. Console pages keep full CSRF
  protection. The workaround is temporary, until Cacti offers a supported
  exemption; see `lib/bootstrap.php`.
- **Origin check.** On `mcp.php`, if the request has an `Origin` header it must be
  exactly the scheme, host, and port of the Public Cacti URL, or the request is
  refused with `ORIGIN_NOT_ALLOWED`. A request with no `Origin` header, which is how
  non-browser clients connect, is allowed. This stops a web page from driving the
  endpoint from a visitor's browser. `api.php` does not apply this check.
- **Read-only only.** Registration rejects any ability that is not read-only,
  non-destructive, and idempotent.
- **Exposure and realms both apply.** An ability must be exposed and the token
  owner must hold its realm. If the exposure table cannot be read, every ability is
  hidden rather than falling back to defaults.
- **Hidden, unknown, and unpermitted look the same.** A client cannot tell an
  ability that does not exist from one that is hidden or that its user may not
  use: discovery omits it and execution returns `NOT_FOUND`
  (`ABILITY_NOT_FOUND`).
- **Tokens.** Stored as SHA-256 hashes, shown once, always expiring, revocable, and
  rejected when the owner is disabled or loses the realm.
- **Audit.** `plugin_mcp_audit` records request ID, token ID, user ID, ability,
  outcome (`started`, `succeeded`, `failed`, `denied`, `rejected`), and timestamps.
  It never records arguments, results, or token values. An ability runs only after
  its `started` row is written; if the audit table is unavailable the call fails
  with `UNAVAILABLE` and does not run. Token creation and revocation in the console
  are recorded as `mcp-token-create` and `mcp-token-revoke`. Discovery is not logged.
- **Main poller only.**

## Lifecycle

Disabling the plugin blocks both endpoints. Uninstalling revokes every token and
keeps the three tables (`plugin_mcp_token`, `plugin_mcp_audit`,
`plugin_mcp_ability`) for retention; reinstalling does not reactivate a revoked
token. Back up the tables with the Cacti database and apply an audit retention
policy outside the plugin.

## Development and tests

Tests are plain PHP scripts that run without Cacti:

```console
for t in tests/*.php; do php "$t" || break; done
git archive --format=tar.gz --prefix=mcp/ HEAD > mcp.tar.gz
```

CI lints PHP and runs every script in `tests/` on PHP 8.2 through 8.5, then
builds the installable `mcp/` archive and its checksum. Tests that touch Cacti use
fixtures at the Cacti helper boundary; they do not exercise a full Cacti install.

## Contributing

Pull requests go to `develop`. Keep production code compatible with PHP 8.2 and
use Cacti's style: tabs, single-quoted strings, and Cacti's database and
permission helpers. Add tests for changed validation, authorization, or protocol
behavior, and sign off commits with `git commit -s`. Coordinate changes to the HTTP
API with [cacti-mcp](https://github.com/somethingwithproof/cacti-mcp).

## License

Copyright (C) 2026 Thomas Vincent. Released under the GNU General Public
License, version 2 or later. See [LICENSE](LICENSE).
