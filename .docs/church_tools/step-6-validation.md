# Step 6 — event actions and effective display validation

Date: 2026-09-23. Status: **local implementation and acceptance passed on Contao 5.7.13 and 6.0.0**. The earlier independent review found two concrete defects; the correction acceptance and parent re-review status are recorded below. This record covers step 6 only; no step-7 frontend elements/cache output, SSO, host deployment, visual browser inspection or hosted CI acceptance is claimed.

## Implemented behavior

- A DCA operation opens an attribute-routed backend action form in the Contao Twig namespace. Link, explicit unlink, authorized open-target and explicit unpublished creation apply to exactly one occurrence. Source widgets remain absent and the step-5 mutation guards stay intact.
- A separate local permission uses the actual core excluded-field mechanism: `tl_church_tools_entry::contaoEventId`. The permission is visible in the real user-group editor. It grants only the dedicated actions, never ordinary source-field editing. Core module/table/record voters and allowed-calendar checks protect selection, submitted IDs and opening. Creating details also requires content create, text-element and text-field permissions. The POST controller explicitly validates the Contao CSRF token; GET never mutates.
- Existing non-null IDs, including missing/forbidden targets, cannot be replaced by create. Repeated creates/links are idempotent. Explicit unlink preserves the core event/content. Missing IDs remain stored and same-ID restoration reactivates display when authorized and published.
- Actions use the synchronizer's exact archive advisory-lock key, then archive/entry row locks and a single transaction for event, text child and link. Cache invalidation occurs before and after commit. No synchronization/EmptySnapshots/series-UID behavior changed. Exactly two extension tables and the existing nullable reference remain.
- The copied event has an acting-user author, no recurrence, `source=default`, unpublished status and an empty alias using Core's numeric-ID URL fallback. The normal editor subsequently generates/edits its alias and can publish it. Description is escaped plaintext in both teaser and a real `tl_content` text child. No location, category, link or image is invented.
- `EventFields` explicitly sets addTime/startDate/endDate/startTime/endTime. All-day end dates remain inclusive; endTime uses the next local midnight minus one second. Offset values remain instants in Contao's initialized timezone. Nonzero fractions, identical timed endpoints and out-of-range timestamps are explicitly rejected rather than rounded or reinterpreted. Identical timed endpoints mean open-ended in Core, unlike point occurrences in the source; unchanged source display remains supported.
- The resolver loads all retained selected-archive rows and batch-loads referenced events/calendars. Current publication windows, calendar rights and destination page/article rights are evaluated with real Core services. Public display does not use backend preview exceptions. Only a usable, visible target supplies target data/URL. Otherwise output contains only source data. UID winners are selected before the inclusive period filter; sorting uses effective dates. No recurrence expansion or result cache is introduced.

## Core findings that affected implementation

Inspected both installed calendar DCAs/models, core DC voters, member-group voter, PageModel/PageAccessListener/PublishedFilter, CalendarEventsResolver, ContentUrlGenerator and the actual event reader/templates.

1. Model save does not invoke DCA `adjustTime()`. Full date/time fields must be assigned explicitly. October's repeated local clock time can represent different instants and must not be reparsed from clock strings during creation.
2. The reader renders content children; teaser fallback is not evidence of working details. Final tests edit a child with a unique marker and require that marker in the reader output.
3. Core 5.7 stores encoded text titles; Core 6 stores plaintext and its Twig reader escapes it. Version-aware copying and decoding avoid both raw HTML and double escaping. Core 5 title probes preserve literal HTML-like text, Markdown and insert-tag syntax. Core 6 titles remain plaintext but Create rejects any `{{` with exact translated feedback, without modifying the source or creating event/content rows. Descriptions preserve literal insert tags on both versions.
4. The native editor requires an author. Creation supplies the acting backend user. A real core form POST publishes a copied event; subsequent reader output verifies its usability.
5. The reconstructed temporary projects lacked `system/tmp`, so Core skipped initializeSystem hooks and initially did not register its text content controller. The matrix factory and isolated harness now create this standard directory. Earlier teaser-only output was not accepted as final proof. Final runs require a registered Core text element and real content output.

