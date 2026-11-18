# Calendar V2 validation — 2026-11-18

The implementation replaces the prototype's CT archive/entry/link model with direct native event imports and shared series content. All database tests use explicitly isolated databases. No host database migration, live appointment mutation, commit, push or deployment was performed.

## Executed matrix

| Check | Contao 5.7.17 | Contao 6.0.6 |
| --- | --- | --- |
| PHP / Symfony / DBAL | 8.4.27 / 7.4.23 / 4.6.0 | 8.4.27 / 8.1.11 / 4.6.0 |
| Complete PHPUnit suite | 107 tests / 326 assertions, PASS | 107 tests / 326 assertions, PASS |
| Real backend schema/actions/versions | 42 checks, PASS | 42 checks, PASS |
| Native frontend modules/rendering | 28 checks, PASS | 28 checks, PASS |
| Authenticated backend HTTP / login / CSRF / real POST | 107 checks, PASS | 107 checks, PASS |
| Manager discovery / container / setup and sync command input | PASS | PASS |
| Container lint / Twig lint | PASS / 2 templates | PASS / 2 templates |
| PHP syntax | 91 files, PASS | Same source |
| Composer manifest / diff whitespace | PASS | Same source |

The projects are `/tmp/churchtools-v2-57-current` and `/tmp/churchtools-v2-60` **inside DDEV jzm**, using `churchtools_v2_57` and `churchtools_v2_60`. The earlier `/tmp/churchtools-v2-57` directory was abandoned during the namespace change and is not the verified project.

Reproduce from host `/home/dev/Kunden/privat/jzm-contao`, replacing `60` / the project path for 5.7:

```sh
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_v2_60 php /tmp/churchtools-v2-60/vendor/bin/phpunit --bootstrap /tmp/churchtools-v2-60/vendor/autoload.php --configuration /home/dev/Kunden/github/contao-churchtools/phpunit.xml.dist
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_v2_60 php /home/dev/Kunden/github/contao-churchtools/tools/calendar-test.php /tmp/churchtools-v2-60 --apply
ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_v2_60 php /home/dev/Kunden/github/contao-churchtools/tools/backend-http-test.php /tmp/churchtools-v2-60
```

For a new isolated project, use `tools/create-matrix-project.php`, install its Composer dependencies, create a dedicated `churchtools_v2_*` database, and preview `tools/calendar-test.php <project>` before `--apply`. The runner only creates missing tables; schema drift fails rather than applying arbitrary changes. The sole incremental test-schema change during development was the newly added nullable `ct_sync_counts` column, corrected to Doctrine's MariaDB LONGTEXT representation. Host schema was not touched. Strict comparison covers the extension-modified calendar/events/series tables; native `tl_content.jsonData` is a MariaDB JSON alias introspected as LONGTEXT, unrelated to this extension.

## Behaviors actually exercised

- API transport authentication/error redaction, malformed/partial responses, snapshot full/chunk agreement, disappearing discovery, ambiguous/empty snapshots, UID length/conflict limits.
- Real temporary-table SQL: insert, unchanged/hash updates, manual hidden events, hash-independent sync reactivation, internal/removal/deselection cleanup, historical and manual retention, assignment race, series split, stable aliases, invalid late mapping rollback and post-commit cache failure.
- Date-only inclusive all-day ends, multi-day overlap, local midnight, 23/25-hour DST days, repeated offset clocks, native timestamp range and unsupported fractional/equal timed values.
- Real schema uniqueness and nullable calendar assignments; forgery/action voters, divergent clipboard vs selection, save-callback preservation and author handling; **native Versions::restore()** preserving sync/source fields while restoring editorial data; native operation methods/permissions; nested series permissions.
- Publication marker deferred until a successful actual publication change, not teaser-only/invalid form handling.
- Authenticated loopback backend HTTP: real admin login and CSRF, imported edit form GET/POST with native successful controls, teaser save with author0, unchanged repeated-DST clock instants and stable alias, invalid form marker preservation, native eye publication/hide, correctly formatted source-date tampering, and direct copy/delete/cut denial. HTTP fixtures/config/session files are removed in finally; no production route or controller is introduced.
- Two independent DB connections verify the shared archive advisory lock excludes sync while editor source snapshots are pending; series-row locks exclude relinking during orphan deletion.
- Real ContentModel/Controller text-element rendering, unpublished/protected/future content exclusion, native event generator plus registered hook, actual ModuleEventlist and ModuleEventReader output, DOM-level literal special characters, real image and caption fallbacks, module image dimensions, and unchanged manual-event content.
- Lazy content and per-main-request cache reset; series response tags even when empty, nested child-parent tag traversal, old/new series invalidation for content copy/cut; reader root sitemap invalidation on sync changes, none for unchanged events.
- Native acceptance cleanup checks verify no remaining rows in calendar/events/series/content/page/files/version fixture tables.

