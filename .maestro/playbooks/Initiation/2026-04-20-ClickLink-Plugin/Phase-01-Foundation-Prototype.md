# Phase 01: Foundation Prototype

This phase creates a fully runnable ClickLink plugin baseline that installs cleanly, exposes an admin mapping UI, auto-links keywords on post save, and shows starter statistics so there is immediate visible functionality without requiring any user decisions during execution.

## Tasks

- [x] Bootstrap the plugin foundation and reuse-first structure:
  - Inspect the repository first (`rg --files`, `rg "Plugin Name|add_action|register_activation_hook"`) and reuse any existing WordPress/plugin patterns before creating new files
  - Create the initial plugin scaffold (`clicklink.php`, `includes/`, `admin/`, `assets/`) with a single bootstrap flow and namespaced/class-prefixed modules
  - Add plugin header metadata, constants, autoload/include wiring, activation/deactivation hooks, capability checks, and guardrails for latest stable WordPress/PHP compatibility (no multisite behavior)
  - Completion note (2026-04-20, loop 00001): No reusable plugin code existed in-repo, so a full foundation scaffold was added with bootstrap/autoloader/lifecycle/admin capability checks and multisite/environment guardrails, plus passing automated bootstrap tests.

- [x] Implement persistent storage and install migrations:
  - Create install/upgrade routines with `dbDelta` for a keyword mapping table that allows duplicate keywords with different URLs
  - Add indexes for keyword and timestamps, plus schema version tracking option for future migrations
  - Add plugin options with sane defaults (including `max_links_per_post = 5`) so Phase 01 is executable without prompts
  - Completion note (2026-04-20, loop 00001): Added `Installer` migration/runtime upgrade flow with `dbDelta` schema creation for `clicklink_keyword_mappings` (non-unique keyword rows), indexes on keyword/created_at/updated_at, schema version tracking via `clicklink_schema_version`, and default `clicklink_options` initialization with `max_links_per_post = 5`, validated by new installer tests plus existing suite.

- [ ] Build the admin mappings page for CRUD management:
  - Add an admin menu page under the dashboard for ClickLink settings
  - Implement a secure mappings table UI with add/edit/delete actions for `keyword + url` rows (duplicate keyword rows allowed)
  - Apply nonce validation, capability checks, URL sanitization, keyword normalization, and success/error admin notices

- [ ] Create the post-save auto-linking prototype engine:
  - Hook into post save for blog posts only, skipping autosaves/revisions and unchanged content saves
  - Reuse helper patterns where possible, then implement a linker service that selects a random URL when a keyword has multiple rows
  - Process content body paragraphs only and skip headings, code/pre blocks, and existing anchors while enforcing the 5-links-per-post cap

- [ ] Add baseline metrics capture and stats widget output:
  - Record per-save link insertion counts and cumulative totals needed for the admin widget
  - Create an admin dashboard/widget panel showing: total blog posts, total keyword/url rows, total links inserted, and posts touched by linker
  - Ensure stats update immediately after save operations and are safe if no mappings exist

- [ ] Write Phase 01 automated validation coverage:
  - Add focused unit tests for keyword matching, random URL selection constraints, paragraph-only replacement behavior, and post-level link cap enforcement
  - Add install/migration tests for mapping table creation and default option initialization
  - Keep tests isolated and deterministic with reusable fixtures for sample post content

- [ ] Run verification and produce a working prototype handoff state:
  - Execute lint/syntax and test commands (`php -l`, PHPUnit/WordPress test runner if configured) and fix failures
  - Perform a local smoke path: activate plugin, add sample mappings, save a sample post, and confirm links/stats appear
  - Leave the plugin in a runnable state with clear in-code defaults so the next phase can build directly on this baseline
