# LinkFlow Auditor

LinkFlow Auditor is a lightweight WordPress admin plugin for auditing internal links, broken links and redirecting links without running every check at once.

The plugin is designed for content-heavy WordPress sites where SEO teams need to find underlinked pages, broken internal URLs and unnecessary 3XX redirect hops from inside the WordPress dashboard.

## Status

- Current version: `1.5.1`
- WordPress minimum: `6.4`
- PHP minimum: `7.4`
- Main plugin file: `linkflow-auditor.php`
- Text domain: `linkflow-auditor`
- Package folder: `linkflow-auditor`

## Features

- Internal link count report for published public post types.
- Link Health report: duplicate permalinks, orphan content, dead-end content, insecure `http://` internal links and weak/empty anchor text.
- Broken link report for 404, 410, other 4XX/5XX responses and restricted 401/403 responses.
- Redirect report for 301, 302, 307 and 308 links.
- Separate admin tabs and separate scan buttons for each report.
- Internal link counting does not make HTTP requests.
- Broken link and redirect scans run independently to reduce load.
- External link checking is optional and disabled by default.
- Automatic broken link checks via WordPress Cron are optional and disabled by default.
- Redirect report groups repeated URLs and shows total usage plus unique source count.
- Broken and redirect report rows include manual remove/replace actions.
- AJAX batch scanning keeps long scans out of a single heavy request.
- Report and temporary scan data are stored with autoload disabled.
- Legacy option migration from the former `ic-link-sayici` / `maya_ils_*` naming.

## Reports

### Link Health

Aggregates internal-linking problems found during the internal scan (no extra HTTP requests):

- **Aynı URL'yi paylaşan içerik** — two or more published items resolving to the same permalink.
- **Öksüz içerik** — content with zero incoming internal links.
- **Çıkışsız içerik** — content with zero outgoing internal links.
- **Güvensiz (http) iç linkler** — `http://` links to the site's own host while the site runs on `https`.
- **Zayıf/eksik anchor text** — generic or empty anchor text on internal links.

### Internal Link Counts

Shows each published content item with:

- Content title and URL
- Post type
- Incoming internal link count
- Unique source content count
- Outgoing internal link count
- Unique target content count
- Edit link

Rows are sorted by the lowest incoming internal link count first, then by unique source count, outgoing link count and title.

### Broken Links

Checks eligible links and lists:

- Source post/page title
- Source URL
- Anchor text
- URL used in the content
- HTTP status or issue type
- Last checked time
- Manual actions to remove the link or replace it with a new URL

By default, only internal links are checked. External links are included only when the external link setting is enabled for the broken link scan.

### Redirecting Links

Checks eligible links and reports URLs returning:

- `301 Moved Permanently`
- `302 Found`
- `307 Temporary Redirect`
- `308 Permanent Redirect`

The report shows:

- URL used in content
- First reportable redirect status code
- Final URL after redirects
- Number of unique source posts/pages
- Total usage count
- Occurrence details with source title, source URL, anchor text and used URL
- Manual actions to remove the link or replace it with the final redirected URL

## Performance Model

The plugin intentionally avoids one large "check everything" operation.

- The internal link tab only parses content and counts internal links.
- The broken link tab performs HTTP checks for broken/restricted responses.
- The redirect tab performs HTTP checks for reportable 3XX responses.
- Manual scans run in AJAX batches of 25 content items by default.
- Automatic scans, when enabled, only run the broken link report.

Developers can adjust batch sizes with filters.

## Installation

1. Upload the `linkflow-auditor` folder to `wp-content/plugins/`, or install the generated ZIP through the WordPress plugin uploader.
2. Activate **LinkFlow Auditor** in the WordPress admin.
3. Open **Tools > LinkFlow Auditor**.
4. Run the needed report from its own tab:
   - Internal Link Counts
   - Broken Links
   - Redirecting Links

## Settings

The settings box controls broken link scanning:

- **Check external links**: disabled by default. When off, only links pointing to the current site's host are checked.
- **Automatic broken link checks**: disabled by default. When enabled, WordPress Cron runs the broken link scan at the selected interval.

Redirect scans are manual and internal-link focused by default.

## Developer Hooks

Limit scanned post types:

```php
add_filter( 'linkflow_auditor_post_types', function () {
	return array( 'post', 'page', 'your_custom_post_type' );
} );
```

Change the admin AJAX batch size:

```php
add_filter( 'linkflow_auditor_scan_batch_size', function () {
	return 40;
} );
```

Change the background scan batch size:

```php
add_filter( 'linkflow_auditor_background_scan_batch_size', function () {
	return 40;
} );
```

Customize HTTP request arguments:

