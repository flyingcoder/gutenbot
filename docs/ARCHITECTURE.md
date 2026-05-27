# GutenBot — Technical Architecture & Charts

## 1. System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     CLIENT WORDPRESS SITE                        │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │               WordPress Block Editor                     │    │
│  │                                                           │    │
│  │   ┌─────────────────────────────────────────────────┐   │    │
│  │   │           GutenBot Sidebar (React)               │   │    │
│  │   │                                                   │   │    │
│  │   │  [Content textarea]  [Page type dropdown]        │   │    │
│  │   │  [Generate button]                               │   │    │
│  │   │  [Section preview list]                          │   │    │
│  │   │  [thumbs-up/down per section]                    │   │    │
│  │   │  [Insert into editor button]                     │   │    │
│  │   └───────────────┬─────────────────────────────────┘   │    │
│  │                   │ @wordpress/api-fetch (WP nonce)      │    │
│  └───────────────────┼─────────────────────────────────────┘    │
│                       │                                           │
│  ┌────────────────────▼────────────────────────────────────┐    │
│  │              GutenBot PHP Plugin                         │    │
│  │                                                           │    │
│  │  REST Routes (ai-pagebuilder/v1)                         │    │
│  │    POST /generate  ->  proxies to Edge Function          │    │
│  │    POST /rate      ->  proxies to Edge Function          │    │
│  │    GET  /status    ->  reads wp_options                  │    │
│  │                                                           │    │
│  │  WP-Cron                                                  │    │
│  │    aipb_onboarding_scan  ->  Block Scanner               │    │
│  │                           ->  Design Token Extractor      │    │
│  │                           ->  Pattern Library Builder     │    │
│  │                           ->  sync_client (Edge Function) │    │
│  └────────────────────────────────┬────────────────────────┘    │
└───────────────────────────────────┼─────────────────────────────┘
                                    │ HTTPS (client_id in body)
                                    ▼
┌───────────────────────────────────────────────────────────────────┐
│                   SUPABASE EDGE FUNCTION (Deno/TS)                 │
│                                                                     │
│   ┌─────────────────────────────────────────────────────────┐     │
│   │                    Action Router                          │     │
│   │                                                           │     │
│   │  analyse_content  ->  Content Analyser (LLM prompt)      │     │
│   │  generate_blocks  ->  Block Generator  (LLM prompt)      │     │
│   │  sync_client      ->  DB upsert                          │     │
│   │  log_sections     ->  DB insert                          │     │
│   │  update_rating    ->  DB write + accept_rate calc         │     │
│   │  log_generation   ->  DB insert                          │     │
│   │  get_best_patterns->  DB query                           │     │
│   └──────────────┬──────────────────────┬────────────────────┘     │
│                  │                       │                           │
│   ┌──────────────▼──────────┐   ┌───────▼────────────────────┐    │
│   │     callLLM() Router     │   │   @supabase/supabase-js     │    │
│   │                          │   │   (service role key)        │    │
│   │  APP_ENV=production      │   └───────────────┬────────────┘    │
│   │    -> Anthropic Claude   │                   │                  │
│   │  APP_ENV=local           │                   │                  │
│   │    -> Ollama             │                   │                  │
│   └──────────────────────────┘                   │                  │
└──────────────────────────────────────────────────┼──────────────────┘
            │              │                        │
            ▼              ▼                        ▼
    ┌──────────────┐ ┌──────────┐     ┌──────────────────────────┐
    │ Anthropic    │ │  Ollama  │     │  Supabase PostgreSQL      │
    │ Claude API   │ │ (local)  │     │  (pgvector + RLS)         │
    └──────────────┘ └──────────┘     └──────────────────────────┘
