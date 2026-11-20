<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Live Integration Tests in CI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A nightly and on-demand workflow that installs the packaged plugin into real Cacti 1.2.31 (Apache, mod_php) and Cacti develop (nginx, PHP-FPM) and runs the live endpoint checks, the D1–D3 regressions and MCP Inspector.

**Architecture:** A Docker Compose stack in `tests/integration/`, driven by `run.sh`, the single entry point for both local runs and CI. `setup.sh` runs inside `web` and prepares Cacti. `checks.php` runs in a PHP CLI container that shares `web`'s network namespace, so `http://localhost/cacti` is the Public Cacti URL. Inspector runs the same way in a Node container.

**Tech Stack:** Docker Compose v2, MariaDB 11.4, `php:8.2-apache`, `php:8.4-fpm-bookworm` with Debian nginx, `php:8.4-cli` with the curl extension, `node:22-bookworm-slim`, `@modelcontextprotocol/inspector@2.16.0`, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-19-ci-integration-tests-design.md`. Read it before any task. It holds the check table (C1–C9, C6b, D1–D3), the Task 13 reference values, and the calls made.

## Global Constraints

- **Triggers:** `schedule` (daily, off the hour) and `workflow_dispatch` only. Never `pull_request` or `push`.
- **Pins:**
  - Actions use full commit SHAs, matching `.github/workflows/ci.yml`: checkout `3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1`, upload-artifact `cf430e030ddbb5b0abf93d22962f4752f3646cd9 # v7.0.2`. Resolve download-artifact's SHA from its release tag at build time.
  - Every base image is pinned by digest: `image:tag@sha256:...`. Resolve each with `docker buildx imagetools inspect <image:tag>`.
  - Inspector is pinned to `@modelcontextprotocol/inspector@2.16.0`, with a comment that it is bumped by hand.
- **Cacti refs:**
  - 1.2.31 uses the tag `release/1.2.31` (commit `1e8eaca2`).
  - develop uses the `develop` head from `https://github.com/Cacti/cacti`. Its commit goes into the summary right after the clone.
- **Permissions and secrets:** `permissions: contents: read`, and no repository secrets.
- **Generated values:** passwords and tokens are generated at run time and never printed. In CI every token is passed to `::add-mask::`. Artifacts redact `cmcp_pat_[A-Za-z0-9_-]+` to `cmcp_pat_REDACTED`, and the job fails if the pattern survives.
- **Shell hygiene:** no `set -x` anywhere, and no `|| true` on a gate. `grep` exit status above 1 is a failure.
- **Naming:** every container, network and volume name is prefixed `mcp-it-`. The Compose project name is `mcp-it-<ref>`, with the dot dropped (`mcp-it-1231`, `mcp-it-develop`).
- **Headers:** every new file carries the repo's SPDX header (`SPDX-FileCopyrightText: 2026 Thomas Vincent`, `SPDX-License-Identifier: GPL-2.0-or-later`).
- **Commits:** `git commit -s`, Conventional Commits, stage named files only, no AI attribution.
- **Check style:** `checks.php` follows `tests/*.php`: a standalone script with a `check()`-style helper and no framework. Unlike the unit tests it keeps going after a failure and prints `PASS <id> <name>` or `FAIL <id> <name>: <reason>`. A reason never contains a token or an `Authorization` or `Set-Cookie` value.

## Review Focus

1. **A token leaks into output.** A failing check's reason, a curl verbose trace, Inspector's stderr or a web access log can carry a token. Expect redaction before upload and a post-redaction scan that fails the job (Task 4).
2. **A partly started stack.** The `db` health check isn't ready yet, or develop's `composer install` fails. Expect `run.sh` to report phase `build` or `install`, not `checks`, and still collect logs and tear down (Task 1).
3. **The database never comes back after the D2 phase.** Expect the post-restart C2 call to fail loudly rather than pass on a cached response (Task 3).
4. **A CSRF token field missing from a console page.** Expect `checks.php` to report `FAIL D3 ...: no CSRF token on page` instead of POSTing without one and calling the refusal a pass (Task 3).
5. **A leftover stack from an earlier local run.** Expect `run.sh` to start with `docker compose down -v` for its project, so stale volumes and tokens don't make a run pass (Task 1).

---

### Task 1: Stack, setup and first smoke call

Prove setup on both refs before writing any checks. The spec calls for this
because none of the setup has been scripted before.