```php
add_filter( 'linkflow_auditor_http_request_args', function ( array $args, string $url ) {
	$args['timeout'] = 10;
	return $args;
}, 10, 2 );
```

## Migration Notes

This plugin was renamed from the earlier local **Ic Link Sayici** build. On activation, LinkFlow Auditor copies legacy report/settings options when present:

- `maya_ils_report` to `linkflow_auditor_report`
- `maya_ils_settings` to `linkflow_auditor_settings`
- `maya_ils_check_external_links` to `linkflow_auditor_check_external_links`

The legacy scheduled hook is cleared during activation to prevent duplicate background checks.

## Limitations

LinkFlow Auditor reads links from editor content. It does not count or check:

- Menu, header, footer or sidebar links generated by the theme.
- Links generated only at runtime by shortcodes.
- Links inserted by JavaScript after page load.
- Draft, private or trashed content.
- `mailto:`, `tel:`, `sms:`, `javascript:`, `data:` and similar non-HTTP links.
- External links when external link checking is disabled.

## Packaging

Build a WordPress-installable package with the folder name `linkflow-auditor`. ZIP files, macOS metadata and Git internals should not be included in the package.

## Changelog

### 1.7.0

- Added a **Link Sağlığı (Link Health)** tab that collects internal-linking problems in one place, produced from the internal scan with no extra HTTP requests. Checks:
  - **Aynı URL'yi paylaşan içerik** — duplicate-permalink content (post + page at one URL).
  - **Öksüz içerik** — content with 0 incoming internal links.
  - **Çıkışsız içerik** — content with 0 outgoing internal links.
  - **Güvensiz (http) iç linkler** — `http://` internal links on an `https` site (mixed content).
  - **Zayıf/eksik anchor text** — generic ("tıklayın", "buraya", "devamı") or empty/image link anchors.

### 1.6.1

- Fixed a counting bug where two published items sharing the same permalink (for example a post and a page with the same slug) caused **all** incoming links to that URL to be reported as zero. The colliding URL was previously dropped from the internal lookup; incoming links are now attributed to every content item at that URL.
- Added a **"Aynı URL'yi paylaşan içerik"** warning badge on affected rows so duplicate-permalink content can be found and fixed (delete one and 301-redirect it).

### 1.6.0

- Reworked the internal link report around **unique linking pages** as the trusted "incoming link" metric, with total link occurrences kept as a secondary column.
- Internal link counting now renders block/shortcode/page-builder content before counting, so links that are not typed directly as `<a>` tags are no longer missed (toggle with the `linkflow_auditor_render_content` filter).
- Fixed a matching bug where Turkish/UTF-8 slugs (İ, Ğ, Ş, Ü, Ö, Ç) were lowercased incorrectly, causing valid internal links to be uncounted.
- Added an auditable "Bu içeriğe link veren yazılar" detail panel: expand any incoming count to see exactly which posts link to it, with anchor text and per-source counts.
- Added a filter/report bar on the internal tab with quick presets (0, 0–3, 1–3, 4+), a custom min–max range, title search and one-click CSV export of the filtered list.
- Modernised the admin UI (hero header, card layout, pill tabs, badges, refreshed tables).

### 1.5.1

- Preserved the active report tab after a manual scan or report clear.
- Broken link and redirect scans now reopen their own report tabs after completion instead of returning to the internal link count tab.

### 1.5.0

- Added manual remove/replace actions for broken link rows.
- Added direct replacement of redirecting URLs with their final destination.
- Updated report counts after a link is fixed without requiring a full rescan.

### 1.4.0

- Renamed the plugin to LinkFlow Auditor.
- Renamed the main plugin file, slug, text domain, AJAX actions, options, hooks and package folder to `linkflow-auditor` / `linkflow_auditor`.
- Added legacy option migration from the former `maya_ils_*` option names.
- Split scanning into three independent report tabs: internal link counts, broken links and redirecting links.
- Kept external link checking and automatic checks disabled by default.
- Preserved each report section when another section is rescanned.

### 1.3.0

- Merged automatic broken link checking, external link settings and restricted-response handling from the former broken link checker plugin.
- Expanded default scan scope to all published public post types except attachments.
- Added last checked time and restricted-response labels to the broken link report.

### 1.2.0

- Added broken link reporting.
- Added redirecting link reporting for 301, 302, 307 and 308.
- Added summary counters for scanned content, checked links, broken links and redirecting links.
- Grouped redirecting links by URL/status/final URL with usage counts.

### 1.1.0

- Split incoming and outgoing internal link counts.
- Added unique source and target counts.
- Expanded documentation.

### 1.0.0

- Initial internal link count report.
