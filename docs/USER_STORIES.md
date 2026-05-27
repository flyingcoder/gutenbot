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

### US-06 — Enter the Edge Function URL

**As an** agency admin,
**I want to** enter the Supabase Edge Function URL in the plugin settings page,
**so that** the plugin knows where to send generation requests.

**Acceptance criteria:**
- A settings page exists under Settings > AI Page Builder.
- The page shows a text field for the Edge Function URL.
- Saving the URL triggers an automatic rescan within 5 seconds (onboarding resets to `pending` then runs).
- An info notice appears on activation if the URL has not been set.

**Maps to:** FR-18, FR-08, FR-19, FR-03, AC-02, AC-10

---

### US-07 — Monitor onboarding status

**As an** agency admin,
**I want to** see the current onboarding status and last scan timestamp on the settings page,
**so that** I know whether a client site is ready for content generation.

**Acceptance criteria:**
- The settings page displays: client UUID, onboarding status (`pending_settings`, `pending`, `complete`, `error`), and last-scanned timestamp.
- A persistent error notice is shown if onboarding failed, including the error message.
- A one-time success notice is shown after onboarding completes, then suppressed.

**Maps to:** FR-18, FR-19, FR-20, AC-09

---

### US-08 — Activate the plugin without disrupting the site

**As an** agency admin,
**I want** plugin activation to be non-blocking,
**so that** the WordPress admin remains responsive during onboarding.

**Acceptance criteria:**
- Activation schedules a WP-Cron event (+5 seconds) rather than running synchronously.
- The activation hook does not make any outbound HTTP requests.
- If no Edge Function URL is configured, status is set to `pending_settings` and no HTTP calls are made.

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
- Setting `APP_ENV=local` and `OLLAMA_BASE_URL` routes all LLM calls to Ollama.
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

### US-13 — Scan a Kadence-based site and build its block registry

**As a** developer,
**I want** the block scanner to correctly index Kadence Blocks in addition to core blocks,
**so that** pattern selection leverages the site's actual page builder.

**Acceptance criteria:**
- After onboarding a Kadence-based site, `block_registry` in the `clients` row contains entries with `kadence/*` block names.
- `block_patterns` includes patterns for `kadence/rowlayout` or equivalent if they appear 2 or more times.
- Design token extraction reads Kadence global palette settings as a fallback when `theme.json` is absent.

**Maps to:** FR-04, FR-05, FR-06, AC-11

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

## Story Map Summary

| Theme | Stories |
|-------|---------|
| Content Generation | US-01, US-02, US-05 |
| Quality Feedback | US-03, US-04 |
| Onboarding & Settings | US-06, US-07, US-08, US-09 |
| Developer / Ops | US-10, US-11, US-12, US-13, US-14 |
