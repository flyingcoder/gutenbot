# GutenBot — Implementation Plan

Version 1.0 | Target: Agency Deployment

> **Repository scope:** This plan covers the full GutenBot system. Phases marked with **`gutenbot-edge`** repo are implemented in that separate repository. Work in this file (`guten-bot`) covers the WordPress plugin only: activation, scanning, REST API, Gutenberg sidebar, admin UI, and plugin-side tests.

---

## Phases at a Glance

| Phase | Name | Repo | Deliverable | Est. effort | Status |
|-------|------|------|-------------|-------------|--------|
| 0 | Project Setup | gutenbot | Dev environment, scaffolding, CI | 1 day | ✅ Done |
| 1 | Plugin Foundation | gutenbot | Activation, UUID, settings, cron | 2 days | ✅ Done |
| 2 | Site Scanning | gutenbot | Block scanner, token extractor, pattern builder, indexed_pages | 3 days | ✅ Done |
| 3 | Supabase Schema | gutenbot-edge | Database tables, RLS, indexes | 1 day | ✅ Done |
| 4 | Edge Function Core | gutenbot-edge | Action router, DB queries, LLM router | 3 days | ⬜ Pending |
| 5 | Page Generation | gutenbot | `/generate` REST endpoint + AiClient | 3 days | ✅ Done (PHP side) |
| 6 | Gutenberg Sidebar | gutenbot | React UI: generate, preview, insert | 3 days | 🔄 In Progress |
| 7 | Rating System | gutenbot | `/rate` REST endpoint + thumbs UI | 1 day | ⚠️ Partial — REST done; sidebar UI pending Phase 6 |
| 8 | Admin UI & Notices | gutenbot | Settings page, status endpoint, admin notices | 1 day | ⚠️ Partial — settings page done; complete/error notices missing |
| 9 | Testing & Hardening | both | PHPUnit, E2E, security, acceptance criteria | 3 days | ⬜ Pending |
| 10 | Deployment | both | Supabase deploy, staging rollout, agency handoff | 1 day | ⬜ Pending |

Total estimated: ~22 working days

---

## Phase 0 — Project Setup

**Goal:** Establish the repo structure, local Docker stack, Composer, and npm tooling.

### Tasks

- [ ] Initialise plugin directory structure:
  ```
  plugins/guten-bot/         # gutenbot — WordPress plugin repository
  ├── gutenbot.php           # Plugin header
  ├── composer.json          # PHPUnit, classmap autoload
  ├── package.json           # @wordpress/scripts
  ├── includes/              # PHP classes
  ├── assets/src/            # React sidebar source
  ├── admin/                 # PHP admin templates
  └── tests/                 # PHPUnit test suites

  gutenbot-edge/             # Separate repository — Deno / TypeScript Edge Function
  ├── index.ts               # Action router
  ├── llm-router.ts          # Anthropic / Ollama routing
  ├── prompts/               # LLM prompt templates
  └── db/                    # Supabase query helpers
  ```
