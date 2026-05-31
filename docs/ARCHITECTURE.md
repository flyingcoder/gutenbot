# GutenBot — Technical Architecture & Charts

> **Repository scope:** This repository (`guten-bot`) contains only the **WordPress plugin layer** (PHP classes, Gutenberg sidebar JS, WP-Cron, REST API). The Supabase Edge Function (Deno/TypeScript) and the PostgreSQL database schema live in the separate **`gutenbot-edge`** repository.

---

## 1. System Architecture Overview

```mermaid
flowchart TD
    subgraph WP["CLIENT WORDPRESS SITE — guten-bot repo"]
        subgraph BE["WordPress Block Editor"]
            SB["GutenBot Sidebar (React)\nContent textarea · Page type dropdown\nGenerate button · Section preview list\nThumbs-up/down · Insert into editor"]
        end
        PHP["GutenBot PHP Plugin\nREST: POST /generate · POST /rate · GET /status\nWP-Cron: aipb_site_scan → incremental Block Scanner\nAdmin AJAX: gutenbot_sync_supabase\nAdmin POST: gutenbot_scan_site\nWP Table: gutenbot_indexed_posts (per-post scan data)"]
        SB -->|"@wordpress/api-fetch (WP nonce)"| PHP
    end

    subgraph EFR["gutenbot-edge repo"]
        subgraph SUP["Supabase Edge Function (Deno/TS)"]
            AR["Action Router\nanalyse_content · generate_blocks\nsync_client · log_sections\nupdate_rating · log_generation · get_best_patterns"]
            LLMRt["callLLM() Router"]
            SDK["@supabase/supabase-js\nSUPABASE_SECRET_KEYS auto-injected"]
            AR --> LLMRt
            AR --> SDK
        end
        DB[("Supabase PostgreSQL\npgvector + RLS")]
        SDK --> DB
    end

    PHP -->|"HTTPS + client_id"| AR
    LLMRt -->|"localMode=false"| Claude["Anthropic Claude API"]
    LLMRt -->|"localMode=true"| Ollama["Ollama (local dev)"]
```

---

## 2. Page Generation Sequence

Three-phase template synthesis: the LLM no longer invents page structure — it only fills content into an existing site layout.

```mermaid
sequenceDiagram
    participant Editor as Content Editor
    participant REST as WP REST API
    participant Edge as Edge Function
    participant LLM as LLM (Claude / Ollama)
    participant DB as Supabase DB

    Editor->>REST: POST /generate {content, page_type}

    Note over Edge: Phase 1 — Content Parsing
    REST->>Edge: parse_content {content}
    Edge->>Edge: Detect section boundaries<br/>(---, headings, blank-line clusters)
    Edge->>LLM: classify sections {raw_sections[]}
    LLM-->>Edge: {page_type, sections[{heading, body, semantic_intent}]}

    Note over Edge: Phase 2 — Layout Retrieval
    REST->>Edge: get_best_layout {page_type, section_count}
    Edge->>DB: SELECT indexed_pages WHERE page_type<br/>ORDER BY engagement_score DESC LIMIT 1
    DB-->>Edge: template{section_order[], block_trees[]}

    Note over Edge: Phase 3 — Section Mapping
    loop Each parsed section paired with template section
        Edge->>LLM: adapt content into template block_tree<br/>(preserve CSS classes, attrs, block comments)
        LLM-->>Edge: {markup}
    end

    Edge->>DB: log_sections (rating=0)
    DB-->>Edge: section_ids[]
    Edge->>DB: log_generation
    DB-->>Edge: ok

    Edge-->>REST: {sections[], full_markup}
    REST-->>Editor: {success: true, data: {sections[], full_markup}}

    Note over Editor: wp.blocks.parse(full_markup)<br/>insertBlocks() at cursor — no reload
```

---

## 3. Activation & Incremental Scan Sequence

Scan and Supabase sync are independent operations. Activation schedules a scan only; sync is always user-initiated.

