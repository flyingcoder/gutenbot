# GutenBot — Behavioral Diagrams

Mermaid diagrams covering all major behaviors. Render in any Markdown viewer with Mermaid support (GitHub, VS Code + Mermaid extension, Obsidian, etc.).

> **Repository scope:** Diagrams 1–10 describe the full three-layer system for reference. Only the WordPress plugin layer (PHP classes, Gutenberg sidebar, WP-Cron, REST API) is implemented in this repository. The Edge Function and PostgreSQL database are in the separate **`gutenbot-edge`** repository.

---

## 1. System Context

High-level view of every actor and external system.

```mermaid
flowchart TD
    subgraph WP["WordPress Site (per client)"]
        Editor["Content Editor\n(browser)"]
        Admin["Agency Admin\n(browser)"]
        Plugin["GutenBot PHP Plugin\n(REST + WP-Cron)"]
        BlockEditor["Gutenberg Block Editor\n(React sidebar)"]
    end

    subgraph Supabase["Supabase Cloud"]
        EdgeFn["Edge Function\n(Deno / TypeScript)"]
        DB["PostgreSQL\n(pgvector + RLS)"]
    end

    subgraph LLM["LLM Providers"]
        Claude["Anthropic Claude\n(production)"]
        Ollama["Ollama\n(local dev)"]
    end

    Editor -->|"generates pages via sidebar"| BlockEditor
    Admin -->|"configures settings"| Plugin
    BlockEditor -->|"@wordpress/api-fetch\n(WP nonce)"| Plugin
    Plugin -->|"HTTPS + client_id"| EdgeFn
    EdgeFn -->|"callLLM()"| Claude
    EdgeFn -->|"callLLM()"| Ollama
    EdgeFn -->|"supabase-js\n(SUPABASE_SECRET_KEYS\nauto-injected)"| DB
```

---

## 2. Plugin Activation & Site Scan Sequence

```mermaid
sequenceDiagram
    actor Admin
    participant WP as WordPress
    participant Activator as Activator
    participant Options as wp_options
    participant Table as gutenbot_indexed_posts
    participant Cron as WP-Cron
    participant Hooks as Hooks
    participant Scanner as Indexer

    Admin->>WP: Activate plugin
    WP->>Activator: activate()
    Activator->>Options: get_option('aipb_client_id')

    alt First activation — no UUID
        Options-->>Activator: false
        Activator->>Options: update_option('aipb_client_id', uuid4)
    else Re-activation — UUID exists
        Options-->>Activator: existing_uuid
        Note over Activator: UUID preserved, no overwrite
    end

    Activator->>Table: dbDelta() — create table if not exists

    alt Cron not already scheduled
        Activator->>Cron: wp_schedule_single_event(now+5, 'aipb_site_scan')
    else Already scheduled
        Note over Activator: Skip, no double-schedule
    end

    WP-->>Admin: Activation complete (non-blocking)\nNo outbound HTTP calls made

    Note over Cron: ~5 seconds later
    Cron->>Hooks: fire aipb_site_scan
    Hooks->>Scanner: scan_incremental()
    Scanner->>WP: get_posts(post_status=publish, fields=ID+post_modified_gmt)
    WP-->>Scanner: posts[]

    loop Each post
        Scanner->>Table: SELECT scanned_at WHERE post_id = ?
        Table-->>Scanner: scanned_at or null
        alt post_modified_gmt > scanned_at OR no row exists
            Scanner->>WP: parse_blocks(post_content)
            WP-->>Scanner: blocks[]
            Scanner->>Scanner: detect_page_type · split_into_sections\nbuild section tree + block registry
            Scanner->>Table: UPSERT {post_id, post_modified, page_type,\nsection_order, sections, block_names, scanned_at}
        else Already up to date
            Note over Scanner: Skip — post unchanged
        end
    end

    Scanner->>Table: DELETE rows for unpublished or deleted posts
    Scanner->>Table: SELECT all rows — rebuild aggregates
    Scanner->>Scanner: extract_design_tokens from theme.json
    Scanner->>Scanner: PatternBuilder — build patterns from all rows
    Scanner->>Options: update aipb_block_registry · aipb_design_tokens\naipb_patterns · aipb_scan_status = complete\naipb_scanned_at = now
```

---

## 3. Page Generation Sequence

Three-phase template synthesis. The LLM resolves content only; layout structure comes from an existing indexed page.

