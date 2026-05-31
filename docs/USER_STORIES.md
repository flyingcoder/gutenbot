# GutenBot — User Stories

Stories are grouped by persona. Each story includes acceptance criteria derived from the SRS functional requirements.

---

## Persona: Content Editor

An agency employee who builds new pages on client WordPress sites.

---

### US-01 — Generate a page layout from raw content

**As a** content editor,
**I want to** paste raw page copy and click a Generate button in the block editor sidebar,
**so that** I receive a structured Gutenberg layout without having to manually choose and configure blocks.

**Acceptance criteria:**
- The sidebar shows a textarea for raw content and a page-type dropdown (Auto-detect, Service page, Guide, Location page).
- Clicking Generate sends the content to the API and shows a loading state.
- The Generate button is disabled while the request is in flight to prevent double-submission.
- Results appear as a section list (type + headline per section) within 20 seconds for content up to 1,000 words.
- At least 3 sections are returned for a typical 500-word service page description.
- The full Gutenberg markup is shown as read-only text for inspection.

**Maps to:** FR-09, FR-10, FR-11, FR-12, FR-13, AC-03

---

### US-02 — Insert generated blocks into the editor

**As a** content editor,
**I want to** click "Insert into editor" after reviewing the generated sections,
**so that** the blocks appear at my cursor position without a page reload.

**Acceptance criteria:**
- Clicking Insert parses `full_markup` using `wp.blocks.parse`.
- Blocks appear in the editor at the current cursor position immediately.
- No page reload is required.
- The inserted markup passes WordPress's `has_blocks()` check.

**Maps to:** FR-14, AC-04

---

### US-03 — Rate a generated section

**As a** content editor,
**I want to** give a thumbs-up or thumbs-down to each generated section,
**so that** the system learns which block patterns work well for this site.

**Acceptance criteria:**
- Each section in the preview panel has a thumbs-up and thumbs-down button.
- Clicking either immediately sends a rating request; no additional confirmation needed.
- Rating value 1 (accept) or -1 (reject) is written to `sections.rating` in Supabase within 5 seconds.
- The `accept_rate` on the relevant `block_pattern` row is recalculated after every rating event.

**Maps to:** FR-15, FR-17, AC-05, AC-06

---

### US-04 — Signal that I edited a section after inserting

**As a** content editor,
**I want to** rate a section as "edited" when I changed the generated block after inserting it,
**so that** the system records that the layout needed manual adjustment.

**Acceptance criteria:**
- When a section has been inserted and subsequently modified, the sidebar allows the user to submit a rating with `was_edited = true`.
- The rating request includes `was_edited: true` and stores correctly in Supabase.

**Maps to:** FR-16

---

### US-05 — Choose the page type before generating

**As a** content editor,
**I want to** select a page type hint (or let the system auto-detect),
**so that** the generated sections match the conventions for that page type.

**Acceptance criteria:**
- The page-type dropdown offers: Auto-detect, Service page, Guide, Location page.
- Selecting a type sends it as part of the generate request.
- Auto-detect lets the LLM infer the type from the content.

**Maps to:** FR-09, FR-11

---

## Persona: Agency Admin

The agency team lead who sets up the plugin on client sites and monitors status.

---

### US-06 — Configure connection settings

**As an** agency admin,
**I want to** enter the Edge Function URL, Anon Key, and LLM Provider in the plugin settings page,
**so that** the plugin can sync scan data to Supabase and trigger AI generation.

**Acceptance criteria:**
- A settings page exists under Settings > AI Page Builder.
- The page shows fields for Edge Function URL, Anon Key, and LLM Provider.
- Saving these settings resets `aipb_sync_status` to `pending` — it does **not** trigger an automatic rescan.
- The "Sync to Supabase" button is hidden until all three connection fields are saved.
- An info notice appears on activation if a site scan has not yet been run.

**Maps to:** FR-18, FR-08, FR-19, FR-03, AC-02, AC-10

