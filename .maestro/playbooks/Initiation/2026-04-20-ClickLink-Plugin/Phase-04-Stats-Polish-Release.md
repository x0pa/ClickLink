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

- [ ] Expand the statistics dashboard/widget into an operator-focused analytics view:
  - Preserve required headline metrics (total blog posts, total links created, total mapping rows)
  - Add additional useful stats (posts with links, links added by backfill run, average links per changed post, top matched keywords)
  - Ensure metrics are query-efficient and remain accurate after repeated save and backfill operations

- [ ] Improve admin mappings UX for larger datasets:
  - Add search/filter/sort and pagination for keyword/url rows while preserving duplicate-keyword support
  - Add bulk actions for row deletion and validation feedback for malformed URLs/empty keywords
  - Keep all admin interactions nonce-protected and capability-gated

- [ ] Add operational safety controls and diagnostics:
  - Implement a safe reset tool for stats/backfill run state (without deleting mappings unless explicitly chosen)
  - Add structured debug logging hooks (toggleable) for linking and backfill errors to support troubleshooting
  - Add guardrails for unexpected HTML/content edge cases so failures degrade gracefully

- [ ] Write comprehensive regression and acceptance tests:
  - Add integration tests covering save-time linking + manual backfill + stats coherence across repeated runs
  - Add admin action tests for mapping CRUD, bulk operations, and permission/nonce enforcement
  - Add performance-oriented tests/fixtures for larger post and mapping counts

- [ ] Run full quality verification and remediate issues:
  - Execute lint/static analysis/tests and resolve all failures
  - Run end-to-end smoke checks on a clean WordPress install with sample content volume
  - Validate that final behavior matches requirements: random URL choice per keyword, 5-link cap, paragraph-only linking, manual run-only backfill

- [ ] Package the plugin for distribution readiness:
  - Prepare the release artifact structure and verify activation/deactivation/uninstall flows are clean
  - Confirm text domain/loading metadata, versioning, and changelog-ready code comments where needed
  - Produce a shippable plugin package that can be installed and validated in a fresh WordPress environment
