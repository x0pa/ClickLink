# Manual Backfill Scanner Reuse-First Design

## Scope

Define the Phase 03 manual scanner architecture before implementation, with strict reuse of existing linker and metrics behavior.

## Reuse-First Decisions

- Reuse `ClickLink\Post_Save_Linker` as the only link insertion engine so save-time and backfill-time behavior stay identical (paragraph-only linking, exclusion zones, cap logic, random URL selection).
- Reuse `ClickLink\Keyword_Mapping_Repository::fetch_grouped_keyword_urls()` for keyword/url source of truth; no parallel mapping loader for scanning.
- Reuse `ClickLink\Linker_Stats::record_save_metrics()` for global cumulative stats updates so dashboard totals are updated from one shared path.
- Avoid duplicating normalization, URL sanitization, or keyword matching logic in scanner code.

## Single-Site Manual Scan Flow

1. Admin user clicks "Run Now" from ClickLink admin screen.
2. Scanner initializes run snapshot with `status = pending`, validates environment, then transitions to `running`.
3. Scanner fetches only eligible posts: `post_type = post`, `post_status = publish`, ordered by ascending post ID, in fixed-size batches.
4. For each post in batch, scanner routes content through the shared linker pipeline and records per-post result counters.
5. Scanner persists cursor and counters after each batch so progress survives page reloads/timeouts.
6. When no more eligible posts remain, scanner transitions to `completed` and records final timestamps.

## Explicit Non-Goals and Constraints

- Multisite stays unsupported; rely on existing `Compatibility` gate and do not add network-aware scan paths.
- No `wp_schedule_event`, cron registration, or background worker loop; execution is admin-triggered only.
- Scanner does not process pages, custom post types, drafts, or trash posts.

## Scanner State Model

Allowed states:

- `pending`: run initialized, not yet processing.
- `running`: actively processing batches.
- `completed`: run ended normally (including zero-eligible-post no-op).
- `error`: run stopped due to unrecoverable error and must be reset/restarted.

Transition rules:

- `pending -> running` on successful start.
- `running -> completed` when cursor reaches end.
- `running -> error` on unrecoverable batch/start failure.
- `error -> pending` only via explicit reset action.
- `completed -> pending` only when operator starts a new run.

## Persistence Model (Pre-Code Contract)

Store scanner run state in a dedicated option (autoload disabled), `clicklink_backfill_run_state`, as an associative array:

- `status` (`pending|running|completed|error`)
- `started_at` (UTC MySQL datetime or empty)
- `completed_at` (UTC MySQL datetime or empty)
- `cursor_post_id` (last processed post ID, integer)
- `processed_posts` (integer)
- `changed_posts` (integer)
- `inserted_links` (integer)
- `failures` (integer)
- `last_error` (string)
- `batch_size` (integer)
- `total_eligible_posts` (integer snapshot)

Rationale:

- Option persistence is consistent with current plugin patterns (`clicklink_options`, `clicklink_stats`).
- Single object keeps read/write operations simple for admin UI status polling.
- Cursor-by-post-ID makes resume deterministic and avoids offset drift when posts change during a run.

## Implementation Guardrails

- Expose a reusable method on `Post_Save_Linker` for scanner invocation rather than reimplementing link transforms.
- Keep per-run stats persistence separate from global totals, but call shared stats recorder for link insertions.
- Ensure reruns are idempotent by relying on content-hash/meta plus existing exclusion/link detection behavior.