---

### US-07 — Monitor scan and sync status independently

**As an** agency admin,
**I want to** see separate status indicators for the site scan and the Supabase sync on the settings page,
**so that** I can tell at a glance whether data has been scanned locally and whether it has been pushed to Supabase.

**Acceptance criteria:**
- The settings page shows a **Site Scan** section with its own status badge (`pending`, `complete`, `error`) and last-scanned timestamp.
- The settings page shows a **Supabase Sync** section with its own status badge (`pending`, `pending_settings`, `complete`, `error`), last-synced timestamp, and Client UUID.
- Errors are shown inline within the relevant section — no separate notice banners required.
- Scan status and sync status can differ (e.g. scan complete but sync pending).

**Maps to:** FR-18, FR-19, FR-20, AC-09

---

### US-08 — Activate the plugin without disrupting the site

**As an** agency admin,
**I want** plugin activation to be non-blocking,
**so that** the WordPress admin remains responsive while the plugin sets up.

**Acceptance criteria:**
- Activation creates the `{prefix}_gutenbot_indexed_posts` table via `dbDelta()` — safe and idempotent.
- Activation schedules an `aipb_site_scan` WP-Cron event (+5 seconds) rather than running synchronously.
- The activation hook makes **no outbound HTTP requests** — the scan runs locally, the sync is always user-initiated.
- The site scan runs without any connection settings being present.

**Maps to:** FR-02, FR-03, AC-02

---

### US-09 — Receive a stable client UUID on each site

**As an** agency admin,
**I want** each client site to have a stable, unique identifier,
**so that** data in Supabase is correctly isolated per client.

**Acceptance criteria:**
- On first activation a UUID is generated and stored in `wp_options` under `aipb_client_id`.
- Re-activating the plugin does not overwrite the existing UUID.
- The UUID is visible on the settings page.

**Maps to:** FR-01, FR-18, AC-09

---

## Persona: Developer

An engineer building or maintaining the plugin and Edge Function.

---

### US-10 — Run the full generation flow against a local LLM

**As a** developer,
**I want to** switch the Edge Function to use a local Ollama instance,
**so that** I can test generation without incurring Anthropic API costs.

**Acceptance criteria:**
- Enabling the "Local / Dev Mode" checkbox on the settings page (or defining `GUTENBOT_LOCAL_MODE` in `wp-config.php`) routes all LLM calls to Ollama via `OLLAMA_BASE_URL`.
- No requests are made to `api.anthropic.com` in local mode.
- The full generate flow (content analysis + block generation) completes successfully against a local model.

**Maps to:** FR-34 (LLM abstraction), AC-07

---

### US-11 — Ensure REST endpoints are authenticated

**As a** developer,
**I want** all plugin REST endpoints to reject unauthenticated requests,
**so that** no unauthorised party can trigger generation or read onboarding data.

**Acceptance criteria:**
- `POST /generate`, `POST /rate`, and `GET /status` all return `HTTP 401` without a valid WordPress session and `edit_pages` capability.
- Unauthenticated curl requests confirm 401 responses.

**Maps to:** FR-25, AC-08

---

### US-12 — Fall back to core blocks when no pattern exists

**As a** developer,
**I want** generation to fall back to `core/*` blocks when no site-specific pattern exists for a section type,
**so that** generation never fails due to a missing pattern.

**Acceptance criteria:**
- When `block_patterns` has no entry for the requested `section_type`, the LLM prompt requests markup using `core/group`, `core/heading`, `core/paragraph`, `core/list`, and `core/buttons`.
- The returned markup uses only `core/` block names.
- The sidebar displays the fallback result without error.

**Maps to:** FR-12, FR-30, AC-12

---

### US-13 — Block scanner targets core and theme blocks only (MVP)

**As a** developer,
**I want** the block scanner to index only WordPress core blocks and the active theme's custom blocks,
**so that** the MVP ships with a predictable, well-defined scope and is not broken by third-party block libraries installed on client sites.

