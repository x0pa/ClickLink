# ClickLink

ClickLink is a WordPress plugin that auto-links keyword mappings in blog posts and provides operational metrics for content teams.

## Features

- Keyword-to-URL mapping management in wp-admin.
- Automatic link insertion on `post` save.
- Context-aware linking that skips existing links, headings, and code/script/style blocks.
- Configurable per-post max link cap (default `5`).
- Manual backfill scanner for processing existing published posts in batches.
- Dashboard widget metrics, including total links created and top matched keywords.
- Uninstall cleanup for plugin table, options, scanner state, stats, and linker post meta.

## Requirements

- WordPress `6.5+`
- PHP `8.1+`
- Single-site installs only (`multisite` is intentionally unsupported)

## Installation

1. Place this plugin in `wp-content/plugins/clicklink`.
2. Activate **ClickLink** in WordPress.
3. Open **Dashboard -> ClickLink**.
4. Add one or more keyword-to-URL mappings.
5. Save a published post to apply linking.

## Usage

- Add mappings from the ClickLink admin page.
- Save a post to trigger auto-linking.
- Use the manual backfill controls to process older posts.
- Review cumulative performance metrics in the **ClickLink Stats** dashboard widget.

## Author

Dade Williams  
https://www.dadewilliams.com

## License

MIT License. See [`LICENSE`](LICENSE).

Attribution requirement: your distributions must retain the copyright and license notice.