**Files:**
- Create:
  - `tests/integration/compose.yaml`
  - `tests/integration/apache/Dockerfile`
  - `tests/integration/apache/cacti.conf`
  - `tests/integration/nginx/Dockerfile`
  - `tests/integration/nginx/cacti.conf`
  - `tests/integration/setup.sh`
  - `tests/integration/run.sh`
  - `tests/integration/checks.php` (smoke phase only)
- Modify: `.gitignore`. Add `tests/integration/out/` and `tests/integration/src/`.

**Interfaces:**
- Produces:
  - **`run.sh <1.2.31|develop> [path/to/mcp.tar.gz]`.**
    - Exit 0 when every phase passes, non-zero otherwise.
    - Prints `PHASE <name>` before each phase and `FAILED PHASE <name>` on failure.
    - Phases, in order: `clone`, `build`, `install`, `plugin`, `checks`, `inspector`, `db-down`, `logs`.
    - Later tasks add the bodies of `inspector`, `db-down` and `logs`. In this task they are no-ops that say so.
    - `KEEP=1` leaves the stack up.
    - With no tarball argument, `run.sh` builds one with `git archive --format=tar.gz --prefix=mcp/ HEAD`.
  - **`tests/integration/src/<ref>/`:** the Cacti checkout.
  - **`tests/integration/out/<ref>/`:** logs and results. In CI, `run.sh` also writes `out/<ref>/summary.md`.
  - **Compose services:** `db`, `web`, `checks`, `inspector`.
  - **Shared volume `mcp-it-secrets`:** a local driver volume with `type: tmpfs`, mounted at `/run/mcp-it` in `web`, `checks` and `inspector`. Its files:
    - `token-mcpuser`, `token-apionly`, `token-norealm`, `token-revoked` (each a raw token);
    - `admin-password` and `guestu-password`;
    - `ids.json`: `{"guestu": <int>, "mcpuser": <int>}`.
  - **`setup.sh <subcommand>`** (run with `docker compose exec web`):
    - `install`: config.php and `install_cacti.php`.
    - `plugin`: unpacks the tarball, then runs `plugin_manage.php --plugin=mcp --install --enable --allperms`.
    - `fixtures`: users, realms, Public URL, tokens, devices, and the admin password reset.
    - `guest-on` and `guest-off`: set `guest_user` to `guestu`'s id, or to 0.
    - `count-tokens <username>`: prints an integer.
  - **`checks.php --ref=<ref> --phase=<smoke|main|d3-open|d3-finish|db-down|db-up>`:** exit 0 when all checks in the phase pass, 1 otherwise. Only `smoke` exists after this task.

- [ ] **Step 1: Resolve and record base image digests.**
  Run `docker buildx imagetools inspect` for these tags:
  - `mariadb:11.4`
  - `php:8.2-apache`
  - `php:8.4-fpm-bookworm`
  - `php:8.4-cli`
  - `node:22-bookworm-slim`

  Use `image:tag@sha256:<index digest>` in the Dockerfiles and `compose.yaml`.
- [ ] **Step 2: Write the smoke phase of `checks.php`.**
  One check: `S1 tools/list` POSTs `{"jsonrpc":"2.0","id":1,"method":"tools/list"}` to `http://localhost/cacti/plugins/mcp/mcp.php` with these headers:
  - `Authorization: Bearer <token-mcpuser>`
  - `Content-Type: application/json`
  - `Accept: application/json, text/event-stream`
  - `Mcp-Protocol-Version: 2026-07-28`
  - `Mcp-Method: tools/list`

  The request also carries the matching `_meta` `io.modelcontextprotocol/protocolVersion`, as `lib/mcp_server.php` requires for the modern revision.

  The check passes on status 200 with result tool names equal to `['cacti-get-device', 'cacti-get-system-info', 'cacti-list-devices']` after sorting.
- [ ] **Step 3: Run it before the stack exists.**
  Run `tests/integration/run.sh develop`.
  Expected: it fails in phase `clone` or `build`, because the files are incomplete.
