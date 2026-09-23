# Implementation steps

Step 1 retains residual checks. Step 2 is implemented and locally validated; hosted CI remains unverified. Step 3 has passed local storage acceptance on both targets; step 4 has passed local synchronization acceptance; step 5 has passed local authenticated backend acceptance; steps 6–8 are pending. This document tracks implementation evidence, not only code completion.

| Step | Deliverable | Status |
| --- | --- | --- |
| 1 | Verified API contract | Core contract consolidated; residual checks open |
| 2 | Installable bundle skeleton and compatibility matrix | Local acceptance passed; hosted CI unverified |
| 3 | Archives and storage | Local acceptance passed on 5.7/6; hosted CI unverified |
| 4 | Synchronization and cleanup | Local acceptance passed on 5.7/6; API/cache limits documented |
| 5 | Backend module | Local authenticated HTTP acceptance passed on 5.7/6; visual browser/hosted CI unverified |
| 6 | Contao event linking and creation | Pending |
| 7 | Content elements | Pending |
| 8 | Compatibility validation and documentation | Pending |

## Step 1 — Verify API access and occurrence semantics

- [x] Document authentication and verify the authorized runtime credentials `CT_USER` / `CT_PASSWORD` without inspecting `.env.local`; token retrieval is optional.
- [x] Read the instance API specification and verify calendar discovery and appointment retrieval. Consult `5pm-hdh/churchtools-api` for research only if useful; do not adopt it as a runtime dependency.
- [ ] Map calendar metadata, appointment tags/categories and optional source links.
- [ ] Verify pagination, date windows, all-day/multi-day values and timezone handling.
- [ ] Finish remaining cancellation cases; controlled series moves/splits and occurrence deletion are verified. Live permission-loss testing is skipped at the user’s request, not passed.
- [x] Compare controlled recurrence snapshots: adopt `(pid, calculated.iCalUid)` after the base-ID hypothesis failed; preserve the test fixtures.
- [x] Verify nonempty tags, timed/all-day multi-day examples, window overlap and chunk deduplication.
- [ ] Finish residual category/visibility, optional deep-link, explicit DST and separate cancellation/whole-series checks listed in `api-contract.md`.
- [x] Record investigation findings and remaining gaps in [api-contract.md](api-contract.md); this does not complete live contract verification.

Acceptance: supported payloads and deletion/identity semantics have concrete evidence. Do not finalize synchronization keys or destructive reconciliation on guesses. If credentials or representative cases are unavailable, record the precise gap; mark this step incomplete.

## Step 2 — Create the package skeleton and compatibility matrix

Depends on step 1's connection findings; API-independent scaffolding can proceed earlier.

- [x] Create `/home/dev/Kunden/github/contao-churchtools` with Composer name `koertho/contao-churchtools`.
- [x] Define PHP 8.4+ and Contao 5.7/6 dependency constraints compatible with the actual supported dependency matrix.
- [x] Add bundle/manager integration, service discovery and validated global configuration.
- [x] Add bundle-owned connection/token/setup-credential configuration and an explicit optional token-provisioning command. Verify the retrieved token without the login session and save only to an explicitly selected secret destination; never print it.
- [x] Add a small Symfony HttpClient-based client and secret-safe error handling. Leave the existing `ChurchToolsTestController.php` untouched.
- [x] Integrate the local package through a Composer path repository.
- [x] Add package README and test configuration.
- [x] Establish a reproducible CI matrix for Contao 5.7 and 6 on PHP 8.4+, covering dependency resolution, package installation, container boot and available automated tests from the start.
- [x] Keep DDEV `jzm` as the host integration environment; isolate additional compatibility environments and record their resolved PHP/Contao/dependency versions.
- [x] Run local PHP and Composer development commands through DDEV rather than relying on the host CLI version. Retain PHP 8.4+ and both Contao targets as requirements.

Acceptance: Composer validation, package discovery and container boot succeed in DDEV `jzm`; the initial CI matrix exercises both Contao targets and remains active throughout subsequent steps. Record exact resolved versions and unavailable checks explicitly. Do not claim Contao 6 runtime compatibility from a 5.7 boot.

Validation: [Step 2 record, 2026-09-17](step-2-validation.md). Both Contao targets pass local managed-install/container/tests on PHP 8.4.24. Hosted CI is not yet verified; step 2 is not unconditionally marked complete.

## Step 3 — Implement archives and storage

Depends on steps 1–2.

