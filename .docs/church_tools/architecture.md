# Architecture

Status: steps 2–7 implemented and locally validated in isolated Contao 5.7.13/6.0.0 matrices; see `step-6-validation.md` and `step-7-validation.md` for precise evidence and limits. The implemented storage and synchronization contracts below distinguish runtime behavior from later design. See `api-contract.md` for API evidence and residual verification gates.

## Package and conventions

Implement in `/home/dev/Kunden/github/contao-churchtools` with Composer name `koertho/contao-churchtools`. Later integrate through a Composer path repository with symlinking, following the existing host-project convention. Do not couple the reusable package to JZM-specific classes or assets.

- Use Symfony `AbstractBundle` where supported and the appropriate Contao manager integration.
- Define DCA SQL columns with Doctrine schema representations.
- Use Symfony PHP translation files.
- Register callbacks, hooks, events, frontend content elements and cronjobs with their matching PHP attributes, not configuration tags or callback arrays.
- Set explicit priorities where callback order matters.
- Cron listeners belong in `src/EventListener/Cron/`.
- DCA listeners belong in `src/EventListener/DataContainer/<TableWithoutTlPrefix>/`, one class per callback, named after the callback with a `Listener` suffix.
- Do not specify custom `targetColumn` values for virtual fields without an explicit requirement.

## Decision: dedicated appointment storage

Keep ChurchTools appointments out of the editorial Contao calendar unless an editor explicitly creates a core event. Reusing an iCal importer or importing REST results directly into `tl_calendar_events` does not meet this requirement. Dedicated storage costs synchronization and rendering work, but preserves the requested read-only mirror and explicit editorial adoption.

The two new content elements list ChurchTools appointments only. They do not replace existing core modules or include independent Contao events. Existing importer migration, page cutover and URL continuity belong to a separate project-specific plan. Installation leaves existing content and URLs untouched. No image support is planned for version one.

Use a small Symfony HttpClient-based API client. The testing installation of `5pm-hdh/churchtools-api` may serve as research material, but is not an extension runtime dependency or the authoritative API contract. Leave `ChurchToolsTestController.php` untouched.

## Service boundaries

1. API client: authenticated HTTP requests, response validation and rejection of detectable unsupported pagination; no deletion-safe snapshot guarantee.
2. Mapper: API payloads to typed calendar, tag and occurrence data.
3. Synchronizer: selected-calendar reconciliation, locking, transactions and cleanup.
4. Contao models: dedicated local storage and indexed lookups; no generic repository hierarchy.
5. Contao event integration: permission-aware linking and explicit event creation.
6. Display resolver: effective information, dates, visibility and URLs.
7. Content elements: local queries and Twig rendering.

Store the instance URL and credential references in global configuration; secrets come from environment configuration. Never expose credentials through templates, committed files, fixtures or logs. Calendar selection is archive configuration, not a second connection definition. Only explicitly selected calendars are eligible for public listing; API-account visibility alone is not publication intent.

## Logical storage

Version one has exactly two extension tables: **archive** and **entry**. The implemented step 3 schema is documented below. An entry represents one appointment occurrence within its archive.

| Table | Responsibility |
| --- | --- |
| Archive | Name, selected source calendar IDs as a blob, last successful synchronization, latest error and linked-entry removal count for the last successful sync |
| Entry | Parent archive ID (`pid`), stable remote occurrence identity, source calendar ID/name and any needed calendar metadata, dates, timezone/all-day semantics, title, available description/location, optional source link information, tags/category metadata blob and nullable Contao event ID |

There are no separate source-calendar, calendar-assignment, taxonomy, synchronization-state or link tables. Calendar options come from the API, merged with all stored selections. Preserve and display stored IDs even when missing from the response or when the API is unavailable; use an ID-based fallback label if no name is known. An unavailable options list must never silently clear selections on save. Metadata needed for local rendering/filtering is stored on entries. Synchronization status belongs on the archive.

Backend navigation uses one **Events** module under **ChurchTools**, with **Archives → appointments** using the standard parent/child relationship through `pid`.

Store each source occurrence once **per archive**, with uniqueness scoped to `(pid, calculated.iCalUid)`. If archives select the same calendar, each archive stores its own entries and Contao links are archive-specific. Avoid overlapping calendar selections initially to keep editorial ownership clear. Frontend output selecting multiple archives deduplicates by remote occurrence identity; it does not require globally shared entry rows.

