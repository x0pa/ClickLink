# Changelog

## 0.1.0 - 2026-04-20

- Added release-ready uninstall flow (`uninstall.php` + `ClickLink\\Uninstaller`) to remove ClickLink table, options, and linker post meta.
- Added text-domain loading during plugin boot and explicit plugin domain metadata.
- Added a release packaging script to build installable `clicklink-<version>.zip` bundles from repository source.
- Added regression coverage for uninstall bootstrap and i18n loading behavior.
