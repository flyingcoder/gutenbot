# GutenBot Technical Documentation

**Version:** 1.0  
**Type:** WordPress Plugin  
**Status:** Implementation Plan

---

## Overview

GutenBot is an AI-powered WordPress plugin that automates the creation of draft pages from uploaded documents. It learns from the site's existing Gutenberg layouts, theme styles, and content patterns, then uses that knowledge to produce structurally consistent, on-brand draft pages.

The plugin eliminates the manual effort of converting raw content documents (briefs, copy decks, markdown files) into properly structured Gutenberg pages by:

1. Indexing the site's existing page layouts and block patterns
2. Scanning active theme configuration for visual style rules
3. Accepting bulk document uploads and extracting raw text
4. Sending extracted content to an AI planner that maps it to a page plan
5. Generating valid Gutenberg block markup from that plan
6. Inserting the result as a WordPress draft page

---

## Architecture

### Plugin Directory Structure

```
gutenbot/
├── gutenbot.php                        # Plugin bootstrap and loader
├── includes/
│   ├── class-activator.php             # Activation hooks: DB tables, defaults
│   ├── class-admin.php                 # Admin menu registration
│   ├── class-indexer.php               # Page and block pattern scanner
│   ├── class-document-processor.php    # File parsing (md, txt, docx, pdf)
│   ├── class-ai-client.php             # AI API communication
│   ├── class-page-generator.php        # Gutenberg markup assembler
│   └── class-hooks.php                 # WordPress action/filter bindings
├── admin/
│   ├── upload-page.php                 # Bulk document upload UI
│   ├── settings-page.php               # API keys, rules, preferences
│   └── index-status-page.php           # Index health and re-index controls
└── assets/                             # JS/CSS for admin UI
```

### Class Responsibilities

| Class | Responsibility |
|---|---|
| `GutenBot_Activator` | Creates database tables, registers upload directory, sets default options |
| `GutenBot_Admin` | Registers admin menu pages and enqueues admin assets |
| `GutenBot_Indexer` | Scans `wp_posts`, theme files, and block patterns; writes to index tables |
| `GutenBot_Document_Processor` | Parses uploaded files into normalized plain text |
| `GutenBot_AI_Client` | Sends content + context to the AI API; returns structured page plan |
| `GutenBot_Page_Generator` | Converts the AI page plan into valid Gutenberg HTML comment markup |
| `GutenBot_Hooks` | Registers all `add_action` and `add_filter` calls |

---

## Database Schema

GutenBot creates five custom tables on activation.

### `wp_gutenbot_layout_index`

Stores analyzed published pages with their parsed block structure.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT` | Primary key |
| `post_id` | `BIGINT` | Source `wp_posts` ID |
| `page_type` | `VARCHAR(64)` | Detected type: `service`, `location`, `guide`, etc. |
| `template_slug` | `VARCHAR(128)` | Active page template |
| `block_structure` | `LONGTEXT` | JSON: ordered block name hierarchy |
| `section_order` | `TEXT` | JSON: high-level section sequence |
| `indexed_at` | `DATETIME` | Last index timestamp — format: `YYYY-MM-DD HH:MM:SS` |

### `wp_gutenbot_section_index`

Stores individual reusable sections (hero, FAQ, CTA, etc.) extracted from published pages.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT` | Primary key |
| `layout_id` | `BIGINT` | FK → `wp_gutenbot_layout_index.id` |
| `section_type` | `VARCHAR(64)` | Section label: `hero`, `cta`, `faq`, `columns`, etc. |
| `block_markup` | `LONGTEXT` | Raw Gutenberg HTML comment block |
| `css_classes` | `TEXT` | Space-separated CSS classes on the block |
| `source_post_id` | `BIGINT` | Originating page |

### `wp_gutenbot_style_index`