```

---

## 2. Page Generation Sequence

```
Content Editor     WP REST API      Edge Function    LLM (Claude/Ollama)   Supabase DB
      │                │                  │                  │                   │
      │ POST /generate │                  │                  │                   │
      │ {content,type} │                  │                  │                   │
      │───────────────►│                  │                  │                   │
      │                │ get_best_patterns│                  │                   │
      │                │─────────────────►│                  │                   │
      │                │                  │ SELECT patterns  │                   │
      │                │                  │ (top accept_rate)│                   │
      │                │                  │──────────────────────────────────────►
      │                │                  │◄──────────────────────────────────────
      │                │ analyse_content  │                  │                   │
      │                │─────────────────►│                  │                   │
      │                │                  │ classify sections│                   │
      │                │                  │─────────────────►│                   │
      │                │                  │◄─────────────────│                   │
      │                │                  │ [{section_type,  │                   │
      │                │                  │  headline,...}]  │                   │
      │                │ generate_blocks  │                  │                   │
      │                │─────────────────►│                  │                   │
      │                │                  │ adapt markup     │                   │
      │                │                  │─────────────────►│                   │
      │                │                  │◄─────────────────│                   │
      │                │                  │                  │                   │
      │                │                  │ log_sections     │                   │
      │                │                  │─────────────────────────────────────►│
      │                │                  │◄─────────────────────────────────────│
      │                │                  │ {section UUIDs}  │                   │
      │                │                  │ log_generation   │                   │
      │                │                  │─────────────────────────────────────►│
      │                │ {sections[],     │                  │                   │
      │                │  full_markup}    │                  │                   │
      │◄───────────────│                  │                  │                   │
      │                │                  │                  │                   │
      │ wp.blocks.parse(full_markup)      │                  │                   │
      │ insertBlocks() -> block editor    │                  │                   │
```

---

## 3. Onboarding Sequence

```
WP Activation    WP-Cron       PHP Scanner       Edge Function     Supabase DB
      │              │               │                  │                │
      │ schedule cron│               │                  │                │
      │ (+5 seconds) │               │                  │                │
      │─────────────►│               │                  │                │
      │              │ fire:         │                  │                │
      │              │ onboarding    │                  │                │
      │              │ _scan         │                  │                │
      │              │──────────────►│                  │                │
      │              │               │ check edge URL   │                │
      │              │               │ if absent ->     │                │
      │              │               │ pending_settings │                │
      │              │               │                  │                │
      │              │               │ parse_blocks()   │                │
      │              │               │ all pub. pages   │                │
      │              │               │                  │                │
      │              │               │ read theme.json  │                │
      │              │               │ + Kadence tokens │                │
      │              │               │                  │                │
      │              │               │ build pattern map│                │
      │              │               │ (>=2 occurrences)│                │
      │              │               │                  │                │
      │              │               │ POST sync_client │                │
      │              │               │─────────────────►│                │
      │              │               │                  │ UPSERT clients │
      │              │               │                  │───────────────►│
      │              │               │                  │ INSERT patterns│
      │              │               │                  │───────────────►│
      │              │               │◄─────────────────│                │
      │              │               │ {success: true}  │                │
      │              │               │                  │                │
      │              │               │ status=complete  │                │
      │              │◄──────────────│ onboarded_at=now │                │
```

---

## 4. Rating Flow

```
Content Editor    WP REST API     Edge Function       Supabase DB
      │                │                │                   │
      │ (clicks +1/-1) │                │                   │
      │ POST /rate     │                │                   │
      │ {section_id,   │                │                   │
      │  rating,       │                │                   │
      │  was_edited}   │                │                   │
      │───────────────►│                │                   │
      │                │ update_rating  │                   │
      │                │───────────────►│                   │
      │                │                │ UPDATE sections   │
      │                │                │ SET rating=?      │
      │                │                │──────────────────►│
      │                │                │                   │
      │                │                │ SELECT AVG(rating)│
      │                │                │ WHERE block_name  │
      │                │                │ AND client_id     │
      │                │                │──────────────────►│
      │                │                │◄──────────────────│
      │                │                │                   │
      │                │                │ UPDATE patterns   │
      │                │                │ SET accept_rate   │
      │                │                │     use_count     │
      │                │                │──────────────────►│
      │◄───────────────│ {success:true} │                   │
```

---

## 5. Database Entity Relationship Diagram

```
┌───────────────────────────────┐
│            clients             │
├───────────────────────────────┤
│ id (PK, uuid)                  │
│ site_url (unique)              │
│ site_name                      │
│ theme                          │
│ page_builder                   │
│ block_registry (jsonb)         │
│ design_tokens  (jsonb)         │
│ last_scanned_at (timestamptz)  │
│ created_at      (timestamptz)  │
└───────────────┬───────────────┘
                │ 1
                │
                │ N
