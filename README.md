<h1 align="center">
	<picture>
		<source media="(prefers-color-scheme: dark)" srcset="images/abilities_logo_dark.png">
		<img src="images/abilities_logo.png" width="360" alt="Cacti Abilities: a cactus with a shield. Discover. Authorize. Execute.">
	</picture>
</h1>

<p align="center">
	<a href="https://github.com/somethingwithproof/plugin_abilities/actions/workflows/ci.yml"><img src="https://github.com/somethingwithproof/plugin_abilities/actions/workflows/ci.yml/badge.svg?branch=develop" alt="CI"></a>
	<a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" alt="License: GPL-2.0-or-later"></a>
	<img src="https://img.shields.io/badge/Cacti-1.2.31%2B-2e7d32" alt="Cacti 1.2.31+">
	<img src="https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&amp;logoColor=white" alt="PHP 8.2+">
	<img src="https://img.shields.io/badge/Abilities_API-v1-2e7d32" alt="Abilities API v1">
</p>

Cacti Abilities is a Cacti plugin that exposes named, permission-filtered
operations to external clients through an HTTPS API. Cacti owns authorization
and execution; the separate [cacti-mcp adapter](https://github.com/somethingwithproof/cacti-mcp)
turns those operations into MCP tools.

**Status: in development, before 1.0.** Version 0.1.0 provides read-only device
inventory and installation versions. There is no tagged release yet. Doctor
diagnostics, repairs, and other mutations are future work.

## What it exposes

| Name | Arguments | Required Cacti realm |
| --- | --- | --- |
| `cacti.system.info` | `{}` | Settings/Utilities (15) |
| `cacti.devices.list` | Optional `limit` (1–100), `offset` (0–1,000,000) | Sites/Devices/Data (3) |
| `cacti.devices.get` | Required positive integer `id` | Sites/Devices/Data (3) |

Every call also requires the plugin realm and exact token scope. The device
providers use Cacti's allowed-device helper with the explicit token-owner ID,
including the site's configured graph/device permission policy. Returned fields
are limited to ID, description, hostname, disabled state, and numeric status.
SNMP credentials and other database columns are excluded.

The [API contract](https://github.com/somethingwithproof/cacti-mcp/blob/develop/docs/api.md)
documents discovery, execution envelopes, errors, and limits.

## Use cases

- **Ask an MCP client about the devices you can access.** With the separate
  Python adapter, a client can discover `cacti.devices.list` and
  `cacti.devices.get`, then retrieve inventory through Cacti's existing device
  permissions. The adapter does not need database access or SNMP credentials.
- **Connect an inventory report or internal portal.** An HTTPS client can list
  permitted devices in pages of up to 100 and fetch a selected device by ID.
  Give its dedicated user only the device realms and token scopes it needs.
- **Check installation versions.** An administrator can grant
  `cacti.system.info` to retrieve the Cacti, plugin, and API versions without
  exposing configuration secrets.
- **Expose another plugin's read-only operation.** A plugin author can register
  a named provider through `abilities_register`, with an input schema,
  permission callback, and execution callback. The provider is available only
  to users and tokens permitted to call it. Doctor diagnosis and repair
  providers are planned; they are not included in version 0.1.

For example, a service account scoped only to `cacti.devices.list` can produce
an inventory report, but cannot call `cacti.system.info`. Removing its Cacti
permissions or revoking its token blocks subsequent requests, even if a client
previously discovered the ability.

## Code and test map

| Behavior | Implementation | Test evidence in this repository |
|---|---|---|
| Permission-filtered discovery and execution | [lib/registry.php](lib/registry.php) | [tests/registry.php](tests/registry.php) |
| Input schemas and audit callbacks | [lib/registry.php](lib/registry.php) | [tests/registry.php](tests/registry.php) |
| Cacti inventory providers and extension hook | [lib/providers.php](lib/providers.php) | [tests/providers.php](tests/providers.php) |
| Installation, enable readiness, and retained tables | [setup.php](setup.php), [lib/storage.php](lib/storage.php) | [tests/lifecycle.php](tests/lifecycle.php) |

These checks run in CI. Lifecycle and provider tests use fixtures at the Cacti
helper boundary. The live Cacti integration check is separate; the unit tests
do not exercise a complete Cacti installation.

## How it stays safe

- Each request needs a valid scoped bearer token owned by an enabled Cacti user.
  The plugin realm, provider realms, and device permissions still apply.
- Inputs must match a supported closed-object schema. Providers return bounded
  result objects, and the built-in inventory providers omit credentials.
- Cacti's session and CSRF checks remain in place. A browser cookie alone
  never grants API access.
- Execution requires a persisted audit start record. Audit rows contain
  identity and outcome fields, without arguments, results, or token values.

Version 0.1 exposes read-only domain operations. Session and audit records are
still written when a client uses the API.

## The WordPress Abilities API example

The [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
registers named operations with schemas, permission checks, and execution
callbacks so plugins and external tools can discover functionality. Its
site-information example registers `my-plugin/site-info` and checks
`current_user_can('manage_options')` before executing it.

Cacti Abilities follows that registration pattern within Cacti:

| WordPress example | Cacti Abilities |
| --- | --- |
| Register a named ability | Register `cacti.system.info` in `AbilitiesRegistry` |
| Describe accepted input | Supply the supported closed-object input schema |
| Check permission before execution | Check the explicit token owner's Cacti realm and token scope |
| Execute a PHP callback | Run the installed provider and record its audit outcome |
| Discover available functionality | Fetch the permission-filtered `abilities` list through API v1 |

This is a conceptual example, not WordPress API compatibility. WordPress
supports categories and input/output schemas; this plugin has a smaller input
schema dialect and bounded object results. It does not implement WordPress
functions, categories, REST routes, or a full JSON Schema validator. The
separate `cacti-mcp` adapter exposes the permitted read-only operations as MCP
tools.

## Does Abilities replace MCP?

Abilities supplies Cacti's application operations and permission checks. An MCP
server supplies the protocol an MCP client speaks. The companion Python adapter
bridges them:

```text
MCP client --> cacti-mcp --> Abilities API --> Cacti provider
HTTPS client -------------> Abilities API --> Cacti provider
```

Use the API directly for an HTTPS integration. Use `cacti-mcp` when the client
expects MCP tools. Adding a provider to Cacti makes its permitted read-only
abilities discoverable through the adapter without adding a Python tool handler.

## Installation

Requires Cacti 1.2.31 or later, PHP 8.2 or later, and the main poller.
Cacti authentication must be enabled; the API fails closed when it is off.
The integration has been exercised with Cacti 1.2.31, PHP 8.2, and MariaDB 11.8.
Other Cacti versions have not yet received full integration testing.

For a source checkout:

```console
cd /path/to/cacti/plugins
git clone https://github.com/somethingwithproof/plugin_abilities.git abilities
```

1. Place the repository contents in Cacti's `plugins/abilities/` directory.
   The directory name must be `abilities`, not `plugin_abilities`.
2. Install and enable **Cacti Abilities** in Plugin Management.
3. Grant **Abilities API** to a dedicated enabled Cacti user. No user receives
   that realm automatically. Also grant the existing Cacti realms needed below.
4. Serve `/plugins/abilities/api.php` over HTTPS. Forward Authorization to
   PHP; for Apache setups that strip it, use this directive in the relevant
   virtual host/directory configuration and reload Apache:

   ```apache
   SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
   ```

5. Create a scoped token with the CLI, then configure the Python adapter with
   the same token through your secret manager.

The API retains Cacti's normal session and CSRF protection. Discovery returns a
CSRF token; execution uses a form POST with the session cookie and bearer header.
Cookie authentication alone never grants API access.

## Command line

Use an OS account authorized to administer this Cacti installation. Generate a
random 256-bit token, retain it in your secret manager, and supply it through
`CACTI_MCP_TOKEN`. The CLI never prints the value or stores it in plaintext.

```console
export CACTI_MCP_TOKEN="$(openssl rand -hex 32)"
# Store this value in your secret manager before ending this shell session.
php plugins/abilities/tokens.php --create=mcp_reader \
  --scopes=cacti.devices.list,cacti.devices.get --days=30
php plugins/abilities/tokens.php --list
php plugins/abilities/tokens.php --revoke=1
```

Creation validates the enabled user, plugin realm, and current permission for
every named scope. Expiry is mandatory, defaults to 30 days, and is capped at
90 days. Wildcards are unsupported. Rotation creates a new token and revokes
the old token ID. Revocation and disabled users take effect on the next request.

## Provider extension

An enabled Cacti plugin can register the `abilities_register` hook through
`api_plugin_register_hook()` during its own install/setup lifecycle. Its callback
receives the registry object and must return that same object for the hook chain.

```php
function example_abilities_register($registry) {
    $registry->register(
        'example.version',
        'Read the example provider version.',
        ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        fn(int $userId) => is_realm_allowed(15, $userId),
        fn(int $userId, stdClass $arguments) => ['version' => '1']
    );

    return $registry;
}
```

Providers are trusted installed PHP code. Register only bounded, read-only
operations. Authorization callbacks receive an explicit user ID; execution
callbacks must enforce object-level permissions and return an array of named
result fields. Never rely on browser session identity. There is no arbitrary
function dispatch or mechanism to register tools through HTTP.

The schema dialect supports closed objects (`properties`, `required`,
`additionalProperties: false`), integers (`minimum`, `maximum`), strings
(`minLength`, `maxLength`, `enum`), and booleans. Other keywords/types are rejected
at registration. Strings default to a maximum of 4,096 Unicode characters.
Use an empty object for an empty `properties` map. The registry supports at most
256 abilities. Results are bounded to 1 MB; request JSON to 16 KB.

## Audit and lifecycle

`plugin_abilities_audit` stores request ID, user ID, token ID, ability,
outcome, and timestamps. It excludes arguments, results, and credentials.
Execution requires a successful start record; provider failures are recorded.
Authorization and validation failures occur before execution and do not create
execution audit rows. Discovery is not logged there.

Disabling the plugin blocks its API. Uninstallation revokes tokens and preserves
both plugin tables for administrator retention/cleanup. Reinstallation is
idempotent and does not reactivate revoked tokens. Back up these tables with the
Cacti database and apply an appropriate audit retention policy externally.

## Releases

The plugin version is recorded in [INFO](INFO). Until tagged releases are
available, use a source checkout or the `abilities-plugin` artifact from a
successful [CI run](https://github.com/somethingwithproof/plugin_abilities/actions/workflows/ci.yml).
The artifact contains `abilities.tar.gz` and `SHA256SUMS`. Verify the checksum
before extracting the `abilities/` directory into Cacti's `plugins/` directory.
GitHub's automatic source archives use a different top-level directory name.

## Development and tests

```console
php tests/registry.php
php tests/lifecycle.php
php tests/providers.php
git archive --format=tar.gz --prefix=abilities/ HEAD > abilities.tar.gz
```

CI lints PHP and runs registry, lifecycle, and provider tests on PHP 8.2 through
8.5. It also builds an installable `abilities/` archive and checksum as workflow
artifacts. The Python repository contains transport and MCP subprocess tests.
The initial integration
check additionally used isolated Cacti/MariaDB containers to exercise discovery,
execution, CSRF, realm/device permissions, disabled users, token expiry and
revocation, and audit outcomes. No production installation is modified by setup
of the Python adapter.

## Contributing

Pull requests go to `develop`. Keep production code compatible with PHP 8.2
and use Cacti's style: tabs, single-quoted strings, and Cacti database and
permission helpers. Add registry tests for changed validation or authorization
behavior, and sign off commits with `git commit -s`. Coordinate API contract
changes with the Python adapter and [its API documentation](https://github.com/somethingwithproof/cacti-mcp/blob/develop/docs/api.md).

## Source naming

This initial source uses the Abilities name throughout. Install it as
`plugins/abilities/`; the API, realm, hook, translation domain, and database
tables use that name. The MCP adapter must use
`/plugins/abilities/api.php`. There is no compatibility endpoint for the earlier
local prototype. Do not rename tables on an existing installation without
backing up its token and audit data and planning a migration.

## License

Copyright (C) 2026 Thomas Vincent. Released under the GNU General Public
License, version 2 or later. See [LICENSE](LICENSE).
