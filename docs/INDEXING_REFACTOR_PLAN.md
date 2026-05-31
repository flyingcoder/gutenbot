# Indexing Refactor Plan

## Goals

1. **Persist scan data in WordPress** so scan progress survives and only changed/new content needs reprocessing — not a full rescan every time.
2. **AJAX-based Supabase sync** — replace the current full-page form POST + redirect flow with a jQuery AJAX call so the sync button responds without a page reload.

---

## Change 1 — Per-page scanning with save points

### Why scanning in one go is fragile

The original plan looped over all posts in a single PHP execution. If that execution timed out or crashed at post 15 of 50, nothing was saved for any post. The user would have to start over from scratch.

Per-page scanning fixes this by making each page its own atomic unit:
- Parse one page → write its row to the DB table (save point) → move to the next.
- If interrupted after page 15, pages 1–14 are already persisted.
- On the next scan trigger, the queue rebuilds from only the remaining unscanned or changed pages.

### New table: `{prefix}_gutenbot_indexed_posts`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `post_id` | BIGINT UNSIGNED NOT NULL | WP post ID, unique index |
| `post_modified` | DATETIME NOT NULL | Copy of `post_modified_gmt` at scan time |
| `page_type` | VARCHAR(32) | `landing`, `blog`, `service`, `guide`, `location` |
| `section_order` | TEXT | JSON array of section type strings |
| `sections` | LONGTEXT | JSON — full section tree |
| `block_names` | TEXT | JSON array of unique block names found |
| `scanned_at` | DATETIME NOT NULL | UTC timestamp when this row was last written |

The `wp_options` keys (`aipb_block_registry`, `aipb_design_tokens`, `aipb_patterns`) remain as the **aggregated summary** rebuilt only after the full queue is exhausted.

### Scan queue

A scan queue is stored in `wp_options` under `aipb_scan_queue` as a JSON array of post IDs that still need to be processed in the current run. Two companion keys track progress:

| Key | Value |
|---|---|
| `aipb_scan_queue` | JSON array of remaining post IDs |
| `aipb_scan_total` | Total posts to scan in this run (fixed at init time, used for progress calculation) |

### Three-phase scanning

Each scan run is split into three discrete phases, each callable independently:

**Phase 1 — Init (`scan_init`)**
1. Query all published pages/posts: `post_id` + `post_modified_gmt`.
2. Compare each against `scanned_at` in the DB table.
3. Build a queue of only post IDs that are new or modified (`post_modified_gmt > scanned_at`).
4. Delete rows for post IDs that no longer exist (unpublished / deleted).
5. Save queue to `aipb_scan_queue`, total count to `aipb_scan_total`.
6. Set `aipb_scan_status = scanning`.
7. Returns `{ total: N, remaining: N }`.

If the queue is empty at init time (nothing has changed since the last scan), skip directly to Phase 3.

**Phase 2 — Scan one page (`scan_page`)**
Accepts a single `post_id`. Called once per page:
1. Fetch full post object.
2. Detect `page_type`, call `parse_blocks()`, split into sections, walk innerBlocks.
3. UPSERT one row into `gutenbot_indexed_posts` — **this is the save point**.
4. Remove the post ID from `aipb_scan_queue`.
5. Returns `{ post_id, post_title, remaining: N, total: N }`.

**Phase 3 — Finalize (`scan_finalize`)**
Called once after the queue is empty:
1. SELECT all rows from `gutenbot_indexed_posts` to rebuild aggregated data.
2. Filter block registry to occurrence ≥ 2.
3. Call `DocumentProcessor::extract_design_tokens()`.
4. Call `PatternBuilder::build()` and `build_indexed_pages()`.
5. Write `aipb_block_registry`, `aipb_design_tokens`, `aipb_patterns` to `wp_options`.
6. Set `aipb_scan_status = complete`, `aipb_scanned_at = now`.
7. Delete `aipb_scan_queue` and `aipb_scan_total`.

### How the phases are driven

**Manual scan (settings page — AJAX)**

The Scan Site button drives the three phases via sequential jQuery AJAX calls:

```
[Scan Site click]
  → AJAX: gutenbot_scan_init        → returns { total, remaining }
  → JS loop while remaining > 0:
      AJAX: gutenbot_scan_page      → processes one post, returns { remaining, total, post_title }
      JS updates progress bar: "Scanning X of Y — <post_title>"
  → AJAX: gutenbot_scan_finalize    → rebuilds aggregates, returns { status, scanned_at }
  → JS updates status badge inline
```

