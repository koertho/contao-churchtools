# ChurchTools extension

Planning baseline: 2026-09-12, consolidated with the subsequent user review decisions. Step 1 API investigation started on 2026-09-14. Step 2 implementation and local acceptance passed on 2026-09-17; hosted CI is unverified.

| Item | Value |
| --- | --- |
| Composer package | `koertho/contao-churchtools` |
| Package directory | `/home/dev/Kunden/github/contao-churchtools` |
| Supported targets | PHP 8.4+, Contao 5.7 and 6 |
| Development environment | DDEV project `jzm` |
| Instance | <https://jzm.church.tools/> |

## Documents

- [Requirements](requirements.md): confirmed scope, proposed defaults and exclusions.
- [Architecture](architecture.md): package boundaries, storage, synchronization and presentation rules.
- [API contract investigation](api-contract.md): authentication evidence, current access blockers and remaining live checks.
- [Implementation steps](steps.md): ordered work, acceptance criteria and progress checklist.

The package is developed in its own directory outside the host project. The extension must remain independently installable; application-specific styling belongs in the host project.

These documents authorize no implementation status claims. Mark a step complete only after recording its actual validation results and limitations. Keep requirements, architecture and steps consistent as decisions change.

## Reference material

- [Instance API documentation](https://jzm.church.tools/api)
- [Existing day-grouped list](https://jz-meissen.de/): “Kommende Woche...”
- [Existing month calendar](https://jz-meissen.de/termine)
- [ChurchTools appointment tags](https://churchtools.academy/en/help/churchtools-modules/create-administer-calendars/how-to-manage-appointment-tags/)
- [ChurchTools calendar categories](https://churchtools.academy/en/help/churchtools-modules/create-administer-calendars/what-calendar-categories-are-there/)
- [ChurchTools OAuth documentation](https://churchtools.academy/en/help/system-settings/oauth-login-systemsettings/oauth-authentication-with-churchtools/)

Prior discussion established the visual baseline and reviewed general ChurchTools documentation. Core payloads, token authentication and occurrence transitions have since been investigated; remaining limits are listed in api-contract.md. General documentation is not evidence of the exact API contract available on this instance.

## Review decisions incorporated

The extension keeps dedicated appointment storage and provides two content elements for ChurchTools appointments only. Existing core modules and importer cutover remain separate project work. Version one has no images, separate taxonomy tables or durable link mapping system: metadata is an appointment blob and the Contao link is a nullable event ID on that entity. Linked presentation is resolved in PHP without expanding core recurrence.

Keep Archives → appointments inside the Events backend module. Show last successful sync and latest error only in the backend. Establish the compatibility matrix in step 2. The installed ChurchTools SDK is research material only; leave the existing test controller untouched. This directory remains the documentation home. See requirements and architecture for the full rules and accepted limitations.

## Storage simplification

Use exactly two extension tables: archive and entry (`pid` parent relationship). Calendar selection and synchronization status live on archives; calendar metadata, taxonomy blobs and the nullable Contao event ID live on entries. Occurrences are unique per archive, not globally. Overlapping archives have independent entries/links, with frontend deduplication by remote occurrence identity. No auxiliary calendar, assignment, taxonomy, sync-state or link tables.

## Second review decisions

Only published Contao targets currently visible to the visitor override ChurchTools data. Otherwise keep the source listing without a Contao link, retaining missing target IDs for possible restoration. For duplicate occurrences, prefer a qualifying Contao target, then the lowest archive ID; without one, show source data once. Load all retained archive entries before PHP date filtering.

Keep stored calendar selections visible and intact during API outages. Store a simple linked-entry removal count on the archive for cancellation/source-deletion reconciliation in the last successful sync and show a backend notice when nonzero; exclude retention/configuration cleanup. The two-table design remains unchanged.

## Current API investigation status

As of 2026-09-15, the core API contract is consolidated and reflected in requirements/architecture: UID identity, controlled move/deletion, tags and multi-day semantics have evidence. See the opening summary in `api-contract.md`. Step 1 retains explicit residual checks and is not reported as fully passed; step 2 is now implemented and locally validated (see [validation record](step-2-validation.md)).

## Schritt 2 starten

Der eigenständige Bundle-Pfad und Composername wurden auf Benutzerwunsch geändert. Das Host-Projekt bleibt DDEV `jzm`; die lokale Integration benötigt einen passenden DDEV-Mount und Composer-Path-Repository.

Siehe [Prompt für Schritt 2](step-2-prompt.md). Alle Planungsdokumente und Test-Fixtures befinden sich jetzt ausschließlich in diesem Bundle-Verzeichnis.

## Step 2 implementation

See the [package README](../../README.md) for installation/configuration and [validation record](step-2-validation.md) for the tested Contao 5.7/6 environments. Step 3 storage is now implemented and locally validated; see [its validation record](step-3-validation.md). Step 4 synchronization is also implemented and locally validated; see [its validation record](step-4-validation.md). Step 5 backend management now passes local authenticated HTTP acceptance on both targets; see [its validation record](step-5-validation.md). Event actions and frontend elements remain pending.

## Schritt 3 starten

Siehe [Prompt für Schritt 3](step-3-prompt.md): genau zwei Tabellen, DCA, Contao-Modelle und Speicherprüfungen. Synchronisierung und vollständige Backend-Verwaltung folgen in späteren Schritten.
