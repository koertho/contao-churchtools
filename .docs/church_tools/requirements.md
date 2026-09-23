# Requirements

## Confirmed scope

### Package and connection

- Separate extension: `koertho/contao-churchtools` in `/home/dev/Kunden/github/contao-churchtools`.
- PHP 8.4+, Contao 5.7 and Contao 6 compatibility.
- One ChurchTools instance per installation.
- A dedicated API account exists. Production authentication uses a login token; retrieval and token-only authentication have been verified. Minimum permissions remain to be established.
- Offer an explicit optional setup command that uses configured username/password to retrieve and verify the current account’s login token. Provide bundle configuration keys for instance URL, token and optional setup credentials; the host maps `CT_TOKEN`, `CT_USER` and `CT_PASSWORD` through environment configuration. Do not hard-code these host variable names in the package. Never display credentials or tokens.
- Archives select configurable ChurchTools source calendar IDs.

### Synchronization

- Use exactly two extension tables: archive and entry, with `pid` linking entries to their parent archive. Periodically synchronize appointments through a cronjob.
- Store selected calendar IDs as a blob and last successful sync/latest error on the archive. Store source calendar ID/name and needed metadata on entries; no separate calendar, assignment or synchronization-state tables.
- Store each occurrence once per archive. Overlapping calendars create entries in each archive, with archive-specific Contao links; deduplicate frontend output by remote occurrence identity.
- Configurable future window, default six months.
- Configurable past retention, default one month.
- Delete local source records when ChurchTools appointments are deleted or cancelled.
- Never delete an independently linked or created Contao event during source cleanup.
- ChurchTools information is read-only in Contao.
- Descriptions are plaintext: escape HTML, do not interpret Markdown, and preserve line breaks. Convert safely when copying into a Contao rich-text field. The separate source link field is not automatically a ChurchTools detail-page URL.
- Do not store appointments marked `isInternal=true`. If an existing entry becomes internal, remove it locally and invalidate its frontend output; preserve any linked Contao event. This visibility removal is excluded from the cancellation/source-removal counter.
- Persist only explicitly mapped fields. Never store complete API objects or personal auxiliary metadata in the entry blob.
- Store available category/tag metadata as a blob on the appointment entity. No separate taxonomy or assignment tables in version one; the API representation still needs verification.
- Frontend rendering uses local data.

### Contao event integration

- Optional manual link to an existing Contao event.
- Optional action to create and link a Contao event from a source occurrence.
- Each link applies to one occurrence, not an entire ChurchTools series.
- Store a nullable Contao event ID directly on the appointment entity. Updates preserve it; deleting the appointment removes the association, but preserves the Contao event. No separate mappings or tombstones.
- Accepted limitation: editing “this and all following” in ChurchTools can regenerate occurrence UIDs, including a preceding unchanged occurrence. UID-based sync removes/recreates affected entries; editors manually restore Contao links. Core Contao events remain untouched. Do not add heuristic reassignment or mapping tables. The archive removal notice also covers linked entries removed through these identity changes and must not label them all as cancellations.
- Resolve linked information in PHP before date filtering, sorting and grouping. Each ChurchTools occurrence produces one entry; linked Contao recurrence rules generate no additional entries.
- Only a published Contao target currently visible to the visitor overrides ChurchTools information and supplies its detail-page link. Respect publication windows and access rules.
- An unpublished, inaccessible or missing target leaves the ChurchTools data visible without a Contao detail-page link. Creating an unpublished target does not hide the appointment.
- Keep a missing target’s stored ID; do not clear it automatically. Restoration under the same ID reactivates the override when published and visible. Only explicit unlinking or deletion of the appointment removes the association.
- Load all locally retained entries of the selected archives and their linked targets before resolving presentation in PHP and filtering by the requested period.
- For duplicate source occurrences, prefer an entry with a published, visitor-visible Contao target; break ties by lowest archive ID. If no target qualifies, show ChurchTools data once using the lowest archive ID.
- Unlinked appointments are listings only, without an extension detail page.
- Retain an optional ChurchTools URL or enough verified source identifiers to generate it. This does not imply automatic frontend linking.

### Backend

- New navigation category: **ChurchTools**.
- Dedicated backend module: **Events**.
- Keep the name **Archives** and use one parent/child backend workflow: **archives → appointments**. Archives configure source calendar IDs.
- Preserve and display stored calendar IDs even if API options are unavailable or omit them; use an ID-based label when the name is unknown. Saving during an API outage must not silently clear the selection.
- Show the last successful synchronization and latest error in the backend; no frontend notices or configurable stale-data warnings in version one.
- Store a simple per-archive count of entries with a Contao event ID removed by cancellation/source-deletion reconciliation in the last successful sync. Show a backend notice when nonzero. Exclude retention, configuration/window cleanup and removals because an appointment became internal; preserve core events.
- List synchronized events and allow filtering by source calendar.
- Provide link/create actions and read-only source information.

### Frontend

- Two new content elements, outputting only ChurchTools appointments from selected archives.
- Do not include independent Contao events or offer additional Contao-calendar selection. Published, visitor-visible linked Contao information takes precedence.
- Existing core modules remain separate; the live pages are presentation references, not replacement targets.
- No appointment images in version one.
- Day-grouped list matching the structure of “Kommende Woche...” on the current homepage.
- Month calendar matching the current calendar's behaviour.
- The only visitor-facing filter is previous/next month navigation in the calendar.
- No visitor-facing source-calendar or tag filters.
- Use Bootstrap 5.3 and reuse standard/project components where practical.

## Proposed implementation defaults

These are planning choices, not additional explicit user requirements:

- Hourly synchronization and a manual CLI command.
- Global synchronization window and retention configuration.
- Configurable list period, initially seven days.
- Content elements select one or more archives.
- Monday-first calendar and overridable Twig templates.
- New Contao events start unpublished in an editor-selected target calendar.
- Avoid overlapping archive calendar selections initially to keep editorial ownership clear.
- English and German backend translations.

## Deferred and excluded

- Later phase: ChurchTools authentication for Contao **frontend members**. OAuth feasibility and account linking require a separate design.
- No backend-user SSO in this scope.
- No automatic bulk import into the Contao core calendar.
- No writes back to ChurchTools.
- No local overrides of synchronized source fields.
- No extension event-detail reader, featured-event system or whole-series linking.
- Migration/cutover of the existing importer, events, URLs and page elements is separate project work. Installation leaves them untouched; no automatic migration, deletion or redirects.
- Leave the existing `ChurchToolsTestController.php` untouched and outside scope.
- `5pm-hdh/churchtools-api` may be consulted for research but must not become a runtime dependency of the extension. Use a small Symfony HttpClient-based client.
- No automatic deployment, commit or package publication.

## API contract and remaining checks

- Minimum account permissions remain open; token acquisition and token authentication are verified.
- Calendar appointment endpoints versus service-planning event endpoints.
- Use calculated `iCalUid` for occurrence identity, scoped to the archive locally. The controlled recurring move retained this UID while changing its base ID; never identify an occurrence by base ID/current date alone.
- Cancellation, deletion, pagination and permission-loss semantics.
- Tags versus calendar categories and their exposed metadata.
- Date bounds, timezone, all-day and multi-day semantics.
- Valid ChurchTools deep-link format, including any occurrence date requirement.

The consolidated findings and remaining validation gates are in [api-contract.md](api-contract.md). Core authentication, retrieval, moved-occurrence identity, single-occurrence deletion, tags and multi-day examples are verified. Permission-loss behaviour, separate cancellation status, category/visibility mapping, optional deep links and explicit DST cases remain unverified.
