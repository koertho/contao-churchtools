# Step 5 — backend management validation

Date: 2026-09-23. Status: **local acceptance passed on Contao 5.7.13 and 6.0.0**. Scope is step 5 only; linking/creation actions, frontend elements and SSO remain unimplemented. Hosted CI and visual browser acceptance are not claimed.

## Implementation

- One backend module, `church_tools_events`, under **ChurchTools**, labelled **Events / Termine**. Standard DC_Table navigation: **Archives / Archive → Appointments / Termine**. Archive creation and editing use core widgets; appointment creation/editing/copying/deletion/moving and bulk mutations are disabled. Archive copying/deletion are not exposed.
- Name and calendar selection are the only editable archive data. `calendarIds` has separate attribute load/save/options callbacks: JSON → integer list → core checkbox widget; validated widget serialization → positive integer list → JSON. NULL loads as no selection; explicit unchecked hidden input saves `[]`. Missing POST input preserves storage. No Contao serialization is written to the JSON blob.
- Options discover only calendars with the existing token client. Saved IDs are merged into every result, including empty or unavailable discovery; absent names show `ID N`. API failures give a translated notice. They never trigger synchronization, setup-token or password fallback. Core choice validation rejects unknown submitted options; an unsaved newly discovered option cannot be silently accepted during a later outage.
- Source rows have no input widgets. Lists offer source-calendar filtering, search and pagination. The core information action displays UID, source identifiers/calendar, title, plaintext description with line breaks, original date strings/all-day status and decoded allowlisted tag metadata. Tag colors are text, never CSS/HTML. No invented category/location/deep-link mapping.
- Link status distinguishes unlinked, existing target ID and missing target ID. It exposes neither protected target titles nor event-action links. Missing IDs are retained; no core-event writes occur.
- Archive list/edit views display last successful sync, escaped latest error and the nonzero linked-removal notice. The explanation of conservative failure on an entirely empty source window is visible in archive editing. It explicitly covers deletion of the last occurrence; this existing sync limitation is unchanged.
- Symfony PHP translations cover English/German, including navigation, editable fields, notices and tag labels. A small Contao-managed Twig list extension adds table headings only for the two ChurchTools tables and otherwise delegates to the core template. No custom CSS, JavaScript, `.html5` template or frontend element.
- All DCA callbacks use attributes, one listener class per callback in the required directories. SQL column definitions remain Doctrine arrays. `syncStatus` is an unsaved presentation field without a custom target column. No production schema change or new extension table.

## Permissions and review

Use standard user-group permissions:

1. **Backend modules → Events / Termine** in ChurchTools grants read access to this module's archives and appointments.
2. **Allowed operations → archive create/update** grants the corresponding archive operation.
3. **Allowed fields → archive name/calendarIds** controls the two widgets independently.

A module-only reader can read records but cannot update archives. A name-only editor can rename an archive but cannot change calendar IDs with a forged POST. There is no additional per-archive permission model. Both tables' onload callbacks also require the ChurchTools module permission, including entry points under a different module. Entry onload rejects every action except listing and `show`, in addition to the core read-only DCA flags. Status fields and `contaoEventId` have no editable widgets/palette entries.

Review followed the actual DC_Table, table/module/field voters, checkbox serialization/hidden-input behavior, value formatter and list/detail templates in the installed targets. A runtime difference was found and corrected: **Contao 6 requires `RecordLabel::fromHtml()` for deliberate HTML labels**, while 5.7 expects an HTML string. Detail values use Twig `Markup` only after HTML escaping. The matrix tests exercise these final output boundaries. The initial HTTP runner also needed Contao's normal `_target_path` login field; no security check was disabled.

## Environments and results

All PHP, Composer, database and HTTP tests ran through **DDEV jzm**, PHP **8.4.24**, MariaDB **10.3.39**, DBAL **4.4.4**, PHPUnit **12.5.35**. The test server binds an ephemeral loopback port inside the DDEV web container and invokes the real Contao HTTP kernel, routing, firewall, login/CSRF, DCA and database saves. Cookies/passwords remain process-local; server access logging is discarded, no response/session/credential dumps are produced.