┌───────────────▼───────────────┐       ┌───────────────────────────────┐
│             pages              │       │         block_patterns         │
├───────────────────────────────┤       ├───────────────────────────────┤
│ id          (PK, uuid)         │       │ id          (PK, uuid)         │
│ client_id   (FK -> clients)    │       │ client_id   (FK -> clients)    │
│ page_type                      │       │ section_type                   │
│ wp_post_id                     │       │ block_name                     │
│ url                            │       │ sample_markup                  │
│ status                         │       │ embedding   (vector 1536)      │
│ generated_at (timestamptz)     │       │ use_count   (integer)          │
└───────────────┬───────────────┘       │ accept_rate (float, 0.0-1.0)   │
                │ 1                      │ updated_at  (timestamptz)      │
                │                        └───────────────────────────────┘
                │ N
┌───────────────▼───────────────┐
│            sections            │
├───────────────────────────────┤
│ id          (PK, uuid)         │
│ page_id     (FK -> pages)      │
│ section_type                   │
│ position    (integer)          │
│ headline                       │
│ body_text                      │
│ block_name                     │
│ block_markup                   │
│ rating      (smallint)         │  1=accepted | -1=rejected | 0=edited
│ was_edited  (boolean)          │
│ created_at  (timestamptz)      │
└───────────────────────────────┘

┌───────────────────────────────┐
│        generation_logs         │
├───────────────────────────────┤
│ id               (PK, uuid)    │
│ page_id          (FK -> pages) │
│ prompt_tokens    (integer)     │
│ completion_tokens(integer)     │
│ latency_ms       (float)       │
│ model            (text)        │
│ created_at       (timestamptz) │
└───────────────────────────────┘
```

---

## 6. Component Dependency Map

```
WordPress Plugin (PHP)
├── class-activator.php           -> schedules aipb_onboarding_scan cron
├── class-hooks.php               -> registers all WP hooks, REST routes, admin menus
├── class-indexer.php             -> block scanner: parse_blocks(), builds registry
├── class-document-processor.php -> design token extractor: theme.json + Kadence
├── class-ai-client.php          -> HTTP client: proxies requests to Edge Function
├── class-admin.php               -> settings page, admin notices, status display
└── assets/
    ├── admin.js                  -> Gutenberg sidebar (React)
    └── admin.css                 -> sidebar styles

Supabase Edge Function (Deno/TS)
├── index.ts                      -> action router (switch on action field)
├── llm-router.ts                 -> callLLM(): Anthropic or Ollama
├── prompts/
│   ├── analyse-content.ts        -> content classification prompt
│   └── generate-blocks.ts        -> block markup adaptation prompt
└── db/
    ├── clients.ts                -> clients table queries
    ├── patterns.ts               -> block_patterns queries + accept_rate calc
    ├── sections.ts               -> sections insert/update
    └── logs.ts                   -> generation_logs insert
```

---

## 7. LLM Environment Routing

```
Edge Function boot
       │
       ├── APP_ENV === 'production'
       │         └──► Anthropic Claude Sonnet
       │               ANTHROPIC_API_KEY (required)
       │               JSON-only response enforced
       │
       └── APP_ENV === 'local'
                 └──► Ollama HTTP API
                       OLLAMA_BASE_URL (default: http://localhost:11434)
                       OLLAMA_MODEL    (default: llama3.2)
                       JSON-only response enforced
```

---

## 8. REST API Surface

| Method | Route | Auth | Proxies to Edge |
|--------|-------|------|-----------------|
| `POST` | `/wp-json/ai-pagebuilder/v1/generate` | `edit_pages` | `analyse_content` + `generate_blocks` |
| `POST` | `/wp-json/ai-pagebuilder/v1/rate` | `edit_pages` | `update_rating` |
| `GET` | `/wp-json/ai-pagebuilder/v1/status` | `edit_pages` | reads `wp_options` only |

All endpoints return `HTTP 401` for unauthenticated requests.
