# Step 4 validation — 2026-09-17

Status: **step 4 implemented; local acceptance passed on Contao 5.7 and 6**, with the API and cache failure boundaries below. Hosted CI has not run. Steps 5–8 remain pending. No commit, push, Git initialization or publication.

## Delivered scope

- Shared `SyncWindow`, `SnapshotFetcher`, `Synchronizer`; CLI `church-tools:sync [--archive=ID]`; hourly attribute cron with sanitized aggregate logging.
- Default one-month retention/six-month future window, month-end clamping, half-open UTC day window, date-only inclusive all-day handling and precise timed overlap tests.
- Full snapshot prevalidation, before/after discovery, per-calendar full/chunk comparison, UID conflict checks, unknown pagination/partial metadata/header rejection, response/mapped-snapshot/storage limits. Completely empty selected calendar windows fail closed.
- Database/ archive advisory locks, row locks, configuration-race check, transactional source reconciliation, model cache cleanup on exit, per-archive status and classified counters.
- Internal visibility removal without persistence or absence misclassification; source moves/metadata updates through the existing `saveSource()`; no core-event writes. Accepted series-split association loss, no heuristics.
- Contao cache tag invalidation and retry behavior. Actual tag-dispatch tests; no frontend implementation or rendered-page cache claim.
- Exactly two extension tables. The sole schema addition is nullable unsigned `lastSyncPastMonths` on the existing archive, recording the last successful past-month configuration to distinguish natural retention from shortened windows. No mapping, status, lock or outbox tables.

## Files

New runtime files:

- `src/Sync/{SyncWindow,SnapshotFetcher,Synchronizer}.php`
- `src/Command/SyncCommand.php`
- `src/EventListener/Cron/SyncListener.php`

Adjusted runtime/configuration:

- `src/ChurchToolsBundle.php`: service wiring, bounded month configuration.
- `src/Api/{Transport,ChurchToolsClient}.php`: bounded streamed responses and stricter partial/pagination rejection.
- `src/Storage/SourceOccurrence.php`: UTF-8/text/blob storage-size validation.
- `src/Model/ChurchToolsEntryModel.php`: synchronization contract comment only; existing save operation reused.
- `contao/dca/tl_church_tools_archive.php`: one nullable retention bookkeeping column, no new backend widget.
- `composer.json`: direct DBAL `^4.3` and PSR Log `^3.0` dependencies for runtime imports; existing matrix versions satisfy both.

Validation/documentation:

- `tests/Sync/{RemoteFixture,SnapshotTest,LimitsTest,SyncAcceptance,CommitAcceptance}.php`
- `tools/{sync-test,live-sync-test}.php`; `tools/storage-test.php` supports only the additive step-4 test-schema change and refuses stale DCA caches/arbitrary drift.
- `.github/workflows/ci.yml`: synchronization acceptance added to both targets.
- `README.md`, `.docs/church_tools/{README,steps,architecture,step-4-validation}.md`.

## Environments and final results

All PHP, Composer and database validation ran through **DDEV jzm**, PHP **8.4.24**, using the existing independent projects and existing test databases. No host schema command or migration runner was invoked.

| Environment | Core / calendar | Symfony | DBAL | Database | Results |
| --- | --- | --- | --- | --- | --- |
| `/tmp/churchtools-matrix-57` | 5.7.13 / 5.7.13 | 7.4.19 | 4.4.4 | `churchtools_step3_57` | 75 PHPUnit tests / 190 assertions; 83 storage checks; 65 synchronization/CLI/cron checks + 45 real-commit regression checks |
| `/tmp/churchtools-matrix-60` | 6.0.0 / 6.0.0 | 8.1.7 | 4.4.4 | `churchtools_step3_60` | 75 PHPUnit tests / 190 assertions; 83 storage checks; 65 synchronization/CLI/cron checks + 45 real-commit regression checks |

MariaDB **10.3.39-MariaDB-1:10.3.39+maria~ubu2004-log**; PHPUnit **12.5.35**. Both container boots, manager discovery, platform checks, real CLI service and hourly cron tag registration pass. The existing client/setup/configuration/storage tests remain included. Peak reported PHPUnit memory with deliberate oversize fixtures: **62 MiB**; this is a test measurement, not a universal runtime bound.

