# Step 7 validation

Date: 2026-09-25. Scope: the standalone bundle's two frontend content elements in isolated Contao 5.7.13 and **6.0.0** matrices. No host database, host pages, live ChurchTools API, deployment, commit or push was used.

## Implementation

- `church_tools_list` and `church_tools_calendar` are registered with `#[AsContentElement]`, use two managed overridable Twig templates and select existing archives through two new `tl_content` fields. There is no new ChurchTools table, frontend module, source-calendar/tag filter or independent core-event query.
- Both pass the complete selected archive set to the existing resolver. The list requests its configured period, defaults to seven local days and emits one row per occurrence. The calendar calls the resolver once across local years 1000–9999, derives bounds from its effective starts/ends plus the current month, and only then selects overlapping rows for the displayed month. This preserves the resolver's UID winner and visitor-visibility decisions without another core query. The Monday-first grid repeats multi-day spans on overlapping days, including cross-month spans. Empty months inside the bounds remain traversable; valid out-of-range GET months clamp, invalid values fall back to the current month, and no results yield no links. Month GET keys are per content element and safely rebuilt with unrelated parameters.
- Frontend descriptions are escaped plaintext for source rows. Visible core editorial teasers pass through Contao's `sanitize_html('contao')` filter without insert-tag processing. Only validated effective core detail links are rendered. Source URL is not used.
- Controllers and the final main-response listener force `private, no-store` to prevent shared or client caching of member- and minute-dependent content. Core 5.7.13 and 6.0.0 were inspected for content-element attributes, fragment response merging, page shared-cache headers and member-private response handling.

## Matrix and schema

The isolated projects are `/tmp/churchtools-matrix-57` and `/tmp/churchtools-matrix-60` inside DDEV project `jzm`, using only `churchtools_step3_57` and `churchtools_step3_60`. Composer installed 5.7.13; 6.0 was explicitly pinned from the `6.0.*` update to **6.0.0**. Both run PHP 8.4.24 with DBAL 4.4.4 and MariaDB 10.3.39.

`contao:migrate --schema-only --dry-run` was reviewed before the only schema writes. It proposed `churchToolsArchives LONGBLOB DEFAULT NULL` and `churchToolsDays SMALLINT UNSIGNED DEFAULT 7 NOT NULL` on `tl_content`, along with unrelated missing core tables and a `jsonData` change. Only the two reviewed columns were applied in the two isolated databases. No broad migration or host schema change was run.

## Tests and HTTP evidence

| Check | 5.7.13 | 6.0.0 |
| --- | ---: | ---: |
| PHPUnit, including view grouping/DST/month-boundary/bounded-navigation/unsafe-link tests | 99 tests, 266 assertions | 99 tests, 266 assertions |
| Backend, resolver and frontend HTTP harness | 553 checks | 557 checks |
| Storage acceptance | 83 checks | 83 checks |
| Sync acceptance and real-commit regression | 65 + 45 checks | 65 + 45 checks |
| Event transaction regression | 26 checks | 26 checks |

The HTTP harness registers an isolated frontend route and creates a real temporary Contao root, page, theme/layout, article and both content elements. It renders the element fragments and a complete frontend page. The page is intentionally configured for a 3600-second shared cache with `alwaysLoadFromCache`, yet its actual main HTTP response has `no-store`. All fixtures, including member accounts, page/layout/theme, article/content, archives, entries and core target, are removed in `finally`.

Repeated requests to the **same full page** run anonymously, then as a member without target-calendar rights, then as a member with rights, then anonymously again. The body changes as expected and every response remains `no-store`; no target title or teaser appears in the unauthorized responses. The same page reflects changes to the isolated core target row (title edit and deletion), source upsert plus unlink; fragment HTTP additionally covers relink, restoration under the same ID, future/current-minute publication starts and current-minute stops. These step-7 mutations use direct isolated database writes between actual HTTP requests; the step-6 suite separately covers real core-editor publication. The harness checks that an independent core event does not appear, the resolver's visible-target winner appears once in the list, source HTML and insert-tag syntax remain literal, editorial rich text is sanitized, and multi-day calendar cells are populated.