## Environments and final results

All PHP/Composer/database/HTTP validation ran through **DDEV jzm** from `/home/dev/Kunden/privat/jzm-contao`, using the existing isolated container projects and databases. No dependency updates or reinstall were needed.

| Target / isolated project | Database | Symfony FrameworkBundle | PHPUnit | Storage | Sync | Real sync commit | Backend + FE HTTP | Event transaction/concurrency |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Core/calendar 5.7.13, `/tmp/churchtools-matrix-57` | `churchtools_step3_57` | 7.4.18 | 94 tests / 221 assertions | 83 checks | 65 checks | 45 checks | 420 checks | 26 checks |
| Core/calendar 6.0.0, `/tmp/churchtools-matrix-60` | `churchtools_step3_60` | 8.1.7 | 94 tests / 221 assertions | 83 checks | 65 checks | 45 checks | 424 checks | 26 checks |

Both use PHP **8.4.24**, DBAL **4.4.4**, MariaDB **10.3.39**, PHPUnit **12.5.35**. The original 84 tests/203 assertions and 78 backend checks remain in the suites. Storage's operation-list assertion now expects the dedicated `link` operation plus `show`; all widget/read-only/relationship checks remain intact.

Production cache clear, container lint, both Twig templates, Composer platform checks and package PHP syntax checks pass. `composer validate --strict --no-check-lock` passes with the existing ignored development-lock warning (stale manifest and missing calendar-bundle in that unused lock); no lockfile/dependency update was performed. `git diff --check` passes. No commit, push or Git initialization was performed.

### HTTP evidence

The existing ephemeral loopback server runs the real kernel, firewall, login, CSRF, routes, DCA and model/database saves. The original backend scenarios are preserved and extended, not replaced with mocked authorization.

- Module reader/editor/denied users cannot use local link actions. Calendar/editor rights without the local field permission remain insufficient. The actual group editor exposes that independent permission.
- An authorized restricted editor sees only allowed targets/calendars and can create in its calendar. Forged target/calendar IDs are denied. Forbidden linked targets disclose no title and cannot be opened. Removing event-update or calendar-module rights denies opening. Missing references survive reads and repeated creates. Invalid CSRF fails; GET action parameters do not write.
- Explicit link/unlink/open, repeated link/create and refusal to replace a different reference are exercised through HTTP. Created events are unpublished; core event/content records remain independent of unlink and source editing.
- Stored core date values and actual reader output are verified for single/multiple-day all-day cases, March/October DST (23/25-hour days), timed single/multiple-day cases, offset transitions including repeated October clock time, local midnight and zero fractions. Equal timed endpoints and nonzero microseconds return 422 without partial writes. Unit tests additionally cover Core's timestamp range. Source fractions and point semantics survive resolver output.
- Reader tests verify edited `tl_content` details rather than teaser fallback, safe titles/descriptions, actual date/clock display, native numeric-ID URLs and a successful real editor publication POST.
- Actual frontend logins cover anonymous visitors and members with/without calendar and page rights. Tests cover inherited page protection, page/root publication, publication start/stop (including literal stop `0`), backend-login-without-preview, missing/delete/restore transitions, moved dates in both directions, recurrence without expansion, effective sorting and distinct series UIDs.
- Deduplication covers visible higher-archive targets, all-source fallback to the lowest archive, two visible targets choosing the lowest archive, and filtering only after winner selection without fallback to another copy.
- Actual Core URL resolution covers default readers, internal pages, external URLs (never fetched), articles and page forwards. Protected/unpublished destination pages/articles cannot supply target information.

### Transaction evidence

`event-test.php` uses no enclosing test transaction and observes durable state from a separate DB connection. Two separate PHP workers overlap during creation: one creates, the other receives the shared-lock conflict, and retry retains exactly one event and one detail element. Holding the exact sync lock independently prevents action writes.