```mermaid
sequenceDiagram
    participant WPA as WP Activation
    participant Table as gutenbot_indexed_posts
    participant Cron as WP-Cron
    participant Scanner as Indexer
    participant Options as wp_options

    WPA->>Table: dbDelta() — create table if not exists
    WPA->>Cron: wp_schedule_single_event(now+5, aipb_site_scan)

    Note over Cron: ~5 seconds later
    Cron->>Scanner: fire aipb_site_scan → scan_incremental()

    Scanner->>Scanner: get_posts(ID + post_modified_gmt)
    loop Each post
        Scanner->>Table: SELECT scanned_at WHERE post_id = ?
        alt post_modified_gmt > scanned_at OR no row
            Scanner->>Scanner: parse_blocks() · detect_page_type()\nsplit_into_sections() · walk innerBlocks
            Note over Scanner: core/* and active theme blocks only
            Scanner->>Table: UPSERT row with section data + scanned_at
        else Post unchanged
            Note over Scanner: Skip
        end
    end
    Scanner->>Table: DELETE stale rows (unpublished posts)
    Scanner->>Table: SELECT all — rebuild aggregates
    Scanner->>Scanner: extract_design_tokens from theme.json
    Scanner->>Scanner: PatternBuilder — build patterns
    Scanner->>Options: update aipb_block_registry · aipb_design_tokens\naipb_patterns · aipb_scan_status=complete · aipb_scanned_at=now
```

---

## 4. Rating Flow

```mermaid
sequenceDiagram
    participant Editor as Content Editor
    participant REST as WP REST API
    participant Edge as Edge Function
    participant DB as Supabase DB

    Editor->>REST: POST /rate {section_id, rating: 1 or -1, was_edited}
    REST->>REST: Validate inputs
    REST->>Edge: POST {action: update_rating}
    Edge->>DB: UPDATE sections SET rating, was_edited
    Edge->>DB: SELECT positive_count, total_count WHERE block_name AND client_id
    DB-->>Edge: counts
    Edge->>DB: UPDATE block_patterns SET accept_rate, use_count+1
    DB-->>Edge: ok
    Edge-->>REST: {success: true}
    REST-->>Editor: {success: true}
```

---

## 5. Database Entity Relationship Diagram

> Tables live in the `gutenbot-edge` repository (Supabase migrations).

```mermaid
erDiagram
    clients {
        uuid id PK
        text site_url UK
        text site_name
        text theme
        text page_builder
        jsonb block_registry
        jsonb design_tokens
        timestamptz last_scanned_at
        timestamptz created_at
    }

    pages {
        uuid id PK
        uuid client_id FK
        text page_type
        int wp_post_id
        text url
        text status
        timestamptz generated_at
    }

    sections {
        uuid id PK
        uuid page_id FK
        text section_type
        int position
        text headline
        text body_text
        text block_name
        text block_markup
        smallint rating
        boolean was_edited
        timestamptz created_at
    }

    block_patterns {
        uuid id PK
        uuid client_id FK
        text section_type
        text block_name
        text sample_markup
        vector embedding
        int use_count
        float accept_rate
        timestamptz updated_at
    }

    indexed_pages {
        uuid id PK
        uuid client_id FK
        text page_type
        int wp_post_id
        jsonb section_order
        jsonb sections
        float engagement_score
        timestamptz indexed_at
    }

    generation_logs {
        uuid id PK
        uuid page_id FK
        int prompt_tokens
        int completion_tokens
        float latency_ms
        text model
        timestamptz created_at
    }

    clients ||--o{ pages : "has"
    clients ||--o{ block_patterns : "has"
    clients ||--o{ indexed_pages : "has"
    pages ||--o{ sections : "has"
    pages ||--o{ generation_logs : "has"
```

---

## 6. Component Dependency Map