Do not assume that a series ID alone identifies an occurrence or that its current start time is a stable key. Preserve manual links during upserts and moved-occurrence updates within the archive. Use `(pid, calculated.iCalUid)` as the unique local occurrence key and the UID within the single configured instance for cross-archive deduplication. The controlled move retained its UID while its base appointment ID changed; base ID, calendar ID and dates are mutable source metadata. Missing or conflicting UIDs must prevent reconciliation, not trigger heuristic matching. Avoid retaining unnecessary personal data or entire API responses.

The nullable Contao event ID lives directly on the appointment entity. There is no independent link table or tombstone. Deleting a local appointment, including through retention/window cleanup, loses its association. If it later returns, it is unlinked. This simplicity tradeoff is accepted; the core Contao event survives.

## Synchronization rules

- Cron and CLI call the same service; proposed cadence is hourly.
- Synchronize each archive’s selected source calendars into its own child entries within the retention/future window. Remote fetches may be reused during a run, but writes and status remain archive-specific.
- Interpret retention against the source occurrence's end, with explicit all-day/timezone semantics.
- Only reconcile absence after all required pages for a calendar and date window were successfully fetched and validated.
- Failed, malformed, partial or unauthorized responses must not cause absence-based deletion.
- Scope reconciliation to the archive and successfully fetched calendar/window. Never infer deletion outside it.
- Handle remote moves across window boundaries and source-calendar selection changes explicitly; shrinking a window is not evidence of remote deletion.
- The live permission-loss test was explicitly skipped; do not assume verified API behaviour. Refresh calendar discovery and suppress absence cleanup for missing/inaccessible calendars, 401/403, failed/incomplete responses or ambiguous access/completeness. Record the condition without treating it as cancellation. Verify these guards with simulated responses; this does not prove every live permission transition is detectable.
- Preserve the Contao event ID during updates of existing appointments; deletion removes the association with the appointment. No sync or cleanup writes to linked Contao events.
- Expose last successful synchronization and latest error in the backend. Do not add frontend notices or configurable stale-data warnings in version one.
- Count entries with a non-null Contao event ID removed through cancellation/source-deletion reconciliation and store the count on the archive after a successful sync. Show a short backend notice when nonzero. Exclude retention, calendar deselection, window cleanup and confirmed changes to internal visibility. A successful run with no qualifying removals stores zero; failed runs do not replace the last successful count. No extra table or core-event mutation is needed.
- Use locking and transactional writes to avoid overlapping or partially applied runs.
- Invalidate affected frontend caches after successful changes.

Removal from the extension removes its listing there. A surviving Contao event can still appear in normal Contao event modules.

## Contao integration and presentation

Create a core event only through the explicit backend action. Check target-calendar permissions, copy supported source fields, create it unpublished and link it to that one occurrence. Prevent duplicate creation on repeated submission. Sanitize copied/rendered remote content according to its verified format.

The shared display resolver uses linked Contao title, dates and other supported presentation information and Contao URL generation only when the target is published and currently visible to the visitor. Source calendar and tags remain synchronization metadata. Resolve linked data in PHP before date filtering, sorting and grouping in both content elements, including when a linked Contao event was rescheduled. Load all locally retained appointments across the full stored synchronization window of the selected archives and batch-load their linked targets; avoid per-row target queries. Do not restrict this load to the requested list period or calendar month. A source date three weeks ahead with a qualifying Contao date tomorrow must appear in a seven-day list; the reverse must not. This applies only to retained entries, not appointments already removed by cleanup. This deliberately favours a straightforward in-memory display pipeline over SQL date-precedence expressions. Do not prefilter only by source dates and accidentally discard a matching Contao date.

Respect Contao publication windows and access rules when deciding whether a target can override the source. For an unpublished, inaccessible or missing target, render ChurchTools information without a Contao detail-page link; never expose restricted target data. A newly created unpublished event therefore leaves the source listing visible. Keep a missing target ID unchanged during resolution: restoration under the same ID reactivates the override once published and visible. Explicit unlinking clears the ID; deleting the appointment removes the association with it.

Use the core event's selected date range for a single linked occurrence; do not automatically expand it into a second recurring series. Each ChurchTools occurrence produces one entry even when its linked Contao event has recurrence rules. Verify that the host's repeating-event extensions do not accidentally expand these entries.

## Frontend

Provide list and month-calendar frontend content elements selecting archives. Use one query/display path for consistent deduplication, access control and dates. For each remote occurrence identity, first determine which entries have a published, currently visitor-visible Contao target. Prefer a qualifying entry and break ties by ascending archive ID. If none qualify, use the ChurchTools data of the entry with the lowest archive ID. Then filter the chosen effective dates by the requested period, sort and group; do not select another copy merely because the winner falls outside that period. Show a Contao link only for a qualifying target, otherwise a plain ChurchTools entry. Keep the optional ChurchTools URL available without automatically making it the default link.

