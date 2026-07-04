=== LinkFlow Auditor ===
Contributors: mfatihyavass-oss
Tags: internal links, broken links, redirects, seo audit, link audit
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit internal links, broken links and redirecting links from separate WordPress admin reports.

== Description ==

LinkFlow Auditor is a lightweight WordPress admin plugin for auditing internal links, broken links and redirecting links without running every check at once.

The plugin is designed for content-heavy WordPress sites where SEO teams need to find underlinked pages, broken internal URLs and unnecessary 3XX redirect hops from inside the WordPress dashboard.

== Features ==

* Internal link count report for published public post types.
* Broken link report for 404, 410, other 4XX/5XX responses and restricted 401/403 responses.
* Redirect report for 301, 302, 307 and 308 links.
* Separate admin tabs and separate scan buttons for each report.
* Internal link counting does not make HTTP requests.
* Broken link and redirect scans run independently to reduce load.
* External link checking is optional and disabled by default.
* Automatic broken link checks via WordPress Cron are optional and disabled by default.
* Redirect report groups repeated URLs and shows total usage plus unique source count.
* Broken and redirect report rows include manual remove/replace actions.
* AJAX batch scanning keeps long scans out of a single heavy request.
* Report and temporary scan data are stored with autoload disabled.
* Legacy option migration from the former `ic-link-sayici` / `maya_ils_*` naming.

== Reports ==

= Internal Link Counts =

Shows each published content item with title, URL, post type, incoming internal link count, unique source count, outgoing internal link count, unique target count and edit link.

= Broken Links =

Lists source title, source URL, anchor text, URL used in content, HTTP status or issue type, and last checked time.

By default, only internal links are checked. External links are included only when the external link setting is enabled for the broken link scan.

= Redirecting Links =

Reports links returning 301, 302, 307 or 308. The report shows the URL used in content, first reportable redirect status code, final URL, unique source count, total usage count and occurrence details.

== Performance Notes ==

The plugin intentionally avoids one large "check everything" operation.

* The internal link tab only parses content and counts internal links.
* The broken link tab performs HTTP checks for broken/restricted responses.
* The redirect tab performs HTTP checks for reportable 3XX responses.
* Manual scans run in AJAX batches of 25 content items by default.
* Automatic scans, when enabled, only run the broken link report.

== Installation ==

1. Upload the `linkflow-auditor` folder to `wp-content/plugins/`, or install the generated ZIP through the WordPress plugin uploader.
2. Activate LinkFlow Auditor in the WordPress admin.
3. Open Tools > LinkFlow Auditor.
4. Run the needed report from its own tab: Internal Link Counts, Broken Links or Redirecting Links.

== Frequently Asked Questions ==

= Does this slow down the public site? =

Manual scans run only from the WordPress admin. Scans are split into AJAX batches. Automatic scans are disabled by default and, when enabled, only run the broken link report through WordPress Cron.

= Are external links checked? =

External links are not checked by default. Enable the external link setting before running the broken link scan if you want to include them.

= Does internal link counting send HTTP requests? =

No. The internal link count report parses editor content and resolves links against the site's published content index.

= Does the plugin change content automatically? =

No. LinkFlow Auditor does not rewrite links automatically. Report rows include manual remove/replace buttons, and content changes only after an admin confirms one of those actions.

== Developer Notes ==

Limit scanned post types:

`
add_filter( 'linkflow_auditor_post_types', function () {
	return array( 'post', 'page', 'your_custom_post_type' );
} );
`

Change the admin AJAX batch size:

`
add_filter( 'linkflow_auditor_scan_batch_size', function () {
	return 40;
} );
`

Change the background scan batch size:

`
add_filter( 'linkflow_auditor_background_scan_batch_size', function () {
	return 40;
} );
`

Customize HTTP request arguments:

`
add_filter( 'linkflow_auditor_http_request_args', function ( array $args, string $url ) {
	$args['timeout'] = 10;
	return $args;
}, 10, 2 );
`

== Migration Notes ==

This plugin was renamed from the earlier local Ic Link Sayici build. On activation, LinkFlow Auditor copies legacy report/settings options when present:

* `maya_ils_report` to `linkflow_auditor_report`
* `maya_ils_settings` to `linkflow_auditor_settings`
* `maya_ils_check_external_links` to `linkflow_auditor_check_external_links`

The legacy scheduled hook is cleared during activation to prevent duplicate background checks.

== Limitations ==

LinkFlow Auditor reads links from editor content. It does not count or check menu, header, footer or sidebar links generated by the theme, links generated only at runtime by shortcodes, links inserted by JavaScript after page load, draft/private/trashed content, or non-HTTP links such as `mailto:`, `tel:`, `sms:`, `javascript:` and `data:`.

== Changelog ==

= 1.6.1 =
* Fixed a bug where two published items sharing the same permalink (e.g. a post and a page with the same slug) caused all incoming links to that URL to be counted as zero. Incoming links are now attributed to every content item at that URL.
* Added a "shared URL" warning badge to flag duplicate-permalink content that should be merged or redirected.

= 1.6.0 =
* Internal link report now uses unique linking pages as the trusted incoming metric, with total occurrences kept as a secondary column.
* Internal counting renders block/shortcode/page-builder content before counting so links that are not raw <a> tags are included.
* Fixed Turkish/UTF-8 slug lowercasing so accented internal links are no longer missed.
* Added an expandable "who links here" detail panel with source posts, anchor text and per-source counts.
* Added filter presets (0, 0-3, 1-3, 4+), a custom range, title search and CSV export on the internal tab.
* Modernised the admin interface.

= 1.5.1 =
* Preserved the active report tab after a manual scan or report clear.
* Broken link and redirect scans now reopen their own report tabs after completion instead of returning to the internal link count tab.

= 1.5.0 =
* Added manual remove/replace actions for broken link rows.
* Added direct replacement of redirecting URLs with their final destination.
* Updated report counts after a link is fixed without requiring a full rescan.

= 1.4.0 =
* Renamed the plugin to LinkFlow Auditor.
* Renamed the main plugin file, slug, text domain, AJAX actions, options, hooks and package folder to `linkflow-auditor` / `linkflow_auditor`.
* Added legacy option migration from the former `maya_ils_*` option names.
* Split scanning into three independent report tabs: internal link counts, broken links and redirecting links.
* Kept external link checking and automatic checks disabled by default.
* Preserved each report section when another section is rescanned.

= 1.3.0 =
* Merged automatic broken link checking, external link settings and restricted-response handling from the former broken link checker plugin.
* Expanded default scan scope to all published public post types except attachments.
* Added last checked time and restricted-response labels to the broken link report.

= 1.2.0 =
* Added broken link reporting.
* Added redirecting link reporting for 301, 302, 307 and 308.
* Added summary counters for scanned content, checked links, broken links and redirecting links.
* Grouped redirecting links by URL/status/final URL with usage counts.

= 1.1.0 =
* Split incoming and outgoing internal link counts.
* Added unique source and target counts.
* Expanded documentation.

= 1.0.0 =
* Initial internal link count report.
