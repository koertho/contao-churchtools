# Step 3 validation — 2026-09-17

Status: **step 3 local acceptance passed on Contao 5.7 and 6**. Hosted CI is configured but **not executed/verified**. Steps 4–8 have not been implemented. No commit, push or publication was performed.

## Implemented scope

- Exactly `tl_church_tools_archive` and `tl_church_tools_entry`, Doctrine DCA schema arrays, registered real Contao models, minimal archive → entry navigation configuration, read-only source DCA and English/German Symfony PHP translations.
- Explicit public-source mapping and per-archive UID storage operation. Remote arrays cannot overwrite local identifiers/links/status. Missing targets are retained, and local deletion never touches a core event.
- Calendar selection and tags use documented JSON blobs. Tags retain nullable descriptions and symbolic colors; unknown/private metadata is excluded.
- Inclusive all-day calendar dates and offset-bearing timed source strings; separate nullable date/whole-second index columns. No Contao event mapping or exclusive all-day conversion.
- The matrix installs target-matching calendar bundles for tests and now defines an isolated MariaDB service in CI. No package/host runtime dependency changed: runtime code does not yet import calendar classes or define a DCA relation to core calendar tables.

The final schema, index rationale, supported input limits and step 4 concurrency boundary are recorded in [architecture.md](architecture.md#implemented-step-3-storage-contract--2026-09-17).

## Actual environments and outcomes

All PHP/Composer/database tests ran through **DDEV project `jzm`**, PHP **8.4.24**. The existing standalone test installations were reused; adding the calendar bundle in each produced **1 install, 0 updates, 0 removals**. These are independent projects and lockfiles inside the web container, not the host Composer installation.

| Environment | Core / calendar | Symfony FrameworkBundle | DBAL | Database | Results |
| --- | --- | --- | --- | --- | --- |
| `/tmp/churchtools-matrix-57` | 5.7.13 / 5.7.13 | 7.4.19 | 4.4.4 | MariaDB 10.3.39, `churchtools_step3_57` | 83 database checks; 55 PHPUnit tests / 158 assertions; boot and platform checks pass |
| `/tmp/churchtools-matrix-60` | 6.0.0 / 6.0.0 | 8.1.7 | 4.4.4 | MariaDB 10.3.39, `churchtools_step3_60` | 83 database checks; 55 PHPUnit tests / 158 assertions; boot and platform checks pass |

Exact database version: `10.3.39-MariaDB-1:10.3.39+maria~ubu2004-log`; PHPUnit **12.5.35**. DDEV **1.25.4**. Both schema plans were inspected before application. Only the two extension tables and the two real core calendar tables were created in each isolated test database. No host-schema commands, migration runner or remote API calls were used. Test row changes run inside a transaction and are rolled back; table structures remain for repeat runs.

Package `composer validate --strict --no-check-lock`, PHP syntax checks across `src`, `contao`, `translations`, `tests`, `tools`, and both installations' `composer check-platform-reqs` passed. Existing client/config/setup tests passed unchanged. The runner boots the real Contao framework and uses its DCA/Doctrine schema provider, not a hand-written replacement test schema.

### Proven storage cases

- Schema installation and a second DBAL schema comparison produce no extension ALTER statements; a comparison with inserted rows preserves those rows byte-for-byte.
- Model registration, archive and entry roundtrips, inherited archive queries, real `getRelated('pid')`, parent/child DCA configuration and child navigation href.
- Database rejection of a duplicate `(pid, occurrenceUid)`; the same UID succeeds in another archive. Distinct 2048-byte UIDs sharing the first 2047 bytes, case differences and trailing spaces remain distinct and roundtrip exactly. Oversized values are refused before storage.
- Repeated source save; same-UID move changing base ID, calendar ID and dates; original row and Contao target ID remain unchanged.
- Independent archive links, nullable links, unresolved numeric targets and deletion of an actual referenced core target; the stored target ID survives subsequent source updates.
- Deletion of a local entry preserves the entire independently created core event row. Recreating the same UID or inserting a new UID does not restore/guess its previous association.
- Calendar lists `[110, 2, 110]` → `[110, 2]`, empty lists, rejected malformed/invalid selections without losing the previous value; empty/nonempty tag lists, null descriptions and exclusion of tag counts/private keys.
- Original plaintext fixture (including literal HTML/Markdown/newlines) survives unchanged. Source payload local-field injection and private metadata do not reach the stored row.
- Metadata fixtures cover one-day timed and multi-day timed/all-day cases. Synthetic date cases cover single-day and multi-day all-day dates across the March DST boundary, both March/October offset transitions, fractional seconds and source-offset preservation.
- Internal/unknown visibility cannot be stored; refusal does not delete or overwrite an existing row. Synchronizer visibility-transition deletion remains step 4.

An added target-deletion test initially compared against the deleted Active Record's cleared primary key. Core `Model::delete()` explicitly clears that in-memory key. The test now retains the ID before deleting; both final suites pass without changing runtime storage behavior.

## Reproduction

Run from `/home/dev/Kunden/privat/jzm-contao`. Use new project/database suffixes when a prior project exists. The generator refuses to overwrite an existing Composer project. Example for 5.7 (repeat with `6.0.*`, `60_check` and the matching URL):

```sh
ddev exec php /home/dev/Kunden/github/contao-churchtools/tools/create-matrix-project.php '5.7.*' /tmp/churchtools-step3-57-check
ddev exec bash -c 'cd /tmp/churchtools-step3-57-check && composer update --no-interaction --prefer-dist && composer check-platform-reqs'
ddev exec mysql --host=db --user=root --password=root -e 'CREATE DATABASE churchtools_step3_57_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57_check php /home/dev/Kunden/github/contao-churchtools/tools/storage-test.php /tmp/churchtools-step3-57-check
```

Inspect the four proposed CREATE statements, then apply only to that isolated database:

```sh
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57_check php /home/dev/Kunden/github/contao-churchtools/tools/storage-test.php /tmp/churchtools-step3-57-check --apply
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57_check php /tmp/churchtools-step3-57-check/matrix-boot.php
ddev exec php /tmp/churchtools-step3-57-check/vendor/bin/phpunit --bootstrap /tmp/churchtools-step3-57-check/vendor/autoload.php --configuration /home/dev/Kunden/github/contao-churchtools/phpunit.xml.dist
```

`root/root` here is the standard local DDEV database account, not ChurchTools credentials. The runner verifies both the explicit test URL and the effective database name prefix `churchtools_step3_`. It issues no DROP/TRUNCATE, applies no migrations, leaves existing tables untouched, rejects extension schema drift, and rolls back fixture rows. Retain the isolated Composer lockfiles to reproduce exact versions. Initial local matrix projects used equivalent synthetic config pointing at the explicit test-URL environment variable; neither loaded the host environment file.

## Limits and preserved state

- Hosted GitHub Actions and its configured MariaDB **10.11** service have not run. The local database result is MariaDB **10.3.39**, not a claim of having exercised MySQL or every supported server/PHP combination.
- Parent/child navigation is proven as DCA configuration plus real model parent relation. No complete backend module or browser interaction exists yet; that acceptance belongs to step 5. Translation files are supplied, but their rendered backend appearance is not claimed tested.
- The UID maximum of **2048 bytes** and timed fractional precision of **six digits** are explicit local supported-input limits, not observed ChurchTools maxima. Values beyond them fail rather than truncate. No optional location/category/deep-link contract has been invented.
- Sequential storage idempotency and the DB uniqueness guard are tested. Concurrent synchronization, locking, all-or-nothing snapshots, absence/retention cleanup, status transitions, cache invalidation, event creation, display resolution and frontend output are not implemented or tested here.
- Synthetic DST cases prove the selected local representation. They do not claim a new live ChurchTools DST observation. The user-skipped permission-loss live test remains skipped and is not a step 3 blocker.
- The API client, setup/token code, existing host test controller, host connection configuration and `.env.local` were unchanged. `.env.local` was never inspected. No real ChurchTools credentials or remote mutations were required.
- Existing host changes (moved/deleted planning files, Composer/config modifications and dirty sermons submodule) were preserved. Host HEAD remains `f677a2dc4c602cc78a14f55473924da7b0a3aa18`. Bundle Git status/HEAD are unavailable because its `.git` has no usable repository metadata; no Git initialization was performed.

## Step 4 handoff

Build the synchronizer on `ChurchToolsEntryModel::saveSource()` and inherited model queries. Add run serialization, transactions, successful-window/access gates, internal-visibility handling and classified cleanup/status accounting. The client's returned list alone is not deletion authority. Preserve the documented UID/series-split limitation and independent core events; do not transfer links by title/date similarity. No part of this orchestration was implemented in step 3.
