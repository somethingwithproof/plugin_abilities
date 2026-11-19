<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Live integration tests in CI

- Date: 2026-11-19
- Status: Draft, awaiting approval
- Repository: `somethingwithproof/plugin_mcp`
- Source: follow-up item 1 in `docs/superpowers/plans/2026-11-19-mcp-foundation.md`

## Intent

The unit tests run each plugin file against stubbed Cacti functions. The
manual live run in Task 13 found three defects those tests could not see:

- **D1:** nginx `PATH_INFO` routing.
- **D2:** HTML with a 200 status when `global.php` exits early.
- **D3:** a stale guest setting in a session opened earlier on 1.2.31.

This work turns that manual run into a repeatable job. It installs the
packaged plugin into a real Cacti on each supported web stack and calls it the
way an MCP client does. A break caused by the plugin or by upstream Cacti
should then show up within a day, not at the next manual check.

## Goals

1. Run the live checks against Cacti 1.2.31 on Apache with mod_php and Cacti
   develop on nginx with PHP-FPM, each with MariaDB.
2. Install the plugin from the same `git archive` tarball the `package` job
   builds, not from the working tree.
3. Cover the checks listed in follow-up item 1, plus regressions for D1, D2
   and D3.
4. Run MCP Inspector CLI against `mcp.php`, pinned to an exact version.
5. Let a developer reproduce a failing run locally with Docker and one command.

## Non-goals

- Running on pull requests. The user chose nightly plus on demand only.
- Console UI coverage: screenshots, the Playwright flow, and the
  hide/expose round trip. These are covered by unit tests and the Task 13 run.
- The Claude Code client check from Task 13 (J). It needs a model call and an
  API key.
- Upgrade-path tests. Those belong to follow-up item 2, which can reuse this
  stack.
- Filing issues automatically when a run fails.

## Anti-goals

- **A job that passes when a check fails.** No `|| true`, and no skipped
  steps that still report success. A missing tool or unreadable log fails the
  job.
- **A token in a log, artifact, or summary.**
- **A setup different from what a Cacti admin does.** Use Cacti's own CLI
  installers and the README's web server configuration, not direct table
  inserts, wherever Cacti provides a supported path.

## Constraints

- **Triggers:** `schedule`, once a day, and `workflow_dispatch`. Never
  `pull_request` or `push`.
- **Pinning:**
  - Actions are pinned to full commit SHAs, matching `ci.yml`.
  - Base images are pinned by digest.
  - MCP Inspector is pinned to an exact npm version: `2.16.0`, the version
    verified in Task 13 and the current release.
- **Cacti sources:**
  - **1.2.31:** the upstream tag `release/1.2.31` (commit `1e8eaca2`).
  - **develop:** upstream `develop` at run time, cloned from `Cacti/cacti`
    rather than the local branches Task 13 used. The job prints the develop
    commit it tested in the job summary. Tracking develop is deliberate: the
    point is to catch upstream drift.
- **Workflow permissions:** `contents: read`. The workflow uses no repository
  secrets.
- **Generated values:** admin and test-user passwords and MCP tokens are
  generated at run time. Every token is passed to `::add-mask::` before it is
  used anywhere that could print.
- **Public Cacti URL:** must be `https`, or `http` on `localhost` or
  `127.0.0.1` (`lib/origin.php`). The stack uses `http://localhost/cacti`, so
  the checks must reach the web server as `localhost` (see Design).
- **Archive contents:** `tests/` is `export-ignore` in `.gitattributes`, so
  nothing added there ships in the plugin archive.
- **Licensing:** every new file carries the SPDX header used across the repo.

## Approaches

1. **Docker Compose stack in `tests/integration/` (chosen).** It holds a
   Compose file, one Dockerfile per web stack, a setup script, and a PHP check
   script. The workflow runs the same entry point a developer runs locally.
   - Costs more files in the repo.
   - Reuses the container setup Task 13 already proved.
2. **Install on the runner directly.** This uses `setup-php`, apt Apache or
   nginx, and MariaDB as a service container.
   - Fewer files.
   - Failures can't be reproduced locally, and the two web stacks need two
     differently built jobs on one runner image.
   - Rejected for the reproduction goal.

The user also ruled out checks written in Python or bash. They match the repo's
existing tests instead: standalone PHP scripts with a `check()` helper and no
framework.

## Design

### Layout

```
tests/integration/
  compose.yaml            db, web, checks, inspector services
  apache/Dockerfile       php:8.2-apache (digest-pinned) + Cacti deps
  apache/cacti.conf       SetEnvIf Authorization, /cacti alias
  nginx/Dockerfile        php:8.4-fpm-bookworm (digest-pinned) + nginx + Cacti deps
  nginx/cacti.conf        the README nginx location block, verbatim apart from fastcgi_pass
  setup.sh                install Cacti and the plugin, create users and tokens
  checks.php              the live checks (HTTP client over curl ext)
  run.sh                  entry point: build, up, setup, checks, collect logs, down
.github/workflows/integration.yml
```