Both matrix Composer updates changed **only the path package reference** (0 installs, 1 package update, 0 removals); all third-party versions stayed fixed. Bundle `composer validate --strict --no-check-lock` exited successfully; its ignored, unused development lockfile still produces an out-of-date-lock warning after adding direct dependency declarations. Matrix lockfiles are updated and retained. PHP syntax checks across runtime, DCA, translations, tests and tools pass.

Schema plans contained only:

```sql
ALTER TABLE tl_church_tools_archive ADD lastSyncPastMonths SMALLINT UNSIGNED DEFAULT NULL;
```

Applied only to the two isolated databases. Both subsequent real DCA/Doctrine schema comparisons pass with no schema drift. No DROP/TRUNCATE or unrelated migration was executed. Existing and live test records were rolled back; final aggregate checks showed **0 archives, 0 entries, 0 core calendars and 0 core events** in each isolated database. After the final 5.7 live repeat, another aggregate query confirmed zero archives and entries in both databases.

### Proven scenarios

- Idempotency including byte-identical unchanged rows/timestamps; source update, base-ID/date move with unchanged local row/link, tag updates/allowlist and private metadata exclusion.
- Archive-specific links and isolation for overlapping source UIDs; synthetic cron continues the next archive after a prior archive fails.
- Missing/duplicate discovery calendars, disappearance on post-fetch discovery, HTTP 401/403/500, malformed JSON, count mismatch, unknown pagination metadata, next-link envelope and pagination header, full/chunk disagreement, completely empty response. Source rows and previous success timestamp/removal count/configuration remain unchanged; errors are fixed text.
- Invalid later UID prevents all writes; HTTP byte budget, snapshot row budget and source mediumtext byte bound reject oversize input. Conflicting cross-calendar UID fails closed.
- UTC/date-domain boundaries, inclusive all-day end, exclusive timed end, zero-duration lower-bound inclusion, offset-aware overlap and month-end clamping. Existing step-3 tests continue covering DST and fractional seconds.
- Natural expiry, past-window reduction, upper-window exclusion and calendar deselection have separate counters. Explicit empty selection needs no remote call. None of these increments the linked source-removal notice.
- Internal records never persist; a public linked occurrence becoming internal is removed once, excluded from source-removal counts, and invalidates cache tags. Public reappearance starts unlinked.
- Saved `repeat-baseline`, `repeat-after-move`, `repeat-after-delete`, `series-split-baseline`, `series-split-after` fixtures: moved detached UID preserves links despite exceptions; one deleted recurrence counts once; split removes/recreates five UIDs with no transferred links and five linked source removals.
- Entire independently created core-event row remains unchanged across reconciliation paths. Removed/reappearing source identities never restore associations.
- A second real database connection holds the same advisory lock: synchronization makes no HTTP request or data/status change. Selection change while fetching prevents stale writes.
- A failure after an actual insertion rolls back all source changes; retry succeeds without stale Contao Active Records. The additional regression below proves linked-removal rollback and status atomicity at real commit boundaries.
- Real container CLI: invalid ID exits 2, valid empty-selection archive exits 0. Hourly attribute registration confirmed by the compiled container on both targets; the actual cron invoker/logging path is exercised with controlled HTTP responses.

### Real read-only remote synchronization

Used the existing token through **normal process-local Symfony Dotenv loading**, never inspected/read back/output `.env.local`. No token setup, credentials/cookies in arguments, remote write operation or personal payload output. Selected the already documented calendar 2; discovery does not implicitly select additional calendars.

Each target ran a full service synchronization twice in an outer fixture transaction on its isolated database. Only these aggregates were emitted:

| Target | First run | Second run | Removals |
| --- | --- | --- | --- |
| Contao 5.7 | 102 inserted | 102 unchanged | 0 in every category |
| Contao 6 | 102 inserted | 102 unchanged | 0 in every category |

The live runner closes its dedicated connection to roll back all fixture rows. It stores no raw response fixtures. This proves real token-authenticated GET discovery/fetch, bounded full/chunk agreement, mapping, local model writes, cache-tag invalidation and repeat idempotency for the observed calendar/window. It does not prove other calendars, permission loss or live cancellation/visibility transitions.

### Issues resolved during validation