- [ ] **Step 4: Write the Dockerfiles, web configs and `compose.yaml`.**
  - **Common to both web images:** Cacti's PHP extensions, plus `rrdtool`, `snmp`, the MariaDB client and `git`.
  - **nginx image:** also gets `fping` and `composer`.
    - `nginx/cacti.conf` holds the README block (`README.md:150-156`) verbatim except `fastcgi_pass 127.0.0.1:9000;`, with no `try_files`.
    - The image needs an entry point that starts both PHP-FPM and nginx.
  - **Apache image:** `apache/cacti.conf` aliases `/cacti` and sets `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
  - **`db`:** a health check, plus an init SQL that loads the time zones (`mariadb-tzinfo-to-sql`) and grants `cactiuser` SELECT on `mysql.time_zone_name`.
  - **`checks` and `inspector`:** `network_mode: service:web`.
  - **Ports:** no service publishes a host port.
- [ ] **Step 5: Write `setup.sh install`, `plugin` and `fixtures`, and `run.sh` phases `clone` to `checks`.**
  - **`clone`:**
    - `git clone --depth 1 --branch <release/1.2.31|develop>` into `src/<ref>`.
    - For develop, run `composer install --no-dev` in the build or web container, not on the host.
    - Write `CACTI_COMMIT=<sha>` to `out/<ref>/summary.md`.
  - **`run.sh` start:** `docker compose -p <project> down -v`.
  - **`run.sh` exit trap:** collect `docker compose logs` into `out/<ref>/`. Then, unless `KEEP=1`, run `down -v`.
  - **Generated values:**
    - Passwords come from `openssl rand`. Hash them with PHP `password_hash()` inside `web`.
    - Write `user_auth` rows with `enabled='on'` and `must_change_password=''`.
    - Realm rows go in `user_auth_realm`. Users and realms are listed in the spec table, plus `norealm`.
  - **Public Cacti URL:** write the plugin's settings row with the name the plugin's setting uses. Find it in `setup.php`.
  - **Tokens:** run `tokens.php --create=<user> --days=1` for `mcpuser`, `apionly` and `norealm`, and a second one for `mcpuser`.
    - Revoke that second `mcpuser` token with `tokens.php --revoke=<id>` and save it as `token-revoked`.
    - Then delete `norealm`'s realm-102 row.
  - **Devices:** add two with `cli/add_device.php`, named `it-alpha` and `it-beta`, on 192.0.2.21 and 192.0.2.22.
  - **CI masking:** when `GITHUB_ACTIONS=true`, `run.sh` prints `::add-mask::<token>` for each token file. The values are read inside `run.sh` and not echoed otherwise.
- [ ] **Step 6: Run both refs.**
  Run `tests/integration/run.sh 1.2.31` and `tests/integration/run.sh develop`.
  Expected: each ends with `PASS S1 tools/list` and exit 0. Afterwards, `docker ps -a`, `docker volume ls` and `docker network ls` show no `mcp-it-` entries.
- [ ] **Step 7: Check phase reporting.**
  Temporarily break `config.php`'s password in a `KEEP=0` run (local edit, not committed). Expected: `FAILED PHASE install`, logs are present in `out/`, and the stack is gone.
- [ ] **Step 8: Commit.**

  ```bash
  git add .gitignore tests/integration/compose.yaml tests/integration/apache/Dockerfile tests/integration/apache/cacti.conf tests/integration/nginx/Dockerfile tests/integration/nginx/cacti.conf tests/integration/setup.sh tests/integration/run.sh tests/integration/checks.php
  git commit -s -m "test: add a live Cacti stack for integration tests"
  ```

### Task 2: Endpoint checks C1–C9, C6b and D1

**Files:** Modify `tests/integration/checks.php` and `tests/integration/run.sh`. In `run.sh`, the `checks` phase now runs `--phase=main` after `smoke`.

**Interfaces:**
- **Consumes:** token files and `ids.json` from Task 1, and the user table in the spec.
- **Produces:** `checks.php --phase=main`.

- [ ] **Step 1: Write the checks, one `PASS`/`FAIL` line each.**
  The expected values come from the spec's check table, verbatim. Assertions specific to this code:
  - **C1:** `server/discover` (2026-07-28). The result lists `2026-07-28` and `2025-11-25` among supported revisions.
  - **C2 and C3, legacy path:** send `initialize` with `protocolVersion: "2025-11-25"`, then `tools/list` and `tools/call`, with no `Mcp-Protocol-Version` header and no `_meta` revision.
  - **C3:** `cacti-get-system-info` returns `cactiVersion`.
    - On 1.2.31 it is `1.2.31`.
    - On develop it starts with `1.3`. If upstream bumps develop's version, adjust this and say so in the commit.
    - `cacti-list-devices` returns `it-alpha` and `it-beta`.
  - **C4:** `GET api.php/v1/abilities` returns an `abilities` array with 3 entries.
    - `GET .../v1/abilities/cacti-get-device` returns 200.
    - `POST .../v1/abilities/cacti-get-system-info:execute` with body `{"arguments":{}}` returns 200. Use the AIP shapes in `lib/api_http.php` and `tests/api.php` as the authority on field names.
  - **C5:** each case returns 401 with `WWW-Authenticate: Bearer` and a JSON body, on both `mcp.php` and `api.php`. The cases:
    - no `Authorization` header;
    - `Authorization: Bearer cmcp_pat_bad`;
    - the revoked token;
    - a session cookie only, from logging in as `mcpuser`. The CSRF handling here is the same as in D3, Task 3; share the helper.
  - **C6:** `norealm` gets 403 with `PERMISSION_DENIED` and `REALM_REQUIRED` on both endpoints.
  - **C6b:** `apionly` gets an empty `tools/list` result.
    - `:execute` on `cacti-get-device` returns 404 `ABILITY_NOT_FOUND`.
    - So does `:execute` on `cacti-no-such-ability`, with an identical body apart from any request id.
    - MCP `tools/call` on `cacti-get-device` returns `result.isError === true`.
  - **C7:** each case returns 405, with `Allow` containing `POST` and a body that is empty or valid JSON:
    - `GET mcp.php`;
    - `PUT mcp.php`;
    - `PUT api.php/v1/abilities`.
  - **C8:** every response recorded in C1–C7 lacks a `Set-Cookie` header. Collect the headers in the HTTP helper.
  - **C9:** `mcp.php` `tools/list` with each Origin:
    - none: 200;
    - `http://localhost`: 200;
    - `https://evil.test`: 403 with `ORIGIN_NOT_ALLOWED` in `error.data.reason`;
    - `null`: 403.
  - **D1** (develop only; on 1.2.31 print `SKIP D1 nginx only`): `GET api.php/v1/abilities` returns 200 with `Content-Type` starting `application/json`. The body does not contain `<html`, and it does not contain `UNKNOWN_PATH`.