`run.sh 1.2.31` and `run.sh develop` take the same steps locally and in CI.
`run.sh` takes the plugin tarball's path. When it is missing, `run.sh` builds
the tarball with `git archive --format=tar.gz --prefix=mcp/ HEAD`, exactly as
`ci.yml` does.

### Services

- **`db`.** MariaDB 11.4, utf8mb4, with time zone tables loaded and
  `cactiuser` granted SELECT on `mysql.time_zone_name`. It has a health check,
  and `web` waits for it to be healthy.
- **`web`.** It runs one of two images, selected by an environment variable
  that `run.sh` sets:
  - **1.2.31:** Apache with mod_php on PHP 8.2.
  - **develop:** nginx plus PHP-FPM 8.4 in one container. That one image also
    installs `fping`, because the develop CLI installer stops without it, and
    runs `composer install --no-dev` against the develop checkout.

  Both images:
  - set `display_errors=Off` and `log_errors=On`;
  - publish nothing on the host by default;
  - mount the Cacti checkout and the unpacked plugin.
- **`checks`.** `php:8.4-cli`, digest-pinned, with the curl extension. It
  sets `network_mode: service:web` so that `http://localhost/cacti` reaches
  the web server, which satisfies the Public Cacti URL rule. It runs
  `checks.php`.
- **`inspector`.** `node:22-bookworm-slim`, digest-pinned, also with
  `network_mode: service:web`. It runs
  `npx -y @modelcontextprotocol/inspector@2.16.0 --cli ...`.

### Setup (`setup.sh`, run inside `web`)

It follows the Task 13 steps, using Cacti's CLI where one exists:

1. Clone the Cacti source. This step runs on the runner before the stack
   starts, so `web` mounts a prepared tree.
   - **1.2.31:** `git clone --depth 1 --branch release/1.2.31`.
   - **develop:** `--branch develop`, then `composer install --no-dev`.
2. Write `include/config.php` from the DB service credentials.
3. `php cli/install_cacti.php --accept-eula --install`.
4. Unpack the tarball into `plugins/`, so the result is `plugins/mcp/`.
5. `php cli/plugin_manage.php --plugin=mcp --install --enable --allperms`.
   This command exists on both refs. `--allperms` gives the admin realm 103.
6. Create the test users with random passwords stored as hashes. Grant the
   realms by inserting rows into `user_auth_realm`, as Task 13 did, because
   Cacti has no CLI for realm grants:

   | User | Realms | Used for |
   | --- | --- | --- |
   | `mcpuser` | 102, 3, 15, 8 | main token owner; sees all three built-in tools |
   | `apionly` | 102 | sees no tools; an ability it lacks rights to answers like an unknown one |
   | `norealm` | 102, then removed | 403 path: token created, then realm 102 deleted |
   | `guestu` | 102, 3, 15 | D3 |

7. Set the Public Cacti URL to `http://localhost/cacti`. This is a
   `settings` row write. The settings form POST is not needed to test the
   endpoint, and the unit tests cover the form.
8. Create tokens with `php plugins/mcp/tokens.php --create=<user> --days=1`
   for `mcpuser`, `apionly` and `norealm`. Then delete `norealm`'s realm
   102 row.
   - Write each token to a file in a `tmpfs` volume shared with `checks` and
     `inspector`.
   - In CI, `run.sh` also emits `::add-mask::` for each token.
9. Add two devices with `cli/add_device.php` so list and get return real rows.

### Checks (`checks.php`)

Each check prints `PASS <id> <name>` or `FAIL <id> <name>: <reason>`. A
failing check doesn't stop the others. The script exits non-zero when any
check fails. Reasons never include a token or a response header that could
hold one.