Each `gutenbot_scan_page` AJAX call processes exactly one post — one HTTP request, one DB write, one save point. If the browser is closed mid-scan, pages already processed are preserved in the table. The next "Scan Site" click will call `scan_init` again, which rebuilds the queue from only the remaining unscanned posts.

**Activation cron (background, no browser)**

On activation, WP-Cron fires `aipb_site_scan`. Since there is no browser to drive an AJAX loop, the cron handler uses a self-chaining single-event pattern:

```
aipb_site_scan fires
  → if queue empty: call scan_init, then schedule aipb_scan_next +1s
  → aipb_scan_next fires: pop one post_id from queue, call scan_page, reschedule aipb_scan_next +1s
  → repeat until queue empty
  → final aipb_scan_next: call scan_finalize, do not reschedule
```

Each `aipb_scan_next` event is a fresh PHP process, so no single execution ever loops over all posts. The save point after each post means a server restart between events loses only the one in-flight post.

### Resume behaviour

Because the queue is persisted and each save point is a DB UPSERT, interrupted scans are automatically resumable:

| Interruption scenario | What is lost | What happens next |
|---|---|---|
| Browser closed during AJAX scan | Nothing — all completed pages are in DB | Next "Scan Site" click re-inits from remaining posts |
| PHP timeout during cron scan | At most one in-flight page | Next `aipb_scan_next` event continues from queue |
| Server restart mid-scan | At most one in-flight page | Cron re-fires (or admin clicks Scan Site), queue resumes |
| Full queue completed, finalize crashes | Aggregates not rebuilt | Scan status stays `scanning`; clicking Scan Site re-runs finalize |

### JS implementation sketch (manual scan)

```js
jQuery(function ($) {
    $('#gutenbot-scan-btn').on('click', async function (e) {
        e.preventDefault();
        const $btn   = $(this).prop('disabled', true).text('Scanning…');
        const $bar   = $('#gutenbot-scan-progress');
        const $label = $('#gutenbot-scan-label');
        const nonce  = gutenbotData.scanNonce;

        const init = await $.post(ajaxurl, { action: 'gutenbot_scan_init', _wpnonce: nonce });
        if (!init.success) { showError(init.data.message); return; }

        let { total, remaining } = init.data;
        $bar.attr('max', total).attr('value', total - remaining);

        while (remaining > 0) {
            const step = await $.post(ajaxurl, { action: 'gutenbot_scan_page', _wpnonce: nonce });
            if (!step.success) { showError(step.data.message); return; }
            remaining = step.data.remaining;
            $bar.attr('value', total - remaining);
            $label.text('Scanning ' + (total - remaining) + ' of ' + total + ' — ' + step.data.post_title);
        }

        const fin = await $.post(ajaxurl, { action: 'gutenbot_scan_finalize', _wpnonce: nonce });
        if (fin.success) {
            $('#gutenbot-scan-status').text('Complete').removeClass('notice-warning notice-error').addClass('notice-success');
            $('#gutenbot-scanned-at').text(fin.data.scanned_at);
        } else {
            showError(fin.data.message);
        }

        $btn.prop('disabled', false).text('Scan Site');
    });
});
```

### PHP handlers (new AJAX actions)

```php
// In Hooks::register():
add_action( 'wp_ajax_gutenbot_scan_init',     [ $this, 'ajax_scan_init' ] );
add_action( 'wp_ajax_gutenbot_scan_page',     [ $this, 'ajax_scan_page' ] );
add_action( 'wp_ajax_gutenbot_scan_finalize', [ $this, 'ajax_scan_finalize' ] );
add_action( 'aipb_scan_next',                 [ $this, 'run_scan_next' ] );  // cron chain
```

### Files to change

| File | Change |
|---|---|
| `includes/class-activator.php` | `dbDelta()` creates table; schedule `aipb_site_scan` cron |
| `includes/class-indexer.php` | Replace `scan_incremental()` with `scan_init()`, `scan_page(int $post_id)`, `scan_finalize()` |
| `includes/class-hooks.php` | Register 3 AJAX actions + `aipb_scan_next` cron; update `run_site_scan()` to use init + chain |
| `includes/class-admin.php` | `wp_localize_script()` to pass `gutenbotData.scanNonce` to JS |
| `admin/settings-page.php` | Add `<progress>` element and label; change Scan Site button to `type="button"` |
| `assets/src/admin.js` | AJAX scan loop above (replaces or extends sync JS) |

---

## Change 2 — jQuery AJAX sync instead of form POST + redirect

### Why the current approach is suboptimal

