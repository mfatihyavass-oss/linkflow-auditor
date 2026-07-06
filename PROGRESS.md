# LinkFlow Auditor Progress

## Current Status

LinkFlow Auditor is ready as a combined WordPress admin plugin for auditing internal links, broken links, redirects, external links and internal-link health from separate WordPress admin reports.

Current package target:

- Plugin name: LinkFlow Auditor
- Slug: `linkflow-auditor`
- Version: `1.11.6`
- Main file: `linkflow-auditor.php`
- Text domain: `linkflow-auditor`
- GitHub repository target: `mfatihyavass-oss/linkflow-auditor`
- Latest package: `linkflow-auditor-1.11.6.zip`

## Completed

- Renamed the plugin from the previous Turkish/local name to LinkFlow Auditor.
- Renamed the main plugin file to `linkflow-auditor.php`.
- Updated the public plugin header, slug, text domain, admin page slug and dashboard widget name.
- Updated internal AJAX actions, option names, cron hook names, filters and script localization names to the `linkflow_auditor` prefix.
- Updated admin CSS/JS selectors from the old local prefix to the `lfa` prefix.
- Added migration from legacy option names:
  - `maya_ils_report`
  - `maya_ils_settings`
  - `maya_ils_check_external_links`
- Added uninstall cleanup for current and legacy option names, scan state options, dismissed suggestions and suggestion rotation records.
- Split scanning into independent admin tabs:
  - Internal link counts
  - Link Health
  - Internal link suggestions
  - Manual suggestions
  - Broken links
  - Redirecting links
  - External links
- Kept external HTTP checking disabled by default.
- Kept automatic broken link checks disabled by default.
- Limited automatic scans to broken link checks only.
- Preserved existing report sections when another section is rescanned.
- Added manual link remove/replace actions for broken, redirect, external and internal-detail report rows.
- Preserved the active report tab after scans, fixes and report clearing.
- Added Link Health reporting for duplicate permalinks, orphan content, dead-end content, insecure internal `http://` links and weak/empty anchor text.
- Added collapsible Link Health sections, closed by default.
- Added auditable incoming-link detail rows with source, anchors, counts and raw href.
- Added internal report filters, quick presets and CSV export.
- Added automatic internal-link suggestions with accept, dismiss, dismissed-reset and batch rotation.
- Added manual phrase + target URL suggestions.
- Added manual source-URL mode for finding possible outgoing internal links from one source page.
- Added a post/page editor metabox that scans the edited content and returns up to 25 outgoing internal-link suggestions with a priority selector (least linked, oldest or newest targets) and one-click accept that links directly into the content.
- Broadened suggestion matching so shared two-word groups and distinctive single words (stop words removed) become anchor candidates, not only full title phrases.
- Added a per-scan "Negatif kelimeler" field in the editor metabox to exclude chosen words from being used as anchor text.
- Added resettable suggestion rotation records for automatic and manual suggestions.
- Added one-click cleanup for reports, temporary scan states and suggestion records while preserving settings.
- Fixed broken-link tab counters so restricted 401/403 warning rows are counted with the rows shown in the table.
- Hardened suggestion rotation so empty or malformed suggestion IDs do not pollute batches.
- Hardened runtime record cleanup so deleted report/suggestion options are recreated as clean non-autoloaded empty arrays.
- Fixed the editor metabox accept flow: the accepted link is saved to the post content and the editor reloads so the open block editor no longer overwrites it on the next save.
- Added bulk apply to the editor metabox: per-suggestion select checkboxes, a select-all, and an always-visible "Seçilenleri uygula" button that applies the selected suggestions sequentially and refreshes the editor once at the end.
- Switched admin/editor asset versioning to `filemtime` so JS/CSS changes bust the browser and CDN cache automatically.
- Updated `README.md` for GitHub/project use.
- Updated `readme.txt` for WordPress plugin directory style documentation.
- Built `linkflow-auditor-1.11.6.zip` with a top-level `linkflow-auditor` folder and without local metadata or old ZIP files.

## Latest Validation

Last checked: 2026-07-07.

Passed:

- PHP syntax check for every plugin PHP file.
- `node --check assets/admin.js`.
- `node --check assets/editor.js`.
- End-to-end stub test of editor suggestion generation, single accept and sequential bulk apply (Gutenberg block content included).
- ZIP archive integrity test for `linkflow-auditor-1.11.6.zip`.
- ZIP content check confirmed no `.git`, `.github`, `.DS_Store`, `PROGRESS.md` or old release ZIP files are included.

## Validation Checklist

Run before every release package:

- `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l`
- `node --check assets/admin.js`
- `node --check assets/editor.js`
- Build a ZIP with a top-level `linkflow-auditor` folder.
- `unzip -t linkflow-auditor-{version}.zip`
- Confirm the ZIP does not include old ZIP files, `.git`, `.github`, `.DS_Store`, `PROGRESS.md` or local temporary files.
- Load the main plugin file in a WordPress runtime.

## Release Notes For 1.11.6

