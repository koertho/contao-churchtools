# Contao ChurchTools

`koertho/contao-churchtools` is an independent Contao bundle for a read-only ChurchTools appointment integration. **Steps 2–7** are implemented: bundle/configuration, authenticated API client, optional explicit token setup, two-table archive/occurrence storage, guarded synchronization, backend management, single-occurrence Contao event actions and two visitor-aware content elements. SSO remains later work.

Requires PHP 8.4+ and Contao 5.7 or 6.0. Direct Symfony dependencies allow 7.4 and 8.x; Composer selects compatible versions for each Contao target. No ChurchTools SDK dependency is used.

## Installation

The package is not published. Add a Composer path repository pointing to your checkout, with `options.symlink: true` and `options.versions.koertho/contao-churchtools: dev-main`, then require `koertho/contao-churchtools:@dev`. The Contao Manager plugin registers the bundle automatically. Use your normal Contao cache/setup procedure; review the two new ChurchTools tables before applying the normal database schema update. No automatic migration runs. Once schema/configuration and archives exist, the hourly cron synchronizes configured archives.

For JZM, the existing `.ddev/docker-compose.mounts.yaml` already mounts `$HOME/Kunden` at the identical container path. The host Composer repository and installed vendor symlink both resolve `/home/dev/Kunden/github/contao-churchtools`. No mount was added. This local absolute repository path is a development setting, not portable deployment configuration. Other installations must use their own reachable path or a published/VCS source when available.

Local PHP, Composer and tests run in DDEV project `jzm` from `/home/dev/Kunden/privat/jzm-contao`:

```sh
ddev composer update koertho/contao-churchtools --no-scripts --minimal-changes
ddev exec php bin/console cache:clear --env=prod
```

## Configuration

Use Contao's `config/config.yaml` (or the application's supported Symfony config import):

```yaml
parameters:
  church_tools.empty_setup_credential: ''

church_tools:
  instance_url: 'https://your-instance.church.tools'
  token: '%env(CT_TOKEN)%'
  setup:
    username: '%env(default:church_tools.empty_setup_credential:CT_USER)%'
    password: '%env(default:church_tools.empty_setup_credential:CT_PASSWORD)%'
  sync:
    future_months: 6
    past_months: 1
```

These environment names are **host mappings**, not built into the package. Setup credentials are optional and can be removed after provisioning. `past_months: 0` is supported; future months must be positive. These parameters configure later synchronization; no scheduling or cleanup runs in this step.

An unconfigured installation can boot. The client validates the resolved HTTPS origin and token before its first request. Origins must have no user information, path, query or fragment. Empty tokens fail locally. Requests have bounded timeouts and do not follow redirects, retry authentication or fall back to username/password. A dedicated untraced Symfony HttpClient avoids placing private responses and headers in the Symfony HTTP profiler. Errors contain fixed descriptions/HTTP codes without upstream bodies or chained transport exceptions.

Inject `Koertho\ChurchToolsBundle\Api\ChurchToolsClient`; call `calendars()` or `appointments([2], $from, $to)` with positive integer calendar IDs and an increasing date window. The client requests tags, validates nested occurrence fields/dates, collapses identical duplicate UIDs and rejects conflicts or detectable partial/paginated responses. Returned arrays are transient API data: callers must not persist or render them wholesale. Publication filtering, allowlisted mapping and local date-range filtering belong to later steps. Returned API bounds can overlap; this client does not claim a deletion-safe snapshot or prove access/completeness from an empty result. No pagination protocol has been verified, so an explicit pagination envelope fails rather than silently dropping pages.

## Optional explicit token setup

**JZM already has a token: do not run setup there to replace it.** Normal requests only use the configured token. For a new installation with configured setup credentials:

```sh
php bin/console church-tools:setup-token --output=/private/secrets/churchtools-token
```

Use DDEV to run this command locally. Supply only a destination path as an argument, never credentials or tokens. The existing parent directory should be private and outside the web root/version control. The command logs into the configured account, checks `/api/whoami`, retrieves that account's `/api/persons/{id}/logintoken`, and verifies the same identity with a token-only request without the session cookie. ChurchTools may create a token on retrieval if none exists. This flow is explicit and never part of ordinary reads.

Only after verification does the command create the selected **new** raw-token file with mode `0600`; existing files and symlinks are refused. It never prints a secret or edits deployment/environment configuration. Configure the file reference separately, for example:

```yaml
church_tools:
  token: '%env(file:CHURCH_TOOLS_TOKEN_FILE)%'
```

Set `CHURCH_TOOLS_TOKEN_FILE` to the absolute path. Remove setup credentials when no longer needed. Rotation/revocation in ChurchTools remains an operator action; this package does not revoke or overwrite tokens. Keep the token file and compiled container/cache inaccessible to web visitors.

## Tests and compatibility

