# API contract investigation

## Consolidated implementation contract — 2026-09-16

**Identity limitation confirmed:** the controlled series split regenerated all five UIDs, including the unchanged preceding occurrence. UID matching preserves links only while the UID survives. Automatic association continuity across series splitting is not established; the user has explicitly accepted link loss and manual reassignment for this operation. See the series-split result below.

This section is the current decision summary; dated investigation entries below preserve historical evidence and superseded hypotheses. Core appointment behaviour is verified. Step 1 remains partially complete because the explicitly listed residual checks have not been exercised.

| Concern | Implementation rule | Evidence/limit |
| --- | --- | --- |
| Authentication | Regular access via configured login token; optional explicit username/password setup | Token retrieved and verified without login cookies on 2026-09-16; never inspect `.env.local` |
| Discovery | GET `/api/calendars`; archive stores explicit selected IDs | Access is not publication intent; live permission-loss test skipped by user, behaviour unverified |
| Retrieval | GET `/api/calendars/appointments` with `calendar_ids[]`, date bounds and `include[]=tags` | Chunk union matched full window; no pagination parameter documented |
| Local identity | Unique `(pid, calculated.iCalUid)` | UID survived controlled detachment/move while base ID changed |
| Source metadata | Store base appointment ID and source calendar ID separately and update them on matching UID | Do not include mutable IDs/dates in the uniqueness key |
| Cross-archive identity | Calculated UID within the configured instance | Apply already agreed visible-Contao-target precedence |
| Date source | `appointment.calculated.startDate/endDate` | Base dates can describe the entire series |
| Timed values | Parse timezone-bearing timestamps as instants | Tested UTC values; explicit DST fixture still outstanding |
| All-day values | Preserve date-only values; API end is inclusive, normalize with one calendar-day increment if using exclusive internal bounds | Controlled fixture confirmed against iCal |
| Chunk boundaries | Deduplicate returned UIDs and apply local overlap/range rules | API returned upper-bound-day values despite documented exclusivity |
| Tags | Entry blob containing ID, name, nullable description and color | Color can be symbolic (`basic`); no taxonomy table or image handling |
| Removal | Compare complete successful UID sets; a series exception alone never triggers deletion | Controlled deletion removes UID; a moved occurrence keeps it |
| Contao links | Retain event ID during matching-UID updates; entry deletion loses association but never changes core event | Two-table design preserved |

Within a response, identical repeated UIDs at chunk boundaries are deduplicated. Missing/empty UIDs or conflicting records sharing a UID must fail reconciliation safely rather than invoke title/date matching. The tested series split is known to change UIDs; stability under cross-calendar moves remains unverified.

### Residual checks and implementation gates

- Live permission-loss testing is explicitly skipped at the user’s request. Do not request permission changes again as a prerequisite. Implement and test conservative failure/partial-response handling with simulated responses: missing calendars in discovery, 401/403, failed or incomplete responses must never trigger absence-based deletion. If access/completeness is ambiguous, skip absence cleanup for that calendar and report the condition. These tests do not prove the live API distinguishes permission loss from an empty response.
- A separate cancellation status and whole-series deletion have not been demonstrated. The verified removal case is deletion of one occurrence, represented by exclusion plus UID absence.
- Before shipping date handling: add explicit DST and single-day all-day tests; multi-day end semantics are verified.
- Before generating an optional ChurchTools deep link: verify its occurrence-specific format. The base `link` field is not proven to be that deep link.
- Confirm category metadata mapping and handling of `isInternal`/calendar visibility before exposing arbitrary selected calendars.

These are explicit remaining parts of step 1, not passing results. API-independent package scaffolding can proceed in step 2; unverified behaviour must not be silently assumed during later implementation.


Date: 2026-09-14. Status: **in progress; authenticated access and initial response structure verified**.

This is the step 1 evidence record, not an implementation specification. The agreed two-table model and presentation rules remain unchanged.

## Authentication: official documentation verified

ChurchTools documents login-token authentication using the HTTP header:

```http
Authorization: Login <token>
```