- [ ] Add `composer.json` with `phpunit/phpunit` and PSR-4 autoload for `GutenBot\` namespace.
- [ ] Add `package.json` with `@wordpress/scripts` for sidebar build (`npm run build`, `npm run start`).
- [ ] Confirm Docker Compose stack starts cleanly with the plugin mounted.
- [ ] ~~Add `.env.example`~~ — removed. LLM mode is controlled via `aipb_local_mode` WP setting or `GUTENBOT_LOCAL_MODE` wp-config constant. The `gutenbot-edge` repo requires only `ANTHROPIC_API_KEY` as a manual Supabase secret. `SUPABASE_URL` and `SUPABASE_SECRET_KEYS` are auto-injected by the Supabase platform. `OLLAMA_BASE_URL` and `OLLAMA_MODEL` are optional dev-only secrets.

### Definition of done
Plugin activates in WP Admin with no fatal errors; `vendor/bin/phpunit` runs with zero tests (green).

---

## Phase 1 — Plugin Foundation

**Goal:** UUID assignment, settings storage, WP-Cron scheduling, and REST route registration.

### Tasks

- [ ] **`gutenbot.php`** — Plugin header with `Requires at least: 6.3`, `Requires PHP: 8.1`. Register activation hook calling `GutenBot\Activator::activate()`.
- [ ] **`class-activator.php`**
  - Generate UUID (`wp_generate_uuid4()`) if `aipb_client_id` absent; store in `wp_options`.
  - Schedule `aipb_onboarding_scan` cron event (+5 seconds) if not already scheduled.
- [ ] **`class-hooks.php`** — Wire all hooks: `init`, `rest_api_init`, `admin_menu`, `admin_notices`, `aipb_onboarding_scan`.
- [ ] **`class-admin.php`** — Register settings page (Settings > AI Page Builder). Fields: Edge Function URL (`aipb_edge_function_url`). Display: client UUID, onboarding status, last-scanned timestamp.
- [ ] **REST `/status` endpoint** — `GET ai-pagebuilder/v1/status`. Reads `wp_options`. Requires `edit_pages`. Returns JSON envelope.
- [ ] **Admin notice: missing URL** — Dismissible info notice on activation if `aipb_edge_function_url` is empty.
- [ ] **Unit tests** — UUID not overwritten on re-activation; cron not double-scheduled.

### Definition of done
Settings page renders; UUID persists across deactivation/reactivation; `/status` returns 401 unauthenticated, 200 authenticated.

---

## Phase 2 — Site Scanning

**Goal:** Parse published pages, extract design tokens, build the initial pattern library, and index full page templates for the layout retrieval phase.

### Tasks

- [ ] **`class-indexer.php`** — Block scanner:
  - Query all published pages and posts (`get_posts`).
  - Detect `page_type` per post using title/slug/taxonomy heuristics (`guide`, `service`, `location`, `blog`).
  - For each post: `parse_blocks( $post->post_content )`.
  - Split the block tree into logical sections at `core/separator`, `core/group` boundaries, or consecutive heading blocks.
  - For each section record: `section_type`, `section_signature` (normalized heading pattern), `block_tree` (full nested structure), `content_density` (`short` / `medium` / `long`), `semantic_intent`.
  - Record `section_order` for the page (e.g. `['hero', 'intro', 'faq', 'cta']`).
  - Recursively walk `innerBlocks` for the block registry.
  - For each unique `blockName`: record occurrence count, one `innerHTML` sample, one `attrs` sample.
  - **MVP scope:** scan WordPress core blocks (`core/*`) and the active theme's registered custom blocks only. Third-party block libraries (e.g. Kadence, GenerateBlocks, Stackable) are **not** detected or indexed in v1.
  - Store `aipb_block_registry` and `aipb_indexed_pages` in `wp_options`.
- [ ] **`class-document-processor.php`** — Design token extractor:
  - Read `get_template_directory() . '/theme.json'` if present; extract `settings.color.palette`, `settings.typography.fontSizes`, `settings.spacing.spacingSizes`.
  - Returns empty token structure if `theme.json` is absent.
  - Store result as `aipb_design_tokens`.
- [ ] **`class-pattern-builder.php`** — Builds two artifacts:
  - `block_patterns` — heuristic lookup of `blockName → section_type`; include only blocks with occurrence count ≥ 2. Output: `{ section_type, block_name, sample_markup }[]`.
  - `indexed_pages` — all scanned pages with ≥ 2 mapped sections. Output: `{ page_type, section_order[], sections[{ section_type, signature, block_tree, content_density, semantic_intent }] }[]`.
- [ ] **`aipb_onboarding_scan` cron handler** in `class-hooks.php`:
  - Check `aipb_edge_function_url`; set `pending_settings` and return early if absent.
  - Run scanner → token extractor → pattern builder.
  - Call `class-ai-client.php::sync_client()` with `{ registry, tokens, patterns, indexed_pages }`.
  - On success: set `aipb_onboarding_status = complete`, `aipb_onboarded_at = now`.
  - On failure: set `aipb_onboarding_status = error`, store error message.
- [ ] **Settings-save rescan** — `update_option` hook on `aipb_edge_function_url` schedules immediate rescan (+2 seconds), resets status to `pending`.
- [ ] **Unit tests** — Scanner output shape; section splitter detects boundaries; token extractor handles missing `theme.json`; pattern builder excludes blocks with count < 2; `indexed_pages` excludes pages with < 2 mapped sections.

### Definition of done
Activating on a test site with 10+ pages populates `aipb_block_registry`, `aipb_design_tokens`, and `aipb_indexed_pages` in `wp_options`; `indexed_pages` entries contain `section_order` and per-section `block_tree`.

---

## Phase 3 — Supabase Schema

> **Repository:** `gutenbot-edge`

**Goal:** Create all database tables, enable pgvector, configure RLS.

### Tasks

- [ ] **Migration: `clients` table** — All columns per SRS §4.2.1. `site_url` unique index.
- [ ] **Migration: `pages` table** — FK to `clients`. Status enum check constraint.
- [ ] **Migration: `sections` table** — FK to `pages`. Rating check constraint (-1, 0, 1).
- [ ] **Migration: `block_patterns` table** — FK to `clients`. `embedding vector(1536)` nullable. Index on `(client_id, section_type, accept_rate DESC)`.
- [ ] **Migration: `generation_logs` table** — FK to `pages`.
- [ ] **Enable pgvector** — `CREATE EXTENSION IF NOT EXISTS vector;`
- [ ] **RLS policies** — Enable on `pages`, `sections`, `block_patterns`, `generation_logs`. Policy: `client_id = current_setting('app.client_id')::uuid`. Service role bypasses RLS.
- [ ] **Seed data** — Optional: seed one test client row for local dev.

### Definition of done
All migrations run cleanly; RLS prevents cross-client reads in SQL editor test.

---

## Phase 4 — Edge Function Core

> **Repository:** `gutenbot-edge`

**Goal:** Deploy the Deno action router, DB query layer, and LLM router.

### Tasks

- [ ] **`index.ts`** — Parse JSON body, extract `action` field, route to handler. Return `{ success, data, error }` envelope. Validate `client_id` exists in `clients` before any write.
- [ ] **`llm-router.ts`** — `callLLM(prompt, systemPrompt)`:
  - `localMode === false` (request body) -> Anthropic Messages API.
  - `localMode === true` (request body) -> Ollama `/api/chat`.
  - PHP resolves `localMode` from `aipb_local_mode` WP option or `GUTENBOT_LOCAL_MODE` wp-config constant.
  - Return parsed JSON (enforce JSON-only via system prompt instruction).
- [ ] **`db/clients.ts`** — `upsertClient(data)`, `getClient(clientId)`.
- [ ] **`db/patterns.ts`** — `getBestPatterns(clientId, sectionTypes[])`, `upsertPatterns(clientId, patterns[])`, `recalculateAcceptRate(clientId, blockName)`.
- [ ] **`db/sections.ts`** — `insertSections(sections[])`, `updateRating(sectionId, rating, wasEdited)`.
- [ ] **`db/logs.ts`** — `logGeneration(data)`.
- [ ] **`sync_client` action** — Upsert `clients` row; bulk upsert `block_patterns`.
- [ ] **`get_best_patterns` action** — Query top `accept_rate` pattern per section type for the client.
- [ ] **Unit tests (Deno)** — LLM router switches on `localMode` flag from request body; `sync_client` upserts correctly; `get_best_patterns` returns highest accept_rate row.

### Definition of done
`supabase functions serve` starts without error; `sync_client` curl test upserts a client row.

---

## Phase 5 — Page Generation

**Goal:** Implement the three-phase template synthesis algorithm in the Edge Function and the PHP REST `/generate` endpoint.

Generation model: **Raw Content → Parse Sections → Retrieve Layout Template → Map Content into Template Blocks**. The LLM does not invent structure — it only substitutes text into an existing block tree.

### Tasks

- [ ] **`prompts/parse-content.ts`** — System prompt: split raw content into sections (boundaries: `---`, `===`, Markdown headings, blank-line clusters). JSON-only output schema `{ page_type, sections: [{ heading, body, semantic_intent }] }`.
- [ ] **`parse_content` action** — Call `callLLM()` with raw content. Return parsed section array with detected `page_type`.
- [ ] **`get_best_layout` action** — Query `indexed_pages WHERE page_type = input ORDER BY engagement_score DESC LIMIT 1`. Return `{ section_order[], block_trees[] }`. Fallback: if no match, fall back to `block_patterns` core fallback.
- [ ] **`prompts/generate-blocks.ts`** — System prompt: given a `block_tree` (full Gutenberg block markup) and `{ heading, body }`, replace only the text content. Preserve all CSS classes, attributes, block comment delimiters, and structure. Images and buttons are left unchanged. JSON-only: `{ markup: "..." }`.
- [ ] **`generate_blocks` action** — For each parsed section: pair with nearest template section by `section_type`. Call `callLLM()` with the paired `block_tree`. Collect per-section markup. Concatenate into `full_markup`. Call `log_sections` and `log_generation`.
- [ ] **Core block fallback** — When `indexed_pages` has no match for the `page_type`: fall back to `block_patterns` (per-section templates). When no pattern exists at all: use `core/group`, `core/heading`, `core/paragraph`, `core/list`, `core/buttons`.
- [ ] **`class-ai-client.php`** — `generate(content, pageType, clientId)`: POST to Edge Function. Return response to REST endpoint.
- [ ] **PHP REST `POST /generate`** — Auth check. Validate and sanitise `content` (string, max 5000 chars) and `page_type`. Call `class-ai-client`. Return sections array + `full_markup`.
- [ ] **Integration test** — POST to `/generate` with 500-word guide text; assert >= 3 sections with non-empty `block_markup`; assert CSS classes from template are present in output.

### Definition of done
Full generate flow returns valid Gutenberg markup from both Anthropic (production) and Ollama (local) within 20 seconds; output markup preserves template block structure with only text content replaced.

---

## Phase 6 — Gutenberg Sidebar

**Goal:** React sidebar with content input, generation, section preview, and block insertion.

### Tasks

- [ ] **Sidebar registration** — Register `gutenbot-sidebar` via `@wordpress/plugins`. Add with `PluginSidebar`.
- [ ] **Content input panel** — `TextareaControl` for raw content. `SelectControl` for page type. `Button` (Generate) disabled during loading; shows `Spinner`.
- [ ] **API call** — `apiFetch({ path: '/ai-pagebuilder/v1/generate', method: 'POST', data: { content, page_type } })`.
- [ ] **Section preview list** — `PanelBody` per section showing `section_type` label + headline. Ordered by `priority` (ascending). Read-only `TextareaControl` for `full_markup` inspection.
- [ ] **Insert button** — `wp.blocks.parse(full_markup)` -> `dispatch('core/block-editor').insertBlocks(blocks)`. No page reload.
- [ ] **Error state** — Display API error message; allow retry.
- [ ] **Rating buttons** — Thumbs-up / thumbs-down per section (wired in Phase 7).
- [ ] **Build** — `npm run build` produces `assets/admin.js` + `assets/admin.css`. Enqueued via `enqueue_block_editor_assets`.

### Definition of done
Clicking Generate in the editor sidebar returns sections; clicking Insert places blocks at cursor without reload.

---

## Phase 7 — Rating System

**Goal:** Per-section rating UI and `update_rating` Edge Function action.

### Tasks

- [ ] **Rating UI** — Two `Button` components (thumbs-up, thumbs-down) per section. Visually indicate selected rating.
- [ ] **Rating API call** — `apiFetch({ path: '/ai-pagebuilder/v1/rate', method: 'POST', data: { section_id, rating, was_edited } })`.
- [ ] **PHP REST `POST /rate`** — Auth check. Validate `section_id` (UUID), `rating` (1 or -1), `was_edited` (bool). Proxy to `update_rating` Edge Function action.
- [ ] **`update_rating` action** — Write `rating` and `was_edited` to `sections`. Recalculate `accept_rate` for the affected `block_pattern`. Update `use_count`.
- [ ] **Edit detection** (best-effort) — After `insertBlocks`, sidebar may offer a "I edited this" rating prompt.
- [ ] **Unit tests** — `accept_rate` recalculation is correct; `use_count` increments.

### Definition of done
Rating a section updates `sections.rating` and `block_patterns.accept_rate` in Supabase within 5 seconds.

---

## Phase 8 — Admin UI & Notices

**Goal:** Complete settings page, status display, and all admin notices.

### Tasks

- [ ] **Settings page** — Render client UUID, onboarding status badge, `aipb_onboarded_at`, Edge Function URL field (masked in display). Save triggers rescan.
- [ ] **Admin notices**:
  - Activation + missing URL -> dismissible info notice.
  - Onboarding complete -> one-time success notice (suppressed after dismiss).
  - Onboarding error -> persistent error notice with message + rescan link.
- [ ] **`/status` endpoint masking** — Return first 30 chars of Edge Function URL + `...`.
- [ ] **Notice suppression logic** — Store dismiss state in `wp_options`; clear on next successful onboard.

### Definition of done
All three notice states visible in WP Admin; settings page shows all required fields; `/status` returns masked URL.

---

## Phase 9 — Testing & Hardening

**Goal:** PHPUnit coverage, E2E acceptance criteria, security verification.

### PHP Unit Tests (PHPUnit)
- [ ] `class-activator.php` — UUID not overwritten; cron scheduled once.
- [ ] `class-indexer.php` — Scanner output shape; pattern builder threshold (>= 2 occurrences).
- [ ] `class-document-processor.php` — Token extraction from `theme.json`; empty fallback when absent.
- [ ] REST `/generate` — 401 unauthenticated; validates content length.
- [ ] REST `/rate` — 401 unauthenticated; validates rating value.
- [ ] REST `/status` — 401 unauthenticated; returns correct status fields.

### Edge Function Tests (Deno) — `gutenbot-edge` repo
- [ ] `llm-router.ts` — Routes to Anthropic on `production`, Ollama on `local`.
- [ ] `sync_client` — Upserts clients row; inserts patterns.
- [ ] `update_rating` — Correct `accept_rate` calculation.
- [ ] `generate_blocks` — Core fallback when no pattern found.

### Acceptance Criteria Verification
- [ ] AC-01 Onboarding completes within 30 s on 10+ page site
- [ ] AC-02 Activation without URL sets `pending_settings`, no HTTP calls
- [ ] AC-03 500-word content returns >= 3 sections in <= 20 s
- [ ] AC-04 Insert places blocks at cursor, no reload
- [ ] AC-05 Rating written to Supabase within 5 s
- [ ] AC-06 `accept_rate` recalculated after every rating
- [ ] AC-07 Local Ollama flow makes no Anthropic requests
- [ ] AC-08 All endpoints return 401 unauthenticated
- [ ] AC-09 Settings page shows correct UUID and status
- [ ] AC-10 Saving URL triggers rescan within 5 s

- [ ] AC-12 No-pattern fallback uses `core/` blocks

### Security
- [ ] No API keys in PHP source, `wp_options`, or HTTP responses.
- [ ] All user input sanitised on input; escaped on output.
- [ ] RLS verified: client A cannot read client B's rows.

### Definition of done
All 12 acceptance criteria pass; PHPUnit green; no CRITICAL or HIGH security findings.

---

## Phase 10 — Deployment

**Goal:** Deploy Edge Function to Supabase, roll out to staging site, hand off to agency.

### Tasks

- [ ] **Deploy Edge Function** (`gutenbot-edge` repo) — `supabase functions deploy gutenbot --project-ref <ref>`. Set one manual secret in the Supabase dashboard: `ANTHROPIC_API_KEY`. `SUPABASE_URL` and `SUPABASE_SECRET_KEYS` are auto-injected — no action needed. For local dev only: set `OLLAMA_BASE_URL` and `OLLAMA_MODEL`.
- [ ] **Run Supabase migrations** — `supabase db push` against production project.
- [ ] **Staging rollout** — Install plugin on one client staging site. Enter Edge Function URL. Verify AC-01, AC-03, AC-04, AC-05.
- [ ] **Agency handoff** — Provide deployment checklist: plugin zip, Edge Function URL, `ANTHROPIC_API_KEY` secret, how to trigger rescan.
- [ ] **Zip for distribution** — `composer install --no-dev`, `npm run build`, zip `plugins/guten-bot/` excluding `.git`, `node_modules`, `tests/`.

### Definition of done
Plugin installed and onboarded on staging site; content editor can generate and insert a full page layout end to end.

---

## Risk Register

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Anthropic API latency > 20 s | High | Use streaming responses in v1.1; set client timeout to 25 s with retry |
| LLM returns invalid JSON | High | Wrap `callLLM()` in try/catch; retry once with stricter system prompt |
| WP-Cron not firing (no traffic) | Medium | Document: agency should install WP Crontrol on client sites |
| pgvector not available on Supabase plan | Low | Extension pre-enabled on all Supabase Pro plans; confirm before deploy |
| Pattern library too sparse on new sites | Medium | Core block fallback (FR-30) ensures generation always produces output |
| RLS misconfiguration leaks cross-client data | Critical | Integration test: verify RLS with two test client rows before deploy |

---

## Out-of-Scope Reminders (v1.0)

These are explicitly deferred and should not be implemented in this plan:

- **Third-party block library detection** — Kadence, GenerateBlocks, Stackable, CoBlocks, and similar libraries are not scanned or indexed in MVP. The block scanner targets `core/*` blocks and the active theme's own custom blocks only.
- Vector embedding population and semantic similarity search
- SEO meta field writing (Rank Math / Yoast)
- Multisite / network activation
- Image generation
- Elementor / Oxygen compatibility
- Admin analytics dashboard
- Feedback-driven prompt tuning
