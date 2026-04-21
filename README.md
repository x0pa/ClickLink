---
type: reference
title: ClickLink Plugin
created: 2026-04-20
tags:
  - wordpress
  - plugin
  - release
related:
  - '[[Phase-04-Stats-Polish-Release]]'
  - '[[Manual-Backfill-Scanner-Design]]'
---

# ClickLink

ClickLink is a single-site WordPress plugin that auto-links mapped keywords in post paragraphs, enforces a per-post link cap, and exposes operator metrics for save-time and manual backfill runs.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- Single-site install (multisite is blocked)

## Local Validation

```sh
sh tests/run-tests.sh
```

## Build Distribution ZIP

```sh
sh scripts/build-release-package.sh
```

The packaging script writes `clicklink-<version>.zip` to `.maestro/playbooks/Working` and excludes test/playbook/git artifacts.

## Lifecycle Expectations

- Activation: creates/updates the mappings schema and merges default options.
- Deactivation: intentionally no-op (no scheduled jobs or rewrites to flush).
- Uninstall: drops mappings table and removes ClickLink options + linker post meta.