Stores extracted theme style data from `theme.json` and CSS.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT` | Primary key |
| `style_key` | `VARCHAR(128)` | E.g., `color.palette`, `typography.fontSizes` |
| `style_value` | `LONGTEXT` | JSON value from theme configuration |
| `source` | `VARCHAR(64)` | Origin: `theme.json`, `style.css`, `functions.php` |
| `indexed_at` | `DATETIME` | Last index timestamp — format: `YYYY-MM-DD HH:MM:SS` |

### `wp_gutenbot_generation_jobs`

Tracks the lifecycle of each uploaded document through processing.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT` | Primary key |
| `file_name` | `VARCHAR(255)` | Original uploaded filename |
| `file_path` | `VARCHAR(512)` | Server path to uploaded file |
| `file_type` | `VARCHAR(16)` | `md`, `txt`, `docx`, `pdf` |
| `status` | `VARCHAR(32)` | `uploaded`, `processing`, `draft_created`, `failed` |
| `detected_page_type` | `VARCHAR(64)` | AI-detected type |
| `draft_post_id` | `BIGINT` | Created draft page ID (nullable) |
| `layout_source_id` | `BIGINT` | Index ID used as layout reference |
| `error_message` | `TEXT` | Error detail on failure |
| `created_at` | `DATETIME` | Upload timestamp — format: `YYYY-MM-DD HH:MM:SS` |
| `updated_at` | `DATETIME` | Last status change — format: `YYYY-MM-DD HH:MM:SS` |

### `wp_gutenbot_rules`

Stores admin-defined rules that influence AI page planning.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT` | Primary key |
| `rule_key` | `VARCHAR(128)` | Rule identifier |
| `rule_value` | `TEXT` | Rule content |
| `created_at` | `DATETIME` | Creation timestamp — format: `YYYY-MM-DD HH:MM:SS` |

---

## Phase Implementation Guide

### Phase 1: Plugin Foundation

The main plugin file (`gutenbot.php`) bootstraps all classes and registers the activation hook:

```php
register_activation_hook(__FILE__, ['GutenBot_Activator', 'activate']);
```

`GutenBot_Activator::activate()` must:

- Run `dbDelta()` to create all five custom tables
- Call `wp_upload_dir()` to verify and create the GutenBot upload subdirectory
- Register admin menu pages via `GutenBot_Admin`
- Store default plugin options via `update_option()`

All WordPress action and filter bindings live in `GutenBot_Hooks` to keep other classes free of WordPress coupling.

---

### Phase 2: Site Indexer

`GutenBot_Indexer` scans the site's content to build the layout and section indexes.

**Trigger points:**
- Manual re-index from the Index Status admin page
- Automatic re-index when a page is published (see Phase 8)

**Page scanning loop:**

```php
$posts = get_posts([
    'post_type'   => 'page',
    'post_status' => 'publish',
    'numberposts' => -1,
]);