```mermaid
sequenceDiagram
    actor Editor
    participant Sidebar as React Sidebar
    participant REST as WP REST API
    participant AI as AiClient
    participant Edge as Edge Function
    participant LLM as LLM (Claude / Ollama)
    participant DB as Supabase DB

    Editor->>Sidebar: Paste content, select page type
    Editor->>Sidebar: Click Generate
    Sidebar->>Sidebar: Disable button, show spinner

    Sidebar->>REST: POST /ai-pagebuilder/v1/generate
    Note over REST: WP nonce + edit_pages cap

    REST->>AI: generate(content, pageType, clientId)

    Note over Edge: Phase 1 — Content Parsing
    AI->>Edge: POST {action: parse_content, content}
    Edge->>Edge: Split at ---, headings,<br/>blank-line clusters
    Edge->>LLM: classify raw sections
    LLM-->>Edge: {page_type, sections[{heading, body, semantic_intent}]}
    Edge-->>AI: parsed_sections[]

    Note over Edge: Phase 2 — Layout Retrieval
    AI->>Edge: POST {action: get_best_layout, page_type, section_count}
    Edge->>DB: SELECT indexed_pages WHERE page_type<br/>ORDER BY engagement_score DESC LIMIT 1
    DB-->>Edge: template{section_order[], block_trees[]}
    Edge-->>AI: template

    Note over Edge: Phase 3 — Section Mapping
    loop Each parsed section paired with nearest template section
        AI->>Edge: POST {action: generate_blocks, parsed_section, block_tree}
        Edge->>LLM: Replace heading + body in block_tree<br/>Preserve classes, attrs, block comments<br/>Images and buttons unchanged
        LLM-->>Edge: {markup}
    end

    AI->>Edge: POST {action: log_sections, sections[]}
    Edge->>DB: INSERT sections (rating=0)
    DB-->>Edge: section_ids[]

    AI->>Edge: POST {action: log_generation}
    Edge->>DB: INSERT generation_logs
    DB-->>Edge: ok

    Edge-->>AI: {sections[], full_markup}
    AI-->>REST: response
    REST-->>Sidebar: {success: true, data: {sections[], full_markup}}

    Sidebar->>Sidebar: Render section preview list
    Sidebar->>Sidebar: Enable Insert + rating buttons

    Editor->>Sidebar: Click Insert into Editor
    Sidebar->>Sidebar: wp.blocks.parse(full_markup)
    Sidebar->>Sidebar: insertBlocks() at cursor
    Note over Sidebar: No page reload
```

---

## 4. Rating Flow Sequence

```mermaid
sequenceDiagram
    actor Editor
    participant Sidebar as React Sidebar
    participant REST as WP REST API
    participant AI as AiClient
    participant Edge as Edge Function
    participant DB as Supabase DB

    Editor->>Sidebar: Click thumbs-up or thumbs-down
    Sidebar->>REST: POST /ai-pagebuilder/v1/rate
    Note over Sidebar,REST: {section_id, rating: 1 or -1, was_edited: bool}

    REST->>REST: Validate section_id, rating, was_edited
    REST->>AI: proxy update_rating

    AI->>Edge: POST {action: update_rating, sectionId, rating, wasEdited}
    Edge->>DB: UPDATE sections SET rating, was_edited
    Edge->>DB: SELECT block_name, client_id FROM sections
    DB-->>Edge: {block_name, client_id}
    Edge->>DB: SELECT positive_count, total_count for block_name + client_id
    DB-->>Edge: counts
    Edge->>DB: UPDATE block_patterns SET accept_rate, use_count+1
    DB-->>Edge: ok

    Edge-->>AI: {success: true}
    AI-->>REST: ok
    REST-->>Sidebar: {success: true}
    Sidebar->>Sidebar: Highlight selected rating button
```

---

## 5. Status State Machines

Scan and sync are tracked independently. Scan does not require connection settings. Sync requires both scan data and connection settings.

```mermaid
stateDiagram-v2
    state "aipb_scan_status" as Scan {
        [*] --> scan_pending : Plugin activated\nCron +5s scheduled
        scan_pending --> scan_complete : Incremental scan runs\nall changed posts processed
        scan_pending --> scan_error : Parse or DB error
        scan_complete --> scan_pending : Admin clicks Scan Site\nor new post published (future)
        scan_error --> scan_pending : Admin clicks Scan Site
    }

    state "aipb_sync_status" as Sync {
        [*] --> sync_pending_settings : No Edge URL\nor Anon Key saved
        sync_pending_settings --> sync_pending : Admin saves\nEdge URL + Anon Key
        sync_pending --> sync_complete : AJAX sync succeeds\nEdge Function 200
        sync_pending --> sync_error : Network error\nor Edge 5xx
        sync_complete --> sync_pending : Admin saves\nnew connection settings
        sync_error --> sync_pending : Admin retries sync
    }
```