Use overridable Twig templates and Bootstrap 5.3-compatible markup. Format times and dates using the active locale. Handle all-day, overlapping and multi-day events; verify a usable mobile layout. Only the calendar exposes month navigation. Resolve the selected archives once across the locally representable four-digit years (1000–9999); derive the first and last navigable local months from the effective starts and ends of the retained, visitor-visible UID winners. Include the current month as an anchor, so gaps between it and available appointments remain traversable. Clamp a valid month outside those bounds to the nearest bound; invalid month values use the current month. With no winners, show only the current month and no links. Filter the already resolved winners by overlap with the displayed month, after deduplication. These bounds must change with current frontend visibility and must never expose a restricted target's effective date.

## Later authentication

Keep API synchronization credentials independent from future member authentication. Version one does not add member fields, an authenticator or OAuth dependencies solely for future use. A later design must verify the supported OAuth flow, remote identity, account provisioning/linking, permissions and logout behaviour.

## Verified API mapping

Use nested `appointment.base` for source metadata and `appointment.calculated` for occurrence UID/dates; avoid deprecated top-level copies. Request tags explicitly and retain ID/name/nullable description/color in the entry blob. For all-day values, the API returns inclusive date-only ends; timed values are timezone-bearing instants. Deduplicate chunks before reconciliation and apply local range checks. See the consolidated contract for permission-loss, cancellation-status, deep-link and DST checks still outstanding.

## Series-split identity limit — accepted

The controlled September 16 test regenerated all five occurrence UIDs during a series split, including the unchanged preceding occurrence. `(pid, calculated.iCalUid)` therefore does not preserve links across every editorial operation. Under this matching rule the entries would be recreated and links lost; core events survive. The user explicitly accepts this limitation: affected Contao links are manually reassigned after a series split, including links on preceding occurrences whose UIDs also change. UID-based reconciliation remains the chosen approach. No heuristic remapping or additional table is authorized by this finding. See `series-split-after.json` and `api-contract.md`.

## Authentication and optional token setup

Use `Authorization: Login <token>` for regular API access through bundle-owned configuration. The host may map `CT_TOKEN` with Symfony environment configuration. Offer an explicit setup command accepting configured username/password (host mappings `CT_USER`/`CT_PASSWORD`): authenticate, resolve the current account, retrieve its own login token, and verify the token without the login session before saving to an explicitly selected local secret destination. Never print the token or pass it in process arguments. Require an explicit destination/write action; do not silently edit deployment configuration. Ordinary synchronization does not automatically fall back to a password or repeat token provisioning. Setup credentials can be removed after provisioning. No additional database table is required.

## Internal appointments and persisted-field allowlist

Do not insert `isInternal=true` appointments. When a successfully retrieved appointment with a known UID becomes internal, delete the matching archive entry, invalidate affected caches and leave its core Contao event untouched. Classify this as a visibility removal, not cancellation/source absence, and exclude it from that counter. Keep observed internal UIDs transiently during reconciliation so filtering them out does not misclassify their absence. If an entry later becomes public again, normal insertion applies; its deleted Contao association is not restored automatically.

Do not equate inaccessible calendars or failed responses with explicit `isInternal=true`: the existing permission-loss/fetch-failure safeguards still apply. The policy is decided; its implementation and API transition test remain to be verified.

Persist only: archive parent ID, calculated UID, source appointment/calendar identifiers, calendar name and explicitly needed display metadata, title, mapped description/location, occurrence start/end and all-day semantics, verified optional source-link data, the tags/category blob, and local Contao event reference and required local bookkeeping. Map nested values explicitly rather than copying source objects. The tag blob permits ID/name/nullable description/color; any additional category fields require an explicit mapping. Do not persist remote `meta`, `onBehalfOfPid`, `signup`, `image`, bookings, meeting requests or complete response bodies. The user confirmed description is plaintext. Escape it, preserve line breaks, and never interpret user-entered HTML/Markdown. When writing rich text, encode the text before introducing any deliberate paragraph/line-break markup. Treat the separate link field independently and validate its scheme before rendering a URL.

## Programmatic Contao event date mapping