- [ ] **Step 2: Run both refs and expect every line to pass.**
  Run `tests/integration/run.sh 1.2.31` and `tests/integration/run.sh develop`.
  Expected: every line is `PASS` (or the D1 `SKIP` on 1.2.31), with exit 0.
- [ ] **Step 3: Prove a check can fail.**
  Locally, with `KEEP=1`, change C9's expected status for `https://evil.test` to 200 and rerun only `checks.php --phase=main` through `docker compose exec`.
  Expected: `FAIL C9 ...` and exit 1. Revert.
- [ ] **Step 4: Commit.**

  ```bash
  git add tests/integration/checks.php tests/integration/run.sh
  git commit -s -m "test: check MCP and HTTP API endpoints against live Cacti"
  ```

### Task 3: D2 (database down) and D3 (stale guest setting)

**Files:** Modify:
- `tests/integration/checks.php`, adding phases `d3-open`, `d3-finish`, `db-down` and `db-up`;
- `tests/integration/run.sh`;
- `tests/integration/setup.sh`, adding `guest-on`, `guest-off` and `count-tokens`.

**Interfaces:**
- **Consumes:** `admin-password`, `guestu-password` and `ids.json` from Task 1, and the login helper from Task 2.
- **Produces:**
  - **`/run/mcp-it/`:** `jar-guestu` and `jar-admin` (cookie jars) and `d3-before.json` (`{"guestu": <int>}`).

- [ ] **Step 1: Write the D3 phases.**
  - **`d3-open`:**
    - Log in as `guestu` and GET `plugins/mcp/mcp_tokens.php`.
    - Log in as `admin` and GET `plugins/mcp/mcp_admin.php` on the Tokens tab.
    - Save both jars.
  - **`run.sh` between the phases:**
    - Write `setup.sh count-tokens guestu` into `d3-before.json`.
    - Run `setup.sh guest-on`.
  - **`d3-finish`:** reload both pages, then:
    - **`D3a self`:** POST create on My MCP Tokens with the page's fresh nonce and CSRF field.
    - **`D3b admin`:** check that `guestu` is absent from the User `<select>`. Then POST `action=create_token` with `user_id=<guestu id>`, `days=1` and the fresh nonce and CSRF field.
  - **Pass condition:** no response body contains `cmcp_pat_`. `run.sh` then compares `setup.sh count-tokens guestu` with `d3-before.json` and fails D3 on any increase.
  - **CSRF field:** read it from the form's hidden input:
    - `__csrf_magic` on 1.2.31;
    - on develop, whichever hidden CSRF input the form renders.

    A page with no such field gives `FAIL D3x ...: no CSRF token on page`.
  - **After the checks:** `run.sh` runs `setup.sh guest-off`.