- [x] Finalize exactly two extension tables (archive and entry), a parent `pid` relationship, archive-scoped occurrence uniqueness and indexed date/calendar lookups.
- [x] Store calendar IDs as a blob, last successful sync/latest error and the last successful sync’s linked-entry removal count on the archive. Store source calendar ID/name, required metadata and tags/category blob on entries. Add no calendar, assignment, taxonomy or synchronization-state tables.
- [x] Use Doctrine DCA schema definitions and the repository's translation conventions.
- [x] Store a nullable Contao event ID directly on each appointment, separate from source fields. Do not add independent mapping storage or tombstones.
- [x] Verify one occurrence per archive: overlapping archives have separate entries and independent Contao links. Repeated sync must not duplicate entries within the same archive.

Acceptance: schema installation/update and repository round trips succeed; archive-scoped uniqueness, parent/child navigation and link preservation are covered. Record the final schema in `architecture.md`.

Validation: [Step 3 record](step-3-validation.md). Schema stability, real database/model roundtrips, DCA parent/child configuration, identity and link preservation passed on both local targets. The complete backend module/browser navigation is intentionally deferred to step 5. Hosted CI remains unverified.

## Step 4 — Implement synchronization

Depends on step 3.

- [x] Add the shared sync service, CLI command and attribute-registered cronjob.
- [x] Apply configurable six-month future and one-month past defaults.
- [x] Fetch complete calendar/window snapshots before reconciling absence.
- [x] Test missing discovery entries, 401/403, failed/partial responses and ambiguous access using simulated responses: skip absence cleanup for the affected calendar and report the condition. Live permission-loss testing is user-skipped and remains an explicit coverage limit.
- [x] Handle updates, metadata blobs, cancellation, deletion, moved occurrences and retention per archive; persist sync status on that archive.
- [x] Test internal-appointment exclusion and public-to-internal removal: preserve the core event, invalidate caches, exclude the removal from cancellation counts, and avoid reclassifying filtered internal UIDs as absent.
- [x] Enforce the persisted-field allowlist with tests that remote personal metadata and full API objects cannot enter the entry blob.
- [x] Add locks, transactional writes, diagnostics and cache invalidation.
- [x] Count cancellation/source-deletion removals with a Contao event ID on the archive; exclude retention, configuration/window cleanup and internal-visibility removals. Test zero counts on clean successful runs and preservation of the last successful count on failure.
- [x] Define behaviour for calendar deselection and changed synchronization windows.
- [x] Test the accepted series-split behaviour against the saved fixtures: recreate changed-UID entries without transferring Contao links, preserve core events and include removed linked entries in the archive notice without claiming a cancellation.
- [x] Ensure no sync/cleanup path modifies or deletes core Contao events. Test that appointment deletion removes its association and re-fetching it does not restore the link.

Acceptance: tests cover idempotency, recurrence exceptions, preserved links, window edges and failed/partial responses without data loss. Run a real read-only remote fetch and local synchronization in DDEV; record counts and remaining coverage gaps.

Validation: [Step 4 record](step-4-validation.md). Both DDEV matrix targets pass automated acceptance and real read-only remote synchronization with aggregate output. Empty/ambiguous calendars fail closed; skipped live permission-loss and undetectable API partiality remain explicit limits. Follow-up regression proves atomic source/status rollback for synchronous cache dispatch and final status-write failures on both targets, including 45 checks without an outer test transaction. No step 5+ work or host schema changes.

## Step 5 — Add backend management

Depends on steps 3–4.

- [x] Register the ChurchTools menu category and Events backend module.
- [x] Keep the Archives name and implement parent/child navigation: archives → appointments, within the single Events module. Provide archive editing with calendar-ID selection.
- [x] Preserve and display stored calendar IDs when API options are missing/unavailable, using ID-based labels if needed. Verify saving during an API failure leaves the selection intact.
- [x] Show read-only source events and filter by source calendar.
- [x] Show source metadata from the appointment blob, linked-state information, last successful sync and latest error. Show the linked-entry removal notice when the stored count is nonzero. No frontend sync notices or configurable stale-data warnings.
- [x] Apply backend permissions and translated labels.

Acceptance: verify archive configuration, event navigation and calendar filtering in the authenticated DDEV backend, including a restricted backend user where applicable.

Validation: [Step 5 record](step-5-validation.md). Both targets pass real login, archive creation/editing, calendar filtering, outage/deselection and valid-CSRF permission tests using temporary users in isolated databases. Source/link/status write protection and escaped metadata are verified. No step-6 actions or host schema changes.