Injected failures in pre-commit cache dispatch and the final link write roll back event/content/link changes; source rows stay identical. Post-commit invalidation failure leaves a durable link, and retry does not duplicate it. Lock release is checked. A real controlled synchronization follows the link, retains it on update, then removes the source against a nonempty snapshot while preserving the core target byte-for-byte. Existing 45-commit/65-sync checks retain the series/internal/cleanup coverage.

### Isolated schema and cleanup

`backend-schema.php` was extended only with allowlisted missing Core tables, using Doctrine-generated CREATE previews before each apply. Added in both isolated databases:

- `tl_content`, `tl_article`, `tl_member`, `tl_member_group` for reader details, destinations and real FE users.
- `tl_image_size`, `tl_form`, `tl_theme` for the real core group-permission form.
- `tl_layout` for the core event editor's reader-page/SERP widget.
- `tl_search`, `tl_search_term` for Core's alias-save search invalidation callback during real publication.

No existing table was altered and no host migration/schema/user change was performed. There is no third extension table. The empty Core test tables and standard `system/tmp` directories are retained for reproduction.

The harness disables only automatic web cron, Messenger web-worker draining and search-index HTTP listeners in its temporary environment. This avoids unrelated background execution/queue setup. The actual core alias-save callback, permissions, CSRF, content rendering, login and publication logic remain active. ChurchTools HTTP is replaced only in the temporary test environment by the existing calendar-only fixture transport; no live request, setup-token operation or production test-controller change occurred.

Owned backend/frontend accounts, groups, archives, events, details, pages, articles, versions, logs, triggers, sessions and temporary config/routes are removed. During development, the missing text registration produced identifiable reader diagnostics and core opening created versions with userid 0. These verified owned remnants were removed, and cleanup now also removes versions by owned event ID. **Final `event-audit.php` reports zero rows in every fixture table, including versions/logs, zero triggers, and exactly `tl_church_tools_archive` / `tl_church_tools_entry` as extension tables on both targets.** No unrelated data was deleted.

## Reproduction

Run from `/home/dev/Kunden/privat/jzm-contao`. Substitute `60` for `57`; do not overlap fixture suites within one database. The root/root credentials here are only the standard isolated DDEV test DB connection.

```sh
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/backend-schema.php /tmp/churchtools-matrix-57
# Review any missing-table CREATE plan before --apply:
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/backend-schema.php /tmp/churchtools-matrix-57 --apply
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/backend-test.php /tmp/churchtools-matrix-57
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/event-test.php /tmp/churchtools-matrix-57
ddev exec php /tmp/churchtools-matrix-57/vendor/bin/phpunit --bootstrap /tmp/churchtools-matrix-57/vendor/autoload.php --configuration /home/dev/Kunden/github/contao-churchtools/phpunit.xml.dist
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/storage-test.php /tmp/churchtools-matrix-57 --apply
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/sync-test.php /tmp/churchtools-matrix-57
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/event-audit.php /tmp/churchtools-matrix-57

ddev exec -d /tmp/churchtools-matrix-57 env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 APP_ENV=prod APP_DEBUG=0 php vendor/bin/contao-console cache:clear
ddev exec -d /tmp/churchtools-matrix-57 env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 APP_ENV=prod APP_DEBUG=0 php vendor/bin/contao-console lint:container
ddev exec -d /tmp/churchtools-matrix-57 env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 APP_ENV=prod APP_DEBUG=0 php vendor/bin/contao-console lint:twig /home/dev/Kunden/github/contao-churchtools/contao/templates
ddev exec -d /tmp/churchtools-matrix-57 composer check-platform-reqs
```

CI now invokes the additional transaction suite; its hosted execution has not been observed.

## Limits