| ID | Check | Expected |
| --- | --- | --- |
| C1 | `server/discover` on `mcp.php`, revision 2026-07-28 | 200, JSON-RPC result that names the server and its revisions |
| C2 | `tools/list` on 2026-07-28 and on legacy 2025-11-25 (`initialize`, then `tools/list`) | 200, the three built-in tools for `mcpuser` on both revisions |
| C3 | `tools/call cacti-get-system-info` and `cacti-list-devices` on both revisions | 200, no `isError`; `cactiVersion` matches the ref under test; list contains the seeded devices |
| C4 | `api.php/v1/abilities`, `/v1/abilities/cacti-get-device`, `:execute` | 200 each with the AIP shapes the unit tests pin |
| C5 | 401: no bearer, a malformed bearer, a revoked token; a session cookie alone | 401 JSON, `WWW-Authenticate: Bearer`, on both endpoints |
| C6 | 403: any call with `norealm`'s token | 403 `PERMISSION_DENIED`/`REALM_REQUIRED` JSON on both endpoints |
| C6b | `apionly` lists tools and calls `cacti-get-device` | `tools/list` is empty; `:execute` gives 404 `ABILITY_NOT_FOUND`, the same as an unknown id; MCP `tools/call` gives `isError` |
| C7 | 405: GET and PUT on `mcp.php`; PUT on `api.php` | 405 JSON with `Allow` |
| C8 | No `Set-Cookie` on any `mcp.php` or `api.php` response in C1–C7 | header absent on every response |
| C9 | Origin rules on `mcp.php`: none, `http://localhost`, `https://evil.test`, `null` | 200, 200, 403 `ORIGIN_NOT_ALLOWED`, 403 |
| D1 | `api.php/v1/abilities` through the README nginx block (develop only) | 200 JSON, not nginx's HTML 404 or `UNKNOWN_PATH` |
| D2 | Database down: `run.sh` stops `db`, `checks.php --phase=db-down` calls both endpoints, then `db` is started again and a C2 call passes | 503 JSON (`UNAVAILABLE`/`CACTI_UNAVAILABLE`), single valid JSON body, no HTML, no `Set-Cookie` |
| D3 | Guest guard with a session opened earlier, on both refs | after `guest_user` is set to `guestu`, no token is created for `guestu` (see below) |

D3 follows the Task 13 reproduction:

1. `checks.php` logs in as `guestu` through the login form and keeps the
   cookie jar. It reads the CSRF token the page needs: `__csrf_magic` on
   1.2.31, the develop token on develop.
2. It opens My MCP Tokens.
3. `setup.sh guest-on` sets `guest_user` to `guestu`'s id.
4. The script reloads the page and POSTs `create` with the fresh nonce.

It passes when the response holds no `cmcp_pat_` value and the
`plugin_mcp_token` row count for `guestu` is unchanged. `setup.sh` reports that
count through `docker compose exec`.

The admin-console variant is required, because Task 13 reproduced D3 on both
paths. It runs as `admin`, whose session is opened before `guest-on`:

1. Open Management > MCP > Tokens.
2. Set `guest_user`.
3. Reload. `guestu` must be absent from the User dropdown.
4. POST `create_token` with `user_id` set to `guestu`'s id. No token may be
   created.

`setup.sh` sets the `admin` password to a generated value and clears
`must_change_password`, so the scripted login doesn't stop at the forced
password change.

A 503 check is listed in the plan's error paths. D2 is that check: the only
503 the endpoints produce in a working install is Cacti being unavailable.

### MCP Inspector

`run.sh` runs these commands in the `inspector` service, reading the token
from the shared file:

- `--method tools/list`, which must list the three tools.
- `--method tools/call --tool-name cacti-list-devices`, which must return the
  seeded devices.

`run.sh` checks Inspector's JSON output with `node -e` in the same container.
That is the only non-PHP check, and it lives there because Inspector is a Node
tool.

Task 13 ran Inspector 2.16.0 in this exact mode:
`--cli <url> --transport http --header "Authorization: Bearer $TOKEN"`,
with `--method tools/list` and `--method tools/call --tool-name
cacti-list-devices`. It worked. Inspector negotiates the legacy revision
`2025-11-25`, so it covers that path; `checks.php` covers 2026-07-28.

The token appears in Inspector's argument list inside the `inspector`
container. That is acceptable for a throwaway container. The command runs
through `sh -c` and reads the token from the shared file into a variable, so
the token never appears in the Compose command line, the workflow YAML or a
`set -x` trace. `run.sh` doesn't use `set -x`.

### Log gate

After the checks, `run.sh` scans Cacti's `log/cacti.log` and the PHP and web
server error logs for lines from the plugin: any PHP warning, notice or error
whose file path contains `plugins/mcp/`, read over the window of this run.

- A match fails the job.
- No match passes.
- A read or `grep` error (exit status above 1) fails the job.

Warnings from Cacti core, such as N2 in `lib/html.php`, are not gated.

### Workflow (`.github/workflows/integration.yml`)

- **Triggers:** `on: schedule` (daily, off the hour) and `workflow_dispatch`
  with an optional `cacti` input: `1.2.31`, `develop`, or `both`, the
  default.
- **Concurrency:** one group per ref, without `cancel-in-progress`. A
  manual run doesn't kill a nightly run.
- **`package` job:** the same `git archive` step and file checks as
  `ci.yml`. It uploads the tarball as an artifact.
