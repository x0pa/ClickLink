# ClickLink

ClickLink is a single-site WordPress plugin that automatically inserts mapped keyword links into blog post content and tracks linking performance metrics.

## Features

- Automatically links configured keywords in `post` content on save.
- Restricts link placement to paragraph text and avoids excluded contexts such as existing links, headings, `code`, `pre`, `script`, and `style` blocks.
- Supports duplicate keywords that map to multiple URLs.
- Enforces a configurable max-links-per-post limit (default: `5`).
- Includes a manual backfill scanner to process existing published posts in resumable batches.
- Adds a WordPress dashboard widget with cumulative totals and top matched keywords.
- Cleans up plugin table, options, and related post meta on uninstall.

## Requirements

- WordPress `6.5` or newer
- PHP `8.1` or newer
- Single-site WordPress install (`multisite` is intentionally unsupported)

## Installation

1. Copy this repository into `wp-content/plugins/clicklink`, or install a built release zip.
2. Activate **ClickLink** from the WordPress Plugins screen.
3. Open **Dashboard -> ClickLink**.
4. Add one or more keyword-to-URL mappings.
5. Save a published post to trigger linking.

## Using ClickLink

1. Go to **Dashboard -> ClickLink**.
2. Add mappings in the format `keyword -> URL`.
3. (Optional) run the manual backfill scanner to process older published posts.
4. Review metrics in the **ClickLink Stats** dashboard widget.

## Local Validation

```sh
sh tests/run-tests.sh
```

## Build a Release Zip

```sh
sh scripts/build-release-package.sh
```

- Default output directory: `dist/`
- Custom output directory: `sh scripts/build-release-package.sh /absolute/output/path`

## Author

Created and maintained by **Dade Williams**.

- Website: https://www.dadewilliams.com

## License

Licensed under `GPL-2.0-or-later`. See [`LICENSE`](LICENSE).