---

## 6. Block Scanner Activity Flow (Incremental)

```mermaid
flowchart TD
    A([aipb_site_scan fired]) --> B[get_posts: IDs + post_modified_gmt\nall published pages and posts]
    B --> C[For each post: query gutenbot_indexed_posts\nfor existing scanned_at timestamp]

    C --> D{post_modified_gmt\n> scanned_at?\nOR no row?}
    D -- No change --> E[Skip post]
    D -- New or changed --> F[parse_blocks post_content]

    F --> G[detect_page_type via title + slug heuristics\nguide / service / location / blog / landing]
    G --> H[split_into_sections at core/separator\ncore/group · core/cover boundaries]
    H --> I[For each section: record\nsection_type · signature · block_tree\ncontent_density · semantic_intent]
    I --> J[Walk innerBlocks recursively\nRecord block names + occurrence counts]
    J --> K[UPSERT gutenbot_indexed_posts\npost_id · post_modified · page_type\nsection_order · sections · block_names · scanned_at]

    E --> L{More posts?}
    K --> L
    L -- Yes --> C
    L -- No --> M[DELETE rows for posts\nno longer published]

    M --> N[SELECT all rows — rebuild aggregates\nfrom entire table]
    N --> O[Filter block_registry: occurrence >= 2]

    O --> P{theme.json\npresent?}
    P -- Yes --> Q[Extract color palette\nfont sizes · spacing]
    P -- No --> R[Empty design tokens]

    Q --> S[PatternBuilder: map block names → section types\nBuild block_patterns + indexed_pages]
    R --> S
    S --> T[Update wp_options:\naipb_block_registry · aipb_design_tokens · aipb_patterns\naipb_scan_status = complete · aipb_scanned_at = now]
    T --> Z([Exit — sync is a separate action])
```

---

## 7. LLM Routing Decision Flow

```mermaid
flowchart TD
    A([callLLM invoked]) --> B{"localMode\n(from request body)?"}

    B -- false --> C["Build Anthropic request\nmodel: claude-sonnet-4-6\nJSON-only system prompt"]
    B -- true --> D["Build Ollama request\nmodel: OLLAMA_MODEL env var\nJSON-only system prompt"]

    C --> E[POST api.anthropic.com/messages]
    D --> F[POST OLLAMA_BASE_URL/api/chat]

    E --> G{Valid JSON\nresponse?}
    F --> G

    G -- Yes --> H([Return parsed object])
    G -- No, attempt 1 --> I[Retry with stricter\nJSON-only prompt]
    I --> J{Valid JSON\nresponse?}
    J -- Yes --> H
    J -- No, attempt 2 --> K[Throw LLMError]
    K --> L([Return error envelope\nto PHP caller])
```

---

## 8. REST API Authorization Flow

```mermaid
flowchart LR
    A([Incoming Request]) --> B{WP nonce\nvalid?}
    B -- No --> ERR[HTTP 401]
    B -- Yes --> C{edit_pages\ncapability?}
    C -- No --> ERR

    C -- Yes --> D{Route}

    D -- GET /status --> S1[Read wp_options]
    S1 --> S2([200: client_id\nstatus, masked URL])

    D -- POST /generate --> G1{content length\npage_type present?}
    G1 -- Invalid --> BAD[HTTP 400]
    G1 -- Valid --> G2[POST Edge Function\nanalyse + generate]
    G2 --> G3{200?}
    G3 -- Yes --> G4(["200: sections[]<br/>full_markup"])
    G3 -- No --> G5([500: error])

    D -- POST /rate --> R1{section_id UUID\nrating 1 or -1\nwas_edited bool?}
    R1 -- Invalid --> BAD
    R1 -- Valid --> R2[POST Edge Function\nupdate_rating]
    R2 --> R3{200?}
    R3 -- Yes --> R4([200: success])
    R3 -- No --> R5([500: error])
```

---

## 9. Settings Page Admin Flow

