# GutenBot — Modules & Features

Each PHP class, JS module, and feature area of the WordPress plugin layer explained.

> **Scope reminder:** This document covers the `guten-bot` repository only. The Supabase Edge Function and PostgreSQL database are in `gutenbot-edge`.

---

## Plugin Entry Point — `gutenbot.php`

The root plugin file. WordPress loads this file when the plugin is activated.

**Responsibilities:**
- Declares plugin metadata (name, version, PHP requirement, text domain) in the file header comment read by WordPress.
- Defines three constants available globally across the plugin:
  - `GUTENBOT_VERSION` — current plugin version string.
  - `GUTENBOT_PLUGIN_DIR` — absolute filesystem path to the plugin root, used for `require` calls.
  - `GUTENBOT_PLUGIN_URL` — public URL to the plugin root, used for asset enqueuing.
- Loads the Composer autoloader (`vendor/autoload.php`), which makes all classes in `includes/` available via the `GutenBot\` namespace.
- Registers the activation hook pointing to `GutenBot\Activator::activate()`.
- Hooks into `plugins_loaded` to instantiate `GutenBot\Hooks` and call `register()`, wiring all WordPress actions and filters.

**Why `plugins_loaded` and not `init`:** Using `plugins_loaded` ensures the plugin's hooks are registered before WordPress runs `init`, avoiding race conditions with other plugins.

---

## Activator — `includes/class-activator.php`

Runs exactly once when an admin clicks "Activate" in WP Admin.

### UUID Generation
Generates a stable, unique identifier for this WordPress site using `wp_generate_uuid4()` and stores it in `wp_options` under `aipb_client_id`. This UUID is sent with every request to the Edge Function and is the primary key that isolates this site's data in Supabase.

- **Idempotent:** checks `get_option('aipb_client_id')` first. Existing UUIDs are never overwritten on re-activation.

### Table Creation
Creates the `{prefix}_gutenbot_indexed_posts` custom table using WordPress's `dbDelta()`. Idempotent — safe on re-activation. See [Custom DB Table](#custom-db-table----prefix_gutenbot_indexed_posts) for the full schema.

### Scan Cron Scheduling
Schedules the `aipb_site_scan` single cron event to fire 5 seconds after activation. The short delay returns the activation response to the browser before any scanning begins.

- **Idempotent:** checks `wp_next_scheduled('aipb_site_scan')` to prevent duplicate scheduling.
- Makes **no outbound HTTP calls** during activation — scan and Supabase sync are separate operations.

**`wp_options` keys written:**

| Key | Value | Autoload |
|-----|-------|----------|
| `aipb_client_id` | UUID v4 string | `false` |

---

## Hooks — `includes/class-hooks.php`

The central wiring class. Instantiated once on `plugins_loaded` and responsible for registering every WordPress action and filter.

### Hook Registration (`register()`)

| Hook | Handler | Purpose |
|------|---------|---------|
| `init` | `on_init()` | Reserved for future use |
| `rest_api_init` | `Rest::register_routes()` | Registers REST endpoints |
| `admin_menu` | `Admin::register_settings_page()` | Adds settings page to WP Admin |
| `admin_init` | `Admin::register_settings()` | Registers settings fields |
| `admin_notices` | `Admin::show_notices()` | Renders admin notice banners |
| `enqueue_block_editor_assets` | `enqueue_editor_assets()` | Loads Gutenberg sidebar JS/CSS |
| `aipb_site_scan` | `run_site_scan()` | WP-Cron / manual scan handler |
| `aipb_supabase_sync` | `run_supabase_sync()` | Internal sync action (called by AJAX handler) |
| `admin_post_gutenbot_scan_site` | `Admin::handle_scan_site()` | Form POST handler for Scan Site button |
| `admin_post_gutenbot_sync_supabase` | `Admin::handle_sync_supabase()` | Form POST fallback for sync (no-JS) |
| `wp_ajax_gutenbot_sync_supabase` | `ajax_sync_supabase()` | jQuery AJAX handler for Sync button |
| `update_option_aipb_edge_function_url` | `on_connection_setting_saved()` | Resets sync status when URL changes |
| `update_option_aipb_anon_key` | `on_connection_setting_saved()` | Resets sync status when key changes |

### Site Scan (`run_site_scan()`)
The background scan job fired by WP-Cron or the Scan Site button. Does **not** require connection settings.

1. Calls `Indexer::scan_incremental()`.
2. On success → saves aggregated results to `wp_options`, sets `aipb_scan_status = complete`.
3. On failure → sets `aipb_scan_status = error`, stores the error message.

### Supabase Sync (`run_supabase_sync()`)
Reads scan data from `wp_options` and pushes it to the Edge Function. Requires connection settings.

1. Reads `aipb_edge_function_url` and `aipb_anon_key` from `wp_options`.
2. If either is empty → sets `aipb_sync_status = pending_settings` and exits.
3. If no scan data exists → sets `aipb_sync_status = error` with message "Run a site scan first."
4. Calls `AiClient::sync_client()` with registry, tokens, patterns, and indexed pages.
5. On success → saves `aipb_client_id` (if returned), sets `aipb_sync_status = complete`.
6. On failure → sets `aipb_sync_status = error`, stores the error message.

### AJAX Sync Handler (`ajax_sync_supabase()`)
Called by the jQuery AJAX request from the settings page. Validates nonce and capability, delegates to `run_supabase_sync()`, then returns a JSON response — no redirect.

### Connection Setting Change Handler (`on_connection_setting_saved()`)
Fires when `aipb_edge_function_url` or `aipb_anon_key` is updated. Resets `aipb_sync_status` to `pending` so the admin knows a new sync is needed. Does **not** auto-schedule a scan.

**`wp_options` keys written:**

| Key | Possible values |
|-----|----------------|
| `aipb_scan_status` | `pending` · `complete` · `error` |
| `aipb_scan_error` | Error message string (deleted on success) |
| `aipb_scanned_at` | MySQL UTC datetime |
| `aipb_sync_status` | `pending` · `pending_settings` · `complete` · `error` |
| `aipb_sync_error` | Error message string (deleted on success) |
| `aipb_synced_at` | MySQL UTC datetime |
| `aipb_block_registry` | Serialized array from `Indexer` |
| `aipb_design_tokens` | Serialized array from `Indexer` |
| `aipb_patterns` | Serialized array from `PatternBuilder` |

---

## REST API — `includes/class-rest.php`

Exposes three authenticated REST endpoints under the namespace `ai-pagebuilder/v1`.

### Authentication
All endpoints require a valid WordPress session with the `edit_pages` capability. Unauthenticated requests receive `HTTP 401`.

---

### `GET /wp-json/ai-pagebuilder/v1/status`

Returns the plugin's current state. Reads `wp_options` only — no outbound HTTP.

**Response:**
```json
{
  "success": true,
  "data": {
    "client_id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "onboarding_status": "complete",
    "onboarded_at": "2025-05-28 10:00:00",
    "edge_function_url": "https://abc.supabase.co/functions/v1/g..."
  }
}
```

The `edge_function_url` is masked to the first 30 characters to prevent exposing the full URL in browser responses.

---

### `POST /wp-json/ai-pagebuilder/v1/generate`

Accepts raw page content and returns Gutenberg block markup grouped by section using the three-phase template synthesis algorithm.

**Request parameters (all required):**

| Parameter | Type | Validation |
|-----------|------|-----------|
| `content` | string | 1–5000 chars; sanitized via `sanitize_textarea_field` |
| `page_type` | string | One of: `guide`, `service`, `location`, `blog`, `custom` |
| `post_id` | integer | Positive integer; the current post being edited |

**Generation algorithm (executed in the Edge Function):**

1. **Phase 1 — Content Parsing:** The raw content is split into sections at natural boundaries (`---`, `===`, Markdown headings, blank-line clusters). An LLM pass classifies each section's `heading`, `body`, and `semantic_intent`. `page_type` and section count are resolved.

2. **Phase 2 — Layout Retrieval:** The Edge Function queries `indexed_pages` for a published page with the same `page_type`, ordered by `engagement_score DESC`. The template's `section_order` and per-section `block_tree`s become the structural scaffold.

3. **Phase 3 — Section Mapping:** Each parsed section is paired with the nearest template section by `section_type`. The LLM replaces heading text and body copy inside the cloned `block_tree` while preserving all CSS classes, attributes, block comments, and layout structure. Images and buttons are left as-is for manual update.

**Response:** `{ success, data: { sections[], full_markup } }` proxied from the Edge Function.

---

### `POST /wp-json/ai-pagebuilder/v1/rate`

Records a content editor's rating for a generated section and triggers `accept_rate` recalculation on the Edge Function.

**Request parameters (all required):**

| Parameter | Type | Validation |
|-----------|------|-----------|
| `section_id` | string | Must match UUID v4 format |
| `rating` | integer | `1` (accept) or `-1` (reject) |
| `was_edited` | boolean | Whether the editor modified the block after insertion |

---

## AI Client — `includes/class-ai-client.php`

A thin HTTP client and the plugin's only outbound communication layer. Every call to the Edge Function goes through this class.

**Responsibilities:**
- Reads `aipb_edge_function_url` and `aipb_client_id` from `wp_options`.
- Builds POST requests with a `{ action, client_id, ...payload }` JSON body.
- Uses WordPress's `wp_remote_post()` so WP's HTTP API handles timeouts, SSL, and proxy settings.
- Returns a normalised `{ success, data, error }` envelope.

**Methods:**

| Method | Edge action triggered | Called by |
|--------|----------------------|-----------|
| `sync_client(registry, tokens, patterns)` | `sync_client` | `Hooks::run_onboarding_scan()` |
| `generate(content, page_type, post_id)` | `analyse_content` + `generate_blocks` | `Rest::generate()` |
| `rate(section_id, rating, was_edited)` | `update_rating` | `Rest::rate()` |

**Security:** The Edge Function URL is never returned to the browser in full. The `ANTHROPIC_API_KEY` and Supabase credentials never pass through this class — they live exclusively in the Edge Function environment.

---

## Block Scanner — `includes/class-indexer.php`

Scans published WordPress content incrementally and stores per-post scan data in a custom DB table. Triggered by `aipb_site_scan` (cron or Scan Site button). Does **not** require Supabase connection settings.

**MVP scope:** Only `core/*` blocks and the active theme's registered custom blocks are indexed. Third-party namespaces (`kadence/`, `generateblocks/`, `stackable/`) are silently skipped.

**Incremental scan logic (`scan_incremental()`):**
1. Calls `get_posts()` to fetch IDs and `post_modified_gmt` for all published pages and posts.
2. For each post, queries `gutenbot_indexed_posts` for an existing `scanned_at` timestamp.
3. **Skips** posts where `post_modified_gmt ≤ scanned_at` — no change since last scan.
4. For changed or new posts:
   - Detects `page_type` via title/slug heuristics (`guide`, `service`, `location`, `blog`, `landing`).
   - Calls `parse_blocks($post->post_content)`.
   - Splits the block tree into sections at `core/separator`, `core/group`, and `core/cover` boundaries.
   - For each section records: `section_type`, `section_signature`, `block_tree`, `content_density`, `semantic_intent`.
   - Records `section_order` for the whole page (`hero → intro → faq → cta`).
   - Recursively walks `innerBlocks`.
   - UPSERTs a row in `gutenbot_indexed_posts`.
5. Deletes rows for posts that are no longer published.
6. SELECTs all rows from the table to rebuild aggregated summaries.
7. Filters block registry to occurrence count ≥ 2.
8. Delegates to `DocumentProcessor` for design tokens and `PatternBuilder` for pattern maps.
9. Saves aggregated results to `wp_options`.

**Output stored in `wp_options` (aggregated from all table rows):**
- `aipb_block_registry` — map of `blockName → { count, sample_html, attrs }`.
- `aipb_design_tokens` — color palette, font sizes, spacing tokens from `theme.json`.
- `aipb_patterns` — section-type pattern library from `PatternBuilder`.

---

## Design Token Extractor — `includes/class-document-processor.php`

Reads the active theme's `theme.json` and extracts design tokens that help the Edge Function generate markup matching the site's visual style.

**Tokens extracted:**

| Token group | `theme.json` path |
|-------------|------------------|
| Color palette | `settings.color.palette` |
| Font sizes | `settings.typography.fontSizes` |
| Spacing sizes | `settings.spacing.spacingSizes` |

**Fallback:** If `theme.json` is absent (classic themes without Global Styles), returns an empty token structure so the onboarding scan completes without error on any WordPress site.

Uses `get_template_directory()` to locate `theme.json`, compatible with both parent and child themes.

---

## Pattern Builder — `includes/class-pattern-builder.php`

Converts the raw block registry and indexed page data into two artifacts sent to the Edge Function:

1. **`block_patterns`** — per-section template library (`{ section_type, block_name, sample_markup }`).
2. **`indexed_pages`** — full page-level templates (`{ page_type, section_order, sections[] }`) used by the Layout Retrieval phase during generation.

**How mapping works:**
- Uses a heuristic lookup table mapping `blockName` values to semantic section types.
- Built-in section types: `hero`, `faq`, `cta`, `services_list`, `testimonials`, `gallery`, `contact`.
- Blocks without a matching section type are excluded from `block_patterns`.
- Only blocks with occurrence count ≥ 2 (enforced upstream by `Indexer`) are included.
- All scanned pages with ≥ 2 mapped sections are included in `indexed_pages` regardless of block frequency.

**Output:** Both arrays are sent to the Edge Function via `AiClient::sync_client()`. `block_patterns` seeds per-section fallback generation. `indexed_pages` seeds the Layout Retrieval query that finds the best matching whole-page template for the new content's `page_type` and section count.

---

## Admin — `includes/class-admin.php`

Renders and manages the plugin settings page and all admin notices in WP Admin.

**Settings page** (`Settings > AI Page Builder`) is divided into three areas:

**Connection Settings** — Edge Function URL, Anon Key, LLM Provider. Saving these fields resets `aipb_sync_status` to `pending` via `on_connection_setting_saved()`. Does not trigger a rescan.

**Group 1 — Site Scan** — Shows `aipb_scan_status` badge, last-scanned timestamp, and error message. The "Scan Site" button submits a standard form POST to `admin-post.php`. No connection settings required. Redirects back with `?gutenbot_scanned=success|error`.

**Group 2 — Supabase Sync** — Shows `aipb_sync_status` badge, last-synced timestamp, Client UUID, and error. The "Sync to Supabase" button triggers a jQuery AJAX call to `admin-ajax.php` — the badge and timestamp update inline without a page reload. The button is hidden (replaced with a message) when connection settings are incomplete.

**Admin notices:**

| Condition | Type | Behaviour |
|-----------|------|-----------|
| Site scan not yet run | Info | Dismissible; links to settings page |
| Scan failed | Shown inline on settings page | Error badge + error text in Group 1 |
| Sync failed | Shown inline on settings page | Error badge + error text in Group 2 |

All output is escaped with `esc_html`, `esc_attr`, and `esc_url`. The URL field is sanitized with `esc_url_raw` on save.

---

## Gutenberg Sidebar — `assets/src/index.js`

A React application running inside the WordPress block editor as a plugin sidebar. Built with `@wordpress/scripts` (webpack) and enqueued via `enqueue_block_editor_assets`.

**Status:** Phase 6 — stub file. Implementation pending.

**Planned UI when implemented:**
- `TextareaControl` for raw content input.
- `SelectControl` for page type (`landing`, `about`, `services`, `contact`, `blog`, `custom`).
- Generate button — calls `POST /ai-pagebuilder/v1/generate` via `@wordpress/api-fetch` (WP nonce applied automatically).
- Spinner while request is in flight; Generate button disabled to prevent double-submission.
- Section preview list — one `PanelBody` per section showing type and headline.
- Insert button — calls `wp.blocks.parse(full_markup)` then `insertBlocks()` at cursor. No page reload.
- Thumbs-up / thumbs-down per section — calls `POST /ai-pagebuilder/v1/rate`.
- Error state with retry option.

**Build commands:**
```bash
npm run build   # production build → assets/
npm run start   # watch mode for development
```

---

## `wp_options` Key Reference

All options use the `aipb_` prefix.

| Key | Type | Set by | Description |
|-----|------|--------|-------------|
| `aipb_client_id` | UUID string | Activator | Unique site identifier; never overwritten |
| `aipb_edge_function_url` | URL string | Admin (user) | Supabase Edge Function deployment URL |
| `aipb_anon_key` | string | Admin (user) | Supabase anon key for edge function calls |
| `aipb_provider` | string | Admin (user) | LLM provider: `anthropic` or `openai` |
| `aipb_scan_status` | enum string | Hooks | `pending` · `complete` · `error` |
| `aipb_scan_error` | string | Hooks | Scan error message; deleted on success |
| `aipb_scanned_at` | datetime string | Hooks | UTC timestamp of last successful scan |
| `aipb_sync_status` | enum string | Hooks | `pending` · `pending_settings` · `complete` · `error` |
| `aipb_sync_error` | string | Hooks | Sync error message; deleted on success |
| `aipb_synced_at` | datetime string | Hooks | UTC timestamp of last successful sync |
| `aipb_block_registry` | serialized array | Hooks | Block names, counts, sample markup — rebuilt from DB table after each scan |
| `aipb_design_tokens` | serialized array | Hooks | Color, typography, spacing tokens from `theme.json` |
| `aipb_patterns` | serialized array | Hooks | Section-type pattern library from `PatternBuilder` |

---

## Custom DB Table — `{prefix}_gutenbot_indexed_posts`

Created by `Activator::activate()` via `dbDelta()`. Stores per-post scan results so subsequent scans only reprocess changed content.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary key |
| `post_id` | `BIGINT UNSIGNED NOT NULL` | WP post ID — unique index |
| `post_modified` | `DATETIME NOT NULL` | Copy of `post_modified_gmt` at scan time |
| `page_type` | `VARCHAR(32)` | `landing` · `blog` · `service` · `guide` · `location` |
| `section_order` | `TEXT` | JSON array of section type strings |
| `sections` | `LONGTEXT` | JSON — full section tree per post |
| `block_names` | `TEXT` | JSON array of unique block names found in post |
| `scanned_at` | `DATETIME NOT NULL` | UTC timestamp when this row was last written |

**Incremental logic:** before parsing a post, `Indexer` compares `post_modified_gmt` (from WP) against `scanned_at` (from this table). If `post_modified_gmt ≤ scanned_at`, the post is skipped. After all posts are processed, rows for deleted or unpublished posts are removed, and the full table is read to rebuild the `wp_options` aggregates.