The CI workflow creates independent managed Contao installations for `5.7.*` and `6.0.*` on PHP 8.4, resolves/installs dependencies, checks platform requirements, verifies bundle discovery and a real container boot, then runs the controlled client/setup/configuration suite against each target's dependencies. It needs no ChurchTools credentials. Storage acceptance uses a separate MariaDB database and target-matching calendar bundle; it never runs host migrations.

To reproduce one target inside DDEV, choose a new container directory:

```sh
ddev exec php /home/dev/Kunden/github/contao-churchtools/tools/create-matrix-project.php '6.0.*' /tmp/churchtools-matrix-example
ddev exec bash -c 'cd /tmp/churchtools-matrix-example && composer update --no-interaction --prefer-dist && composer check-platform-reqs && composer install --no-interaction && php matrix-boot.php && vendor/bin/phpunit --bootstrap vendor/autoload.php --configuration /home/dev/Kunden/github/contao-churchtools/phpunit.xml.dist'
```

Use `5.7.*` for the other target. The generator refuses to replace an existing Composer project. Resolution intentionally follows current compatible releases; retain generated lockfiles when reproducing an exact dependency set. Runtime tests use the target installation's autoloader, not the bundle's development vendor tree. Package-only tests can also run after `composer install` in the bundle checkout, always through DDEV locally.

See [step 2 validation](.docs/church_tools/step-2-validation.md) for actual versions, commands and limits. PHP 8.5+, hosted GitHub Actions, future feature behaviour and residual step 1 API cases are not claimed as tested.


## Local storage (step 3)

Use `ChurchToolsArchiveModel` to create archives and `setCalendarIds([110])` before an explicit `save()`. `ChurchToolsEntryModel::saveSource($archiveId, $row)` accepts one public occurrence in the existing client response shape and preserves locally assigned Contao event IDs on updates. It does not fetch, synchronize, reconcile absence or write core events. All source fields pass through a strict allowlist.