The installed calendar DCA registers `adjustTime()` as an onsubmit callback; model creation must not assume this DCA lifecycle runs. Set `addTime`, date fields and full start/end timestamps explicitly in the target calendar timezone. Normalize date fields to local date boundaries. For all-day events retain the inclusive final date and set `endTime` to the next local midnight minus one second, not by adding a fixed 86400 seconds. Do not write an internally exclusive end as Contao's inclusive end date. Test single-day and multi-day timed/all-day cases plus DST under the compatibility matrix; the inspected 5.7 code is not a 6.x runtime test.

## Implemented step 3 storage contract — 2026-09-17

Exactly two extension tables are now defined through Doctrine DCA schema arrays and registered Contao Active Record classes under `src/Model/`. The schema below supersedes the provisional column wording above. No full backend module is registered yet. Archive DCA supplies an `entries` child-navigation operation and name editing; entry DCA declares its parent and disables creation, editing, copying and deletion. Source fields have no widgets. Calendar selection and status UI remain step 5.

### `tl_church_tools_archive` / `ChurchToolsArchiveModel`

| Field | SQL representation / meaning |
| --- | --- |
| `id` | unsigned integer, auto-increment primary key |
| `tstamp` | unsigned integer, default 0; local modification timestamp |
| `name` | varchar(255), default empty string |
| `calendarIds` | nullable longblob; UTF-8 JSON list of positive unsigned 32-bit integer IDs; `[]` is an explicit empty selection, legacy/unset NULL means no selection |
| `lastSuccessfulSync` | unsigned bigint, default 0; Unix seconds, 0 means never |
| `lastError` | nullable longtext; reserved for a sanitized local error description |
| `removedLinkedEntries` | unsigned integer, default 0; reserved for the last successful source-removal count |

`setCalendarIds()` validates list shape and IDs, removes duplicates in selection order, and assigns JSON; callers explicitly save the model. It does not discover calendars, erase unavailable selections, write synchronization status or save implicitly. No status lifecycle is implemented.

### `tl_church_tools_entry` / `ChurchToolsEntryModel`

| Field | SQL representation / meaning |
| --- | --- |
| `id`, `tstamp` | unsigned integer primary key / modification timestamp, as above |
| `pid` | unsigned integer; parent archive, Contao `belongsTo` relation |
| `occurrenceUid` | **varbinary(2048)**, mandatory, full byte-exact calculated UID |
| `sourceAppointmentId`, `sourceCalendarId` | positive unsigned integers; mutable source metadata |
| `calendarName`, `title` | longtext; mapped source strings |
| `description` | mediumtext; unchanged plaintext, missing/null description becomes empty string |
| `allDay` | boolean (MariaDB tinyint) |
| `sourceStart`, `sourceEnd` | varchar(64); original calculated strings, including offset and fractional seconds |
| `startTimestamp`, `endTimestamp` | nullable signed bigint; whole Unix seconds for timed values only |
| `startDate`, `endDate` | nullable SQL date; all-day values only, **inclusive** end date |
| `tags` | mediumblob containing UTF-8 JSON list; `[]` is empty |
| `contaoEventId` | nullable unsigned integer; local opaque reference, no foreign key or cascade |

The tag JSON permits exactly `id` (positive integer), `name` (string), `description` (string or null), `color` (string, including symbolic values such as `basic`). Remote tag counts and unknown keys are discarded. No category structure, location, source link or generated deep link is introduced because a usable mapping for those optional fields has not been verified. There is no generic metadata/raw-response column. Remote `meta`, `onBehalfOfPid`, `signup`, `image`, bookings, meeting requests and source objects are excluded by explicit assignment.

Indexes: primary `id`; **unique `(pid, occurrenceUid)`**; `(pid, sourceCalendarId)` for selected-calendar queries; `(pid, startTimestamp)` and `(pid, startDate)` for the two distinct date domains. The leading `pid` also serves archive lookup, so no redundant standalone parent index is added. These are not a frontend effective-date filtering strategy; later display resolution still loads retained entries before filtering linked dates.

The UID index covers every stored byte; it is neither a prefix nor a hash, and distinguishes case and trailing spaces. The 2048-byte storage limit leaves the compound key below the standard 3072-byte InnoDB limit. This is a **local supported-input bound, not a verified API maximum**. Empty/whitespace-only or larger UIDs are rejected before writing, never truncated or matched approximately. The test covers distinct 2048-byte UIDs sharing their first 2047 bytes. A future wider API contract requires an explicit schema decision; silently shortening identity is forbidden.

### Dates and source writes