- No live ChurchTools request. Minimum remote permissions, hidden caps, the explicitly skipped live permission-loss test and other step-1 API limits remain unchanged. Empty snapshots remain conservative failures; series splits still require manual reassignment.
- No installed project-specific recurrence plugin was tested. The resolver never invokes Core recurrence expansion or its extension hooks; it maps one retained source UID to at most one flat result.
- Default core reader/module output and generated URLs were exercised in an isolated FE route. This is not visual browser acceptance, a deployed reader-page/layout test or acceptance of arbitrary project templates.
- Date copying preserves representable instants on creation. Subsequent manual core edits follow Core's own `adjustTime()` and local-clock semantics, including its ambiguity at repeated DST clock times; this bundle does not replace that editor lifecycle.
- Core rich-text teaser data is identified as HTML by the resolver; a future frontend consumer must apply appropriate output handling. Grouped rendering, shared-cache output/timing and event-edit cache behavior belong to step 7. Database/cache delivery is not a distributed transaction; deferred delivery failures remain outside the completed DB transaction.
- No host/production validation, hosted CI, PHP 8.5+, interactive screenshots or responsive testing is claimed. `.env.local` was not inspected/displayed; credentials/session data were not dumped. Independent parent re-review passed; no findings from that review remain open.

## Pre-correction implementation acceptance

The action controller now uses Core `AbstractBackendController` and the managed `be_main` Twig layout, retaining native navigation and form styles. After this change, both targets passed all 372 HTTP checks, 94 PHPUnit tests / 221 assertions, 26 event transaction checks, 83 storage checks, 65 synchronization checks and 45 real-commit checks. The final audit again found zero fixture rows and triggers on both targets. `git diff --check` passed. Visual browser inspection remains unperformed.

## Parent-review corrections (2026-09-23)

- `DisplayResolver` catches the specific `NoRootPageFoundException` thrown by `PageModel::loadDetails()` in the real FE scope. Reader `pid=4294967294` and `pid=0` both fall back to source-only output, preserve the stored target ID, and reactivate after restoring the root relationship. No broad Throwable catch was added.
- Core 6 `event_list.html.twig:9` applies `title|insert_tag` in the link-title attribute. A representation-neutral escape covering all reader/list outputs was not established. Create therefore rejects every `{{` (including incomplete syntax) on Core 6 with specific English/German feedback. Source/link/event/content state remains untouched. Core 5 encoded copying is retained; no silent title changes or blanket Core 6 entity encoding were added.
- The existing nine date fixtures use literal HTML/Markdown titles on Core 6 and retain title insert-tag probes on Core 5. Descriptions still contain `{{env::host}}`. Separate title-boundary POST cases check rejection, exact translated feedback and absence of partial writes on Core 6, and encoded acceptance on Core 5. Stored titles are checked exactly.
- Real `ModuleEventlist` with the installed `event_list` template renders all nine date fixtures. Tests inspect link text and the DOM link-title attribute separately. Core 6 itself entity-encodes special characters again in that attribute; this native tooltip limitation is explicitly recorded and asserted, not compensated by changing stored titles. Reader and link text preserve literal HTML/Markdown without double encoding. Description insert tags remain literal in the list as well.
- The harness cleans only the exact orphan-page diagnostic messages for its owned page IDs (Core 5 encoded quotes/Core 6 plain quotes). No general log deletion was introduced.

Final correction acceptance: **PASS** on both existing isolated DDEV matrices. HTTP: **420 checks on 5.7 (+48)** and **424 on 6.0 (+52)**, preserving the previous 372 checks. Each target also passed **94 PHPUnit tests / 221 assertions**, **83 storage**, **65 sync**, **45 real-commit**, and **26 event transaction** checks. Final audits: zero fixture rows (including logs/versions), zero triggers, exactly two extension tables. Production cache clear, container/Twig lint, PHP syntax, platform requirements and `git diff --check` pass. Composer validation retains only the documented development-lock warnings.

Independent parent review: **passed after corrections**. A separate read-only reviewer examined the implementation and actual Core paths, identified the title/insert-tag and orphan-page issues above, and confirmed their fixes without open findings. The parent then independently reran the complete suites through DDEV on both targets: 420/424 authenticated HTTP checks, 94 PHPUnit tests / 221 assertions each, 83 storage, 65 synchronization, 45 real-commit and 26 event-transaction checks each. Both final audits again reported zero fixture rows and triggers and exactly two extension tables. No visual browser, host deployment or hosted CI acceptance is implied.