foreach ($posts as $post) {
    $blocks = parse_blocks($post->post_content);
    $this->analyze_and_store($post, $blocks);
}
```

**Block data extracted per page:**

- Block names and hierarchy depth
- Section order (hero → intro → benefits → faq → cta)
- Heading text and levels
- CTA block count and position
- FAQ block presence
- Column, group, and button usage
- CSS classes on top-level blocks
- Active template slug
- Inferred page type

**Page type classification** inspects the URL slug, page title, and dominant block types against keyword rules. For example: slugs containing `services/` classify as `service`; presence of a map block classifies as `location`.

---

### Phase 3: Theme Scanner

The theme scanner is invoked during indexing and populates `wp_gutenbot_style_index`.

**Files scanned:**

| File | Data extracted |
|---|---|
| `theme.json` | Color palette, typography scale, spacing presets, layout widths |
| `templates/*.html` | Template structure, available block areas |
| `parts/*.html` | Reusable parts (header, footer) |
| `patterns/*.php` | Registered block patterns with slugs |
| `style.css` | Custom properties, font imports |
| `functions.php` | `register_block_pattern()` calls, `add_theme_support()` declarations |

**`theme.json` extraction example:**

```php
$theme_json = json_decode(
    file_get_contents(get_template_directory() . '/theme.json'),
    true
);
$palette = $theme_json['settings']['color']['palette'] ?? [];
```

The style index gives the AI client a compact style summary so generated blocks use the correct color slugs and font size tokens rather than hardcoded values.

---

### Phase 4: Document Upload System

The admin upload page accepts bulk file uploads. Each file must pass server-side validation before a job record is created.

**Accepted formats:**

| Format | Parser |
|---|---|
| `.md` | Markdown-to-text (strip syntax, preserve structure) |
| `.txt` | Direct read, whitespace normalization |
| `.docx` | PHPWord or ZipArchive XML extraction |
| `.pdf` | pdfparser library or `pdftotext` CLI |

**Validation rules:**

- Maximum file size: configurable via settings (default 10 MB)
- MIME type verified server-side against the declared extension
- Filename sanitized on storage with `sanitize_file_name()`

**Job creation:**

```php
$wpdb->insert('wp_gutenbot_generation_jobs', [
    'file_name'  => sanitize_file_name($original_name),
    'file_path'  => $stored_path,
    'file_type'  => $extension,
    'status'     => 'uploaded',
    'created_at' => current_time('mysql'),
]);
```

After insertion, `GutenBot_Document_Processor` normalizes the file content into clean UTF-8 plain text, stripping markup and binary artifacts before AI submission.

---

### Phase 5: AI Page Planner

`GutenBot_AI_Client` constructs a prompt payload and calls the configured AI API endpoint.

**Payload sent to AI:**

```json
{
  "document_content": "<normalized plain text>",
  "similar_layouts": [],
  "reusable_sections": [],
  "theme_style_summary": {},
  "admin_rules": []
}
```

**Expected AI response:**

```json
{
  "page_type": "service",
  "title": "Fence Installation Services",
  "sections": ["hero", "intro", "benefits", "process", "faq", "cta"],
  "layout_source": 123
}
```

`layout_source` references a `wp_gutenbot_layout_index.id`. When present, the generator prioritizes reusing that page's full block structure before falling back to assembled sections.

**Error handling:**

- Validate AI response against expected JSON schema before use
- Retry once on malformed or unexpected response
- Set job status to `failed` with an error message after two failures
- Log raw AI response to `_gutenbot_generation_log` post meta for debugging

---

### Phase 6: Gutenberg Markup Generator

`GutenBot_Page_Generator` converts the AI page plan into valid Gutenberg HTML comment syntax.

**Priority order for each section:**

1. Reuse the full-page layout referenced by `layout_source` (copy block markup, replace text)
2. Reuse the closest matching section from `wp_gutenbot_section_index`
3. Use a registered theme block pattern matching the section type
4. Use a GutenBot built-in fallback block template
5. Generate minimal new Gutenberg block markup from scratch

**Valid Gutenberg output format:**

```html
<!-- wp:group {"className":"hero-section"} -->
<div class="wp-block-group hero-section">

  <!-- wp:heading {"level":1} -->
  <h1>Fence Installation Services</h1>
  <!-- /wp:heading -->

  <!-- wp:paragraph -->
  <p>Professional fence installation for residential and commercial properties.</p>
  <!-- /wp:paragraph -->

</div>
<!-- /wp:group -->
```

All block attributes must be valid JSON within the comment. Malformed attributes cause the block to render as a Classic block in the editor — validate with `parse_blocks()` before insertion.

**Pre-insertion validation:**

- `parse_blocks()` must return no empty or invalid block entries
- Exactly one `wp:heading {"level":1}` must exist in the full output
- No existing published page may share the same title

---

### Phase 7: Draft Page Creation

After markup validation passes, `GutenBot_Page_Generator` creates the draft:

```php
$post_id = wp_insert_post([
    'post_title'   => wp_strip_all_tags($title),
    'post_content' => $block_markup,
    'post_status'  => 'draft',
    'post_type'    => 'page',
]);
```

**Post meta saved on creation:**

| Meta key | Value |
|---|---|
| `_gutenbot_source_file` | Original uploaded filename |
| `_gutenbot_detected_page_type` | `service`, `location`, `guide`, etc. |
| `_gutenbot_layout_source` | `layout_source_id` used |
| `_gutenbot_generation_log` | Raw AI response JSON |

The generation job is then updated:

```php
$wpdb->update('wp_gutenbot_generation_jobs', [
    'status'        => 'draft_created',
    'draft_post_id' => $post_id,
    'updated_at'    => current_time('mysql'),
], ['id' => $job_id]);
```

---

### Phase 8: Auto Re-Indexing

When a page transitions from draft to published, GutenBot re-indexes it automatically:

```php
add_action('transition_post_status', 'gutenbot_reindex_on_publish', 10, 3);

function gutenbot_reindex_on_publish($new_status, $old_status, $post) {
    if ($new_status === 'publish' && $post->post_type === 'page') {
        GutenBot_Indexer::reindex_post($post);
    }
}
```

`reindex_post()` deletes existing index rows for that `post_id` and re-runs the full block analysis, keeping the index current as editors refine pages after initial generation.

Re-indexing is also triggered on `save_post` when the post is already published, so edits to live pages propagate to the layout and section indexes.

---

### Phase 9: Admin Review Queue

The Review Queue admin page provides a table view of all generation jobs.

**Columns displayed:**

| Column | Description |
|---|---|
| File | Uploaded filename |
| Page Type | AI-detected classification |
| Draft | Edit link for the created draft (when available) |
| Layout Source | Link to the indexed source page used |
| Status | Current job status badge |
| Error | Error message (when status is `failed`) |
| Created | Upload timestamp |

**Status values:**

| Status | Meaning |
|---|---|
| `uploaded` | File received, awaiting processing |
| `processing` | Document being parsed and AI called |
| `draft_created` | Draft page successfully created |
| `failed` | Processing stopped; error message available |

---

## Unit Testing

### Test Stack

GutenBot uses [WP_UnitTestCase](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/) (WordPress core test framework) with PHPUnit. The test database is a separate MySQL instance — never the development database.

**Required dev dependencies (`composer.json`):**

```json
{
  "require-dev": {
    "phpunit/phpunit": "^9.0",
    "brain/monkey": "^2.6",
    "mockery/mockery": "^1.5"
  }
}
```

`Brain Monkey` stubs WordPress functions (`parse_blocks`, `get_posts`, `wp_insert_post`, etc.) so unit tests run without a full WordPress bootstrap. Integration tests that touch the database extend `WP_UnitTestCase` and do use the WordPress bootstrap.

### Test Directory Structure

```
gutenbot/
└── tests/
    ├── bootstrap.php                   # PHPUnit bootstrap — loads WP test suite
    ├── unit/
    │   ├── test-document-processor.php # GutenBot_Document_Processor unit tests
    │   ├── test-indexer.php            # GutenBot_Indexer unit tests
    │   ├── test-ai-client.php          # GutenBot_AI_Client unit tests
    │   ├── test-page-generator.php     # GutenBot_Page_Generator unit tests
    │   └── test-activator.php          # GutenBot_Activator unit tests
    └── integration/
        ├── test-indexer-db.php         # Indexer database write/read tests
        ├── test-page-creation.php      # wp_insert_post integration tests
        └── test-reindex-hook.php       # transition_post_status hook tests
```

### Coverage Requirements

Minimum 80% line coverage across all `includes/` classes. Run coverage with:

```bash
phpunit --coverage-html tests/coverage
```

### Unit Test Cases per Class

#### `GutenBot_Document_Processor`

| Test | Assertion |
| --- | --- |
| `test_markdown_stripped_to_plain_text` | Headings, bold, links removed; body text preserved |
| `test_empty_file_returns_empty_string` | Returns `''` for zero-byte input |
| `test_txt_whitespace_normalized` | Multiple blank lines collapsed to single line break |
| `test_oversized_file_throws_exception` | Throws `InvalidArgumentException` when content exceeds size limit |
| `test_unsupported_extension_throws_exception` | Throws `InvalidArgumentException` for `.exe`, `.zip`, etc. |

```php
public function test_markdown_stripped_to_plain_text() {
    // Arrange
    $markdown = "## Services\n\n**Fast** installation with [contact us](https://example.com).";

    // Act
    $result = GutenBot_Document_Processor::parse_md($markdown);

    // Assert
    $this->assertStringNotContainsString('##', $result);
    $this->assertStringNotContainsString('**', $result);
    $this->assertStringContainsString('Fast', $result);
    $this->assertStringContainsString('Services', $result);
}
```

#### `GutenBot_Indexer`

| Test | Assertion |
| --- | --- |
| `test_classify_service_page_from_slug` | Slug `fence-installation-services` returns `service` |
| `test_classify_location_page_from_slug` | Slug `dallas-tx` returns `location` |
| `test_classify_guide_from_title` | Title containing "how to" returns `guide` |
| `test_extract_section_order_from_blocks` | Ordered block list maps to expected section sequence |
| `test_heading_levels_extracted_correctly` | Returns array of `['level' => 2, 'text' => 'Why Us']` entries |
| `test_private_page_skipped` | Pages with `post_status = private` are not added to the index |

```php
public function test_classify_service_page_from_slug() {
    // Arrange
    $post = (object) ['post_name' => 'fence-installation-services', 'post_title' => 'Services'];

    // Act
    $type = GutenBot_Indexer::classify_page_type($post, []);

    // Assert
    $this->assertSame('service', $type);
}
```

#### `GutenBot_AI_Client`

The AI client must be tested against a mock HTTP transport — never a live API in unit tests.

| Test | Assertion |
| --- | --- |
| `test_payload_includes_document_content` | Outgoing request body contains `document_content` key |
| `test_payload_includes_theme_style_summary` | Outgoing request body contains `theme_style_summary` key |
| `test_valid_response_parsed_to_page_plan` | Returns array with `page_type`, `title`, `sections`, `layout_source` |
| `test_malformed_json_triggers_retry` | Client retries once then returns `null` on second failure |
| `test_missing_sections_key_sets_job_failed` | Missing `sections` in response sets job status to `failed` |

```php
public function test_valid_response_parsed_to_page_plan() {
    // Arrange
    $raw_response = json_encode([
        'page_type'     => 'service',
        'title'         => 'Fence Installation',
        'sections'      => ['hero', 'faq', 'cta'],
        'layout_source' => 42,
    ]);
    $client = new GutenBot_AI_Client($this->mock_http_client($raw_response));

    // Act
    $plan = $client->get_page_plan('raw document text', [], [], []);

    // Assert
    $this->assertSame('service', $plan['page_type']);
    $this->assertContains('hero', $plan['sections']);
}
```

#### `GutenBot_Page_Generator`

| Test | Assertion |
| --- | --- |
| `test_output_is_valid_gutenberg_markup` | `parse_blocks($output)` returns no empty blocks |
| `test_exactly_one_h1_in_output` | Exactly one `wp:heading {"level":1}` present |
| `test_layout_source_used_when_provided` | When `layout_source` is set, markup is sourced from index |
| `test_fallback_blocks_used_when_no_source` | When `layout_source` is null, fallback templates are used |
| `test_section_order_matches_plan` | Sections appear in the order specified by the AI page plan |
| `test_duplicate_title_throws_exception` | Throws exception when title already exists as a published page |

```php
public function test_exactly_one_h1_in_output() {
    // Arrange
    $plan = [
        'page_type'     => 'service',
        'title'         => 'Fence Services',
        'sections'      => ['hero', 'cta'],
        'layout_source' => null,
    ];

    // Act
    $markup = GutenBot_Page_Generator::build($plan, []);

    // Assert
    $count = substr_count($markup, '"level":1');
    $this->assertSame(1, $count);
}
```

#### `GutenBot_Activator`

| Test | Assertion |
| --- | --- |
| `test_all_five_tables_created_on_activation` | All five table names exist in `$wpdb->tables` after activation |
| `test_default_options_set_on_activation` | `get_option('gutenbot_file_size_limit')` returns `10485760` (10 MB) |
| `test_upload_directory_created` | Upload subdirectory exists after activation |
| `test_activation_is_idempotent` | Running `activate()` twice does not throw errors or duplicate tables |

### Integration Tests

Integration tests extend `WP_UnitTestCase` and run with `--group integration`. They require the WordPress test suite to be installed via `bin/install-wp-tests.sh`.

| Test | Assertion |
| --- | --- |
| `test_indexer_writes_layout_row` | After indexing a published page, a row exists in `wp_gutenbot_layout_index` |
| `test_indexer_writes_section_rows` | Hero and CTA sections appear in `wp_gutenbot_section_index` |
| `test_draft_page_created_with_correct_meta` | `_gutenbot_source_file` meta matches uploaded filename |
| `test_reindex_fires_on_publish_transition` | Publishing a draft triggers `GutenBot_Indexer::reindex_post()` |
| `test_reindex_updates_existing_row` | Re-indexing a page updates `indexed_at`, does not duplicate the row |

### Running Tests

```bash
# All unit tests
phpunit --testsuite unit

# All integration tests (requires WP test DB)
phpunit --testsuite integration

# Full suite with coverage report
phpunit --coverage-html tests/coverage

# Single class
phpunit tests/unit/test-page-generator.php
```

---

## Safety and Validation

### File Upload Safety

- Files stored outside the web root or in a protected subdirectory
- MIME type verified server-side regardless of declared file extension
- Maximum configurable file size enforced (default: 10 MB)
- Uploaded filenames sanitized with `sanitize_file_name()` before storage

### Content Safety

- All document text sanitized before AI submission
- AI response validated against expected JSON schema before use
- Generated block markup validated with `parse_blocks()` before `wp_insert_post()`

### Page Integrity

- Exactly one H1 required in all generated markup
- Duplicate page titles blocked via pre-insertion title check
- Private and password-protected pages excluded from the site indexer

### Access Control

- All admin pages require `manage_options` capability
- Nonce verification on all form submissions
- Direct file access blocked with `defined('ABSPATH') || exit;` in all PHP files

---

## Recommended MVP Scope

The minimum viable implementation that delivers end-to-end value:

**Include in MVP:**

- Index published pages and parse Gutenberg block structure
- Scan `theme.json` for color palette and typography
- Support `.txt` and `.md` file uploads
- Classify page types: `service`, `location`, `guide`
- Generate draft pages using indexed layouts
- Re-index pages automatically on publish

**Defer to later releases:**

- `.docx` and `.pdf` upload support
- Vector similarity search for section matching
- Advanced section-level reuse across dissimilar layouts
- AI rule editor in admin settings
- Background job processing via WP-Cron or Action Scheduler

---

## Dependencies

| Dependency | Purpose | Required for |
|---|---|---|
| WordPress `parse_blocks()` | Block parsing and validation | Core — always required |
| `wp_insert_post()` | Draft page creation | Core — always required |
| `dbDelta()` | Custom table creation on activation | Core — always required |
| AI API (configurable) | Page planning and classification | Core — key stored in `wp_options` |
| PHPWord / ZipArchive | `.docx` text extraction | Phase 2 — DOCX support |
| PDF parser library | `.pdf` text extraction | Phase 2 — PDF support |
| Action Scheduler | Background job processing for large batches | Optional — production scale |

---

## Extending GutenBot

### Adding a new file format

1. Add the MIME type and extension to the upload allowlist in `GutenBot_Admin`
2. Add a parser method to `GutenBot_Document_Processor`
3. Register the extension in the `switch` block that selects the parser
4. Update the accepted formats list in the upload page UI

### Adding a new page type classifier

1. Add a keyword rule array to `GutenBot_Indexer::classify_page_type()`
2. Add a corresponding section template to `GutenBot_Page_Generator`

### Adding a new admin rule type

1. Add the rule key to the settings page form
2. Include it in the `admin_rules` array passed to `GutenBot_AI_Client`
3. Document the rule key in the settings page description