Timed dates must be valid offset-bearing ISO strings with seconds and optional 1–6 fractional digits. The original strings are authoritative for source offset and subsecond precision; indexed timestamps represent whole Unix seconds. Both offsets are retained independently across DST. No IANA timezone is invented from an offset. Inputs outside the supported precision/date grammar fail explicitly rather than losing information. Date-only values are never parsed in the host's default timezone for conversion into instants: all-day `startDate` and inclusive `endDate` remain calendar dates, while timestamp columns are NULL. Timed rows have NULL calendar-date columns. Equal all-day dates represent one day. No exclusive-end conversion, 86400-second calendar arithmetic or `tl_calendar_events` date mapping occurs in this step.

`ChurchToolsEntryModel::saveSource($archiveId, $row)` validates/maps through `Storage\SourceOccurrence`, requires an existing archive and finds by the sole identity `(pid, calculated.iCalUid)`. It updates only the explicit source field set plus `tstamp`. `pid`, `id`, status fields and `contaoEventId` can never be taken from the remote array. A matching move retains row/link; a new UID starts unlinked. Missing targets are not looked up or cleared. Deleting the local Active Record never touches a core event. Internal or unknown visibility is rejected before writes; removing existing internal entries is step 4 work.

This is a one-record storage operation, not snapshot reconciliation. Sequential repeated writes are idempotent; concurrent insert races are rejected by the unique key. Step 4 must serialize writers and supply transaction boundaries for whole runs. Existing inherited model queries (`findByPk`, `findByPid`, `findOneBy`) provide the required reads without delegation wrappers or a repository hierarchy.

### Direct dependency review

Runtime additions use `Contao\Model`, `DataContainer` and `DC_Table` from the existing direct `contao/core-bundle` requirement, PHP date/JSON functionality and Doctrine DCA type names. No runtime DBAL class is newly imported. `contaoEventId` has no calendar class, `foreignKey`, relation or DCA dependency on `tl_calendar_events`; therefore step 3 does **not** require a new runtime `contao/calendar-bundle` dependency. The isolated matrix explicitly installs the target-matching calendar bundle to test real `CalendarModel`/`CalendarEventsModel` records. Revisit the direct runtime dependency when step 5/6 introduces the actual calendar integration. No host or package dependency upgrades were needed.

## Implemented step 4 synchronization contract — 2026-09-17

This section supersedes the step-3 statements that orchestration/status lifecycle are not yet implemented. Validation: [step-4-validation.md](step-4-validation.md). Steps 5+ remain pending.

### Services and snapshot authority

`SyncWindow`, `SnapshotFetcher` and `Synchronizer` provide the shared implementation. `church-tools:sync [--archive=ID]` and `EventListener/Cron/SyncListener` (`#[AsCronJob('hourly')]`) invoke it. All HTTP calls use the existing token-only client; setup is never invoked. Diagnostics contain only archive IDs, aggregate counters and fixed messages; neither exception chains nor remote payloads are logged.

For each archive, acquire a nonblocking connection-scoped MariaDB/MySQL advisory lock keyed by database and archive ID. It serializes CLI and cron, also across application hosts using that same database primary; there is no TTL that can expire during a slow fetch and no new lock table. Different archives remain independent. A contended archive returns `locked` without changing stored status. There is no claim of compatibility with non-MySQL databases, connection multiplexers or multiple independent primaries.

Before any source write, validate the explicit calendar selection and discovery; retrieve each calendar independently over the full window and again in adjacent 31-day chunks. Validate and deduplicate each response, compare the complete mapped UID/field unions including internal and returned boundary occurrences, reject conflicting UIDs across calendars, and repeat discovery after all fetches. Compare mapped fields, not irrelevant personal metadata. Retain internal UIDs transiently, never persist them. Only selected calendars are synchronized.

Any missing calendar, HTTP/JSON error, unsupported envelope/metadata, pagination marker/header, count mismatch, invalid source field or storage-budget failure aborts the entire archive snapshot. An empty full-window calendar response is ambiguous even with matching `meta.count=0`; it fails closed, including initial import. Successful nonempty calendars in that same archive are not partially applied. Other archives can succeed independently. Explicit empty archive selection performs local deselection cleanup without remote access.

These are conservative checks supported by the observed bounded endpoint, not proof against silently capped or permission-filtered but internally consistent nonempty subsets. No new access flag, endpoint or pagination semantics have been invented. The user-skipped live permission-loss test remains skipped. A genuinely emptied calendar cannot currently authorize absence cleanup through this endpoint alone. Exceptions are never deletion authority; only absence from the validated snapshot is reconciled.

### Bounds, date window and cleanup

