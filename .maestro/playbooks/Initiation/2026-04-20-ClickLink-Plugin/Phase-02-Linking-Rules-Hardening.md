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

- [x] Strengthen mapping retrieval and normalization pipeline:
  - Refactor mapping reads into a dedicated repository/service with cached grouped keyword collections
  - Normalize keyword matching inputs (trim/case strategy) while preserving original display values in admin UI
  - Add safe handling for duplicate rows, invalid/empty rows, and URL validation failures without breaking save flow
  - Completion notes (2026-04-20, loop 00001):
    - Added `includes/class-keyword-mapping-repository.php` and refactored `Post_Save_Linker` plus `Admin_Page` mapping reads to use the shared repository service.
    - Implemented cached grouped keyword collections for linker matching, with duplicate keyword+URL row deduplication and safe skipping of empty/invalid keyword or URL rows.
    - Centralized normalization helpers (`normalize_keyword_for_storage`, `normalize_keyword_for_matching`, `sanitize_url`) and updated admin save behavior to preserve display casing while still trimming/collapsing/sanitizing.
    - Wired a shared repository instance through `Plugin` and invalidated grouped cache after admin mapping insert/update/delete operations.
    - Expanded regression coverage in `tests/test-linker-focused.php`, `tests/test-admin-page.php`, and `tests/test-prototype-smoke.php`; full suite passed via `./tests/run-tests.sh`.

- [x] Implement robust paragraph-only HTML traversal and exclusion logic:
  - Parse and walk post HTML so replacements occur only within paragraph/body text nodes
  - Exclude heading tags (`h1-h6`), anchors, code/pre, script/style, and other non-content regions from mutations
  - Preserve original markup and entity encoding to avoid content corruption during replacement
  - Completion notes (2026-04-20, loop 00001):
    - Replaced regex paragraph matching in `includes/class-post-save-linker.php` with a stateful HTML token walker that tracks paragraph context and applies replacements only to paragraph text fragments.
    - Added explicit exclusion handling for anchors, headings (`h1-h6`), `code`/`pre`, `script`/`style`, and additional non-content regions (`noscript`, `template`, `textarea`, `title`, `svg`, `math`) to prevent unsafe mutations.
    - Added quote-aware HTML token parsing and raw-text-region handling (`script`/`style`) so `<`/`>` inside attributes or script/style payloads do not corrupt traversal.
    - Preserved source markup/entity encoding by rebuilding content from original token/text fragments instead of reserializing with DOM.
    - Expanded coverage with `clicklink_fixture_exclusion_and_encoding_content()` and new assertions in `tests/test-linker-focused.php`; validated with `./tests/run-tests.sh` (all tests passing).

- [x] Enforce business rules for link creation consistency:
  - Apply whole-keyword matching boundaries to prevent partial-word linking artifacts
  - Randomly select among multiple URLs for the same keyword each time a match is linked
  - Enforce a strict max-links-per-post cap (default 5) with deterministic stopping and safe no-op when cap is reached
  - Completion notes (2026-04-20, loop 00001):
    - Hardened `includes/class-post-save-linker.php` to stop HTML traversal immediately once `max_links_per_post` is reached, appending the remaining source content untouched for deterministic cap behavior.
    - Confirmed whole-keyword boundary and duplicate-keyword random URL behavior via focused linker assertions, including per-match random selector call tracking.
    - Added cap-specific regressions in `tests/test-linker-focused.php` covering deterministic earliest-match stopping at cap and safe no-op behavior when `max_links_per_post = 0`.
    - Validated with `php tests/test-linker-focused.php` and `./tests/run-tests.sh` (all tests passing).

- [x] Add comprehensive automated tests for hardened linking behavior:
  - Create unit tests for boundary matching, duplicate-keyword URL randomization, exclusion-zone protection, and cap enforcement
  - Add regression fixtures for HTML with nested tags, existing links, code snippets, and heading-heavy content
  - Add negative tests ensuring unchanged output when no valid mappings or no paragraph matches exist
  - Completion notes (2026-04-20, loop 00001):
    - Extended `tests/test-linker-focused.php` with dedicated assertions for nested inline HTML linking behavior while preserving existing anchors/code regions.
    - Added new fixtures in `tests/fixtures/linker-content.php` for nested paragraph markup and heading-heavy/no-paragraph content to strengthen regression coverage.
    - Added explicit negative-path tests verifying unchanged output and zero post updates when mappings are invalid or when content has no eligible paragraph text nodes.
    - Validated full plugin test coverage with `./tests/run-tests.sh` (all tests passing).

- [x] Run test suite and quality checks, then remediate failures:
  - Execute unit/integration test commands and static checks configured in the plugin
  - Fix all failing scenarios introduced by hardening refactors
  - Re-run the full suite until green and ensure Phase 01 behavior remains intact
  - Completion notes (2026-04-20, loop 00001):
    - Ran full configured test suite with `./tests/run-tests.sh` (compatibility, admin page, dashboard widget, installer, lifecycle, linker focused, post save linker, prototype smoke).
    - Ran repository-wide PHP syntax quality check with `rg --files -g '*.php' | xargs -I{} php -l {}`.
    - No failing scenarios were detected, so no remediation changes were required; full suite remained green and Phase 01 coverage stayed intact.

- [ ] Validate end-to-end author workflow against hardened rules:
  - Smoke test post save behavior in WordPress admin with multiple mappings sharing the same keyword
  - Confirm only paragraph content is linked and excluded zones remain untouched
  - Verify stats counters still align with actual inserted links after hardening changes