## Step 6 — Add Contao event actions and display resolution

Depends on step 5.

- [ ] Add link, unlink and open-target actions for a single occurrence.
- [ ] Create an unpublished Contao event in an authorized selected calendar.
- [ ] Map date fields and full time timestamps explicitly; do not rely on DCA `adjustTime()` running on model save. Test inclusive all-day ends, single/multi-day timed cases and DST without adding an extra day.
- [ ] Escape source plaintext and safely convert line breaks when copying to rich text; test literal HTML/Markdown input and keep the separate link field independent.
- [ ] Prevent duplicate creation from repeated submissions.
- [ ] Resolve effective title/date/visibility/link information in PHP before date filtering, sorting and grouping, using batch-loaded Contao targets.
- [ ] Override source information only with a published, currently visitor-visible target. Otherwise show ChurchTools data without a Contao link; verify creating an unpublished target leaves the appointment visible.
- [ ] Keep missing target IDs unchanged. Verify delete/restore under the same ID, publication/access transitions and explicit unlinking.
- [ ] Load all retained entries in selected archives before period filtering. Test a source date three weeks ahead overridden to tomorrow, and the reverse.
- [ ] Verify that source deletion preserves the target and sync cannot overwrite it.
- [ ] Verify exactly one entry per ChurchTools occurrence: linked core recurrence rules and project repeating-event extensions must not add entries.

Acceptance: authenticated backend actions and tests verify independent editing, effective dates, publication/access rules and cleanup behaviour.

## Step 7 — Build and verify frontend views

Depends on steps 4 and 6.

- [ ] Add exactly two archive-selectable content elements: appointment list and month calendar, with overridable Twig templates. Output only ChurchTools appointments; do not include independent Contao events or additional Contao-calendar selectors.
- [ ] Leave existing core modules, pages and importer content untouched. Images and migration/cutover are outside version one.
- [ ] Implement the configurable list period, proposed default seven days, grouped by day.
- [ ] Implement the month grid and previous/next navigation as the only frontend filter.
- [ ] Render effective times/titles and optional Contao links consistently.
- [ ] Deduplicate by remote occurrence identity: prefer published, visitor-visible Contao targets, then lowest archive ID; without a qualifying target use source data once from the lowest archive ID. Apply date filtering after choosing the winner. Test mixed publication/access states and differing dates/links. Handle all-day/multi-day cases and empty states.
- [ ] Apply Bootstrap 5.3/project styling and verify mobile output.
- [ ] Verify cache updates after sync, linking, Contao event edits/deletion/restoration and publication-window transitions; prevent visitor-specific target visibility from leaking through shared caches.

Acceptance: browser verification against the live list/calendar references, including month transitions, effective-date sorting and restricted/unpublished targets. Record actual desktop/mobile checks; markup inspection alone is not visual acceptance.

## Step 8 — Complete compatibility and operational documentation

Depends on steps 1–7.

- [ ] Run meaningful automated tests and PHP/Twig/container checks.
- [ ] Run the complete extension through the compatibility matrix established in step 2, including package install/schema updates on Contao 5.7 and 6 with PHP 8.4+, and record final results for supported PHP/dependency combinations.
- [ ] Complete runtime validation for both Contao targets, retaining DDEV `jzm` for host integration and isolated environments for additional targets.
- [ ] Document API setup, configuration, archives, cron/CLI, frontend content elements and troubleshooting.
- [ ] Document data ownership, deletion behaviour, token rotation and known limitations.
- [ ] Update every step's status and validation record.

Acceptance: each claimed compatibility target has explicit evidence. Record unavailable environments as unverified, not passed. Release preparation does not include publishing or deployment.

## Validation record template

Append a record when completing or partially validating a step:

```text
Step:
Date:
Status: Pending / In progress / Blocked / Complete
Changes:
Environment and resolved versions:
Commands and runtime/browser checks:
Results:
Unverified cases or blockers:
Decisions affecting requirements/architecture:
Next action:
```

## Step 1 validation record — 2026-09-14

Status: In progress; live verification blocked by DNS resolution and pending token configuration. Official authentication/token retrieval documented. Public instance fetch failed both sandboxed and escalated; web access also returned no usable API response. DDEV status inspection confirmed a paused `jzm` project configured for PHP 8.4, not a tested runtime. No API mutations, package installation or schema changes. Earlier review observations are labelled historical in [api-contract.md](api-contract.md). Next: restore access, locate the dedicated token and verify the current contract with sanitized fixtures.