- Defaults: one past/six future calendar months; UTC midnight boundaries, clamp month ends, half-open interval. Configuration accepts 0–120 past and 1–120 future months. All-day values use date comparisons with inclusive ends; timed values use offset-bearing instants, including microseconds. A zero-duration occurrence at the lower bound is retained. Returned upper-bound occurrences are compared/deduplicated but not inserted outside the local interval.
- Explicit local supported-input budgets: 8 MiB per streamed HTTP response, JSON nesting 64; 10,000 unique UIDs and 16 MiB of JSON-encoded mapped data per snapshot/union. Source strings are valid UTF-8 and capped at 16,777,215 bytes (also protecting mediumtext/blob storage), UIDs remain at most 2048 bytes. These are implementation limits, not ChurchTools maxima or an absolute PHP peak-memory guarantee. Fail before source writes; do not truncate.
- Matching public in-window UIDs use `ChurchToolsEntryModel::saveSource()`. Unchanged records are skipped byte-for-byte, including their timestamps. Source changes preserve row ID and local link. Newly appearing/reappearing UIDs start unlinked. No source path writes `tl_calendar_events`.
- Removal precedence: deselected source calendar; explicitly observed internal UID; outside-window source interval; otherwise absent source UID. Report distinct `deselected`, `internal`, `retention`, `window` and `sourceRemoved` counters. `removedLinkedEntries` counts only source-removed entries whose local event ID was non-null.
- Natural retention is source-end expiry against the current day's lower boundary under the last successful past-month configuration. Shortening that configuration additionally removes entries as `window`, not `retention`. Future-bound exclusion is `window`. A returned occurrence moved outside the window is classified using its new interval. A move beyond all queried bounds cannot be distinguished from source disappearance and is reported under the deliberately broad source-removal label.

### Existing archive schema extension and lifecycle

Exactly two extension tables remain. Add **`lastSyncPastMonths`**, nullable unsigned `smallint`, to `tl_church_tools_archive`. It stores the past-month configuration from the last committed successful database reconciliation (including synchronous pre-commit invalidation). NULL means no previous successful configuration is known; use the current setting for initial retention classification. No separate mapping, status, pending-job or cache-outbox table is introduced. The field has no backend widget in this step.

Fetch and map outside the write transaction. Inside it, lock the archive row, verify its selection still exactly matches the fetched selection, then lock/read its entries and reconcile. Configuration races fail closed. **Source changes and all success metadata share one database transaction**: success timestamp, cleared error, removal count (including zero), past-month setting and modification timestamp. Invoke the Contao cache-tag manager before committing; synchronous dispatch/queue errors and the final status-write error roll back all those database changes together. After rollback only the sanitized error is written separately; the previous source rows, success timestamp and removal count remain intact. A retry can therefore still remove and count the linked occurrence exactly once. Rollback failures caused by a server-aborted transaction close the connection; an uncertain connection is not reused. Touched Active Records are unregistered on exit.

Invalidate `contao.db.tl_church_tools_entry`, `contao.db.tl_church_tools_archive` and `church_tools.archive.<id>`. **Repeat the invalidation after commit**: an immediate listener/custom transport could have evicted before commit, allowing another connection to refill from the old committed source rows. The second pass addresses that interval instead of moving every invalidation ahead of commit. The step-7 views disable HTTP response caching and therefore do not rely on these tags for visitor-specific output.

The verified Contao 5.7.13 and 6.0.0 `CacheTagManager::invalidateTags()` first dispatches `InvalidateCacheTagsEvent` synchronously, then calls the optional FOS invalidator. Standard FOS Symfony proxy invalidations are queued by `HttpDispatcher`/`KernelDispatcher` (duplicates can coalesce); `FOS\HttpCacheBundle\EventListener\InvalidationListener` flushes on `console.terminate`, `kernel.terminate` and kernel exceptions. HTTP termination swallows `ExceptionCollection` after FOS logging; console termination may throw. The synchronizer neither flushes unrelated queued invalidations inside its transaction nor mistakes queue acceptance for successful delivery.

There is still no distributed SQL/cache transaction or durable outbox. Early listener effects and already queued evictions cannot be undone on rollback; these can cause extra cache misses while the **database remains unchanged**. A failure of the explicit post-commit second pass returns `committed_cache_error` with the committed counts and records a fixed cache warning when possible; it never restores old success fields or discards the newly committed removal notice. Failure even to store that warning leaves the committed database state intact and is returned as a fixed diagnostic. CLI reports nonzero/cron logs a warning for this distinct outcome. A later lifecycle flush/remote cache transport error is outside the service's completed DB transaction and cannot roll it back. A process crash before delivery, or an in-flight reader caching stale data after the second invalidation, remains a cache consistency limitation, not loss of the atomic data/status update. Later runs invalidate again, including unchanged runs. Locks are released in `finally`.