See [the implemented schema/date contract](.docs/church_tools/architecture.md#implemented-step-3-storage-contract--2026-09-17) and [reproducible database acceptance](.docs/church_tools/step-3-validation.md). In particular, UIDs are byte-exact with a 2048-byte supported-input limit (larger values fail, never truncate); all-day ends remain inclusive calendar dates. Timed source strings retain offsets and up to six fractional digits. No optional location/category/deep-link mapping has been assumed.


## Synchronization (step 4)

```sh
php bin/console church-tools:sync
php bin/console church-tools:sync --archive=1
```

CLI and `#[AsCronJob('hourly')]` use the same service. CLI emits archive IDs, fixed diagnostics and counters, never appointment texts or credentials. Exit 0 means every selected archive succeeded (or none exist); invalid IDs return 2, failure/missing/locked archives return 1. Cron logs the same summaries. One archive failure does not undo other successful archives.

The default window runs from UTC midnight one calendar month ago to UTC midnight six calendar months ahead, with month-end clamping and an exclusive upper bound. `sync.past_months` accepts 0–120 and `sync.future_months` 1–120. All-day end dates are inclusive, timed ends exclusive; zero-duration instants at the lower bound are retained.

Per-archive DB advisory locks require MariaDB/MySQL and the same writable primary connection for every worker. Validated source changes and success metadata commit in one database transaction through the existing models. Synchronous pre-commit cache dispatch errors roll both back. Invalidation repeats after commit to address premature cache eviction; failure of that second pass is reported as `committed_cache_error` while preserving the committed removal count. External/deferred cache delivery is separate from database atomicity. There are still exactly two extension tables; `lastSyncPastMonths` is one nullable smallint on the archive to distinguish natural expiry from a shortened retention configuration. Review this additive schema change before using the synchronizer; the JZM host schema was not changed by development validation.

Discovery is checked before and after retrieval. Each selected calendar must return a nonempty full-window snapshot matching the union of 31-day requests. HTTP errors, detectable partial/paginated responses, conflicting identities, missing calendars and completely empty calendar windows fail the whole archive without reconciliation. An empty calendar is deliberately **not** treated as deletion authority: the final disappearance of all appointments needs a future verified API access/completeness contract or explicit archive deselection. Deselecting all calendars is an explicit local cleanup and requires no API call.

Retention, changed-window cleanup, deselection and internal visibility never increment the linked source-removal notice. Series splits can regenerate UIDs: affected associations are lost and must be restored manually; core events remain untouched. There is no heuristic reassignment.

Limits and failure boundaries, cache tags for future frontend consumers, matrix results and aggregate live evidence are documented in [step 4 validation](.docs/church_tools/step-4-validation.md). Hidden server caps or silently restricted subsets remain an API risk; the full/chunk comparison is evidence, not an invented API guarantee.

## Backend (step 5)

Open **ChurchTools → Events / Termine**. The archive list shows synchronization status and removal notices; create/edit an archive to choose source calendars. Its appointments action opens the synchronized, read-only list with a source-calendar filter and information view (source metadata, tags and link status).

Calendar discovery never starts synchronization or token setup. Stored IDs stay selectable during API failures, with ID labels when names are unavailable. Saving preserves them; explicitly unchecking calendars removes them from the selection. An entirely empty remote calendar window still stops synchronization conservatively, including deletion of its last occurrence. The backend explains this limit.

For non-admin users, grant the ChurchTools Events backend module to allow reading. Grant the core archive create/update operations and the archive name/calendar fields separately to allow configuration. A user with only the module permission can read; no custom archive-rights system is introduced. Source fields, sync status and Contao event references are read-only, even for admins. Missing Contao target IDs remain stored. References are changed only through the dedicated step-6 actions below.

See [step 5 validation](.docs/church_tools/step-5-validation.md) for the real authenticated Contao 5.7/6 HTTP tests, controlled outage tests, permission checks and test-only core schema setup. These tests do not migrate or deploy the host installation.


## Event actions and effective data (step 6)

In the appointment list, **Contao event link / Contao-Termin verknüpfen** opens a dedicated form to link an authorized existing event or create an unpublished event in an authorized calendar. Existing links offer explicit unlink and, when permitted, opening the core event editor. Unlink preserves the core event and its details. Missing IDs remain stored, and create never replaces an existing reference. Unlink first to select a different target.

Grant non-admin editors the separate core allowed-field permission **Appointments → Contao event ID** (`tl_church_tools_entry::contaoEventId`), in addition to ChurchTools module access. They also need the Calendar module, the relevant allowed calendars and core event create/update permissions. Creating a description requires content create permission and access to the Text content element and text field. Source fields stay read-only. Target choices and open links respect record permissions; forged IDs are rechecked on POST. All mutations require Contao CSRF validation.

A copied description becomes safely escaped teaser and reader text; HTML, Markdown and insert tags remain literal. Core 6 creation rejects source titles containing `{{` explicitly because the native event list interprets insert tags; source data stays unchanged. Core 5 retains encoded title copying. Events start unpublished, nonrecurring and use Core's numeric-ID URL fallback until an editor supplies an alias. The target calendar's reader page controls the public URL. No location/category/image/link is inferred. After editing and publication, the event is a normal independent core event.

Contao stores seconds and interprets equal timed endpoints as open-ended. Creation therefore explicitly rejects nonzero microseconds, equal timed endpoints and dates outside Core's representable range, rather than changing their meaning. The source stays intact. All-day ends remain inclusive, including DST days; timed offsets are converted to the initialized Contao timezone for local date fields.

`EventIntegration\DisplayResolver` resolves retained selected-archive rows before deduplication, inclusive period filtering and sorting. Only currently published, visitor-accessible targets with a usable destination supply editorial data and a link. Unpublished, restricted, missing or unusable targets fall back to source data without a target link. Restoring the same ID reactivates the association. No core recurrence expansion or visitor result cache is used.

See [step 6 validation](.docs/church_tools/step-6-validation.md) for exact local evidence, the real backend/frontend login and core-reader tests, concurrency/rollback checks and remaining limits. No host migration or deployment is implied.

## Frontend content elements (step 7)

Add **ChurchTools appointment list** or **ChurchTools month calendar** as ordinary content elements and select one or more existing ChurchTools archives. The list starts today and shows seven local days by default; editors can set 1–366 days. It groups appointments by day and lists a multi-day occurrence once. The Monday-first calendar shows each day of a multi-day span and offers previous/next month links as its only visitor control. Its navigable range runs from the earliest to the latest effective local month visible to the current visitor, with the current month included as an anchor. Empty months within that range remain traversable; an empty archive has no month links. Valid requests outside the range clamp to its edge, and invalid values return to the current month. Both use the same resolved, deduplicated ChurchTools occurrences. Independent Contao events never enter these views. A visible linked Contao event supplies its editorial title, dates, teaser and normal detail link; otherwise the source appears without a detail link. The optional source URL is not shown.

The default Twig templates are `@Contao/content_element/church_tools_list.html.twig` and `@Contao/content_element/church_tools_calendar.html.twig` and can be overridden through Contao's managed template mechanism. Bootstrap 5.3-compatible classes are used. Source descriptions remain escaped plaintext; linked editorial teasers use Contao's HTML sanitizer without executing ChurchTools insert-tag text. Month navigation preserves unrelated query parameters and uses a per-element key.

Pages containing either element respond with `private, no-store` even if Contao page caching is configured. This is necessary because linked target visibility depends on the current frontend member, publication minute and current editorial state. Expect those pages to render on each request. The two new fields are on `tl_content`; review the schema preview before applying them in an installation. No third ChurchTools table, host-page migration or frontend module is created. See [step 7 validation](.docs/church_tools/step-7-validation.md) for exact matrix, HTTP, screenshot and remaining acceptance limits.
