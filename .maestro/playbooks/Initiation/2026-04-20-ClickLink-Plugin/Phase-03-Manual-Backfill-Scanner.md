# Phase 03: Manual Backfill Scanner

This phase adds a manual “Run Now” scanner that processes older blog posts with the same hardened linking rules used on save, delivering controlled bulk linking with visible progress and no scheduled/background automation.

## Tasks

- [x] Reuse-first design pass for historical scan architecture:
  - Inspect existing save-time linker/statistics services and reuse them directly to avoid duplicate linking logic
  - Define a single-site scan flow for published blog posts only, explicitly excluding multisite and scheduled-cron behaviors
  - Confirm scanner state model (pending/running/completed/error) and persistence approach before coding
  - Completion notes (2026-04-20, loop 00001):
    - Audited existing Phase 02 linker/stats pipeline (`Post_Save_Linker`, `Keyword_Mapping_Repository`, `Linker_Stats`) and locked a reuse-first contract that forbids duplicate keyword matching or stats-update logic in scanner code.
    - Defined single-site manual scan flow for `post_type=post` + `post_status=publish` batches only, with explicit exclusions for multisite paths and any cron/scheduler automation.
    - Confirmed scanner state machine and persistence contract before implementation: `pending/running/completed/error` stored in a dedicated non-autoload option with cursor + run counters; full design captured in `docs/architecture/manual-backfill-scanner-design.md`.

- [x] Implement backfill orchestration service with batch processing:
  - Create a scanner service that fetches eligible posts in fixed batches and tracks cursor/progress state
  - Persist run metadata (started_at, completed_at, processed_posts, inserted_links, failures) for admin visibility
  - Ensure idempotent behavior so reruns do not duplicate links in already-linked regions
  - Completion notes (2026-04-20, loop 00001):
    - Added `ClickLink\Backfill_Scanner` with cursor-based published-post batching (`ID` ascending), explicit state transitions (`pending/running/completed/error`), and non-autoload run-state persistence in `clicklink_backfill_run_state`.
    - Persisted run metadata for admin visibility: `started_at`, `completed_at`, `processed_posts`, `changed_posts`, `inserted_links`, `failures`, `last_error`, `batch_size`, and `total_eligible_posts`.
    - Extended `Post_Save_Linker` with reusable `process_post()` orchestration entrypoint so scanner runs reuse the existing save-time linker/statistics behavior; verified idempotent reruns do not create duplicate links.

- [x] Build admin “Run Now” control surface:
  - Add a dedicated ClickLink admin screen section with start button, run summary, and current status
  - Provide clear counters for scanned posts, changed posts, inserted links, and remaining posts
  - Protect all actions with capability checks and nonces, and handle safe fallback when no posts are eligible
  - Completion notes (2026-04-20, loop 00001):
    - Added a `Manual Backfill Scanner` panel to the ClickLink admin page with a nonce-protected `Run Now` control, current status label, run timestamps, and summary counters for scanned posts, changed posts, inserted links, and remaining posts.
    - Implemented admin-post start handling (`clicklink_backfill_start`) with capability + nonce checks and guarded redirects for already-running runs, successful run initialization, and start failures.
    - Added safe no-eligible-post fallback behavior by exposing `Backfill_Scanner::current_eligible_posts()` and using it to disable `Run Now`, show operator guidance, and prevent start attempts when no published blog posts are available.

- [x] Add manual execution endpoints (no scheduler):
  - Implement secure AJAX/admin-post endpoints for `start`, `next-batch`, and `cancel/reset` actions
  - Keep execution strictly user-triggered from admin UI; do not register WP-Cron schedules
  - Add timeout-aware batch sizing and resilient error handling so partial failures do not corrupt run state
  - Completion notes (2026-04-20, loop 00001):
    - Added secure manual execution handlers for all scanner actions in `Admin_Page`: `admin_post_*` and `wp_ajax_*` endpoints for `clicklink_backfill_start`, `clicklink_backfill_next_batch`, and `clicklink_backfill_reset`, each protected by capability checks and action-specific nonces.
    - Extended the admin scanner panel with explicit user-triggered controls for `Run Now`, `Process Next Batch`, and `Cancel / Reset Run`, with status-driven button disabling and operator notices for running/completed/error/reset flows.
    - Hardened `Backfill_Scanner` execution for partial-failure resilience by persisting state after each processed post, adding explicit reset support, handling remaining-post lookup failures as `error` (instead of false completion), and capping requested batch sizes with timeout-aware limits.
    - Kept execution manual-only with no cron/scheduler registration added anywhere in plugin bootstrap or admin wiring.

- [x] Integrate scanner with shared linker/statistics pipeline:
  - Route each scanned post through the same paragraph-only linker engine used on save
  - Reuse max-links-per-post and random URL selection rules consistently across save and backfill flows
  - Update global and per-run stats in one place to keep dashboard numbers accurate
  - Completion notes (2026-04-20, loop 00001):
    - Updated `Backfill_Scanner` to route each batch item through shared `Post_Save_Linker::process_post()` in backfill mode (`$update=false`) so manual scans always execute the same paragraph-only matcher/link-cap/random-URL rules rather than save-event hash short-circuiting.
    - Centralized per-run counter aggregation in scanner state via `record_link_result()` while keeping global totals sourced from the shared `Linker_Stats::record_save_metrics()` path invoked by `Post_Save_Linker`.
    - Wired plugin bootstrap to inject the same `Post_Save_Linker` instance into `Backfill_Scanner` (`Plugin::run()` -> `Admin_Page`) so save and backfill flows share one linker/statistics orchestration service.
    - Added regression coverage in `tests/test-backfill-scanner.php` for hashed-content backfill processing and verified global stats correctness; extended `tests/test-prototype-smoke.php` to assert plugin-level manual backfill handler registration.

- [x] Create automated tests for scan workflow and endpoints:
  - Add tests for batch cursor progression, completion state, cancellation/reset, and no-op runs
  - Add integration tests that confirm scanner respects exclusion zones and link caps
  - Add failure-path tests for invalid permissions/nonces and malformed requests
  - Completion notes (2026-04-20, loop 00001):
    - Extended `tests/test-backfill-scanner.php` with scanner no-op run coverage (zero eligible published posts) and explicit completion metadata assertions so batch workflow behavior is validated even when nothing can be processed.
    - Added scanner integration regression coverage using `clicklink_fixture_exclusion_and_encoding_content()` to verify exclusion-zone safety (`script`, `style`, `textarea`, headings) and enforcement of `max_links_per_post` caps during backfill execution.
    - Expanded `tests/test-admin-page.php` endpoint coverage with scanner AJAX success/failure paths, malformed-request nonce omission handling, and capability-denial assertions for scanner actions alongside existing admin-post nonce checks.

- [ ] Execute validation and confirm operator-ready workflow:
  - Run all automated tests and quality checks, fix regressions, and re-run to green
  - Perform end-to-end admin smoke test: seed mappings/posts, run backfill, verify progress and final counts
  - Confirm a second “Run Now” pass completes safely without creating invalid duplicate links