Follow-up HTTP fixtures cover an empty archive, current-month-only data, past/future retained dates with an empty traversable month between, a span across a local month edge, early/late valid clamps and invalid-month fallback. A linked target moved four months beyond its source expands the last navigable month only for an authorized member. Anonymous and a second member remain at the source-date bound; a repeated anonymous request to the same full page has no authorized member's month link or target title. The frontend view unit suite tests both bounds, empty results, gaps, and multi-month span placement. The 5.7/6.0 dry-run schema previews were reviewed again before the regression suites: they contained only pre-existing missing core tables and `tl_content.jsonData`, no new bundle columns; no schema apply was run in this follow-up.

The existing resolver suite continues to cover lower-archive tie-breaking before period filtering, effective-date moves, reader destination rights, page/article/calendar protection, missing targets and recurring core events without expansion. The frontend unit suite covers local-day grouping, a point occurrence, DST day, two cross-month spans, Monday-first cell placement, safe navigation parameters and URL rejection.

## Visual review and limits

Headless Chrome screenshots at 1440 px and 390 px were refreshed from synthetic HTML captured from the isolated **real frontend HTTP output**, with the local Bootstrap 5.3/project stylesheet. They were inspected visually: [desktop](step-7-desktop.png), [mobile](step-7-mobile.png). The captured archive has appointments only in the current month, so the refreshed screenshots correctly show no previous/next links. The mobile template shows only event-bearing days while retaining the full month grid at desktop width; titles and dates remain readable without horizontal clipping. Bounded links in other months were verified through HTTP markup, not interactively in this browser snapshot. This snapshot is not a browser request to the temporary Contao page. Live interactive browser acceptance against that page or the public reference pages is therefore **not complete**.

The local HTTP evidence proves the generated page advertises `no-store` and repeated kernel requests do not reuse another member's data. It does not test an external reverse proxy, a browser cache implementation or a live deployment. An HTTP cache that ignores `no-store`, or an already cached page from before adding an element that Core has not invalidated, remains outside this test. Core sync/link cache-tag behavior remains covered by the earlier suites, while these views avoid response caching entirely. No actual remote ChurchTools call was made.

## Reproduction and final audit

Run PHP, Composer and tests only through DDEV project `/home/dev/Kunden/privat/jzm-contao`. The HTTP suite is `ddev exec env CHURCHTOOLS_TEST_DATABASE_URL=mysql://root:root@db/churchtools_step3_57 php /home/dev/Kunden/github/contao-churchtools/tools/backend-test.php /tmp/churchtools-matrix-57`; substitute `60` for `57`. The complete PHPUnit suite uses each matrix's `vendor/bin/phpunit` with this repository's `phpunit.xml.dist`. Review `contao:migrate --schema-only --dry-run` before adding missing columns in a fresh matrix. `tools/storage-test.php --apply`, `tools/sync-test.php` (including real-commit regression) and `tools/event-test.php` passed on both matrices in this follow-up. `tools/event-audit.php` confirmed **zero fixture rows, zero triggers and exactly the two extension tables** in both databases after these regressions. Production container/Twig lints, PHP syntax, Composer platform checks and `git diff --check` passed in the initial Step 7 acceptance; follow-up checks are recorded below. Existing step-6 reproduction commands remain in `step-6-validation.md`.

Follow-up command results: `phpunit` **99 tests / 266 assertions** on each matrix; standard backend/HTTP suite **553** (5.7.13) / **557** (6.0.0) checks. The optional 5.7 screenshot capture made two additional fragment requests and reported **555** checks. PHP syntax of the four changed PHP files, production container lint on both matrices and `git diff --check` passed. Final post-HTTP audits again found zero fixture rows and triggers and exactly two extension tables. No schema apply, host mutation or live API call occurred. The live interactive browser limit above still applies.
