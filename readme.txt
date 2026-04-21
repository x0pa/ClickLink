=== ClickLink ===
Contributors: dadewilliams
Tags: internal linking, seo, content automation, editor workflow
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Automatically insert keyword links into WordPress blog posts with per-post link limits, manual backfill scanning, and operational metrics.

== Description ==

ClickLink manages keyword-to-URL mappings and automatically inserts links when posts are saved.

Key behavior:

* Processes `post` content on save.
* Inserts links in paragraph text while skipping protected contexts such as existing links, headings, code blocks, and script/style regions.
* Supports multiple URLs per keyword.
* Enforces a maximum links-per-post limit.
* Provides a manual backfill scanner to process existing published posts in batches.
* Tracks cumulative metrics and top matched keywords in a dashboard widget.

Environment notes:

* Single-site installs only (multisite is intentionally unsupported).
* Requires WordPress 6.5+ and PHP 8.1+.

Author: Dade Williams (https://www.dadewilliams.com)

== Installation ==

1. Upload the `clicklink` folder to `/wp-content/plugins/`.
2. Activate **ClickLink** in the WordPress Plugins screen.
3. Open **Dashboard -> ClickLink**.
4. Add keyword-to-URL mappings.
5. Save a published post to apply linking.

== Frequently Asked Questions ==

= Does ClickLink support multisite? =

No. ClickLink is currently designed for single-site WordPress installations only.

= How do I run ClickLink on older posts? =

Use the manual backfill scanner on the ClickLink admin screen to process eligible published posts in batches.

= What happens on uninstall? =

The plugin removes its mapping table, options, backfill run state, stats, and ClickLink post meta keys.

== Changelog ==

= 0.1.0 =

* Initial public release.
* Added keyword mapping management UI.
* Added auto-linking on post save with exclusion rules and link cap support.
* Added manual backfill scanner with resumable state.
* Added dashboard metrics and top-keyword reporting.
* Added uninstall cleanup for plugin data.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