## API evidence and deliberate limits

The current public [instance OpenAPI](https://jzm.church.tools/system/runtime/swagger/openapi.json) was read without credentials. `AppointmentBase.address` is a nullable object. Only `name`, `street`, `zip`, `city`, `addition`, `country` are mapped; geo/domain/private metadata is excluded. `repeatId` values are `0,1,7,31,32,365,999`, including manually selected additional dates. Actual UID transition evidence is preserved in the [historical API investigation](api-contract.md) and before/after JSON fixtures.

There was no authenticated live sync or permission-change experiment in this implementation run. Completely empty windows remain ambiguous and do not authorize absence cleanup. Existing live permission-loss testing remains explicitly skipped. UID continuity is demonstrated for a particular detached move, not guaranteed for all CT edits; series splits can regenerate every UID and require editorial reassignment.

Contao 6 native event lists interpret insert tags in source titles; titles containing `{{` are rejected without partial archive writes. Native second-resolution limits, unsupported equal timed endpoints, empty/oversized titles and field-length constraints also fail safely. No source title/description is interpreted as HTML/Markdown.

Use `event_list_churchtools` for full inherited list image settings and `event_full_churchtools` for readers. The native list reloads event models for image metadata, so the hook alone cannot supply all inherited image settings. Own reader images keep native module sizing; inherited reader images default to the series size because the native reader does not expose its module size to the event template. A derived Twig template can pass `churchtools_event(_context, [width, height, mode])` explicitly. These are documented integration requirements, not silent global model changes.

Invalidation failure after commit cannot roll back committed content or guarantee later delivery. Its status retains success counts and calls for a cache purge/retry. No durable outbox is implemented. HTTP-cache behavior in the production hosting topology and visual project-template/browser acceptance remain deployment checks.

## Review-Nachprüfung

- Contao 5.7: vollständige Listen- und Leser-Ausgabe erneut durch den Insert-Tag-Parser geführt. Titel und Ort mit `{{env::host}}` bleiben im DOM wörtlich erhalten, ohne HTML auszuführen.
- Contao 6: nicht wörtlich darstellbare Insert-Tags in CT-Orts- und Adressfeldern werden wie bereits bei Titeln vor dem Schreiben zurückgewiesen. Bereits gespeicherte CT-6-Ortsdaten werden dadurch nicht rückwirkend bereinigt.
- Zwei Datenbankverbindungen prüfen, dass POST und native Augen-Aktion die Schreibsperre halten, ein API-Abruf redaktionelles Speichern nicht sperrt und konkurrierende Sync-Abrufe serialisiert bleiben. Beide Schreibseiten warten höchstens fünf Sekunden.
- Nicht darstellbare Termine melden Kalender-ID, Termin-ID, UID-Hash und einen festen Grund; Titel und Beschreibung gelangen nicht in die Fehlermeldung. Das Archiv wird weiterhin vollständig zurückgerollt, um Abwesenheit nicht fälschlich als Löschung zu interpretieren.
- Der frühere CLI-Befehl ist wieder als Alias registriert. Das Contao-Model folgt der Tabellenbenennung; Kalenderauswahlfehler sind übersetzt. Die native Bildverlinkung der Serienbilder wurde in der tatsächlichen Eventliste geprüft.
- Lokal: 111 PHPUnit-Tests auf 5.7 (ein gezielt übersprungener Contao-6-Test) und 6.0, 71 native Abnahmeprüfungen und 107 authentifizierte Backend-HTTP-Prüfungen je Version. Der JZM-Sync meldete anschließend 125 unveränderte Termine.

### Accepted remaining security boundary

The project explicitly retains the decision not to use a generic `parseTemplate` hook. Therefore the mitigation covers the bundled Twig templates and new imports. Existing pre-validation Contao 6 location/address values in native or third-party templates, are not fully remediated. Contao 5.7’s native page-title decoder escapes insert-tag braces; this behavior was verified separately. Existing HTTP-cache entries must be purged. A count-only audit of the local JZM database found zero imported title or location/address fields containing literal or encoded opening braces; this is a local observation, not a guarantee for other installations.

The final source-title policy retains literal, encoded brace pairs on Contao 5.7 and rejects them on Contao 6. The native Contao 5.7 `HtmlDecoder::inputEncodedToPlainText` escapes those braces before the reader sets the HTML head title; an additional integration assertion exercises this exact conversion. Final PHPunit: 112 tests on both versions; two Contao-6-specific mapper checks are skipped on Contao 5.7. Native integration: 72 checks on 5.7 and 71 on 6.0.
