---
type: report
title: ClickLink Changelog
created: 2026-04-20
tags:
  - changelog
  - release
related:
  - '[[ClickLink Plugin]]'
---

# Changelog

## 0.1.0 - 2026-04-20

- Added release-ready uninstall flow (`uninstall.php` + `ClickLink\Uninstaller`) to remove ClickLink table/options/post meta cleanly.
- Added text-domain loading during plugin boot and explicit plugin header domain metadata (`Domain Path: /languages`).
- Added release packaging script to generate installable `clicklink-<version>.zip` bundles from repository source.
- Added regression coverage for uninstall bootstrap and i18n loading behavior.