- Matrix dependencies do not autoload a dependency package's `autoload-dev`; shared synthetic test fixture loading is now explicit.
- An existing compiled DCA cache initially omitted the new retention column. The schema drift guard refused a proposed removal; **no removal was executed**. Refreshed only matrix caches and added an explicit stale-cache refusal before schema work. Both final comparisons pass.
- A live Contao-6 probe was accidentally run concurrently with synthetic fixtures in the same isolated database. A database transaction abort invalidated a nested savepoint; the attempt failed and is not counted as acceptance. Runs on the same database are now sequenced. Connection-close rollback and fixed diagnostics handle this abort safely; final sequential live acceptance passes. This was local test contention, not an observed remote permission problem.

## Reproduction

Run commands from `/home/dev/Kunden/privat/jzm-contao`; substitute `60` for `57` for the other target. Do not run fixture and live runners concurrently on the same database. The legacy `churchtools_step3_*` database prefix is intentionally retained to reuse the validated isolated projects.

```sh
ddev exec bash -c 'cd /tmp/churchtools-matrix-57 && APP_ENV=prod APP_DEBUG=0 CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php vendor/bin/contao-console cache:clear'
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/storage-test.php /tmp/churchtools-matrix-57
# Inspect the additive plan before --apply; never target the host database.
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/storage-test.php /tmp/churchtools-matrix-57 --apply
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/sync-test.php /tmp/churchtools-matrix-57
ddev exec php /tmp/churchtools-matrix-57/vendor/bin/phpunit --bootstrap /tmp/churchtools-matrix-57/vendor/autoload.php --configuration /home/dev/Kunden/github/contao-churchtools/phpunit.xml.dist
```

Explicit authorized live probe, emitting aggregates only:

```sh
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 CHURCHTOOLS_LIVE_ENV_FILE=/var/www/html/.env CHURCHTOOLS_LIVE_ORIGIN=https://jzm.church.tools CHURCHTOOLS_LIVE_CALENDAR=2 php /home/dev/Kunden/github/contao-churchtools/tools/live-sync-test.php /tmp/churchtools-matrix-57
```

`root/root` are the standard local DDEV test database credentials, not ChurchTools secrets. Test runners check both explicit test URL and effective database name before mutation; they never boot the host kernel or run host migrations.

## Explicit limits and preserved state

- Live permission-loss testing remains **user-skipped**, never requested again or claimed passed. Silent nonempty permission filtering or hidden caps identical in full/chunk queries remain undetectable. Empty calendars intentionally do not reconcile: even true last-occurrence deletion cannot authorize absence through an empty response alone. Simulated safeguards are not new API guarantees.
- No invented cancellation flag, category/location/deep-link mapping, or heuristic identity continuity. An occurrence moved entirely outside retrieved windows can only be classified as broad source disappearance. Existing live DST/category/visibility/whole-series-cancellation contract gaps remain in step 1.
- Source changes and success metadata now commit atomically; synchronous pre-commit invalidation or status-write failure rolls both back. External/deferred cache delivery is separate. Early evictions cannot be rolled back; the after-commit second pass addresses refill before commit. Its failure preserves the committed data/count and returns `committed_cache_error`. No durable outbox, crash-proof cache delivery or complete elimination of in-flight cache races is claimed. See the corrected architecture contract.
- Cache tests prove invalidation dispatch, not future rendered-page tagging or browser behavior. No frontend/backend step 5+ implementation or deployment acceptance.
- Advisory locks are verified across two connections to the same MariaDB primary. No multi-primary/pooling guarantee or other SQL platform claim. The original fixture suite uses DBAL savepoints; the added commit regression deliberately uses no enclosing transaction and verifies durable changes through a second connection.
- Hosted CI, its MariaDB 10.11 service, MySQL and PHP 8.5+ remain unverified. Local proof is the exact two targets above.
- Host schema, environment configuration, `.env.local`, token provisioning and existing `ChurchToolsTestController.php` are unchanged. No personal remote payloads/credentials were printed or committed. The host's prior dirty Composer/config/docs/submodule state was preserved; host HEAD is `f677a2dc4c602cc78a14f55473924da7b0a3aa18`.
- The bundle's `.git` is not usable repository metadata, so Git diff/status/HEAD are unavailable there. No Git initialization, commit or push was attempted. Validation uses the actual source/files and runtime checks.


## Follow-up: atomic data/status correction — 2026-09-17

The initial implementation committed source rows before invalidation and before its success-status write. This was a **database/status atomicity bug**, not an unavoidable external-cache limitation: a synchronous cache error or status-write failure could lose a linked removal and its notice. That ordering and the prior justification above have been replaced.