The token belongs to a user and can be obtained through that user's profile. The documented UI path is Persons & Groups → Persons → API account → Permissions → Login token. An authenticated request to `GET /api/persons/{personId}/logintoken` is another documented retrieval method; it is not an anonymous token endpoint.

For this project, retrieve the dedicated account's token and keep it outside version control. Proposed local variable: `CHURCH_TOOLS_API_TOKEN` in an ignored local environment file, after verifying that the file is actually ignored. Do not put tokens in URLs, command-line arguments, fixtures or diagnostics. No token was retrieved, stored or used during this investigation.

User clarification: credentials are already configured as `CT_USER` and `CT_PASSWORD` in `.env.local`. Do not inspect or display that file. The user authorizes process-local use of these variables. Use the normal Symfony environment loader and `POST /api/login` with JSON keys `username` and `password`; keep the returned session cookies private. Token provisioning is not a prerequisite for this investigation.

The documentation describes reusing session cookies to avoid repeatedly calculating permissions. Exact session behaviour on this instance remains untested; any cookies must be handled as secrets and confined to this instance.

Sources checked on 2026-09-14:

- [API authentication](https://churchtools.academy/en/help/system-settings/api-en/api-authentication/)
- [Finding a sync user's token](https://churchtools.academy/en/help/churchtools-modules/configure-connections/how-to-find-the-sync-users-login-token/)

The second article covers person synchronization. Its broad write permissions are **not** requirements for this read-only appointment extension. Minimum calendar/appointment permissions must still be tested with the dedicated account.

## Historical access results — superseded by authenticated follow-up

| Check | Result |
| --- | --- |
| Instance documentation via web tool | No usable response |
| Public OpenAPI URL via curl, sandboxed | DNS resolution failed |
| Same URL outside sandbox | DNS resolution failed again (`curl: (6) Could not resolve host`) |
| OpenAPI and calendar endpoint via web tool | Tool refused to open URLs; no API response obtained |
| DDEV `jzm` status | Project paused, configured PHP 8.4; no runtime/API test executed |
| Research SDK at `vendor/5pm-hdh` | Directory absent in the current checkout; not installed or changed by this step |

These failures do not establish an outage at ChurchTools, invalid credentials, missing permissions or empty calendars. No source data was changed, and no synchronization or deletion was executed.

## Earlier observations: leads only

A previous review on 2026-09-12 reported successful anonymous reads of this instance. These observations were recovered from its review notes and **were not reproduced today**; they may be outdated:

- OpenAPI location: `/system/runtime/swagger/openapi.json`.
- Candidate read endpoints: `/api/calendars`, `/api/calendars/appointments`, `/api/calendars/appointments/{appointmentId}/{startDate}` and `/api/calendars/{calendarId}/appointments/{appointmentId}/{startDate}`.
- One anonymously visible calendar with ID 2 was reported. This is not the configured calendar selection for the extension.
- A bounded query reportedly returned 100 occurrences from 17 base appointment IDs, including recurring occurrences and distinct calculated iCalendar UIDs. Two smaller windows reportedly produced the same union.
- The reviewed appointment-list operation reportedly had date bounds but no pagination parameter.

No raw response fixture or current OpenAPI snapshot is available in this investigation. Do not derive finalized DTOs, unique keys or deletion logic from these notes. In particular, neither appointment ID alone nor a calculated start date/UID has been proved stable across rescheduling.

## Historical investigation checklist — superseded by the consolidated contract

| Topic | Required evidence |
| --- | --- |
| Calendar discovery | Dedicated account's response shape, IDs, names, metadata and access/visibility behaviour |
| Appointments | Real fields for title, description/location, calendar, base dates and calculated occurrence dates |
| Identity | Test `(calendarId, appointmentId, originalStartDate)` and any returned occurrence UID across ordinary recurrence and moved exceptions |
| Window semantics | Inclusivity, overlapping multi-day appointments, all-day end dates, timezone and DST cases |
| Completeness | Current specification plus bounded/split-window comparison; test pagination only if actually supported |
| Cancellation/deletion | Explicit cancellation representation and complete-snapshot absence; distinguish permission loss and movement outside the requested window |
| Metadata blob | Actual tag/category shape, missing/null values and safe subset to retain on entries |
| Visibility | Calendar and appointment visibility/internal flags; ensure account access is not mistaken for intended website publication |
| Source link | Documented URL or source identifiers sufficient for an occurrence-specific link |
| Errors | 401/403, rate limits, malformed response, timeout and server failure handling without deletion |

Use existing examples or an explicitly authorized test calendar for mutation-dependent checks. Do not reschedule or cancel real appointments merely to obtain evidence. Retain only sanitized fixtures with stable synthetic identities and no credentials or unnecessary personal data. Do not fabricate fixture responses and label them as live observations.

## Historical next actions — superseded

1. Obtain working DNS/network access to the instance and fetch its current OpenAPI document.
2. Use the authorized `CT_USER` / `CT_PASSWORD` variables through the runtime environment, without inspecting `.env.local`.
3. Resume DDEV `jzm` when needed for PHP-based checks; perform read-only authenticated discovery and bounded appointment queries.
4. Record response schemas, sanitized fixtures and occurrence tests here. Leave the occurrence key undecided until moved-exception evidence supports it.

Step 1 remains incomplete. Steps 2–8 have not been started by this investigation.

## Authenticated follow-up — 2026-09-14

The user authorized runtime use of `CT_USER` and `CT_PASSWORD`. Symfony's environment loader supplied these variables internally; the environment file and credential values were not displayed or inspected. A temporary PHP probe ran in DDEV, kept its session cookie in a private temporary file and removed it afterward. No token was requested.

- DDEV started successfully; the earlier network failure did not recur inside DDEV.
- `POST /api/login` with `username` and `password`: HTTP 200.
- `GET /api/calendars`: HTTP 200, 15 calendars visible to the dedicated account. This is discovery, not a publication or archive selection.
- Current OpenAPI: HTTP 200 at `/system/runtime/swagger/openapi.json`.
- List parameters include `calendar_ids[]`, `include[]`, `from`, `to`, `query`. `include[]` supports `tags`. The specification describes `to` as exclusive and lists no pagination parameter for this operation.
- Bounded probe of calendar 2 for `[2026-09-14, 2026-10-14)` with tags: HTTP 200, 16 records. The ID was selected only for the probe; no archive configuration was created.
- Response envelope: `data`, `meta`. Sample records contain `appointment.base`, `appointment.calculated`, and `tags`, alongside deprecated top-level `base`/`calculated` copies. Prefer the nested appointment representation.
- Base fields observed: `id`, `title`, `subtitle`, `address`, `version`, `calendar`, `description`, `image`, `link`, `isInternal`, `startDate`, `endDate`, `allDay`, recurrence fields, `additionals`, `exceptions`, `signup`, `onBehalfOfPid`, `meta`. These are field observations, not an instruction to store every field; images remain excluded.
- Calculated fields observed: `startDate`, `endDate`, `iCalUid`. Occurrence rendering must distinguish calculated dates from base-series dates.
- Both sampled tag collections were empty. Non-empty tag structure, moved-exception identity, completeness, cancellation semantics and timezone boundaries remain unverified. A successful response alone does not complete step 1.

The earlier DNS and missing-token next-action items are superseded by this successful authenticated follow-up. Next work is contract/fixture verification, not credential provisioning. Only field names, counts and HTTP status were emitted; no appointment descriptions or personal details were saved as fixtures.

## Window and recurrence checks — 2026-09-14

Authenticated read-only calendar-2 query with `include[]=tags`:

| Query window (`from`, `to`) | Records |
| --- | --- |
| 2026-08-14 to 2027-03-14 | 101 |
| 2026-08-14 to 2026-10-14 | 37 |
| 2026-10-14 to 2026-12-14 | 34 |
| 2026-12-14 to 2027-03-14 | 31 |

The split result contains 102 rows but its unique union exactly matches the full query's 101 keys, using base appointment ID plus calculated iCalendar UID for this comparison only. The duplicated occurrence starts on 2026-10-14 at 06:30 UTC and ends at 07:30 UTC. Thus live date-boundary behaviour does not support blindly trusting the documented exclusive `to` date. Merge chunk results by occurrence identity and apply the intended date boundaries locally. This one sample shows no truncation; it does not prove universal completeness or UID stability after changes.

Observed across the 101 records:

- 88 calculated start timestamps differ from their base start timestamp.
- 19 records have nonempty base exceptions; 4 have nonempty additional dates. Counts are occurrence records, not distinct series.
- Exception item keys: `id`, `date`, `meta`.
- Additional-date item keys: `id`, `date`, `isRepeated`, `meta`.
- One record is all-day. No nonempty tags or internal appointments occurred in this sample.
- All 101 comparison keys are unique in the full response.

Exception/additional arrays alone do not prove which pair represents a moved occurrence. The proposed original-start key remains unverified. No source appointments were edited or deleted. No raw appointment payloads were persisted.

Remaining evidence needed: known before/after moved occurrence (or documented durable identity guarantee), cancellation/deletion representation, nonempty tags, all-day end semantics and DST/overlap cases. These must not be marked complete from the statistics above.

## Targeted identity investigation — 2026-09-14

After an initial connection failure (no HTTP response), a repeat authenticated read succeeded. No source mutations were performed.

### Current specification

The inspected appointment schemas expose `exceptions`, `additionals` and calculated `iCalUid`, but provide no descriptive identity/stability guarantee for these fields. The targeted schema scan found no `originalStartDate`, `recurrenceId`, `cancelled` or `isCancelled` field under those exact names. This is not proof that every cancellation mechanism is absent; it means no such contract was established by this scan.

The documented DELETE operation targets `/calendars/{calendarId}/appointments/{appointmentId}` and is described only as deleting an appointment. It was inspected, never executed. The GET occurrence endpoint takes `appointmentId` and `startDate`, but its presence does not prove that the date remains stable after a move.

### Existing exception samples

Grouped by base appointment ID, the bounded calendar-2 response contained:

- One series with 17 returned occurrences and 7 exception dates; none of those dates equals a returned calculated UTC date.
- Another series with 2 returned occurrences and 5 exception dates; likewise no matching calculated UTC dates.
- Two series with 2 returned occurrences each and 2 additional dates each; both additional dates match returned calculated UTC dates in each sample.

These observations support treating exclusions and additions separately. Some exception dates may lie outside the queried window, and UTC-date comparison is not a complete timezone-aware exclusion proof. There is no demonstrated mapping from an excluded occurrence to a newly created replacement. Do not infer such a mapping by title or proximity.

### Official behaviour documentation

[ChurchTools: editing a series appointment](https://churchtools.academy/en/help/churchtools-modules/adding-appointments/how-to-edit-a-series-appointment/) was checked during this investigation. It states that editing only one occurrence removes it from its series and treats it as an individual appointment; editing that occurrence and all subsequent ones splits off a new series.

This establishes an important limitation: continuity of a source identity across every edit must not be assumed. The documentation describes object splitting but does not specify the resulting API IDs or a predecessor reference. Therefore `(calendarId, appointmentId, originalStartDate)` remains a hypothesis, not a verified universal durable key. The observed calculated UID is also not documented as durable across these operations.

### Consequences for the existing design

The two-table model remains unchanged. Updates may preserve the Contao ID only when the source occurrence is reliably identified as the same entry. Do not automatically transfer links to a newly identified standalone appointment or series using heuristic matching. The already accepted loss of a link when its entry is removed still applies; no durable mapping system is introduced.

Step 1 is not complete: deletion/cancellation equivalence, moved-occurrence API identity and nonempty tag/date edge fixtures remain open. Read-only snapshots and current schema descriptions cannot prove a before/after identity transition. A controlled test occurrence with explicit authorization would provide that evidence if further existing data offers no attributable transition.

## Controlled user move: single appointment — 2026-09-15

The user created calendar `Test 123` (110) and appointment `Hallo Welt` (797), then moved the appointment manually. Both snapshots were read-only authenticated API observations.

| Property | Before user move | After user move |
| --- | --- | --- |
| Calendar ID | 110 | 110 |
| Appointment ID | 797 | 797 |
| Calculated start (UTC) | 2026-09-20T06:00:00Z | 2026-09-21T08:00:00Z |
| Calculated end (UTC) | 2026-09-20T07:00:00Z | 2026-09-21T09:00:00Z |

After the move, `repeatId` is 0 and base/calculated dates agree. The returned calculated UID is `a2cdb23f5ce5cd5308350a75727bd181@jzm.church.tools`; the previous probe did not record a UID, so UID stability cannot be inferred.

Conclusion: the appointment ID survived this single-appointment move. Including its current start date in the identity would incorrectly treat this update as a new object. This evidence covers a non-recurring appointment only; moving a single occurrence of a series and cancellation/deletion still require separate evidence. No ChurchTools data was modified by the agent.

## Controlled recurrence baseline — 2026-09-15

User-created `Wiederholungstest` in calendar 110 has base appointment ID 800. Five occurrences were observed on September 22, 24, 26, 28 and 30, 2026, each 08:00–09:00 UTC (10:00–11:00 Europe/Berlin). All share the base ID and each has a distinct calculated UID. `repeatId=1`, `repeatFrequency=2`, `repeatUntil=2026-09-30`; exceptions and additionals are empty.

The allowlisted test-only baseline, including each UID, is stored in [repeat-baseline.json](repeat-baseline.json). It contains no credentials or personal metadata. Preserve this file during subsequent comparisons. No occurrence has been changed by the agent. The next check compares a user-moved single occurrence against this baseline.

## Controlled move of one recurring occurrence — 2026-09-15

Compared [repeat-baseline.json](repeat-baseline.json) with [repeat-after-move.json](repeat-after-move.json), using identical query bounds after the user moved only September 24 to September 25.

| Property | Before | After |
| --- | --- | --- |
| Occurrence start | 2026-09-24T08:00:00Z | 2026-09-25T08:00:00Z |
| Base appointment ID | 800 | 803 |
| Repeat ID | 1 | 0 |
| Calculated iCalUid | `6b385ca87e751151f32b02c8afa41772@jzm.church.tools` | unchanged |

The original series (800) now has exception date `2026-09-24` (exception ID 488). The replacement is an independent appointment (803). All five original calculated UIDs remain present exactly once, with no added or removed UID. The four unaffected occurrences retain their base ID and dates.

This is direct evidence that calculated UID survives this single-occurrence detachment/move even though base appointment ID changes. It falsifies any key that requires the base appointment ID to remain unchanged for this operation. The earlier comparison key combining base ID and UID was suitable for unchanged-window comparison, but must not be used as a durable identity for this transition.

Preferred identity candidate for implementation is now `(archive pid, calculated iCalUid)` for local uniqueness and `(instance, calculated iCalUid)` for cross-archive deduplication. Store calendar ID and base appointment ID as mutable source metadata, not components that prevent matching this demonstrated move. On matching UID, update source metadata/dates and retain the local Contao event ID. This requires no extra table or heuristic matching.

This result supersedes the earlier lack of moved-occurrence evidence for the tested operation, not for every possible series edit/calendar move. Fail safely if UIDs are absent or duplicated ambiguously; do not silently fall back to title/date matching. Cancellation/deletion, additional series-edit modes and remaining metadata/date cases are still open. Step 1 is not yet complete.

## Controlled deletion of one recurring occurrence — 2026-09-15

After the user deleted only the September 26 occurrence, an authenticated read with the same bounds was compared with [repeat-after-move.json](repeat-after-move.json). The resulting allowlisted snapshot is [repeat-after-delete.json](repeat-after-delete.json).

- Count decreased from 5 to 4; no new calculated UID appeared.
- Exactly UID `3c953e3343e931e64ea97334f3b863af@jzm.church.tools` (September 26, 08:00–09:00 UTC) disappeared.
- Series 800 now contains an additional exception: ID 491, date `2026-09-26`. The prior September 24 exception remains.
- The four surviving occurrences retain their UIDs, dates and base IDs, including the detached September 25 appointment (803).

This verifies single-occurrence deletion through exclusion from the calculated list plus a series exception. A move also created an exception, so an exception alone cannot distinguish deletion from detachment: compare the complete returned UID set before reconciling absence. Do not delete an entry merely because its old base series lists its date as an exception if the same UID is still returned as a standalone appointment.

The tested deletion supports absence-based removal only after successful complete-window retrieval and calendar-access validation. It does not prove a separate cancellation flag, whole-series deletion, permission-loss behaviour or every window-boundary case. Existing safety rules and the two-table design remain unchanged. No remote data was modified by the agent.

## Controlled tags and multi-day fixtures — 2026-09-15

The user added tags to `Hallo Welt` and two multi-day appointments in calendar 110. Read-only observations are stored in [test-metadata.json](test-metadata.json), with only allowlisted test data.

- Appointment 797: `include[]=tags` returns `Tag1` (32) and `Tag2` (35). Observed tag keys: `id`, `name`, `description`, `color`, `count`. Both descriptions are null and colors are the symbolic string `basic`, not a hex color. Store ID/name/nullable description/color in the entry metadata blob; the global count need not be retained. Do not treat symbolic colors as arbitrary CSS values.
- Appointment 806, all-day: API start `2026-09-23`, end `2026-09-25`. The read-only iCal export has `DTSTART;VALUE=DATE:20260923` and `DTEND;VALUE=DATE:20260926`. This establishes that the API's all-day end is inclusive for this fixture: September 23–25, three days. Normalize to an exclusive September 26 boundary if using half-open intervals internally; retain date-only semantics rather than treating these as UTC instants.
- Appointment 809, timed: API/iCal agree on `2026-09-26T08:00:00Z` through `2026-09-27T09:00:00Z`, corresponding to September 26 at 10:00 through September 27 at 11:00 Europe/Berlin.
- Query September 24–25 returns appointment 806 despite its earlier start. Query September 27–28 returns appointment 809 despite its earlier start. Thus observed retrieval includes overlaps, not only appointments starting inside the window.
- Query September 25–26 returns both 806 and 809, again demonstrating upper-bound-day inclusion. Apply deliberate local interval filtering and UID deduplication when combining chunks.

No iCal body or personal metadata was persisted; only DTSTART/DTEND lines were inspected. These observations complete the requested nonempty tag and all-day/timed multi-day examples, not general DST or every permission/error case.

## Pagination and cross-calendar UID observation — 2026-09-16

User-authorized read-only checks covered all 16 calendars visible to the account, with bounds `2026-08-16` to `2027-03-16` and `include[]=tags`. Only aggregate results were persisted in [pagination-observation.json](pagination-observation.json); credentials and event texts were not emitted.

- Calendar response metadata: `count=16`, matching the returned calendars.
- Normal appointment query: 158 records, metadata `count=158`.
- Same query with `limit=5`: 158 records, metadata `count=158`, exactly the same UID multiset.
- No pagination/total fields appeared in the observed metadata; only `count`.
- 158 unique UIDs, no missing UIDs, no repeated UID groups and no cross-calendar collisions in this window.

Conclusion: `limit=5` does not truncate this operation in the tested instance/query. There is no observed pagination signal here, but the server may simply ignore this parameter; `meta.count` matching the response is not a guaranteed global total. No universal completeness or global UID uniqueness claim follows from this sample. Combined with earlier split-window checks, this supports the bounded-query approach while retaining failure/permission-loss gates before cleanup. Series splitting and UID behaviour on calendar moves/copies remain separate checks.

## Series-split baseline — 2026-09-16

The new user-created `Wiederholungstest 2` in calendar 110 has base ID 812 and five occurrences on September 21, 23, 25, 27 and 29, each 05:00–06:00 UTC (07:00–08:00 Europe/Berlin). Repeat ID 1, frequency 2, until September 30; no exceptions. The test-only UID/date baseline is preserved in [series-split-baseline.json](series-split-baseline.json). Await a user edit of September 23 and all subsequent occurrences before comparing; the agent made no remote changes.

## Controlled series split result — 2026-09-16

Compared [series-split-baseline.json](series-split-baseline.json) with [series-split-after.json](series-split-after.json), after the user changed September 23 and all following occurrences from 07:00–08:00 to 09:00–10:00 Europe/Berlin.

- Five occurrences before and after, but **zero preserved UIDs**: all five old UIDs disappeared and five new UIDs appeared.
- September 21 kept base ID 812 and its original 07:00–08:00 time. Its `repeatId` changed from 1 to 0 and its calculated UID also changed.
- September 23, 25, 27 and 29 now belong to new series 815 and have the requested 09:00–10:00 times; all have new UIDs.

Consequently, UID is not a universally durable identity. The proposed UID-based reconciliation would remove/recreate all five local entries, losing any stored Contao associations, even for the unchanged preceding occurrence. Core Contao events themselves would survive under the existing rules. This is observed test behaviour, not evidence of five real cancellations.

The single-occurrence move result remains valid; it must not be generalized to series splitting. Do not add heuristic matching or a mapping table without a new decision. The user subsequently accepted this demonstrated association loss and manual reassignment; no additional predecessor mapping is required. The existing removal notice should describe entries removed from the source snapshot rather than claim that all were cancelled. The agent performed only reads.

## Token provisioning verified — 2026-09-16

With explicit user authorization, the runtime loaded `CT_USER`/`CT_PASSWORD`, authenticated, resolved the current account via `/api/whoami`, and retrieved its own `/api/persons/{id}/logintoken`. A fresh token-header request without the login cookie returned the same account identity. The verified token was appended as `CT_TOKEN` to `.env.local`, after confirming Git ignores that file. No existing file contents, credential values, response tokens or session cookies were displayed. The temporary cookie and probe script were removed.

Regular production access should use the token. The user also requests an optional credential-based setup facility in the extension; host environment names are configuration mappings, not package constants.

## Internal-appointment policy confirmed — 2026-09-16

The user approved excluding `isInternal=true` from storage and removing entries that become internal, without changing linked core events or counting these as cancellations/source deletions. Architecture now defines the explicit field allowlist. This is a product decision, not a completed runtime transition test; permission-loss handling and description format remain open.

## Description format sample — 2026-09-16

**Subsequent user clarification:** description is plaintext, with links entered in a separate field. The HTML/Markdown below was typed manually. The earlier request for formatting samples is superseded; do not implement Markdown/HTML interpretation.

Token-authenticated read of user-specified `Wiederholungstest 2` returned the same description on base IDs 812 and 815. The field `appointment.base.description` is a string containing newline characters, literal `__Test__`, literal `**Frosch**` and literal `<bold>Hi</bold>`. The API did not convert those markers into rendered HTML. The allowlisted user-provided test value is stored in [description-observation.json](description-observation.json).

This demonstrates that Markdown-like markers and HTML-like input can coexist in the response; it does not establish which syntax the ChurchTools UI supports or renders. `<bold>` is not evidence of a supported HTML bold element. The sample contains neither a link nor a blank-line paragraph separator, so those requested cases remain unverified.

Never render this API string as trusted HTML or copy it into rich text without explicit conversion. Escaped plaintext with preserved newlines is a safe baseline, retaining markers visibly. Markdown support, if desired, requires a verified format contract, disabled/escaped raw HTML, validated link schemes and appropriate sanitization before Contao rich-text insertion. No renderer or conversion implementation was added by this investigation.

## Description and Contao mapping decisions consolidated

Description format is resolved as user-confirmed plaintext, consistent with literal API output. A separate link field remains separate from generated ChurchTools deep links. The installed Contao `adjustTime()` implementation was rechecked at `vendor/contao/calendar-bundle/contao/dca/tl_calendar_events.php:712`: timed values combine date and clock fields; all-day endTime advances to the next day minus one second. Programmatic creation must set equivalent fields explicitly and preserve inclusive Contao end dates. Runtime mapping tests belong to step 6.

## Live permission-loss test skipped by user

The user cannot change the test calendar permissions and explicitly requested skipping this test. No permissions were changed. Live permission-loss semantics remain unverified; this is a documented validation limit, not a passed check or a blocker for package scaffolding. Conservative cleanup guards and simulated-response tests remain required.