### Authenticated follow-up

The user supplied runtime variable names `CT_USER` and `CT_PASSWORD` and prohibited inspecting `.env.local`. Process-local environment loading succeeded in DDEV: login, calendar discovery (15 calendars), OpenAPI retrieval and a bounded appointment query (16 records) returned HTTP 200. Credential provisioning and DNS are no longer blockers. Remaining identity, cancellation, completeness and representative-fixture checks keep step 1 incomplete; see `api-contract.md`.

### Window verification follow-up

Calendar 2 returned 101 records over the full test window and 102 across three adjacent chunks. Their unique occurrence-key unions match exactly; one boundary-day occurrence is duplicated, despite the documented exclusive upper bound. Record chunk deduplication and local range filtering as necessary. Recurrence exception/additional shapes were observed, but stable moved-occurrence identity and cancellation semantics remain unverified. See `api-contract.md`.

### Targeted recurrence follow-up

Current specification and existing exceptions were examined read-only. Exception/additional dates were compared to returned calculated dates. Official ChurchTools documentation confirms individual edits detach an occurrence and future edits split a series; API identity continuity is not guaranteed by the inspected schema. No heuristic relinking is justified. See `api-contract.md` for evidence and limitations; step 1 remains in progress.

### Controlled single-occurrence move verified

The user moved the September 24 occurrence to September 25. Base appointment ID changed from 800 to 803, but calculated iCalUid remained identical; all five UIDs survived. Both test snapshots are retained. The original base-ID/original-date hypothesis is unsuitable for this transition; `(pid, calculated iCalUid)` is now the preferred identity candidate. Cancellation/deletion and other outstanding cases remain open; see `api-contract.md`.

### Controlled single-occurrence deletion verified

After the user deleted September 26, the test series query returned four rather than five occurrences. Exactly that UID disappeared; an exception date was added, and all remaining occurrences (including the detached moved appointment) stayed unchanged. Preserve the before/after fixtures. Reconcile the complete UID set, not series exceptions alone. Other outstanding API cases keep step 1 incomplete.

### Tags and multi-day cases verified

User-created fixtures now cover nonempty tags (including nullable description and symbolic color), inclusive all-day API end dates verified against iCal, timed multi-day UTC dates and overlapping-window retrieval. See `test-metadata.json` and `api-contract.md`. Final contract consolidation and remaining validation boundaries are still required before marking step 1 complete.

### Contract consolidation — 2026-09-15

Updated requirements and architecture to use UID identity and the verified field/date rules. The consolidated contract explicitly distinguishes established core behaviour from residual checks. Step 1 is not marked wholly complete; step 2 scaffolding can proceed independently, while production cleanup and unverified feature paths remain subject to their stated gates.

### Pagination and cross-calendar UID check — 2026-09-16

All 16 accessible calendars queried over the same seven-month window: 158 records with `meta.count=158`, unchanged by `limit=5`. All 158 UIDs were nonempty and unique across returned calendars. See `pagination-observation.json`. This is bounded empirical evidence, not proof against hidden server caps or collisions under untested transformations. Permission-loss and series-split checks remain open.

### Series split verified — 2026-09-16

The controlled change regenerated all five UIDs, including the preceding unchanged appointment. The test is complete but reveals a design limitation: UID-only reconciliation loses associations across this operation. The user accepts manual reassignment after this operation. This handling decision is resolved; do not claim universally stable UID identity. Other documented residual checks remain open.

### Token authentication verified — 2026-09-16

User-authorized retrieval succeeded; a cookie-free token request verified the same account. `CT_TOKEN` was appended to the ignored local environment file without displaying its contents. Optional credential-based provisioning is now in step 2; regular sync uses token authentication.

### Description sample inspected — 2026-09-16

The supplied test description contains literal Markdown markers, newlines and HTML-like input. No API rendering occurs in the sampled field. Link and blank-line paragraph examples are still absent; do not claim verified Markdown/HTML semantics. Preserve the test fixture and escape by default; see `api-contract.md`.

### Plaintext description confirmed

The user clarified that ChurchTools descriptions have no formatting editor and links use a separate field. The manually entered HTML/Markdown fixture must remain literal text. No further formatting example is needed. Requirements/architecture now define plaintext handling and step 6 includes safe rich-text conversion and explicit Contao date normalization.
