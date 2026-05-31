# GutenBot

AI-powered Gutenberg page builder for WordPress. Paste raw content into the block editor sidebar, click Generate, and receive a full multi-section Gutenberg layout built from your site's own block patterns — ready to insert at cursor with one click.

---

## How It Works

GutenBot is part of a three-layer system. This repository is the **WordPress plugin layer** only.

```
WordPress Site (this repo)
  └─ GutenBot PHP Plugin
       └─ Gutenberg Sidebar (React)
            │
            │  HTTPS
            ▼
  Supabase Edge Function  ──► LLM (Claude / Ollama)
  (gutenbot-edge repo)    ──► Supabase PostgreSQL
```

1. **On activation** — the plugin scans your published pages to discover which Gutenberg blocks your site uses, extracts design tokens from `theme.json`, and syncs a pattern library to the Edge Function. Only WordPress core blocks and your active theme's custom blocks are indexed (no third-party block libraries in v1).
2. **On generate** — the content editor pastes copy into the sidebar, selects a page type, and clicks Generate. The plugin sends the content to the Edge Function, which classifies sections, matches them to your site's block patterns, and returns adapted Gutenberg markup via Claude or Ollama.
3. **On insert** — one click places all blocks at the cursor position in the editor without a page reload.
4. **On rating** — thumbs-up or thumbs-down per section teaches the system which block patterns work well for your site. Ratings update `accept_rate` in Supabase, improving future generations.

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress | 6.3 |
| PHP | 8.1 |
| Node.js (build only) | 18 LTS |
| Composer | 2.x |
| Supabase Edge Function | Deployed from `gutenbot-edge` |

---

## Installation

### 1. Clone and install dependencies

```bash
git clone <repo-url> wp-content/plugins/guten-bot
cd wp-content/plugins/guten-bot

composer install
npm install
npm run build
```

### 2. Activate the plugin

Activate **GutenBot** in **WP Admin → Plugins**. The plugin will:
- Assign a stable UUID to this site.
- Schedule the onboarding scan to run in ~5 seconds.

You will see a notice: **"Enter your Edge Function URL to complete setup."**

### 3. Enter the Edge Function URL

Go to **Settings → AI Page Builder** and paste the Supabase Edge Function URL from your `gutenbot-edge` deployment.

Saving the URL triggers an automatic site scan. When the status badge changes to **Complete**, the plugin is ready to generate pages.

---

## Configuration

All settings live under **Settings → AI Page Builder**.

| Setting | Description |
|---------|-------------|
| Edge Function URL | The deployed Supabase Edge Function endpoint from `gutenbot-edge` |
| Local / Dev Mode | Routes LLM calls to a local Ollama instance instead of Anthropic Claude |

The **client UUID** displayed on the settings page is the unique identifier used to isolate your site's data in the shared Supabase database.

---

## Generating a Page

1. Open any page or post in the WordPress block editor.
2. Open the **GutenBot** sidebar (top-right panel icon).
3. Paste your raw page copy into the content textarea.
4. Select a page type: `Landing`, `About`, `Services`, `Contact`, `Blog`, or `Custom`.
5. Click **Generate** and wait (up to 20 seconds for typical content).
6. Review the section list — type label and headline for each section.
7. Click **Insert into Editor** to place the generated blocks at your cursor. No reload needed.
8. Give thumbs-up or thumbs-down to each section to improve future generations.

---

## Development

### PHP

```bash
# Run tests
vendor/bin/phpunit

# Lint a file
php -l includes/class-rest.php

# Rebuild autoload
composer dump-autoload
```

### JavaScript

```bash
# Development watch mode
npm run start

# Production build
npm run build
```

### Local LLM (no API cost)

1. Install and run [Ollama](https://ollama.com) locally.
2. Pull a model: `ollama pull llama3.2`
3. Enable **Local / Dev Mode** in plugin settings, or define in `wp-config.php`:
   ```php
   define( 'GUTENBOT_LOCAL_MODE', true );
   ```

All generation calls will route to `http://localhost:11434` instead of Anthropic.

---

## Project Structure

```
guten-bot/
├── gutenbot.php                       # Plugin entry point
├── composer.json                      # PHP dependencies (PHPUnit, autoload)
├── package.json                       # JS dependencies (@wordpress/scripts)
├── phpunit.xml                        # PHPUnit configuration
│
├── includes/                          # PHP plugin classes
│   ├── class-activator.php            # UUID + cron on activation
│   ├── class-hooks.php                # All WP action/filter registrations
│   ├── class-rest.php                 # REST endpoints: /status /generate /rate
│   ├── class-ai-client.php            # HTTP client → Edge Function
│   ├── class-indexer.php              # Block scanner (core + theme blocks)
│   ├── class-document-processor.php   # theme.json design token extractor
│   ├── class-pattern-builder.php      # Maps blocks to section types
│   └── class-admin.php                # Settings page + admin notices
│
├── assets/src/
│   └── index.js                       # Gutenberg sidebar React app (Phase 6)
│
├── admin/
│   └── settings-page.php              # Settings page template
│
├── tests/                             # PHPUnit test suites
│
└── docs/                              # Documentation
    ├── ARCHITECTURE.md                # System diagrams (Mermaid)
    ├── BEHAVIORAL_DIAGRAMS.md         # Sequence and flow diagrams
    ├── IMPLEMENTATION_PLAN.md         # Phase-by-phase build plan
    ├── MODULES.md                     # Per-class/feature reference
    ├── TECH_STACK.md                  # Technology decisions per layer
    └── USER_STORIES.md                # Acceptance criteria by persona
```

---

## Related Repository

**`gutenbot-edge`** — Supabase Edge Function (Deno/TypeScript) and PostgreSQL schema migrations. Contains the action router, LLM routing (`callLLM()`), and all database query helpers. Deployed independently to Supabase.

---

## Security

- All REST endpoints require WordPress authentication (`edit_pages` capability).
- The Edge Function URL is masked in API responses (first 30 characters only).
- `ANTHROPIC_API_KEY` and Supabase credentials never appear in PHP code, `wp_options`, or HTTP responses — they live exclusively in the Edge Function environment.
- All user input is sanitized on entry (`sanitize_textarea_field`, `esc_url_raw`) and escaped on output (`esc_html`, `esc_attr`, `esc_url`).

---

## License

Proprietary. All rights reserved.
