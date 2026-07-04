# LinkFlow Auditor Progress

## Current Status

LinkFlow Auditor is ready as a combined WordPress admin plugin that replaces the earlier separate internal link counter and broken link checker builds.

Current package target:

- Plugin name: LinkFlow Auditor
- Slug: `linkflow-auditor`
- Version: `1.8.0`
- Main file: `linkflow-auditor.php`
- Text domain: `linkflow-auditor`
- GitHub repository target: `mfatihyavass-oss/linkflow-auditor`

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
- Added uninstall cleanup for both current and legacy option names.
- Split scanning into three independent admin tabs:
  - Internal link counts
  - Broken links
  - Redirecting links
- Kept external link checking disabled by default.
- Kept automatic broken link checks disabled by default.
- Limited automatic scans to broken link checks only.
- Preserved existing report sections when another section is rescanned.
- Updated `README.md` for GitHub/project use.
- Updated `readme.txt` for WordPress plugin directory style documentation.
- Added manual link remove/replace actions for broken and redirect reports.
- Preserved the active report tab after manual scans and report clearing.

## Validation Checklist

Run before every release package:

- `php -l linkflow-auditor.php`
- `php -l uninstall.php`
- `node --check assets/admin.js`
- Build a ZIP with a top-level `linkflow-auditor` folder.
- Confirm the ZIP does not include old ZIP files, `.git`, `.DS_Store` or local temporary files.

## Release Notes For 1.8.0

Version `1.8.0` adds the External Links tab and link-fix actions to the internal detail panel.

Key changes:

- New "Dış Linkler" tab collected during the internal scan (`collect_link_health` also gathers external links); table shows source, anchor text and target URL with remove/replace actions and a client-side search.
- Incoming-links detail panel now stores the raw href per source (`record_incoming_detail`) and renders remove/replace actions (scope `internal`) plus an explicit close button.
- `ajax_fix_link` accepts `external` and `internal` scopes; `update_report_after_fix` prunes the external list on removal and returns `external_count` via the shared `fix_result_counts` helper.
- JS: detail close handler, scope-aware tab-count updates, external search filter.

## Release Notes For 1.7.0

Version `1.7.0` adds the Link Health tab.

Key changes:

- New "Link Sağlığı" report tab summarising internal-linking problems from the last internal scan (no extra HTTP requests).
- Five checks: duplicate permalinks, orphan content (0 incoming), dead-end content (0 outgoing), insecure http:// internal links (mixed content), and weak/empty anchor text.
- `scan_posts` collects insecure links and weak anchors during the internal scan via `collect_link_health`; `build_health_report` assembles the tab data in `finalize_report`.
- Health tab has its own scan button (`data-result-tab="health"`) that runs the internal scan and returns to the health tab.

## Release Notes For 1.6.1

Version `1.6.1` fixes the duplicate-permalink incoming-link bug.

Key changes:

- `build_target_index` no longer deletes a URL lookup key when two published items share the same permalink path; `url_index` now maps each key to a list of target IDs.
- `resolve_internal_href` returns an array of target IDs; incoming links are attributed to every content item at a shared URL, while outgoing is counted once per anchor.
- Targets sharing a permalink are flagged with `shared_url` and rendered with a warning badge so the underlying duplicate can be merged/redirected.
- Root cause found via a user report CSV: pillar pages (`cekismeli-bosanma-davasi`, `anlasmali-bosanma-davasi`) each had a post and a page at the same URL, so every incoming link resolved to nothing and showed 0.

## Release Notes For 1.6.0

Version `1.6.0` reworks the internal link report for accuracy and reporting.

Key changes:

- Incoming links are now measured primarily as unique linking pages; total link occurrences remain as a secondary column.
- Internal counting renders block/shortcode/page-builder content (`do_blocks` + `do_shortcode`) before counting; disable with the `linkflow_auditor_render_content` filter.
- Fixed multibyte (Turkish) slug lowercasing in `normalize_path`/`normalize_host` via a new `mb_lower` helper so accented internal links match.
- Added an auditable incoming-link detail panel (source posts, anchor text, per-source counts) stored in `incoming_detail` on each report row.
- Added a client-side filter/report bar (presets 0, 0-3, 1-3, 4+, custom range, title search) plus UTF-8 CSV export.
- Modernised the admin CSS and added a hero header.

## Release Notes For 1.5.1

Version `1.5.1` is a tab-state fix release.

Key changes:

- Manual scans keep the user on the report tab that started the scan.
- Broken link and redirect scans reopen the matching report tab after the page refreshes.
- Clearing a report also keeps the current tab selected after refresh.

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

- Test the generated ZIP on a staging WordPress site.
- Run each report tab separately with a small content set first.
- Confirm legacy settings migrate correctly if the old plugin data exists in the same WordPress database.
- Decide whether to translate the admin UI strings to English before public WordPress.org submission.
- Add screenshots before a WordPress.org plugin directory submission.
- Add a `languages/` directory and `.pot` file before localization work.