## Implemented step 5 backend contract — 2026-09-23

The earlier step-3 minimal-DCA description is historical. `church_tools_events` now registers both tables under ChurchTools, with archive creation/editing and read-only appointment listing/details. Parent scope is the core `ptable`/`ctable`/`pid` workflow; source-calendar filters use local metadata, never API calls. A scoped Contao Twig list extension adds Archives/Appointments headings, retaining core rendering/styling.

Calendar options discover only calendars, merge all stored IDs, and retain ID-labelled fallback choices during outages. Attribute load/save callbacks explicitly bridge JSON storage and Contao checkbox serialization; no serialized widget value is persisted as JSON. Explicit deselection remains possible, including `[]`; missing POST input never means deselection. API discovery failure produces a translated backend notice without sync/token setup.

Core module permission controls reading both tables; core archive create/update and excluded-field permissions control changes. There is no custom archive permission registry. Table onload guards protect alternate direct entry points; source entry actions are allowlisted to listing/show, with all source/link data read-only. Status is presentation-only. Appointment details show decoded allowlisted tags and existing/missing/unlinked target state, escaping all source/error/tag text and preserving missing IDs. Target titles/access-protected data and step-6 actions are not exposed.

Contao 6 labels use `RecordLabel::fromHtml()` after escaping; Contao 5.7 uses the equivalent escaped HTML string. Detail values become Twig Markup only after escaping. German/English notices explain the retained conservative empty-window failure and the linked source-removal counter. No extension schema, synchronization or token behavior changed. See [step-5-validation.md](step-5-validation.md) for exact environments, core test-schema additions, permissions and reproducible HTTP tests.

The step-5 backend link-status view reads `tl_calendar_events` to distinguish existing and missing target IDs. The package therefore directly requires `contao/calendar-bundle` (`^5.7 || ^6.0`); this dependency is no longer deferred to step 6. It does not add core-event actions or write access.

## Step 6 event integration contract — 2026-09-23

`EventIntegration\BackendAccess`, `LinkActions`, `EventFields` and `DisplayResolver` implement single-occurrence adoption without new tables or columns. The attribute-routed backend controller and attribute DCA operation expose a separate action form. Source DCA fields remain widget-free and ordinary source edit/delete/copy/create actions remain forbidden.

The local permission is the core excluded-field permission **`tl_church_tools_entry::contaoEventId`**. It controls the dedicated actions only; it does not create an editable ID widget. Both the ChurchTools module and the core entry `ReadAction` are required. Target selection and opening require core calendar-event `ReadAction` and `UpdateAction` decisions; creation requires calendar read and event create decisions, including the calendar module, allowed calendars and table-operation restrictions. Text details additionally require the core content create, text-element and text-field permissions. Every submitted target is checked again inside the transaction. Target labels are only rendered after authorization. A missing or forbidden target has no open link; its stored ID is retained. The action form uses Contao's own CSRF token manager; mutations are POST-only. The core edit URL includes its request token.

Link, unlink and create acquire exactly the synchronizer's database/archive advisory lock, then lock the archive and occurrence rows in that order. Event creation, text content creation and assigning the nullable reference share one transaction on the core connection. Repeated create never replaces any non-null reference, even a missing target; repeated linking to the same ID is a no-op, and replacing another ID requires unlink first. Contention returns 409 for an explicit retry. Source fields are never updated by these actions. Unlink removes only the reference. No sync or source-removal code was changed.

Cache tags for entries, the archive, calendar events and content are invalidated inside the transaction and again after commit. Pre-commit failures roll back event/content/link writes; post-commit invalidation failure reports an error with the committed association intact. Retrying is safe. Deferred cache delivery is not a database transaction; step-7 pages avoid shared response caching.

### Core copy and date semantics

The actual 5.7/6 event reader uses `tl_content` children for details. Creation writes a `text` child as well as a teaser when the description is nonempty. Plaintext is escaped before adding only our own paragraph/break tags; Markdown stays literal and braces are encoded to prevent insert-tag interpretation. Titles use 5.7's input-encoded representation and 6.0's plain-text representation; their respective core readers escape the result correctly. Source insert-tag syntax is encoded for legacy HTML output. Core 6 event_list.html.twig applies `title|insert_tag` in the link-title attribute, so creation explicitly rejects every title containing `{{` with translated feedback. No universal representation-neutral escape across reader/list output was established: entity-encoding Core 6 storage would double-encode ordinary output. The source stays unchanged and may instead be linked to a manually created event. Titles that cannot fit the core field are rejected, never truncated. No location, category, image or source link is invented.

