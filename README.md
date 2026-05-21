# GutenBot

AI-powered WordPress plugin that learns your site's existing Gutenberg layouts and generates on-brand draft pages from uploaded documents.

## What it does

GutenBot eliminates the manual work of converting raw content documents (briefs, copy decks, markdown files) into properly structured Gutenberg pages. Upload a document, and GutenBot:

1. Indexes your existing published pages and block patterns
2. Detects the new content's page type (service, location, guide, etc.)
3. Calls an AI API to map the content to a page plan
4. Assembles valid Gutenberg block markup reusing your site's own layouts
5. Creates a WordPress draft page ready for review

## Requirements

- WordPress 6.0+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- An AI API key (configured in plugin settings)

## Installation

1. Copy the `guten-bot` folder into your `wp-content/plugins/` directory
2. Activate the plugin via **Plugins > Installed Plugins**
3. Go to **GutenBot > Settings** and enter your AI API key
4. Run the initial index via **GutenBot > Index Status > Re-index Now**

## Usage

### Generate a draft page

1. Go to **GutenBot > Upload Documents**
2. Upload one or more `.txt` or `.md` files
3. GutenBot processes each file and creates a draft page
4. Review generated drafts via **GutenBot > Review Queue** or **Pages > All Pages**

### Supported file formats

| Format | Notes |
|--------|-------|
| `.txt` | Plain text, whitespace normalized |
| `.md` | Markdown stripped to plain text |
| `.docx` | Planned — future release |
| `.pdf` | Planned — future release |

### Page type detection

GutenBot classifies each document as one of:

- `service` — service or product pages
- `location` — city, region, or branch pages
- `guide` — how-to and informational content

Classification uses the document's headings, keywords, and structure.

## Admin pages

| Page | Purpose |
|------|---------|
| **Upload Documents** | Bulk document upload |
| **Review Queue** | Track job status; link to created drafts |
| **Index Status** | View index health; trigger a manual re-index |
| **Settings** | AI API key, file size limit, custom rules |

## How the index works

On activation (and on every page publish), GutenBot scans your published pages and stores:

- Block name hierarchy and section order
- CSS classes on top-level blocks
- Active page template
- Reusable sections (hero, CTA, FAQ, columns, etc.)
- Theme style tokens from `theme.json`

This index is the source of truth for layout reuse. The AI page planner references it to pick the closest matching layout for each new document.

## Plugin structure

```
guten-bot/
├── gutenbot.php                        # Bootstrap and loader
├── includes/
│   ├── class-activator.php             # DB tables, defaults
│   ├── class-admin.php                 # Admin menu registration
│   ├── class-indexer.php               # Page and block pattern scanner
│   ├── class-document-processor.php    # File parsing
│   ├── class-ai-client.php             # AI API communication
│   ├── class-page-generator.php        # Gutenberg markup assembler
│   └── class-hooks.php                 # WordPress action/filter bindings
├── admin/
│   ├── upload-page.php
│   ├── settings-page.php
│   ├── queue-page.php
│   └── index-status-page.php
├── assets/
│   ├── admin.css
│   └── admin.js
├── tests/
│   ├── unit/
│   └── integration/
└── docs/
    └── technical-documentation.md
```

## Development

### Install dev dependencies

```bash
composer install
```

### Run tests

```bash
# Unit tests only (no WordPress bootstrap needed)
composer test-unit

# Integration tests (requires WP test DB — see below)
composer test-integration

# Full suite with HTML coverage report
vendor/bin/phpunit --coverage-html tests/coverage
```

### Set up the WordPress test database

```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Replace the credentials to match your local MySQL setup.

### Test coverage target

80% line coverage across all `includes/` classes. Coverage report is written to `tests/coverage/` after a full run.

## Database tables

GutenBot creates five tables on activation (prefixed with your WordPress table prefix):

| Table | Purpose |
|-------|---------|
| `gutenbot_layout_index` | Analyzed page layouts with block structure |
| `gutenbot_section_index` | Reusable sections extracted from published pages |
| `gutenbot_style_index` | Theme style tokens from `theme.json` and CSS |
| `gutenbot_generation_jobs` | Upload job lifecycle tracking |
| `gutenbot_rules` | Admin-defined rules passed to the AI planner |

All tables are removed cleanly on plugin uninstall.

## Security

- All admin pages require the `manage_options` capability
- Nonce verification on every form submission
- Uploaded filenames sanitized with `sanitize_file_name()`
- MIME type verified server-side regardless of file extension
- AI responses validated against expected JSON schema before use
- Generated markup validated with `parse_blocks()` before `wp_insert_post()`
- Direct file access blocked in all PHP files

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