```mermaid
flowchart TD
    A([Admin opens Settings > AI Page Builder]) --> B[Load wp_options]
    B --> C[Render Connection Settings form\nEdge URL · Anon Key · LLM Provider]
    C --> D[Render Group 1 — Site Scan\nStatus badge · Last Scanned · Error · Scan Site button\nNo connection settings required]
    D --> E{can_sync?\nEdge URL + Anon Key\n+ Provider all saved?}
    E -- Yes --> F[Render Group 2 — Supabase Sync\nStatus badge · Last Synced · Client UUID\nSync to Supabase button enabled]
    E -- No --> G[Render Group 2 — Supabase Sync\nSync button hidden\nShow: Save settings to enable syncing]

    F --> H{Admin action}
    G --> H

    H -- Saves Connection Settings --> I[sanitize + update_option\naipb_edge_function_url · aipb_anon_key · aipb_provider]
    I --> J[on_connection_setting_saved:\nset aipb_sync_status = pending]
    J --> K([Redirect back to settings page])

    H -- Clicks Scan Site --> L[Form POST → admin-post.php\naction=gutenbot_scan_site + nonce]
    L --> M[Admin::handle_scan_site\ndo_action aipb_site_scan → incremental scan]
    M --> N([Redirect: ?gutenbot_scanned=success|error])

    H -- Clicks Sync to Supabase --> O[jQuery AJAX POST → admin-ajax.php\naction=gutenbot_sync_supabase + nonce]
    O --> P[Hooks::ajax_sync_supabase\nvalidate nonce + capability\nrun_supabase_sync]
    P --> Q{Success?}
    Q -- Yes --> R[JS updates badge to Complete\nUpdates synced_at timestamp inline\nNo page reload]
    Q -- No --> S[JS shows error message inline\nNo page reload]
```

---

## 10. PHP Class Relationships

```mermaid
classDiagram
    class Activator {
        +activate() void
        -maybe_generate_client_id() void
        -maybe_schedule_scan() void
        -create_tables() void
    }

    class Hooks {
        -Admin admin
        -Rest rest
        +register() void
        +on_init() void
        +run_site_scan() void
        +run_supabase_sync() void
        +ajax_sync_supabase() void
        +on_connection_setting_saved() void
    }

    class Admin {
        +register_settings_page() void
        +register_settings() void
        +render_settings_page() void
        +show_notices() void
        +handle_scan_site() void
        +handle_sync_supabase() void
    }

    class Rest {
        +register_routes() void
        +check_permission() bool
        +get_status(WP_REST_Request) WP_REST_Response
        +generate(WP_REST_Request) WP_REST_Response
        +rate(WP_REST_Request) WP_REST_Response
    }

    class Indexer {
        +scan_incremental() array
        -scan_posts() void
        -build_page_data(object, array) array
        -detect_page_type(object) string
        -split_into_sections(array) array
        -classify_section_type(array) string
        -rebuild_aggregates() array
        +get_registry() array
    }

    class DocumentProcessor {
        +extract_design_tokens() array
        -read_theme_json(string) array
    }

    class PatternBuilder {
        +SECTION_TYPE_MAP array
        +build(array) array
        +build_indexed_pages(array) array
    }

    class AiClient {
        -string edgeFunctionUrl
        +sync_client(array, array, array, array) array
        +generate(string, string, int) array
        +rate(string, int, bool) array
        -post(string, array) array
    }

    class IndexedPostsTable {
        <<WordPress custom table>>
        +upsert(post_id, data) void
        +get_scanned_at(post_id) string|null
        +delete_stale(active_ids) void
        +get_all() array
    }

    Hooks "1" --> "1" Admin : owns
    Hooks "1" --> "1" Rest : owns
    Hooks ..> Indexer : invokes via cron / admin-post
    Hooks ..> AiClient : calls sync_client via ajax handler
    Indexer --> DocumentProcessor : uses
    Indexer --> PatternBuilder : uses
    Indexer --> IndexedPostsTable : reads and writes per-post data
    Rest ..> AiClient : delegates generate and rate
```

---