Created events use `source=default`, no recurrence, the acting backend user as author and `published=false`. An empty alias deliberately uses the native numeric-ID URL fallback. The normal core editor can subsequently generate/change the alias, edit independent details and publish the event. All date and time fields are assigned explicitly; `Model::save()` does not execute `adjustTime()`.

The initialized Contao timezone supplies local calendar days. Offset-bearing timed source values remain instants; startDate/endDate are local midnights and startTime/endTime are full timestamps. All-day endDate is inclusive, and endTime is the next local midnight minus one second. No fixed 86400-second arithmetic is used. Core date fields support unsigned whole seconds only. Nonzero fractional seconds, timed equal endpoints (which Core would turn into an open-ended event), and timestamps outside that representation are rejected with a translated explanation and no writes. Zero fractional digits are lossless and supported. These source occurrences remain available unchanged; editors can create and explicitly link a target manually if they intentionally want different semantics.

### Effective display contract

`DisplayResolver::resolve(archiveIds, from, until)` returns flat, sorted presentation arrays, using an **inclusive overlap period**. It loads every locally retained row of the selected existing archives, then batch-loads all referenced events and their calendars. It does not prefilter source dates. DateTimeImmutable values preserve source fractional/point semantics; source descriptions have `descriptionIsHtml=false`. Visible targets provide decoded plain titles, core rich-text teasers (`descriptionIsHtml=true`), effective dates and core-generated URLs. Output consumers must respect this text-format distinction.

Visibility is evaluated per call with the actual current security context, without a result cache. Event publication uses the core minute boundary, inclusive start and exclusive stop. Calendar protection uses the core member-group voter. The resolver follows the same ordered content URL resolvers as Core, checking publication and member rights for every page/article in the destination chain. Page details supply inherited protection, root and page publication. It never uses backend preview publication exceptions. Missing/invisible targets yield source-only output without target ID, title, teaser, dates or URL; stored IDs are never modified. An unusable reader destination also falls back safely.

UID winners are chosen before the period filter: visible target first, then lowest archive ID (and stable record order). A winning record outside the requested period does not fall back to another copy. Sorting uses effective start dates. Core recurrence is not expanded, and the core event generator's repeating hooks are not called. Deliberately open-ended editorial targets retain Core's end-of-day effective end; this does not change equal-endpoint source occurrences.

## Step 7 frontend contract — 2026-09-25

Two `#[AsContentElement]` controllers register `church_tools_list` and `church_tools_calendar` with managed, overridable `@Contao/content_element/...` Twig templates. Shared `tl_content.churchToolsArchives` selects existing archives; `churchToolsDays` configures the list window (1–366, default seven). These are columns on the core content table; exactly two ChurchTools tables remain. No core modules or host page records are touched by installation.

Each controller invokes `DisplayResolver` once for its inclusive local period. The list starts at local midnight today, groups one effective occurrence on its first visible local day, and never expands a multi-day row into duplicates. The calendar requests exactly the current month, places the same logical result on every overlapping local day, and retains Monday-first week order across month boundaries. Previous/next links use a parameter keyed by content-element ID, preserve other GET parameters via RFC 3986 encoding, and reject malformed month input. Locale-aware ICU labels and dates use the frontend locale; day boundaries use the configured timezone rather than 24-hour arithmetic. Calendar cells collapse to event-bearing days on small screens. There are no other visitor controls.

Only a visible linked core target supplies a detail link. An optional ChurchTools source URL stays separate and is not rendered. Rendered detail URLs are checked for unsafe schemes and controls. Source descriptions remain escaped plaintext with line breaks. Core editorial teasers pass through Contao's HTML sanitizer as rich text; no insert-tag filter is called for source text.

The resolver is deliberately uncached. The controllers mark their subresponses `private, no-store` and mark the main request; an attributed response listener applies `private, no-store, no-cache, must-revalidate` to the final main response after Core's fragment-cache merge. Both 5.7 and 6.0 expose the fragment merge and page shared-cache contracts that require this final override. This trades page caching for correct member, publication-minute and edit visibility; it avoids relying on event/page/cache-tag invalidation for these views. An already cached response from before an editor adds the element still relies on Core's normal content-change invalidation. No external reverse-proxy integration was tested.
