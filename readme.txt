=== LinkFlow Auditor ===
Contributors: mfatihyavass-oss
Tags: internal links, broken links, redirects, seo audit, link audit
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.11.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit internal links, broken links and redirecting links from separate WordPress admin reports.

== Description ==

LinkFlow Auditor is a lightweight WordPress admin plugin for auditing internal links, broken links and redirecting links without running every check at once.

The plugin is designed for content-heavy WordPress sites where SEO teams need to find underlinked pages, broken internal URLs and unnecessary 3XX redirect hops from inside the WordPress dashboard.

== Features ==

* Internal link count report for published public post types.
* Internal link suggestions that prioritize pages with fewer internal links, show source metrics, and can be accepted, dismissed or rotated in batches of 25.
* Manual internal link suggestion builder for finding a phrase across posts/pages and linking it to a chosen internal URL, plus a source-URL mode that finds possible outgoing internal links from one page.
* In-editor internal-link suggestion metabox that scans the content being edited and returns up to 25 outgoing link suggestions with a priority selector (least-linked, oldest or newest targets) and one-click accept.
* Collapsible Link Health sections, closed by default.
* One-click cleanup for saved reports, temporary scan states and suggestion records.
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

= Internal Link Suggestions =

Shows safe internal link opportunities generated during the internal scan. Each suggestion includes source content, suggested target page, linkable anchor phrase, short context, source outgoing-link count, source publish date and reason. The "Kabul et" action inserts the link into the source content after admin confirmation.

Suggestions prioritize target pages with fewer incoming internal links. The accept action skips text that is already inside a link and refuses duplicate source-to-target links. The "Öneriyi kaldır" action suppresses the same suggestion in future scans, and "Kaldırılan önerileri sıfırla" clears that suppression list. "Önerileri değiştir" shows a different batch of up to 25 saved suggestions, and "Seçim kaydını sil" resets that rotation history.

= Manual Internal Link Suggestions =

Lets an admin enter a phrase, an internal target URL and a sort mode. The plugin returns up to 25 source posts/pages where the phrase appears in editable plain text, sorted by least-linked sources, oldest first or newest first. The target can be written as `ana sayfa` to use the homepage.

Admins can also switch to source-URL mode, enter one published internal URL, and get up to 25 candidate outgoing link opportunities from that content. Each result shows the phrase found on the source page and the internal URL it can link to. Manual results can be rotated to a different batch of 25, and the rotation record can be cleared.

The least-linked sorting uses the latest internal link scan. If no internal scan exists yet, the admin UI prompts the user to run one first.

= Broken Links =

Lists source title, source URL, anchor text, URL used in content, HTTP status or issue type, and last checked time.

By default, only internal links are checked. External links are included only when the external link setting is enabled for the broken link scan.

= Redirecting Links =

Reports links returning 301, 302, 307 or 308. The report shows the URL used in content, first reportable redirect status code, final URL, unique source count, total usage count and occurrence details.

== Performance Notes ==

The plugin intentionally avoids one large "check everything" operation.

* The internal link tab only parses content and counts internal links.
* The internal suggestions tab is generated from the same internal scan and does not make HTTP requests.
* Manual suggestions scan editor content only when requested and return at most 25 candidate source pages or target opportunities per batch.
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

No. LinkFlow Auditor does not rewrite links without confirmation. Report rows include manual remove/replace buttons, and internal link suggestions include a "Kabul et" action. Content changes only after an admin confirms one of those actions.

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

Change the minimum length a single-word phrase must have to be suggested (default 5):

`
add_filter( 'linkflow_auditor_suggestion_single_word_min_length', function () {
	return 4;
} );
`

Turn off shared-keyword matching so only full title phrases match (default on):

`
add_filter( 'linkflow_auditor_suggestion_keyword_matching', '__return_false' );
`

Add stop words that are never used as one-word/two-word anchors:

`
add_filter( 'linkflow_auditor_suggestion_stopwords', function ( array $words ) {
	$words[] = 'hukuk';
	return $words;
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

LinkFlow Auditor reads post and page content. The internal count report renders blocks and shortcodes before counting visible internal links, but links that are not present in raw editor content cannot be removed or replaced from the report. It does not count or check menu, header, footer or sidebar links generated by the theme, links inserted by JavaScript after page load, draft/private/trashed content, or non-HTTP links such as `mailto:`, `tel:`, `sms:`, `javascript:` and `data:`. Internal link suggestions are only shown when the phrase exists in editable post content and is not already inside a link. Manual suggestions also require the target to be an internal site URL.

== Changelog ==

= 1.11.6 =
* The editor "Seçilenleri uygula" bulk button is now always visible and enabled (shown as a large primary button); clicking it with nothing selected shows a hint instead of the button being greyed out.
* Admin and editor scripts/styles are now versioned by file modification time (filemtime), so any JS/CSS change busts the browser and CDN cache automatically even when the plugin version is unchanged.

= 1.11.5 =
* Added bulk apply to the editor metabox: each suggestion now has a select checkbox (plus a select-all in the header) and a "Seçilenleri uygula" button, so several suggestions can be linked at once with a single editor refresh at the end instead of one refresh per suggestion.
* Suggestions are applied sequentially so each link is saved before the next one is looked up in the updated content.

= 1.11.4 =
* Fixed: accepting an internal-link suggestion from the post/page editor metabox saved the link to the content but the open block editor kept the stale content and overwrote the link on the next save. The editor now reloads after a successful accept so the added link is loaded and persists.
* Editor metabox now warns to save unsaved changes before accepting, since the editor refreshes afterwards.
* Version bump also refreshes the cached admin/editor scripts and styles.

= 1.11.3 =
* Added a "Negatif kelimeler" field to the editor metabox: words entered there (comma/space separated) are excluded as anchor text for that scan, both from keyword generation and from any full-phrase anchor that contains them.

= 1.11.2 =
* Suggestions now also match on shared keywords: two-word groups and distinctive single words from a target's title/slug become anchor candidates, so a target can be linked when the source shares only one or two words with it instead of the whole title.
* Full-title phrases still win when present; keyword anchors are the fallback and skip a Turkish/English stop-word list.
* Added filters: linkflow_auditor_suggestion_keyword_matching, linkflow_auditor_suggestion_stopwords and linkflow_auditor_suggestion_phrase_limit.

= 1.11.1 =
* Editor metabox suggestions now work for draft content too, not only published content; link targets stay limited to published content.
* Lowered the single-word anchor phrase minimum length from 8 to 5 characters (filterable via linkflow_auditor_suggestion_single_word_min_length) so distinctive one-word titles produce more suggestions across every suggestion surface.

= 1.11.0 =
* Added an internal-link suggestion metabox to the post and page editor (classic and block editors), shown below the content.
* The metabox scans the content being edited and returns up to 25 outgoing internal-link suggestions with a priority selector (least-linked, oldest or newest targets).
* Accepting a suggestion links the phrase directly into the content; "Önerileri değiştir" loads a different batch of 25.
* Added dedicated editor AJAX endpoints guarded by an edit_post capability check, leaving the existing manage_options Tools page endpoints untouched.
* Refactored the manual suggestion engine so source-URL and post-ID suggestion building share one code path.

= 1.10.4 =
* Internal detail rows now hide remove/replace actions for links generated by shortcodes or rendered blocks and show an explanatory note instead.
* Fixed protocol-relative external links such as `//example.com/page` so they appear in the external links report.
* Tightened `www.` URL normalization so relative paths starting with `www.` are not treated as external URLs unless they match a domain pattern.
* Fixed numeric-only suggestion rotation IDs so batch history is preserved correctly.

= 1.10.3 =
* Added source-URL manual suggestions that find possible outgoing internal links from one content URL.
* Added "Önerileri değiştir" for automatic and manual suggestions, with 25-result batches and resettable rotation records.
* Added a top-level cleanup button for saved reports, temporary scan states and suggestion records.
* Changed Link Health issue groups into collapsed-by-default sections.
* Fixed broken-link tab counters so restricted 401/403 warning rows are counted with the rows shown in the table.
* Hardened suggestion rotation and cleanup state handling after record resets.

= 1.10.2 =
* Fixed uninstall cleanup for dismissed automatic suggestion data.
* Fixed Turkish İ/i matching in admin filters and search boxes.
* Hardened Unicode phrase matching so link insertion uses original text offsets.

= 1.10.1 =
* Added "Kaldırılan önerileri sıfırla" for dismissed automatic suggestions.
* Added scan-date notes for suggestion data and a prompt to run the internal scan before using least-linked manual sorting.

= 1.10.0 =
* Added "Öneriyi kaldır" for automatic internal link suggestions; dismissed suggestions are suppressed in future scans.
* Added source outgoing-link count and publish date to suggestion rows.
* Added a "Manuel Öneri" tab for finding a phrase across posts/pages and linking it to a chosen internal URL, with max 25 results and user-selected sorting.
* Hardened manual suggestions around Turkish İ/i matching, existing links, shortcode-like text and code/preformatted HTML areas.

= 1.9.0 =
* Added an "İç Link Önerileri" tab generated during the internal scan.
* Suggestions prioritize target pages with fewer internal links and show source, target, anchor context and reason.
* Added a "Kabul et" action that inserts the suggested internal link directly into the source content after admin confirmation.
* Suggestion acceptance skips existing links, code/preformatted areas and shortcode-like text, and refuses duplicate source-to-target links.

= 1.8.0 =
* Added a "Dış Linkler" (External Links) tab listing every external link in posts/pages with its anchor text and target URL, plus per-row remove and replace (change target URL) actions and a live search box.
* Added remove/replace actions to the internal "who links here" detail panel so an internal link can be unlinked or repointed from the linking post.
* Added an explicit close button to the incoming-links detail panel (the expand arrow still toggles).

= 1.7.0 =
* Added a "Link Sağlığı" (Link Health) tab that surfaces internal-linking problems from the internal scan without extra HTTP requests.
* Checks: duplicate-permalink content, orphan content (0 incoming links), dead-end content (0 outgoing links), insecure http:// internal links (mixed content) and weak/empty anchor text.
* Health data is produced together with the internal link scan.

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