## 11. Database Entity Relationship

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
    pages ||--o{ sections : "has"
    pages ||--o{ generation_logs : "has"
```

---

## 12. Edge Function Activity Flow — `gutenbot-edge` repo

Action router logic inside `index.ts`: how an incoming HTTP request is validated and dispatched to the correct handler, and what each handler does against the LLM and the database.

```mermaid
flowchart TD
    A([HTTP POST received]) --> B[Parse JSON body]
    B --> C{client_id\nin body?}
    C -- No --> E400A[Return 400\nmissing client_id]

    C -- Yes --> D[SELECT id FROM clients\nWHERE id = client_id]
    D --> E{Found?}
    E -- No --> E401[Return 401\nunknown client]

    E -- Yes --> F{action field}

    F -- sync_client --> SC1[UPSERT clients row\nsite_url, theme, tokens]
    SC1 --> SC2[Bulk UPSERT block_patterns\nfor this client_id]
    SC2 --> SC3[Bulk UPSERT indexed_pages\npage_type + section_order + block_trees]
    SC3 --> OK

    F -- parse_content --> PC1[Split raw content at ---\nheadings, blank-line clusters]
    PC1 --> PC2[callLLM: classify sections\nheading + body + semantic_intent]
    PC2 --> PC3{Valid JSON?}
    PC3 -- Yes --> OK
    PC3 -- No, attempt 1 --> PC4[Retry with stricter\nJSON-only prompt]
    PC4 --> PC5{Valid JSON?}
    PC5 -- Yes --> OK
    PC5 -- No, attempt 2 --> ELLM[Return LLMError\nenvelope]

    F -- get_best_layout --> GL1[SELECT indexed_pages\nWHERE page_type = input\nORDER BY engagement_score DESC\nLIMIT 1]
    GL1 --> GL2[Return section_order[]\nand block_trees[]]
    GL2 --> OK

    F -- generate_blocks --> GB1[For each section pair\nparsed section + template block_tree]
    GB1 --> GB2[callLLM: replace heading + body\nin block_tree\npreserve classes + attrs + block comments\nimages and buttons unchanged]
    GB2 --> GB3[Collect per-section markup]
    GB3 --> GB4[Concatenate full_markup]
    GB4 --> OK

    F -- log_sections --> LS1[INSERT sections rows\nrating = 0 initial]
    LS1 --> LS2[Return section UUIDs]
    LS2 --> OK

    F -- log_generation --> LG1[INSERT generation_logs\nprompt_tokens, latency_ms, model]
    LG1 --> OK

    F -- update_rating --> UR1[UPDATE sections\nSET rating, was_edited]
    UR1 --> UR2[SELECT positive_count\ntotal_count for block_name + client_id]
    UR2 --> UR3[UPDATE block_patterns\nSET accept_rate = positive / total\nuse_count + 1]
    UR3 --> OK

    F -- unknown --> E400B[Return 400\nunknown action]

    OK([Return success envelope\n{success: true, data: ...}])
    E400A --> DONE([Response sent])
    E401 --> DONE
    ELLM --> DONE
    E400B --> DONE
    OK --> DONE
```

---

## 13. AJAX Supabase Sync Flow

Triggered by the "Sync to Supabase" button on the settings page. Runs without a page reload.

```mermaid
sequenceDiagram
    actor Admin
    participant Page as Settings Page (browser)
    participant Ajax as admin-ajax.php
    participant Hooks as Hooks::ajax_sync_supabase
    participant Options as wp_options
    participant AI as AiClient
    participant Edge as Edge Function
    participant DB as Supabase DB

    Admin->>Page: Click "Sync to Supabase"
    Page->>Page: Disable button · show "Syncing…"

    Page->>Ajax: $.post(ajaxurl, {action: gutenbot_sync_supabase, _wpnonce})
    Ajax->>Hooks: ajax_sync_supabase()
    Hooks->>Hooks: check_ajax_referer + current_user_can('manage_options')

    alt Connection settings missing
        Hooks->>Options: set aipb_sync_status = pending_settings
        Hooks-->>Page: wp_send_json_error({message: 'Save connection settings first'})
    else No scan data in wp_options
        Hooks->>Options: set aipb_sync_status = error
        Hooks-->>Page: wp_send_json_error({message: 'Run a site scan first'})
    else Settings + scan data present
        Hooks->>Options: read aipb_block_registry · aipb_design_tokens\naipb_patterns · aipb_indexed_pages
        Hooks->>AI: sync_client(registry, tokens, patterns, indexed_pages)
        AI->>Edge: POST {action: sync_client, client_id, ...data}
        Edge->>DB: UPSERT clients row
        Edge->>DB: UPSERT block_patterns
        Edge->>DB: UPSERT indexed_pages
        DB-->>Edge: ok
        Edge-->>AI: {success: true, data: {client_id}}
        AI-->>Hooks: {success: true}
        Hooks->>Options: aipb_sync_status = complete · aipb_synced_at = now
        Hooks-->>Page: wp_send_json_success({synced_at: '...'})
    end

    Page->>Page: Re-enable button
    alt Success response
        Page->>Page: Update badge → Complete\nUpdate synced_at text inline
    else Error response
        Page->>Page: Update badge → Error\nShow error message inline
    end
```