The current flow: form POST → `admin-post.php` → PHP handler → `wp_safe_redirect()` → page reload with `?gutenbot_synced=success`. This:
- Gives no feedback during the sync (user sees a blank page / spinner for the full round-trip).
- Cannot stream progress or show partial results.
- Requires the PHP process to stay alive for the full edge function call (timeout risk).

### New flow

1. User clicks **"Sync to Supabase"**.
2. jQuery sends `wp_ajax_gutenbot_sync_supabase` via `$.post()` to `admin-ajax.php`.
3. PHP handler validates nonce + capability, triggers the sync, returns a JSON response.
4. JS updates the status badge and last-synced timestamp inline — no page reload.

### JS implementation sketch

```js
jQuery(function ($) {
    $('#gutenbot-sync-btn').on('click', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Syncing…');
        const $status = $('#gutenbot-sync-status');

        $.post(ajaxurl, {
            action:   'gutenbot_sync_supabase',
            _wpnonce: gutenbotData.syncNonce,
        })
        .done(function (res) {
            if (res.success) {
                $status.text('Complete').removeClass('notice-warning notice-error').addClass('notice-success');
                $('#gutenbot-synced-at').text(res.data.synced_at);
            } else {
                $status.text('Error: ' + res.data.message).removeClass('notice-success notice-warning').addClass('notice-error');
            }
        })
        .fail(function () {
            $status.text('Request failed').addClass('notice-error');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Sync to Supabase');
        });
    });
});
```

### PHP AJAX handler

```php
// Registered in Hooks::register()
add_action( 'wp_ajax_gutenbot_sync_supabase', [ $this, 'ajax_sync_supabase' ] );

public function ajax_sync_supabase(): void {
    check_ajax_referer( 'gutenbot_sync_supabase' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
    }

    $this->run_supabase_sync();

    $status = get_option( 'aipb_sync_status', 'error' );

    if ( $status === 'complete' ) {
        wp_send_json_success( [
            'synced_at' => get_option( 'aipb_synced_at', '' ),
        ] );
    } else {
        wp_send_json_error( [
            'message' => get_option( 'aipb_sync_error', 'Unknown error.' ),
        ] );
    }
}
```

### Files to change

| File | Change |
|---|---|
| `includes/class-hooks.php` | Register `wp_ajax_gutenbot_sync_supabase`; add `ajax_sync_supabase()` method; keep `handle_sync_supabase` for graceful degradation (no-JS fallback) |
| `includes/class-admin.php` | `wp_localize_script()` to pass `gutenbotData.syncNonce` to JS |
| `admin/settings-page.php` | Add `id` attributes to status badge and synced-at elements; change sync button to type `button` (not submit) |
| `assets/src/index.js` or a new `assets/src/admin.js` | jQuery AJAX handler above |

---

## Implementation order

1. **DB table** — `class-activator.php`: `dbDelta()` creates `gutenbot_indexed_posts`; add `aipb_scan_queue` and `aipb_scan_total` to the known options list.
2. **Three-phase Indexer** — `class-indexer.php`: implement `scan_init()`, `scan_page(int $post_id)`, `scan_finalize()`; remove the old `scan()` and `scan_incremental()` methods.
3. **Cron chain wiring** — `class-hooks.php`: register `aipb_scan_next` action; update `run_site_scan()` to call `scan_init()` then schedule `aipb_scan_next`; add `run_scan_next()` to pop one post and chain the next event or call finalize.
4. **AJAX scan handlers** — `class-hooks.php`: register `gutenbot_scan_init`, `gutenbot_scan_page`, `gutenbot_scan_finalize` AJAX actions; add corresponding handler methods.
5. **JS localisation** — `class-admin.php`: add `scanNonce` and `ajaxurl` to `wp_localize_script()` call.
6. **Settings page DOM** — `settings-page.php`: change Scan Site button to `type="button"`; add `<progress>` element, scan label, and `id` attributes on status badge and scanned-at timestamp.
7. **AJAX sync handler** — `class-hooks.php`: register `gutenbot_sync_supabase` AJAX action (Change 2).
8. **Admin JS** — `assets/src/admin.js`: implement scan loop and sync button handler.
9. **Build** — `npm run build`.
10. **Manual tests**:
    - Fresh install → table created → Scan Site → progress bar advances one page at a time → status = complete.
    - Close browser mid-scan → reopen → Scan Site again → only remaining pages processed.
    - Edit one page → Scan Site → only that page re-parsed; others skipped.
    - Sync to Supabase → badge updates inline, no page reload.
