# Phase 02: Linking Rules Hardening

This phase converts the prototype linker into a production-safe content transformer by tightening keyword matching behavior, enforcing paragraph-only replacement guarantees, and validating edge cases with robust automated tests.

## Tasks

- [x] Audit and reuse Phase 01 implementation patterns before extending behavior:
  - Review existing linker, settings, and storage modules first and preserve established naming/style conventions
  - Reuse current helper utilities and only introduce new abstractions where duplication or risk is clear
  - Confirm no multisite paths were introduced and keep single-site assumptions explicit
  - Completion notes (2026-04-20, loop 00001):
    - Audited `includes/class-post-save-linker.php`, `admin/class-admin-page.php`, `includes/class-installer.php`, `includes/class-compatibility.php`, `includes/class-lifecycle.php`, and `includes/class-plugin.php` for Phase 01 conventions.
    - Confirmed existing reusable patterns to preserve in Phase 02: strict guard-first methods, shared `Installer::table_name()` lookup, array-shape annotations, and WordPress-function fallbacks for sanitization/escaping.
    - Confirmed helper duplication risk is already visible in `normalize_keyword()` and `sanitize_url()` across linker/admin modules; Phase 02 should centralize these rather than introduce parallel implementations.
    - Confirmed single-site assumptions remain explicit (`Network: false` plugin header, `Compatibility::is_multisite()` gate, multisite activation rejection/deactivation path in lifecycle).

- [ ] Strengthen mapping retrieval and normalization pipeline:
  - Refactor mapping reads into a dedicated repository/service with cached grouped keyword collections
  - Normalize keyword matching inputs (trim/case strategy) while preserving original display values in admin UI
  - Add safe handling for duplicate rows, invalid/empty rows, and URL validation failures without breaking save flow

- [ ] Implement robust paragraph-only HTML traversal and exclusion logic:
  - Parse and walk post HTML so replacements occur only within paragraph/body text nodes
  - Exclude heading tags (`h1-h6`), anchors, code/pre, script/style, and other non-content regions from mutations
  - Preserve original markup and entity encoding to avoid content corruption during replacement

- [ ] Enforce business rules for link creation consistency:
  - Apply whole-keyword matching boundaries to prevent partial-word linking artifacts
  - Randomly select among multiple URLs for the same keyword each time a match is linked
  - Enforce a strict max-links-per-post cap (default 5) with deterministic stopping and safe no-op when cap is reached

- [ ] Add comprehensive automated tests for hardened linking behavior:
  - Create unit tests for boundary matching, duplicate-keyword URL randomization, exclusion-zone protection, and cap enforcement
  - Add regression fixtures for HTML with nested tags, existing links, code snippets, and heading-heavy content
  - Add negative tests ensuring unchanged output when no valid mappings or no paragraph matches exist

- [ ] Run test suite and quality checks, then remediate failures:
  - Execute unit/integration test commands and static checks configured in the plugin
  - Fix all failing scenarios introduced by hardening refactors
  - Re-run the full suite until green and ensure Phase 01 behavior remains intact

- [ ] Validate end-to-end author workflow against hardened rules:
  - Smoke test post save behavior in WordPress admin with multiple mappings sharing the same keyword
  - Confirm only paragraph content is linked and excluded zones remain untouched
  - Verify stats counters still align with actual inserted links after hardening changes
