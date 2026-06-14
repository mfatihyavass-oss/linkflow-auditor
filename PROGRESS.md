# LinkFlow Auditor Progress

## Current Status

LinkFlow Auditor is ready as a combined WordPress admin plugin that replaces the earlier separate internal link counter and broken link checker builds.

Current package target:

- Plugin name: LinkFlow Auditor
- Slug: `linkflow-auditor`
- Version: `1.4.0`
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

## Validation Checklist

Run before every release package:

- `php -l linkflow-auditor.php`
- `php -l uninstall.php`
- `node --check assets/admin.js`
- Build a ZIP with a top-level `linkflow-auditor` folder.
- Confirm the ZIP does not include old ZIP files, `.git`, `.DS_Store` or local temporary files.

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
