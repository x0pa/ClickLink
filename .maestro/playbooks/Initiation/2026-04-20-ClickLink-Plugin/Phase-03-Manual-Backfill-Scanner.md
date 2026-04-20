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

- [ ] Implement backfill orchestration service with batch processing:
  - Create a scanner service that fetches eligible posts in fixed batches and tracks cursor/progress state
  - Persist run metadata (started_at, completed_at, processed_posts, inserted_links, failures) for admin visibility
  - Ensure idempotent behavior so reruns do not duplicate links in already-linked regions

- [ ] Build admin “Run Now” control surface:
  - Add a dedicated ClickLink admin screen section with start button, run summary, and current status
  - Provide clear counters for scanned posts, changed posts, inserted links, and remaining posts
  - Protect all actions with capability checks and nonces, and handle safe fallback when no posts are eligible

- [ ] Add manual execution endpoints (no scheduler):
  - Implement secure AJAX/admin-post endpoints for `start`, `next-batch`, and `cancel/reset` actions
  - Keep execution strictly user-triggered from admin UI; do not register WP-Cron schedules
  - Add timeout-aware batch sizing and resilient error handling so partial failures do not corrupt run state

- [ ] Integrate scanner with shared linker/statistics pipeline:
  - Route each scanned post through the same paragraph-only linker engine used on save
  - Reuse max-links-per-post and random URL selection rules consistently across save and backfill flows
  - Update global and per-run stats in one place to keep dashboard numbers accurate

- [ ] Create automated tests for scan workflow and endpoints:
  - Add tests for batch cursor progression, completion state, cancellation/reset, and no-op runs
  - Add integration tests that confirm scanner respects exclusion zones and link caps
  - Add failure-path tests for invalid permissions/nonces and malformed requests

- [ ] Execute validation and confirm operator-ready workflow:
  - Run all automated tests and quality checks, fix regressions, and re-run to green
  - Perform end-to-end admin smoke test: seed mappings/posts, run backfill, verify progress and final counts
  - Confirm a second “Run Now” pass completes safely without creating invalid duplicate links