| Target | Symfony FrameworkBundle | PHPUnit | Storage | Synchronization | Real commit regression | Authenticated backend HTTP |
| --- | --- | --- | --- | --- | --- | --- |
| Contao/core + calendar 5.7.13 | 7.4.18 | 84 tests / 203 assertions | 83 checks | 65 checks | 45 checks | 78 checks |
| Contao/core + calendar 6.0.0 | 8.1.7 | 84 tests / 203 assertions | 83 checks | 65 checks | 45 checks | 78 checks |

Both production containers boot, cache clear, container lint, Twig syntax lint and Composer platform checks pass. All package PHP files pass syntax checks. `composer validate --strict --no-check-lock` succeeds with the pre-existing out-of-date development-lock warning. No Composer update, migration runner, commit, push or Git initialization was executed. The bundle's `.git` is not usable repository metadata; no Git diff/HEAD claim is made.

### Recovery of missing temporary installations

Initial DDEV exec calls automatically started/recreated project containers and their readiness checks timed out; subsequent exec calls worked and all validation below completed. The two expected `/tmp/churchtools-matrix-57` and `-60` directories were absent in the resulting DDEV container. Their existing `churchtools_step3_57` / `_60` databases remained, initially containing only the four previously tested tables. Test installations were rebuilt in those same temporary paths using fixed package records from the existing host/bundle locks and cached exact metadata, followed by **Composer install**, not update. Core/calendar versions stayed at 5.7.13/6.0.0; the reconstructed 5.7 Symfony set differs from the historical step-4 installation (7.4.18 rather than 7.4.19). Contao 6 additionally uses cached manager/calendar 6.0.0, web-profiler 8.1.7 and monolog-bundle 4.1.0. These are the tested environments, not a claim of byte-identical recovery of the lost matrix locks. The reconstructed manifests/locks remain in the isolated temporary projects; root/host dependency files were not modified. Temporary recovery/diagnostic helpers were removed from the bundle.

### Controlled core schema additions

Each isolated database was inspected before schema work. `tools/backend-schema.php` previews Doctrine-generated CREATE statements and, only with `--apply`, creates missing allowlisted core tables. It does not alter existing tables or run migrations. Added on both targets:

`tl_user`, `tl_user_group`, `tl_log`, `tl_favorites`, `tl_undo`, `tl_version`, `tl_files`, `tl_page`, `tl_trusted_device`, `tl_module`, `tl_job`.

`tl_module` and `tl_job` were required by the actual Contao header menu; their separate plans were reviewed before creation. No extra ChurchTools table or extension column was added. Existing archive/entry schema comparisons remain stable. Host database/schema/configuration were untouched.

Five uniquely named temporary backend accounts and four groups cover admin, editor, module-only reader, no-module user and name-only editor. Fixtures also include owned archives/source entries and an independent core calendar/event. Cleanup runs in `finally`, including on test failure, and deletes only owned IDs/log usernames/session files/test configuration. Post-run aggregate checks showed **zero archives, entries, core calendars/events, users/groups, favorites, versions, undo records, trusted devices, log rows and triggers** on both targets. No production user was created or reset. The empty test-only core tables remain for reproducibility.

### HTTP scenarios proved

- Actual login and authenticated navigation for all five roles; ChurchTools navigation hidden without module permission; Archive → appointment navigation and separate parent scope; actual archive creation and form save.
- Real checkbox POSTs store `[2,77]`, preserve omitted/unavailable stored options, preserve selection during HTTP 503 discovery, leave a missing field unchanged, support partial deselection and explicit empty `[]` during outage. Fallback options remain checked. Positive saves prove the requests reach DC_Table, not just the route.
- Actual source-calendar filter form POST returns only the selected calendar's entries.
- Source title/calendar/description, tag name/description/color and archive error HTML probes are escaped in list/detail/widget output; description/tag line breaks remain visible. Both translation languages are rendered through the kernel.
- Missing target status retains the ID; existing target status does not expose target data. Source rows and link IDs remain byte-identical after reads, detail POSTs and mutation attempts; the linked core event remains byte-identical.
- Valid-CSRF mutation requests for entry `edit`, `delete`, `copy`, `create`, `editAll`, `overrideAll`, `deleteAll`, `cut` return **403**. Reader archive mutation returns **403**. No-module archive/entry GETs and POSTs, including a forged table under another permitted module, return **403**. These are permission checks, not merely missing-CSRF failures.
- Forged archive status/Contao-ID fields are ignored; a name-only editor cannot post calendar changes. Read-only status remains visible independently of editable-field rights.