Narrow runtime change: `Synchronizer::archive()` now performs source reconciliation, the final success-field UPDATE, and the first synchronous cache-tag call **before a single commit**. The pre-commit failure path rolls back rows and metadata, then records only a sanitized error. Invalidation is repeated after commit to account for immediate listeners/custom transports; a failure of this second pass is explicitly `committed_cache_error` and retains the committed counts. No new production table, outbox, schema change or dependency.

Inspected actual matrix sources, both targets:

- `contao/core-bundle/src/Cache/CacheTagManager.php`, `invalidateTags()`: synchronous event dispatch followed by optional FOS invalidator.
- `friendsofsymfony/http-cache/src/ProxyClient/Symfony.php`, `invalidateTags()`: request queueing.
- `friendsofsymfony/http-cache/src/ProxyClient/HttpDispatcher.php` and `SymfonyCache/KernelDispatcher.php`: queued/deduplicated requests, delivery on `flush()`.
- `friendsofsymfony/http-cache-bundle/src/EventListener/InvalidationListener.php`: flush on console/kernel termination and kernel exception; HTTP termination catches `ExceptionCollection`, while console termination can propagate it. This listener's source is identical in both matrix installations.

`tests/Sync/CommitAcceptance.php`, invoked by the existing `tools/sync-test.php` and CI workflow, runs **without an outer transaction**. A separate autocommit reader verifies what is actually durable. Each scenario owns its archive, entries, core calendar/event and cleanup IDs:

1. Baseline has two source occurrences, one linked to an independent core event, with nonzero old timestamp/count. The remote fixture removes only the linked occurrence, leaving a nonempty snapshot. A synchronous cache listener throws after source deletion and the status UPDATE inside the transaction. Result: original rows byte-identical, all four old success fields unchanged, sanitized error only. Retry deletes/counts exactly one; a further clean run counts zero. Core event remains byte-identical.
2. The same setup injects a **real SQL error on the final archive status UPDATE**, using a uniquely named temporary trigger restricted to that fixture archive ID. Its session marker reads one remaining source row from the active transaction, proving deletion preceded the rejected UPDATE. A separate reader still sees both original rows and all previous success fields after rollback. Retry and subsequent no-change run have the same exact-once behavior. Trigger creation/removal happens outside synchronization transactions; cleanup drops only the trigger this runner created and deletes only owned fixture rows. No extra tables and no host schema operation.
3. A listener that throws only on the second, after-commit dispatch observes already committed source removal and count through the independent reader. The returned `committed_cache_error` preserves the newly committed notice rather than misreporting a rolled-back run.

The event observer also proves nesting level **1 before commit / 0 after commit** and confirms that another connection sees old rows on the first pass and new rows plus count on the second. These are real commit boundaries, not DBAL savepoint-only evidence.

Follow-up results on **both** Contao 5.7.13 and 6.0.0: **45 new real-commit checks**, existing **65 synchronization/CLI/cron checks**, **83 storage checks**, and **75 PHPUnit tests / 190 assertions** pass via DDEV jzm. Modified PHP files pass syntax checks. Existing tests' cache-event expectation now covers both dispatches. The test runner verifies the isolated database prefix and refuses an outer transaction for the new regression; its exact owned-fixture cleanup leaves unrelated rows untouched.

Final aggregate cleanup checks on both isolated databases reported zero archives, entries, core calendars/events and triggers. No live API call, host environment/secret access, host database change, commit or push during this correction. Earlier live results remain historical evidence and were not rerun. Deferred transport failure and premature cache eviction remain explicitly separate from the now-atomic database data/status contract.

## Independent parent review after CLI execution — 2026-09-17

The calling agent reviewed the synchronizer, snapshot fetcher, interval handling, transport and real-commit regression. It identified the original split data/status commit and requested the narrow correction above through a resumed Codex CLI run. After that CLI run completed, it independently reran both target environments: 75 PHPUnit tests / 190 assertions, 65 synchronization checks, 45 real-commit checks and 83 storage checks all passed. The schema previews contained no changes before the storage runner was invoked with `--apply`. No additional live fetch or host-schema operation was performed during this independent verification.

The operational limitation remains explicit: a completely empty selected calendar window blocks the entire archive reconciliation, including the genuine deletion of its last occurrence, because the current API evidence cannot distinguish that from lost appointment visibility. This conservative behavior is not proof that all remote deletion scenarios are supported.