- **`live` job:** needs `package`, `timeout-minutes: 30`, matrix
  `cacti: [1.2.31, develop]` with `fail-fast: false`. Its steps:
  1. Checkout with `persist-credentials: false`.
  2. Download the tarball.
  3. `tests/integration/run.sh ${{ matrix.cacti }} dist/mcp.tar.gz`.
  4. Upload logs.
- **Job summary:** the PASS and FAIL lines, the Cacti ref and commit, and the
  plugin commit.
- **Artifact:** on every run, pass or fail, the job uploads the Cacti log, the
  web server access and error logs, the PHP-FPM log, and `checks.php` output.
  `run.sh` replaces anything matching `cmcp_pat_[A-Za-z0-9_-]+` with
  `cmcp_pat_REDACTED` before upload, and then fails the job if the pattern
  still appears. Retention is 14 days.

A failed run reports the normal Actions way. There is no extra notification.

### Local use

`tests/integration/run.sh develop` needs only Docker with Compose v2 and git.
It leaves the stack up when `KEEP=1` is set, for debugging, and otherwise tears
everything down, volumes included. Every container, network and volume is
prefixed `mcp-it-` so cleanup can be checked.

The README gains a short "Integration tests" paragraph under its testing
section that names the command and what it needs.

### Setup failures versus check failures

`run.sh` runs in named phases and prints the phase that failed:

1. `clone`
2. `build`
3. `install`
4. `plugin`
5. `checks`
6. `inspector`
7. `db-down`
8. `logs`

The job summary leads with that phase, so a red develop run that failed in
`clone`, `build` or `install` reads as "upstream setup failed", not as a
plugin regression. The Cacti commit is written to the summary right after the
clone, before anything can fail.

### Keeping pins current

The existing `.github/dependabot.yml` gains a `docker` entry for
`/tests/integration/apache` and `/tests/integration/nginx`, and a
`docker-compose` entry for `/tests/integration`. Both use the same weekly
schedule, cooldown and grouping as the existing `github-actions` entry.

Dependabot can't see the Inspector version pinned inside `run.sh`. A comment
next to it says to bump it by hand and re-run the workflow with
`workflow_dispatch`.

## Reference details from the Task 13 run

The Task 13 notes are not in the repository. These are the values that run
confirmed:

- **Realms.**
  - 102 is MCP and Abilities API; the user's own token page needs it.
  - 103 is MCP Administration.
  - 3, 15 and 8 are core realms. With them, `mcpuser` sees all three built-in
    tools; 15 is needed for `cacti-get-system-info`.
- **Guest setting.** The guest account is the `guest_user` settings row,
  holding a user id; 0 means none.
- **Plugin install.** 1.2.31 accepts GET `mode=install` and `mode=enable`;
  develop needs a CSRF POST. This design uses `plugin_manage.php` instead on
  both refs.
- **CSRF.** 1.2.31 uses csrf-magic (`__csrf_magic`). For develop, read the
  hidden token field from the form the page renders. Don't hard-code its name.
- **develop prerequisites.** Before `install_cacti.php`, develop needs
  `composer install --no-dev` and `fping`.
- **Database.** MariaDB 11.4 needs the time zone tables loaded and
  `cactiuser` granted SELECT on `mysql.time_zone_name`.
- **nginx.** Use the README block verbatim, with no `try_files`. That
  configuration passed every `api.php` check.

The plan's first task should prove the stack: setup on both refs, plus one
`tools/list` call. Build the checks only after that passes, because none of
this setup has been scripted before.

## Left to the builder

- The exact Dockerfile package lists for Cacti's PHP extensions
  (`gd`, `gmp`, `intl`, `ldap`, `mbstring`, `mysql`, `snmp`, `sockets`, `xml`,
  `zip`) and the `rrdtool`, `snmp` and `fping` packages on each base image.
- How `checks.php` structures its HTTP helper, as long as it uses the curl
  extension and reads response headers.
- The cron minute and hour.
- How `setup.sh` passes the `guestu` id and row counts back to `checks.php`.
  A file in the shared `tmpfs` is fine.

## Calls made without asking

- **D3 runs on both refs**, not only 1.2.31. develop should keep passing, and
  a regression there should show.
- **Realm grants and the Public Cacti URL are written to the database
  directly.** Cacti has no CLI for either, and the unit tests already cover the
  forms.
- **The admin D3 variant is required.** It uses the stock `admin` account,
  with its password reset by `setup.sh`.
- **Setup and check failures are reported by phase.** No separate exit
  codes.
- **Dependabot covers the image digests.** The Inspector pin is bumped by
  hand.
- **The develop clone tracks the branch head.** The tested commit is printed
  so a failure can be traced to an upstream change.
- **Containers run their stock entry points.** Apache and PHP-FPM masters
  start as root and serve as `www-data`, as the official images do. These are
  throwaway test containers, not deployment images.