## Reproduction

From `/home/dev/Kunden/privat/jzm-contao`, using the retained isolated project/lock; substitute `60` for `57`. Never run concurrent fixture suites against the same database. The standard DDEV `root/root` below belongs only to the local test database, not ChurchTools.

```sh
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/backend-schema.php /tmp/churchtools-matrix-57
# Inspect the plan; only then create missing allowlisted core tables:
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/backend-schema.php /tmp/churchtools-matrix-57 --apply
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/backend-test.php /tmp/churchtools-matrix-57
ddev exec php /tmp/churchtools-matrix-57/vendor/bin/phpunit --bootstrap /tmp/churchtools-matrix-57/vendor/autoload.php --configuration /home/dev/Kunden/github/contao-churchtools/phpunit.xml.dist
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/storage-test.php /tmp/churchtools-matrix-57 --apply
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/sync-test.php /tmp/churchtools-matrix-57
```

The runner refuses non-isolated database names and an existing `config_backend_test.yaml`; it owns/removes this temporary configuration and its test environment cache. It replaces the HTTP transport only in that temporary environment. `FixtureClient` permits GET `/api/calendars` exclusively, supplies controlled results/outage and throws for other endpoints. No live API fetch or token setup is needed. CI now includes schema preview, missing-core-table setup and the same HTTP suite; hosted execution has not been observed.

## Limits preserved

- No new live ChurchTools test; minimum remote permissions, hidden response caps and skipped live permission-loss cases remain unresolved. Entirely empty remote windows still fail conservatively, including genuine deletion of the last occurrence. No sync-contract change.
- Series-split identity changes still lose associations; no heuristic relinking. Missing target IDs are not nulled. Event link/create/unlink actions remain step 6.
- Real authenticated HTTP/rendered-markup acceptance is complete; no interactive browser screenshots or visual/responsive acceptance were performed. The test server is private loopback in the container, with no public/DDEV-router deployment.
- No host backend deployment/cache rebuild, host schema migration, production acceptance, PHP 8.5+ or hosted CI claim. `.env.local`, token/setup logic and `ChurchToolsTestController.php` were not read/changed for this work.

## Independent review and final verification

After the CLI completed, the calling agent reviewed the backend callbacks, rendering, package dependencies and test harness, then reran the suites independently on both targets. Two corrections were made:

- `BackendView::linkedState()` now needs the core calendar table at runtime. `composer.json` therefore directly requires `contao/calendar-bundle` with `^5.7 || ^6.0`, rather than relying on its incidental presence in both test projects. Both installed target-matching calendar bundles already satisfy this declaration. No host dependency or lockfile update was performed. Manifest validation passes; the unused ignored bundle development lock remains stale and warns that it lacks the newly declared calendar package.
- A warm schema cache exposed a test-harness assumption: schema generation need not populate process-local DCA globals. `tools/storage-test.php` now explicitly loads the actual four tested table DCAs before checking relationships/models. This fixes the harness without weakening its parent/child assertions or changing runtime storage.

Independent final results on both 5.7.13 and 6.0.0: **78 authenticated backend HTTP checks**, **84 PHPUnit tests / 203 assertions**, **83 storage checks** (two consecutive successful runs with warm cache), **65 synchronization checks**, and **45 real-commit checks**. Backend fixtures were removed by the runner. The calling agent performed no live ChurchTools request, host-schema change, commit or push. Visual browser inspection and hosted CI remain unverified.