- [ ] **Step 2: Prove D3 can fail.**
  Locally, with `KEEP=1`, temporarily change `mcp_guest_user_id(true)` at `lib/storage.php:320` to `mcp_guest_user_id()` in the mounted plugin copy. That restores the D3 bug. Then run the D3 phases on 1.2.31.
  Expected: `FAIL D3a` and `FAIL D3b`. Revert the change.
- [ ] **Step 3: Write the `db-down` phase in `run.sh`.**
  1. `docker compose stop db`.
  2. `checks.php --phase=db-down`. With a bearer token and also with a session cookie, it checks `mcp.php tools/list`, `GET api.php/v1/abilities` and `POST ...:execute`. Each must give:
     - status 503;
     - `Content-Type` starting `application/json`;
     - a body that `json_decode`s in full;
     - `UNAVAILABLE` and `CACTI_UNAVAILABLE` in the body: `error.data` for JSON-RPC, `error.details[0]` for AIP;
     - no `<` in the body;
     - no `Set-Cookie`.
  3. `docker compose start db`, then wait for the health check.
  4. `checks.php --phase=db-up` reruns the C2 modern `tools/list` check and expects 3 tools.
- [ ] **Step 4: Run both refs, all phases.**
  Expected: every line passes and the exit status is 0.
- [ ] **Step 5: Commit.**

  ```bash
  git add tests/integration/checks.php tests/integration/run.sh tests/integration/setup.sh
  git commit -s -m "test: cover the database-down and stale guest setting regressions"
  ```

### Task 4: Inspector, log gate and redaction

**Files:** Modify `tests/integration/run.sh`, adding the bodies of the `inspector` and `logs` phases.

**Interfaces:**
- **Consumes:** `token-mcpuser` and the `inspector` service.
- **Produces:**
  - **`out/<ref>/` contents:**
    - `cacti.log`, `web-access.log`, `web-error.log`, `php.log` (and `php-fpm.log` on develop);
    - `checks.txt` (all `PASS`/`FAIL`/`SKIP` lines);
    - `inspector-list.json` and `inspector-call.json`;
    - `summary.md`.
  - **Redaction:** every file in `out/<ref>/` is redacted.

- [ ] **Step 1: Write the `inspector` phase.**
  Run in the `inspector` service:

  ```sh
  sh -c 'T=$(cat /run/mcp-it/token-mcpuser); exec npx -y @modelcontextprotocol/inspector@2.16.0 --cli http://localhost/cacti/plugins/mcp/mcp.php --transport http --header "Authorization: Bearer $T" --method tools/list'
  ```

  Add a comment that 2.16.0 is pinned and bumped by hand. Then run the same with `--method tools/call --tool-name cacti-list-devices`.

  Save stdout to the two JSON files. Check them with `node -e`:
  - the list has the 3 tool names;
  - the call result's text content contains `it-alpha` and `it-beta`;
  - `isError` is not `true`.

  Print `PASS I1 inspector tools/list` and `PASS I2 inspector tools/call`, or `FAIL` lines.
- [ ] **Step 2: Write the `logs` phase.**
  1. Copy the logs out of `web` with `docker compose cp`.
  2. Gate on plugin lines. Use `grep -E '(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error).*plugins/mcp/'` over `cacti.log`, `php.log` and `web-error.log`:
     - exit 0: `FAIL L1 plugin PHP messages`, and copy the matching lines, after redaction, into `checks.txt`;
     - exit 1: `PASS L1`;
     - above 1: `FAIL L1 log read error`.

     The run window is the fresh stack itself, because every run starts from `down -v`. Note that in a comment.
  3. Redact with `sed -E -i 's/cmcp_pat_[A-Za-z0-9_-]+/cmcp_pat_REDACTED/g'` over every file in `out/<ref>/`.
  4. Then `grep -rE 'cmcp_pat_[A-Za-z0-9_-]*[A-Za-z0-9]' out/<ref>/ | grep -v cmcp_pat_REDACTED`. Any output fails the job with `FAIL R1 token left in artifacts`; a grep error also fails it.
  5. Write `summary.md`:
     - first line `Result: PASS` or `Result: FAILED PHASE <name>`;
     - then the Cacti ref and commit, the plugin commit (`git rev-parse HEAD`), and the contents of `checks.txt`.
