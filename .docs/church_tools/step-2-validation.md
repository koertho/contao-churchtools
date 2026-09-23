# Step 2 validation — 2026-09-17

Status: implementation and local acceptance passed; hosted CI execution remains **unverified**. Step 2 is not labelled unconditionally complete until that workflow has actually run. No commit, push or publication was performed.

## Changes

- Independent `koertho/contao-churchtools` package, PSR-4 namespace, Symfony `AbstractBundle`, Contao Manager plugin and explicit service wiring.
- Bundle-owned connection/setup keys and six-month future/one-month past synchronization configuration; zero past months is retained.
- Dedicated Symfony HttpClient, token-only calendar and appointment reads, bounded requests without redirects, nested payload/UID/date validation, duplicate/conflict handling and detectable partial-response rejection. No logging of private payloads or chained upstream exceptions.
- Explicit `church-tools:setup-token --output=...`: own-account verification, cookie-free token verification, new `0600` file only, refusal of existing files/symlinks. No automatic provisioning or password fallback.
- Existing JZM mount reused. Composer path repository and vendor symlink use `/home/dev/Kunden/github/contao-churchtools` in host and container. Host config maps existing `CT_TOKEN`, `CT_USER`, `CT_PASSWORD`; package contains no such hard-coded environment names.
- CI and isolated matrix scripts exercise managed installations, package discovery, container boot, command wiring, platform requirements and controlled tests.

## Environments and results

All PHP/Composer/runtime checks ran through DDEV project `jzm`, PHP **8.4.24**. No platform requirements were ignored.

| Environment | Contao core/manager | Symfony FrameworkBundle | Result |
| --- | --- | --- | --- |
| Existing JZM host | 5.7.13 | 7.4.18 | Bundle discovery, prod cache rebuild, container lint and read-only live API passed |
| `/tmp/churchtools-matrix-57` inside DDEV | 5.7.13 | 7.4.19 | Independent resolve/install, reinstall from lock, platform checks, manager registration, container/command boot passed; **41 tests, 144 assertions** |
| `/tmp/churchtools-matrix-60` inside DDEV | 6.0.0 | 8.1.7 | Independent resolve/install, reinstall from lock, platform checks, manager registration, container/command boot passed; **41 tests, 144 assertions** |

PHPUnit: **12.5.35** in both matrix projects. Their generated lockfiles remain in those temporary container projects; the committed workflow resolves current versions within the specified targets. Matrix projects do not connect to the host database or use its private environment file.

Package `composer validate --strict --no-check-lock` and syntax checks for all new PHP files passed. Host Composer update reported **1 install, 0 updates, 0 removals**. The host lockfile changes contain only the new package, its stability flag and content hash. No existing dependency was upgraded.

The host smoke used the normal Contao/Symfony environment loader and the existing token in memory; `.env.local` was not inspected, displayed or edited. Read-only calls through the new client returned **16 calendars** and **18 occurrences** for calendar 2 from `2026-09-17` to `2026-10-17`. Only counts and runtime versions were printed. The existing token was not provisioned again or overwritten; no remote appointments, calendars or permissions were changed.

The optional setup command was tested exclusively with synthetic HTTP responses. Coverage includes correct request authentication, own-account matching, token-only verification without cookies, verification mismatch/403, login failure, missing session/token, explicit destination, overwrite/symlink refusal, secret-free command output and file mode. Client tests cover 401/403/429/500/redirect responses, timeout, invalid JSON/envelopes/rows/dates, detectable partial/paginated data, absent token, unsafe origins and conflicting/duplicate occurrence UIDs. Config tests cover bounds, unknown options and explicit zero retention.

## Reproduction

From the JZM host directory, run the matrix commands in the [package README](../../README.md). For each generated project:

1. `composer update --no-interaction --prefer-dist`
2. `composer check-platform-reqs`
3. `composer install --no-interaction --prefer-dist`
4. `php matrix-boot.php`
5. `vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration /home/dev/Kunden/github/contao-churchtools/phpunit.xml.dist`

Run these commands inside DDEV. Host checks were `ddev exec php bin/console cache:clear --env=prod` and `ddev exec php bin/console lint:container --env=prod`. Initial use of `--no-debug` was rejected by the installed Contao console; the supported `--env=prod` invocation succeeded. An early config unit-test harness lacked Symfony 7.4 kernel parameters; the final schema test uses Symfony's configuration processor, and both final suites pass.

## Limits and next action

- Hosted GitHub Actions has not run: no push was requested, and the bundle directory exposes no usable Git repository metadata in this workspace. The workflow's PHP 8.4/Contao 5.7 and 6.0 combinations have been exercised locally. Do not equate this with a hosted CI success.
- PHP 8.5+, other dependency combinations, real credential-based provisioning against a new account and future feature acceptance are unverified. Setup against the existing host token was deliberately not run.
- Client reads do not prove snapshot completeness or distinguish every permission loss from an empty response. No synchronization/deletion is implemented. Step 1 residual live checks remain as documented; the user-skipped permission-loss test was not requested again.
- No tables/migrations, event writes, synchronization, backend/frontend elements or SSO were added. The existing test controller, moved documentation in the host and dirty sermons submodule were preserved.
- Host HEAD remained `f677a2dc4c602cc78a14f55473924da7b0a3aa18`. Bundle HEAD/status cannot be reported because its `.git` directory contains no usable metadata. No Git initialization or commit was attempted.

Next action for final CI acceptance: run the checked-in workflow when the user authorizes making this package available to its GitHub repository. Further feature work starts with step 3 and requires its own task.
