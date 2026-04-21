# Phase 04: Stats, Polish, and Release

This phase turns ClickLink into a polished, release-ready plugin by expanding actionable statistics, tightening admin UX/security/performance, and validating package quality for real-world WordPress deployment.

## Tasks

- [x] Run a reuse-first stabilization audit across all implemented modules:
  - Review Phase 01-03 services for duplicate logic and consolidate shared utilities instead of adding parallel implementations
  - Normalize coding patterns, hook registration, and error handling conventions across bootstrap/admin/linker/scanner modules
  - Remove dead code paths and ensure all features remain single-site only
  - Completed 2026-04-20: Consolidated duplicated scalar/int/table/time helpers into `ClickLink\Runtime` and refactored `Linker_Stats`, `Backfill_Scanner`, and `Admin_Page` to reuse it.
  - Completed 2026-04-20: Normalized `Admin_Page` hook registration through a shared action map registrar and removed duplicated nonce constants by reusing action constants.
  - Completed 2026-04-20: Tightened fallback error handling (`Backfill_Scanner` throwable message normalization, `Admin_Page` deny-access runtime exception fallback) while preserving single-site enforcement via compatibility gating.

- [x] Expand the statistics dashboard/widget into an operator-focused analytics view:
  - Preserve required headline metrics (total blog posts, total links created, total mapping rows)
  - Add additional useful stats (posts with links, links added by backfill run, average links per changed post, top matched keywords)
  - Ensure metrics are query-efficient and remain accurate after repeated save and backfill operations
  - Completed 2026-04-20: Extended `Linker_Stats` and `Post_Save_Linker` to persist per-keyword match counters and expose operator-ready, query-efficient metrics from existing option/meta state.
  - Completed 2026-04-20: Updated `Dashboard_Widget` to render required headline metrics plus posts-with-links, latest backfill-run link totals, average links per changed post, and a top-keyword leaderboard with empty-state messaging.
  - Completed 2026-04-20: Added regression coverage in dashboard, linker, backfill scanner, and prototype smoke tests to verify metric coherence across repeated save/backfill runs.

- [x] Improve admin mappings UX for larger datasets:
  - Add search/filter/sort and pagination for keyword/url rows while preserving duplicate-keyword support
  - Add bulk actions for row deletion and validation feedback for malformed URLs/empty keywords
  - Keep all admin interactions nonce-protected and capability-gated
  - Completed 2026-04-20: Added paginated mapping queries in `Keyword_Mapping_Repository` and upgraded `Admin_Page` with search, keyword filtering, sortable columns, per-page controls, and pagination links while retaining duplicate-keyword row visibility.
  - Completed 2026-04-20: Added nonce-protected `clicklink_bulk_delete_mappings` admin action with capability checks, selected-row deletion, and user-facing success/error notices (including deleted-row counts).
  - Completed 2026-04-20: Expanded admin validation feedback to distinguish empty keyword, empty URL, and malformed URL input; added comprehensive regression assertions in `tests/test-admin-page.php` for new UX and security behavior.

- [x] Add operational safety controls and diagnostics:
  - Implement a safe reset tool for stats/backfill run state (without deleting mappings unless explicitly chosen)
  - Add structured debug logging hooks (toggleable) for linking and backfill errors to support troubleshooting
  - Add guardrails for unexpected HTML/content edge cases so failures degrade gracefully
  - Completed 2026-04-20: Added an admin-only, nonce/capability-gated operational reset tool in `Admin_Page` that resets global linker stats plus manual backfill run state by default, while deleting mappings only when an explicit checkbox is selected.
  - Completed 2026-04-20: Added toggleable structured diagnostics via `Runtime::debug_log()` / `Runtime::is_debug_logging_enabled()` with `clicklink_debug_logging_enabled` filter and `clicklink_debug_log` action, and instrumented linker/backfill failure paths.
  - Completed 2026-04-20: Hardened `Post_Save_Linker` content handling with fail-safe guards for oversized/invalid UTF-8 content and exception-safe linking fallback; expanded admin/linker/backfill regression tests accordingly.

- [x] Write comprehensive regression and acceptance tests:
  - Add integration tests covering save-time linking + manual backfill + stats coherence across repeated runs
  - Add admin action tests for mapping CRUD, bulk operations, and permission/nonce enforcement
  - Add performance-oriented tests/fixtures for larger post and mapping counts
  - Completed 2026-04-20: Expanded `tests/test-backfill-scanner.php` with acceptance-style save-time + backfill + rerun stats-coherence coverage across shared linker/scanner flows.
  - Completed 2026-04-20: Added large-scale fixtures in `tests/fixtures/performance-fixtures.php` and high-volume scanner assertions (120 posts, 90 mappings) including bounded query-call checks.
  - Completed 2026-04-20: Strengthened `tests/test-admin-page.php` with mixed bulk-delete coverage plus additional delete/reset capability and nonce enforcement assertions.
  - Completed 2026-04-20: Re-ran `sh tests/run-tests.sh` successfully after regression suite updates.

- [x] Run full quality verification and remediate issues:
  - Execute lint/static analysis/tests and resolve all failures
  - Run end-to-end smoke checks on a clean WordPress install with sample content volume
  - Validate that final behavior matches requirements: random URL choice per keyword, 5-link cap, paragraph-only linking, manual run-only backfill
  - Completed 2026-04-20: Ran repository-wide PHP lint (`php -l` across all plugin/test files), full regression suite (`sh tests/run-tests.sh`), and explicit smoke reruns (`php tests/test-prototype-smoke.php`, `php tests/test-backfill-scanner.php`); all checks passed with no remediation required.
  - Completed 2026-04-20: Verified high-volume sample coverage via `tests/test-backfill-scanner.php` performance fixtures (120 published posts, deterministic scanner completion/stat counters, bounded mapping query calls).
  - Completed 2026-04-20: Re-validated release-critical linker behavior through passing assertions for duplicate-keyword random URL selection, 5-link max cap enforcement, paragraph-only insertion/exclusion zones, and admin-triggered manual backfill-only flows (no scheduled cron hooks present).

- [ ] Package the plugin for distribution readiness:
  - Prepare the release artifact structure and verify activation/deactivation/uninstall flows are clean
  - Confirm text domain/loading metadata, versioning, and changelog-ready code comments where needed
  - Produce a shippable plugin package that can be installed and validated in a fresh WordPress environment