**Acceptance criteria:**
- The scanner records blocks whose `blockName` begins with `core/` or matches the active theme's registered block namespace.
- Blocks from third-party libraries (e.g. `kadence/`, `generateblocks/`, `stackable/`) are silently skipped during the scan — they are not recorded in `aipb_block_registry`.
- Generation still succeeds on sites that have third-party blocks installed, using the core-block fallback for any unrecognised section type.
- No error or admin notice is shown due to skipped third-party blocks.

**Maps to:** MVP scope decision; AC-12

---

### US-14 — Keep API credentials off WordPress servers

**As a** developer,
**I want** the Anthropic API key and Supabase service role key to reside only in Edge Function environment variables,
**so that** a compromised WordPress server cannot expose those credentials.

**Acceptance criteria:**
- Neither key appears in any PHP file, `wp_options` row, HTTP response to the browser, or Edge Function response body.
- The `/status` endpoint masks the Edge Function URL (shows partial string only).

**Maps to:** FR-24, FR-20

---

---

### US-15 — Run a site scan without connection settings

**As an** agency admin,
**I want to** scan the site's blocks and page patterns immediately after activation,
**so that** local scan data is ready before I have configured Supabase credentials.

**Acceptance criteria:**
- The "Scan Site" button on the settings page is always enabled regardless of whether connection settings are saved.
- Clicking Scan Site triggers `aipb_site_scan`, runs `Indexer::scan_incremental()`, and redirects back with a success or error notice.
- `aipb_scan_status` reaches `complete` on a site with no Edge Function URL configured.
- No outbound HTTP requests are made during the scan.

**Maps to:** Group 1 separation from INDEXING_REFACTOR_PLAN.md

---

### US-16 — Sync to Supabase without a page reload

**As an** agency admin,
**I want to** push scan data to Supabase by clicking a button that responds inline,
**so that** I get immediate feedback without waiting for a full page reload.

**Acceptance criteria:**
- Clicking "Sync to Supabase" sends a jQuery AJAX request to `admin-ajax.php`.
- The button shows "Syncing…" and is disabled while the request is in flight.
- On success: the status badge updates to "Complete" and the last-synced timestamp appears — no page reload.
- On failure: an inline error message appears — no page reload.
- The button is re-enabled after success or failure.
- The sync button is only shown when Edge Function URL, Anon Key, and Provider are all saved.

**Maps to:** Group 2 separation from INDEXING_REFACTOR_PLAN.md

---

### US-17 — Rescan only changed pages

**As an** agency admin,
**I want** subsequent site scans to reprocess only pages that have changed since the last scan,
**so that** scanning a large site after a minor content update completes quickly.

**Acceptance criteria:**
- The scanner queries `{prefix}_gutenbot_indexed_posts` and compares each post's `post_modified_gmt` against `scanned_at`.
- Posts where `post_modified_gmt ≤ scanned_at` are skipped entirely.
- Only new posts or posts modified since the last scan are re-parsed.
- Rows for deleted or unpublished posts are removed from the table.
- The aggregated `wp_options` keys (`aipb_block_registry`, `aipb_design_tokens`, `aipb_patterns`) are rebuilt from the full table after every scan, regardless of how many posts were changed.
- `aipb_scan_status` is set to `complete` and `aipb_scanned_at` is updated after every successful incremental scan.

**Maps to:** Change 1 from INDEXING_REFACTOR_PLAN.md

---

## Story Map Summary

| Theme | Stories |
|-------|---------|
| Content Generation | US-01, US-02, US-05 |
| Quality Feedback | US-03, US-04 |
| Settings & Configuration | US-06, US-07, US-09 |
| Activation & Onboarding | US-08, US-15, US-17 |
| Supabase Sync | US-16 |
| Developer / Ops | US-10, US-11, US-12, US-13, US-14 |
