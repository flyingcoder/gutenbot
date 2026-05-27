# GutenBot — Tech Stack

## Overview

GutenBot is a three-layer system: a PHP WordPress plugin, a stateless Deno/TypeScript Edge Function, and a shared Supabase PostgreSQL database. Each layer has a distinct responsibility and technology set.

---

## Layer 1 — WordPress Plugin (PHP)

| Concern | Technology | Notes |
|---------|-----------|-------|
| Language | PHP 8.1+ | Minimum declared in plugin header |
| Framework | WordPress Plugin API | Hooks, filters, options, cron |
| Block parsing | `parse_blocks()` | Native WP function; no extra library |
| REST API | WP REST API (`rest_api_init`) | Namespace `ai-pagebuilder/v1` |
| Settings storage | `wp_options` | All keys prefixed `aipb_` |
| Background jobs | WP-Cron | Single events for onboarding scan |
| Theme token reading | `theme.json` + Kadence global settings | File read + options API |
| Sanitisation | `sanitize_text_field`, `wp_kses_*` | WP-native; all user input |
| Escaping | `esc_html`, `esc_attr`, `esc_url` | All output |
| Auth enforcement | `current_user_can('edit_pages')` | All REST endpoints |
| Autoloading | Composer PSR-4 | Class loading |
| Unit testing | PHPUnit (via Composer) | `vendor/bin/phpunit` |
| Function prefix | `aipb_` | All public functions |

---

## Layer 2 — Gutenberg Sidebar (React / JS)

| Concern | Technology | Notes |
|---------|-----------|-------|
| UI framework | React (via `@wordpress/element`) | Bundled with WordPress |
| Components | `@wordpress/components` | No external CSS framework |
| Block editor integration | `@wordpress/block-editor`, `@wordpress/data` | `insertBlocks`, cursor position |
| Block parsing (client) | `wp.blocks.parse` | Converts markup string to block objects |
| API calls | `@wordpress/api-fetch` | Uses WP nonce automatically |
| Build tooling | `@wordpress/scripts` (webpack) | Standard WP block toolchain |
| State management | React hooks (`useState`, `useEffect`) | No Redux required |

---

## Layer 3 — Edge Function (Deno / TypeScript)

| Concern | Technology | Notes |
|---------|-----------|-------|
| Runtime | Deno | Supabase Edge Functions runtime |
| Language | TypeScript | Strict mode |
| LLM — Production | Anthropic Claude Sonnet | `claude-sonnet-4-*` via REST |
| LLM — Local dev | Ollama | `http://localhost:11434`, default model `llama3.2` |
| LLM abstraction | `callLLM()` router function | Single entry point; no handler calls Anthropic/Ollama directly |
| DB client | `@supabase/supabase-js` | Service role key; server-side only |
| Auth validation | `client_id` check against `clients` table | Per-request; before any write |
| Environment switching | `APP_ENV` env var | `production` → Anthropic; `local` → Ollama |

---

## Layer 4 — Data (Supabase PostgreSQL)

| Concern | Technology | Notes |
|---------|-----------|-------|
| Database | Supabase PostgreSQL 15 | Managed, shared across all client sites |
| Vector extension | `pgvector` | `vector(1536)` column on `block_patterns`; not populated in v1 |
| Data isolation | Row-Level Security (RLS) | Per-client policies on all tables |
| Admin access | Supabase service role key | Edge Function only; bypasses RLS for agency admin reads |
| Tables | `clients`, `pages`, `sections`, `block_patterns`, `generation_logs` | See ARCHITECTURE.md |

---

## Infrastructure & Tooling

| Concern | Technology | Notes |
|---------|-----------|-------|
| Hosting (Edge Function) | Supabase | Deployed as Supabase Edge Function |
| Local WordPress | Docker Compose | `wp-experiments` container; `http://localhost:8007` |
| Local MySQL | External Docker network | `mysql.local:3306`, db `wp_experiments` |
| Local LLM | Ollama | Dev/test only; no API cost |
| Credentials management | Supabase env vars | API keys never in PHP or `wp_options` |
| PHP dependency management | Composer | `composer.json` at plugin root |
| JS dependency management | npm / `package.json` | `@wordpress/scripts` build |

---

## Compatibility Matrix

| Component | Minimum Version |
|-----------|----------------|
| WordPress | 6.3 |
| PHP | 8.1 |
| Gutenberg (block editor) | bundled with WP 6.3 |
| Deno (Supabase runtime) | managed by Supabase |
| PostgreSQL | 15 (Supabase managed) |
| Node.js (build only) | 18 LTS |

---

## Security Boundaries

```
Browser / WP Admin
       │  WP nonce + edit_pages cap
       ▼
WordPress REST API  ──── no LLM keys here
       │  HTTPS + client_id
       ▼
Supabase Edge Function  ──── ANTHROPIC_API_KEY, SUPABASE_SERVICE_KEY (env vars only)
       │
       ├── Anthropic Claude API (production)
       └── Ollama (local dev)
```

API credentials never leave the Edge Function environment.