```mermaid
flowchart TD
    subgraph GB["guten-bot repository — WordPress Plugin"]
        ACT["class-activator.php\nSchedules aipb_site_scan cron\ndbDelta() creates gutenbot_indexed_posts"]
        HK["class-hooks.php\nRegisters all WP hooks, REST routes, admin menus\nAJAX: gutenbot_sync_supabase\nAdmin POST: gutenbot_scan_site · gutenbot_sync_supabase"]
        IDX["class-indexer.php\nIncremental block scanner: scan_incremental()\nReads/writes gutenbot_indexed_posts\ncore + active theme blocks only"]
        DP["class-document-processor.php\nDesign token extractor: theme.json"]
        PB["class-pattern-builder.php\nBuilds pattern library from registry"]
        AIC["class-ai-client.php\nHTTP client — proxies to Edge Function"]
        ADM["class-admin.php\nSettings page, admin notices\nGroup 1: Site Scan · Group 2: Supabase Sync"]
        SB["assets/src/index.js\nGutenberg sidebar (React)"]
        ADMJS["assets/src/admin.js\njQuery AJAX handler for Sync button"]

        HK --> ADM
        HK --> IDX
        HK --> AIC
        IDX --> DP
        IDX --> PB
        ADM --> ADMJS
        SB -->|"api-fetch (WP nonce)"| HK
        ADMJS -->|"$.post admin-ajax.php"| HK
    end

    subgraph EFR["gutenbot-edge repository"]
        EF["Supabase Edge Function\nindex.ts: action router\nllm-router.ts: callLLM()\ndb/: clients · patterns · sections · logs"]
    end

    AIC -->|"HTTPS POST + client_id"| EF
```

---

## 7. LLM Environment Routing

> `callLLM()` lives in `llm-router.ts` inside the `gutenbot-edge` repository. `localMode` is resolved by the PHP plugin from the `GUTENBOT_LOCAL_MODE` wp-config constant (defaults to `false`) and passed in the request body. There is no UI toggle — set the constant in `wp-config.php` for local dev only.

```mermaid
flowchart TD
    A([callLLM invoked]) --> B{"localMode\n(from PHP request body)"}

    B -- false --> C["Build Anthropic request\nmodel: claude-sonnet-4-6\nANTHROPIC_API_KEY required\nJSON-only system prompt"]
    B -- true --> D["Build Ollama request\nmodel: OLLAMA_MODEL env var\nOLLAMA_BASE_URL env var\nJSON-only system prompt"]

    C --> E[POST api.anthropic.com/messages]
    D --> F[POST OLLAMA_BASE_URL/api/chat]

    E --> G{Valid JSON response?}
    F --> G

    G -- Yes --> H([Return parsed object])
    G -- No attempt 1 --> I[Retry with stricter JSON-only prompt]
    I --> J{Valid JSON response?}
    J -- Yes --> H
    J -- No attempt 2 --> K([Throw LLMError\nReturn error envelope to PHP caller])
```

---

## 8. REST API Surface

| Method | Route / Action | Auth | Proxies to Edge |
|--------|---------------|------|-----------------|
| `POST` | `/wp-json/ai-pagebuilder/v1/generate` | `edit_pages` cap + WP nonce | `analyse_content` + `generate_blocks` |
| `POST` | `/wp-json/ai-pagebuilder/v1/rate` | `edit_pages` cap + WP nonce | `update_rating` |
| `GET` | `/wp-json/ai-pagebuilder/v1/status` | `edit_pages` cap + WP nonce | reads `wp_options` only |
| `POST` | `admin-post.php` action `gutenbot_scan_site` | `manage_options` + nonce | triggers incremental scan, redirects |
| `POST` | `admin-ajax.php` action `gutenbot_sync_supabase` | `manage_options` + nonce | `sync_client` — returns JSON, no redirect |

All REST endpoints return `HTTP 401` for unauthenticated requests. Admin POST/AJAX handlers use `wp_die()` on capability failure.
