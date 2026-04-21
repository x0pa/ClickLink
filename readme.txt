=== ClickLink ===
Contributors: dadewilliams
Tags: internal linking, seo, content automation
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/license/mit

Automatically insert mapped keyword links into WordPress posts with link caps, manual backfill scanning, and dashboard metrics.

== Description ==

ClickLink manages keyword-to-URL mappings and auto-links those keywords inside published blog posts.

Core behavior:

* Processes `post` content during save.
* Skips excluded contexts (existing links, headings, code/pre/script/style blocks).
* Supports multiple URLs per keyword.
* Enforces a max links-per-post limit.
* Includes manual backfill scanning for existing published posts.
* Tracks totals and top keyword matches in a dashboard widget.

Compatibility:

* WordPress 6.5+
* PHP 8.1+
* Single-site only (multisite unsupported)

Author: Dade Williams (https://www.dadewilliams.com)

== Installation ==

1. Upload the `clicklink` folder to `/wp-content/plugins/`.
2. Activate **ClickLink** from the Plugins screen.
3. Open **Dashboard -> ClickLink**.
4. Add keyword-to-URL mappings.
5. Save a published post to trigger auto-linking.

== Frequently Asked Questions ==

= Does ClickLink support multisite? =

No. ClickLink currently supports single-site WordPress installations only.

= How do I process existing posts? =

Run the manual backfill scanner from the ClickLink admin page.

= What is removed on uninstall? =

ClickLink removes its mappings table, plugin options, backfill scanner state, stats, and linker-related post meta.

== Changelog ==

= 0.1.0 =

* Initial public release.
* Added mapping management UI.
* Added automatic linking on post save.
* Added manual backfill scanner.
* Added dashboard metrics and top keyword reporting.
* Added uninstall cleanup.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