Version `1.11.6` makes the editor bulk-apply button reliably visible and fixes recurring asset caching.

Key changes:

- The editor "Seçilenleri uygula" bulk button is always visible and enabled (large primary button); clicking it with nothing selected shows a hint instead of a greyed-out button.
- Admin and editor scripts/styles are versioned by `filemtime`, so any JS/CSS change busts the browser and CDN cache even when the plugin version is unchanged.

## Release Notes For 1.11.5

Version `1.11.5` adds bulk apply to the editor metabox.

Key changes:

- Each suggestion has a select checkbox plus a header select-all, and a "Seçilenleri uygula" button applies the selected suggestions in one pass.
- Suggestions are applied sequentially so each link is saved before the next lookup runs against the updated content, and the editor refreshes only once at the end.

## Release Notes For 1.11.4

Version `1.11.4` fixes the editor metabox accept flow.

Key changes:

- Accepting a suggestion saved the link to the content but the open block editor kept stale content and overwrote it on the next save. The editor now reloads after a successful accept so the added link is loaded and persists.
- The metabox warns to save unsaved changes before accepting.
- Aligned the HTTP link checker version constant with the plugin version.

## Release Notes For 1.11.3

Version `1.11.3` adds negative-word filtering to the editor metabox.

Key changes:

- Added a "Negatif kelimeler" field to the editor metabox. Words typed there (comma or space separated) are excluded as anchor text for that scan.
- Negative words are merged into the shared stop-word list for the request so keyword (single-word and two-word) anchors skip them, and any remaining suggestion whose anchor still contains a negative word is dropped before the 25-row batch is returned.
- A larger candidate pool is fetched when negative words are present so the batch can still be filled after filtering.

## Release Notes For 1.11.2

Version `1.11.2` broadens suggestion matching to shared keywords.

Key changes:

- Suggestions now also match on shared keywords: consecutive two-word groups and distinctive single words from a target's title/slug become anchor candidates, so a target can be linked when the source shares only one or two words with it instead of the whole title phrase.
- Full-title phrases still take priority when they appear verbatim; keyword anchors are a lower-priority fallback and skip a Turkish/English stop-word list.
- Added filters: `linkflow_auditor_suggestion_keyword_matching` (enable/disable), `linkflow_auditor_suggestion_stopwords` (stop-word list) and `linkflow_auditor_suggestion_phrase_limit` (max phrases per target, default 8).
- Applies to every suggestion surface: the editor metabox, the automatic suggestions tab and manual source-URL suggestions.

## Release Notes For 1.11.1

Version `1.11.1` refines the editor metabox suggestions.

Key changes:

- Editor metabox suggestions now work for draft source content too, not only published content; link targets stay limited to published content.
- Lowered the single-word anchor phrase minimum length from 8 to 5 characters (filterable via `linkflow_auditor_suggestion_single_word_min_length`) so distinctive one-word titles (e.g. Turkish legal terms) produce more suggestions in every suggestion surface.

## Release Notes For 1.11.0

Version `1.11.0` adds an in-editor internal-link suggestion metabox.

Key changes:

- Added a "LinkFlow Auditor — İç Link Önerileri" metabox to the post and page editor (classic and block editor), shown below the content like an SEO panel.
- The metabox scans the content currently being edited and returns up to 25 outgoing internal-link suggestions built from safe plain-text phrases only.
- Added a priority selector for the scan: least-linked targets first, oldest targets first or newest targets first.
- Accepting a suggestion links the phrase directly into the content and removes the row; a note reminds the editor to refresh so the change is not overwritten by an in-progress editor session.
- "Önerileri değiştir" fetches a different batch, excluding suggestions already shown in the panel.
- Suggestions and accept run through a dedicated editor AJAX pair guarded by an `edit_post` capability check, so the existing admin (`manage_options`) endpoints are untouched.
- Refactored the manual suggestion engine so source-URL and post-ID suggestion building share one code path (`build_link_suggestions_from_post`).

## Release Notes For 1.10.4

Version `1.10.4` fixes edit-action visibility and URL classification edge cases.

Key changes:

- Internal incoming-link detail rows now mark rendered shortcode/block links as non-editable when the href is not present in raw post content.
- Non-editable internal detail rows show a note instead of remove/replace buttons, preventing the "link not found in content" error path.
- Protocol-relative external links such as `//example.com/page` are normalized with the site scheme before external-link classification.
- `www.` href normalization now requires a domain-like pattern, so relative paths starting with `www.` are not misclassified.
- Numeric-only suggestion rotation IDs are sanitized from the map key, preserving batch history records.

## Release Notes For 1.10.3

Version `1.10.3` adds source-URL manual suggestions, suggestion batch rotation, cleanup controls and collapsed Link Health sections.

Key changes:

- Added source-URL manual suggestions that inspect one published internal URL and find possible outgoing internal-link opportunities from that content.
- Added "Önerileri değiştir" for automatic suggestions and manual suggestions, returning batches of up to 25 results.
- Added resettable suggestion rotation records for automatic and manual suggestion batches.
- Added a top-level cleanup button that clears saved reports, scan states, dismissed suggestions and suggestion rotation records while preserving settings.
- Changed Link Health issue groups into collapsed-by-default sections.
- Fixed broken-link tab counters so restricted 401/403 warning rows are counted with visible broken-link rows.
- Hardened cleanup state after record resets by recreating runtime options as clean non-autoloaded empty arrays.
- Hardened suggestion batch selection by skipping suggestions with empty IDs.

## Release Notes For 1.10.2

Version `1.10.2` fixes cleanup and Unicode matching around suggestions.

Key changes:

- Fixed uninstall cleanup for dismissed automatic suggestion data.
- Fixed Turkish `İ/i` matching in admin filters and search boxes.
- Hardened Unicode phrase matching so link insertion uses original text offsets.

## Release Notes For 1.10.1

Version `1.10.1` improves dismissed suggestions and scan-date guidance.

Key changes:

- Added "Kaldırılan önerileri sıfırla" for dismissed automatic suggestions.
- Added scan-date notes for suggestion data.
- Added a prompt to run the internal scan before using least-linked manual sorting.

## Release Notes For 1.10.0

Version `1.10.0` adds automatic internal-link suggestions.

Key changes:

- Internal scans collect safe linkable phrase candidates.
- Suggestions prioritize targets with fewer incoming internal links.
- Suggestions can be accepted directly from the report.
- Accepted suggestions insert a link only outside existing anchors and unsafe text regions.
- Suggestions can be dismissed so they are not shown in future scans.

## Release Notes For 1.9.0

Version `1.9.0` adds manual internal-link suggestions.

Key changes:

- Admins can search for a phrase across published content.
- Results can be sorted by least-linked sources, oldest first or newest first.
- Manual suggestions skip text already inside a link, code/preformatted areas and shortcode-like text.
- The target URL must be an internal site URL; `ana sayfa` resolves to the homepage.

## Release Notes For 1.8.0

Version `1.8.0` adds the External Links tab and link-fix actions to the internal detail panel.

Key changes:

- New "Dış Linkler" tab collected during the internal scan.
- Incoming-links detail panel stores the raw href per source and renders remove/replace actions.
- `ajax_fix_link` accepts `external` and `internal` scopes.
- External report rows include client-side search.

## Release Notes For 1.7.0

Version `1.7.0` adds the Link Health tab.

Key changes:

- New "Link Sağlığı" report tab summarising internal-linking problems from the last internal scan without extra HTTP requests.
- Five checks: duplicate permalinks, orphan content, dead-end content, insecure `http://` internal links and weak/empty anchor text.
- Health tab has its own scan button that runs the internal scan and returns to the health tab.

## Release Notes For 1.6.1

Version `1.6.1` fixes the duplicate-permalink incoming-link bug.

Key changes:

- `build_target_index` maps each URL lookup key to a list of target IDs.
- `resolve_internal_href` returns all matching target IDs.
- Incoming links are attributed to every content item at a shared URL.
- Outgoing link count is still counted once per anchor.
- Targets sharing a permalink are flagged with `shared_url` and rendered with a warning badge.

## Release Notes For 1.6.0

Version `1.6.0` reworks the internal link report for accuracy and reporting.

Key changes:

- Incoming links are measured primarily as unique linking pages.
- Total link occurrences remain as a secondary column.
- Internal counting renders block/shortcode/page-builder content before counting.
- Fixed multibyte Turkish slug lowercasing in URL/path matching.
- Added an auditable incoming-link detail panel.
- Added a client-side filter/report bar and UTF-8 CSV export.
- Modernised the admin CSS and added a hero header.

## Release Notes For 1.5.1

Version `1.5.1` is a tab-state fix release.

Key changes:

- Manual scans keep the user on the report tab that started the scan.
- Broken link and redirect scans reopen the matching report tab after refresh.
- Clearing a report keeps the current tab selected after refresh.

## Release Notes For 1.5.0

Version `1.5.0` added manual link fixing actions.

Key changes:

- Added remove/replace actions for broken link rows.
- Added direct replacement of redirect links with the final URL.
- Updated report counts after individual link fixes.

## Release Notes For 1.4.0

Version `1.4.0` is the rename and consolidation release.

Key changes:

- New plugin identity: LinkFlow Auditor.
- New slug/package: `linkflow-auditor`.
- Separate scan controls for internal link counts, broken links and redirecting links.
- Legacy option migration from the old local plugin names.
- Complete README and WordPress `readme.txt` documentation.

## Next Recommended Steps

- Test `linkflow-auditor-1.11.6.zip` on a staging WordPress site.
- Verify the editor metabox on both a published and a draft post/page: save, scan, switch priority, accept a suggestion (single and bulk "Seçilenleri uygula"), then confirm the editor reloads with the inserted link(s).
- Run each report tab separately with a small content set first.
- Confirm legacy settings migrate correctly if old plugin data exists in the same WordPress database.
- Add screenshots before a WordPress.org plugin directory submission.
- Add a `languages/` directory and `.pot` file before localization work.
