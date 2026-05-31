# GutenBot

AI-powered Gutenberg sidebar plugin that uses LLM-generated block layouts.

## Commands

### PHP
- **Test:** `vendor/bin/phpunit`
- **Lint a file:** `php -l <file>`
- **Autoload:** `composer dump-autoload`

### JavaScript (Gutenberg blocks)
- **Build:** `npm run build`
- **Dev watch:** `npm run start`

## Architecture

- `gutenbot.php` — plugin entry point, registers activation hook and boots Hooks class
- `includes/` — PHP classes, autoloaded via Composer classmap
  - `class-activator.php` — plugin activation (DB setup)
  - `class-hooks.php` — WordPress action/filter registration
  - `class-rest.php` — WP REST API endpoints (`/wp-json/gutenbot/v1/`)
  - `class-ai-client.php` — LLM/AI API client (Claude, Ollama)
  - `class-indexer.php` — Supabase vector indexing queue processor
  - `class-document-processor.php` — block documentation chunking and embedding prep
  - `class-pattern-builder.php` — Gutenberg block pattern assembly from AI output
- `assets/src/index.js` — Gutenberg sidebar entry point (Phase 6, stub)
- `admin/settings-page.php` — plugin settings UI
- `edge-function/index.ts` — Supabase edge function (deployed separately, not part of this plugin's build)
- `tests/` — PHPUnit unit tests

## Scope

MVP targets WordPress core Gutenberg blocks and theme custom blocks only. Third-party block libraries (Kadence, GenerateBlocks, etc.) are out of scope for v1.

## Notes

- Settings stored in `wp_options` (no service-role credentials — those belong in the Supabase edge function environment)
- Edge function lives in a separate deployment target (`~/dev/gutenbot-edge/`)
- PHP 8.1+ required