- [ ] **Step 3: Prove the redaction gate fails.**
  Locally, append a fake `cmcp_pat_` value to `out/<ref>/checks.txt` after the redaction step, using a temporary debug hook that is not committed, and rerun the `logs` phase.
  Expected: `FAIL R1`. Remove the hook.
- [ ] **Step 4: Run both refs.**
  Expected: `I1`, `I2`, `L1` and `R1` pass, exit 0, and `summary.md` starts with `Result: PASS`.
- [ ] **Step 5: Commit.**

  ```bash
  git add tests/integration/run.sh
  git commit -s -m "test: run MCP Inspector and gate plugin log output"
  ```

### Task 5: Workflow, Dependabot and README

**Files:**
- Create `.github/workflows/integration.yml`.
- Modify `.github/dependabot.yml` and `README.md` (the "Development and tests" section, about `README.md:563`).

**Interfaces:**
- **Consumes:** `run.sh <ref> <tarball>`, `out/<ref>/summary.md` and `out/<ref>/`.

- [ ] **Step 1: Write `integration.yml`.**
  - **Top level:**
    - `name: Integration`.
    - `on.schedule: - cron: '17 6 * * *'`.
    - `on.workflow_dispatch.inputs.cacti`: a choice of `both`, `1.2.31` or `develop`, default `both`.
    - `permissions: contents: read`.
    - `concurrency: group: integration-${{ github.ref }}` with `cancel-in-progress: false`.
  - **Job `package`:** the same steps as `ci.yml`'s `package` job, but the artifact is named `mcp-plugin-integration`.
  - **Job `live`:**
    - `needs: package`, `timeout-minutes: 30`.
    - Matrix `cacti: ['1.2.31', 'develop']` with `fail-fast: false`.
    - Job-level `if`: `inputs.cacti` is `both`, empty (schedule), or equals `matrix.cacti`. A matrix entry can't be filtered by a job-level `if` that reads `matrix`, so put the condition on each step, or build the matrix in the `package` job with `fromJSON` output. Use the output approach.
  - **`live` steps:**
    1. Checkout with `persist-credentials: false`.
    2. Download the artifact to `dist/`.
    3. `tests/integration/run.sh ${{ matrix.cacti }} dist/mcp.tar.gz`.
    4. With `if: always()`: `cat tests/integration/out/${{ matrix.cacti }}/summary.md >> "$GITHUB_STEP_SUMMARY"`.
    5. With `if: always()`: upload `tests/integration/out/${{ matrix.cacti }}/`, named `integration-logs-${{ matrix.cacti }}`, with `retention-days: 14` and `if-no-files-found: error`.
- [ ] **Step 2: Lint the workflow.**
  Run `actionlint .github/workflows/integration.yml` if it is installed; if not, report that it is missing and don't install it. Also run `python3 -c "import yaml,sys; yaml.safe_load(open(sys.argv[1]))" .github/workflows/integration.yml`.
  Expected: no errors.
- [ ] **Step 3: Extend Dependabot.**
  Add `docker` updates with `directories: [/tests/integration/apache, /tests/integration/nginx]` and a `docker-compose` update with `directory: /tests/integration`. Each copies the existing entry's weekly schedule, 7-day cooldown and single all-patterns group, with group names `images` and `compose`.
- [ ] **Step 4: Update the README.**
  Add an "Integration tests" paragraph under "Development and tests". It says:
  - `tests/integration/run.sh 1.2.31` or `run.sh develop` runs the live checks;
  - it needs Docker with Compose v2 and git;
  - `KEEP=1` leaves the stack up;
  - CI runs it nightly and on demand, not on pull requests.
- [ ] **Step 5: Run the unit test suite and both live refs one last time.**
  Run the `ci.yml` test commands in `php:8.4-cli`, then `tests/integration/run.sh 1.2.31` and `tests/integration/run.sh develop`.
  Expected: all pass.
- [ ] **Step 6: Commit.**

  ```bash
  git add .github/workflows/integration.yml .github/dependabot.yml README.md
  git commit -s -m "ci: run live integration tests nightly and on demand"
  ```

## Known verification limit

GitHub runs `workflow_dispatch` and `schedule` only for workflows on the
default branch. The first real CI run can therefore happen only after this
branch merges into `develop`, started with `gh workflow run integration.yml`.
Until then, the evidence is the local `run.sh` runs and the lint. Report it
that way, and don't claim a CI pass before the post-merge run.
