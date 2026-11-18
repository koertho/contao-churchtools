# Contao ChurchTools — Calendar V2

Imports ChurchTools appointments directly into native Contao calendar archives and events. Requires PHP 8.4+, Contao **5.7 or 6**, and MariaDB/MySQL for connection-scoped synchronization locks. The root namespace is `Koertho\ContaoChurchTools`.

ChurchTools owns title, dates, times, location and base description. Contao owns editorial teasers, images and content elements. Every calculated occurrence is a separate native event; Contao recurrence is disabled on imported events.

## Installation and configuration

Require `koertho/contao-churchtools` through your Composer repository and use the normal Contao setup/cache procedure. The Manager plugin registers the bundle. Review the schema update before applying it: V2 adds fields to `tl_calendar` and `tl_calendar_events`, plus `tl_ct_series_templates`.

```yaml
church_tools:
  instance_url: 'https://your-instance.church.tools'
  token: '%env(CT_TOKEN)%'
  sync:
    past_months: 1
    future_months: 6
```

Environment names are application mappings, not built into the package. An unconfigured installation can boot; requests require a valid HTTPS origin and login token. Use a technical CT account with the required calendar read permissions. The extension sends `Authorization: Login <token>` and never writes appointments to CT.

On a native Contao calendar archive, optionally select **one** ChurchTools calendar. Each source calendar can be assigned only once throughout the installation, enforced by a nullable unique database index. Stored selections remain available during API outages. Native calendars without an assignment continue to work normally.

## Editorial workflow

Imported appointments are native Contao events. Their source fields and sync identifiers are protected; copying, moving and deleting imported events are blocked, including direct backend actions. Archives containing imports are also protected against destructive copy/delete operations. Editors can change editorial fields and use native publication controls to hide individual events.

The **ChurchTools series** backend module contains automatically created series templates. Grant that module and the usual field/content-element permissions to editors. Templates provide a shared teaser, full native image configuration and arbitrary Contao content elements. Templates cannot be created/copied manually or deleted while any event references them, including historical or unpublished events.

- Reader content: individual event content first, then series content.
- Teaser: individual event teaser, otherwise series teaser, otherwise CT description.
- Image: a valid individual event image, otherwise the series image and its settings.
- CT descriptions are literal plaintext with preserved line breaks. Source HTML, Markdown and insert tags are not executed.

Use native Contao event lists/calendars. Select **`event_list_churchtools`** for lists to apply the full inherited image configuration; select **`event_full_churchtools`** for the event reader. It uses the same resolver as the list hook and remains overridable through Contao's managed Twig templates. A custom Twig event template can call `churchtools_event(_context)` to obtain resolved data; `details` and `hasDetails` are lazy closures. Custom templates that bypass this integration must opt into the resolver themselves. Individual reader images retain the native module image-size override. An inherited reader image uses the series size; a derived Twig template can explicitly override it with `churchtools_event(_context, [640, 360, 'crop'])`, because the native reader does not expose its module size in the event context.

A known occurrence retains its Contao ID and individual content while its calculated UID survives. If CT detaches a single occurrence and keeps that UID, its previous series template remains assigned. A newly imported standalone occurrence has no inferred former series. CT series splits can regenerate UIDs: old events are unpublished within the active window, new events are imported, and a warning identifies old events with individual content. There is no heuristic transfer of content to new identities.

## Synchronization and retention

```sh
php vendor/bin/contao-console koertho:churchtools:calendar:sync
php vendor/bin/contao-console koertho:churchtools:calendar:sync --archive=1
```

CLI and the Contao cron job (`*/15 * * * *`) use the same synchronizer. Configure the normal Contao cron invocation on the server. Output/logs contain local IDs, fixed diagnostics and counts, never tokens or raw responses. Invalid archive input returns exit 2; failed/missing/locked archives return exit 1.

Each archive is locked independently without waiting. A validated full/chunk snapshot is mapped before transactional writes; changed selections are rechecked under lock. One archive's failure does not undo another archive's success. An invalid, failed, partial or ambiguous response never authorizes absence cleanup. Completely empty calendar windows are conservatively rejected because access loss cannot reliably be distinguished from deletion of the final appointment.

Unchanged occurrences update only their sync timestamp, without changing event `tstamp` or invalidating content cache. New/changed/unpublished/reactivated events and changed series titles invalidate native cache tags after commit. A post-commit cache failure is reported separately; committed data remains committed. Restarting/retrying alone does not guarantee delivery of a previously failed invalidation: perform the normal Contao cache purge when resolving that condition.

Manual publication choices survive synchronization. A reappearing event is automatically published only if the sync had unpublished it. A manual publication action clears that marker.

The configurable import window defaults to one calendar month back and six months ahead, with month-end clamping and explicit local overlap filtering. Outside the active window, already imported events remain stored and keep their publication state; they are no longer reconciled with CT. Removing/replacing the source selection unpublishes the old source events within the active window while preserving their content and historical records. Old events and series templates are never automatically deleted.

The mapper uses calculated occurrence dates, the initialized Contao timezone, inclusive all-day end dates and native second-resolution fields. Unrepresentable dates (including nonzero fractions and equal timed endpoints) fail the archive instead of silently changing meaning. Under Contao 6, titles containing `{{` fail safely because native lists interpret insert tags. Oversized/empty titles likewise fail without partial imports.

## Optional token setup

Normal requests use only the configured token. The existing explicit setup command remains available for a new installation with optional `church_tools.setup.username/password` configuration:

```sh
php vendor/bin/contao-console church-tools:setup-token --output=/private/secrets/churchtools-token
```

It verifies the account and token, creates a new private file, refuses existing files/symlinks and never prints a secret. Do not run this to replace an already configured token. A token file can be mapped with `%env(file:CHURCH_TOOLS_TOKEN_FILE)%`.

## Upgrade from the previous prototype

V2 is a fresh architecture without automatic data migration. The former CT archive/entry tables, linking actions and custom CT frontend elements are no longer registered. Their database records are not automatically removed. Before updating an existing site, replace its old CT elements, configure native calendar archives and readers, and review both schema additions and any proposed removal of legacy tables. Keep a backup if retaining old editorial associations is needed.

## Development and verification

Local PHP/Composer commands run through DDEV project `/home/dev/Kunden/privat/jzm-contao`. Tests use independent Composer projects and dedicated `churchtools_v2_*` databases, never the host schema or live source mutations.

```sh
ddev exec php /home/dev/Kunden/github/contao-churchtools/tools/create-matrix-project.php '5.7.*' /tmp/churchtools-v2-example
# In the isolated project: composer update; php matrix-boot.php
# Set CHURCHTOOLS_TEST_DATABASE_URL to a dedicated churchtools_v2_* database.
# Preview: php tools/calendar-test.php <isolated-project>
# After review: php tools/calendar-test.php <isolated-project> --apply
# Authenticated backend: php tools/backend-http-test.php <isolated-project>
# PHPUnit: <isolated-project>/vendor/bin/phpunit --bootstrap <isolated-project>/vendor/autoload.php --configuration phpunit.xml.dist
```

Repeat against `6.0.*`. The schema runner creates missing tables only in the explicitly selected test database and refuses arbitrary alterations/drops. See [V2 validation](.docs/church_tools/v2-validation.md) for actual evidence and remaining limits, and the [implementation contract](docs/superpowers/plans/2026-11-17-calendar-v2.md). Earlier step documents describe the superseded prototype and retain the original API evidence.
