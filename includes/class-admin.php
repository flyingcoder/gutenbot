<?php
defined('ABSPATH') || exit;

class GutenBot_Admin {

    public static function register_menus() {
        add_menu_page(
            __('GutenBot', 'gutenbot'),
            __('GutenBot', 'gutenbot'),
            'manage_options',
            'gutenbot',
            [__CLASS__, 'render_upload_page'],
            'dashicons-robot',
            80
        );

        add_submenu_page(
            'gutenbot',
            __('Upload Documents', 'gutenbot'),
            __('Upload', 'gutenbot'),
            'manage_options',
            'gutenbot',
            [__CLASS__, 'render_upload_page']
        );

        add_submenu_page(
            'gutenbot',
            __('Review Queue', 'gutenbot'),
            __('Review Queue', 'gutenbot'),
            'manage_options',
            'gutenbot-queue',
            [__CLASS__, 'render_queue_page']
        );

        add_submenu_page(
            'gutenbot',
            __('Index Status', 'gutenbot'),
            __('Index Status', 'gutenbot'),
            'manage_options',
            'gutenbot-index',
            [__CLASS__, 'render_index_page']
        );

        add_submenu_page(
            'gutenbot',
            __('Settings', 'gutenbot'),
            __('Settings', 'gutenbot'),
            'manage_options',
            'gutenbot-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function enqueue_editor_assets() {
        wp_enqueue_script(
            'gutenbot-editor',
            GUTENBOT_PLUGIN_URL . 'assets/editor.js',
            ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components',
             'wp-data', 'wp-api-fetch', 'wp-blocks', 'wp-i18n'],
            GUTENBOT_VERSION,
            true
        );
        wp_localize_script('gutenbot-editor', 'gutenbotEditor', [
            'apiPath'     => '/gutenbot/v1/generate',
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'streamNonce' => wp_create_nonce('gutenbot_stream'),
        ]);
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'gutenbot') === false) {
            return;
        }
        wp_enqueue_style(
            'gutenbot-admin',
            GUTENBOT_PLUGIN_URL . 'assets/admin.css',
            [],
            GUTENBOT_VERSION
        );
        wp_enqueue_script(
            'gutenbot-admin',
            GUTENBOT_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            GUTENBOT_VERSION,
            true
        );
    }

    public static function render_upload_page() {
        include GUTENBOT_PLUGIN_DIR . 'admin/upload-page.php';
    }

    public static function render_queue_page() {
        include GUTENBOT_PLUGIN_DIR . 'admin/queue-page.php';
    }

    public static function render_index_page() {
        include GUTENBOT_PLUGIN_DIR . 'admin/index-status-page.php';
    }

    public static function render_settings_page() {
        include GUTENBOT_PLUGIN_DIR . 'admin/settings-page.php';
    }

    public static function handle_upload() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'gutenbot'));
        }

        check_admin_referer('gutenbot_upload', 'gutenbot_nonce');

        if (empty($_FILES['gutenbot_files'])) {
            wp_redirect(add_query_arg('gutenbot_notice', 'no_files', admin_url('admin.php?page=gutenbot')));
            exit;
        }

        $allowed_types = ['txt', 'md'];
        $size_limit    = (int) get_option('gutenbot_file_size_limit', 10485760);
        $upload_dir    = trailingslashit(wp_upload_dir()['basedir']) . 'gutenbot/';
        $files         = self::normalize_files_array($_FILES['gutenbot_files']);
        $job_count     = 0;

        foreach ($files as $file) {
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = mime_content_type($file['tmp_name']);

            if (!in_array($ext, $allowed_types, true)) {
                continue;
            }

            if ($file['size'] > $size_limit) {
                continue;
            }

            $safe_name  = sanitize_file_name($file['name']);
            $dest       = $upload_dir . wp_unique_filename($upload_dir, $safe_name);

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                continue;
            }

            global $wpdb;
            $wpdb->insert(
                "{$wpdb->prefix}gutenbot_generation_jobs",
                [
                    'file_name'  => $safe_name,
                    'file_path'  => $dest,
                    'file_type'  => $ext,
                    'status'     => 'uploaded',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]
            );
            $job_count++;
        }

        wp_redirect(add_query_arg([
            'page'            => 'gutenbot',
            'gutenbot_notice' => 'uploaded',
            'count'           => $job_count,
        ], admin_url('admin.php')));
        exit;
    }

    private static function normalize_files_array(array $files_input) {
        $normalized = [];
        if (is_array($files_input['name'])) {
            foreach (array_keys($files_input['name']) as $i) {
                $normalized[] = [
                    'name'     => $files_input['name'][$i],
                    'tmp_name' => $files_input['tmp_name'][$i],
                    'size'     => $files_input['size'][$i],
                    'error'    => $files_input['error'][$i],
                ];
            }
        } else {
            $normalized[] = $files_input;
        }
        return $normalized;
    }

    public static function handle_reindex() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'gutenbot'));
        }
        check_admin_referer('gutenbot_reindex', 'gutenbot_nonce');
        $total = GutenBot_Indexer::enqueue_full_index();
        wp_redirect(add_query_arg(
            ['gutenbot_notice' => 'queued', 'total' => $total],
            admin_url('admin.php?page=gutenbot-index')
        ));
        exit;
    }

    public static function handle_reset_index() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'gutenbot'));
        }
        check_admin_referer('gutenbot_reset_index', 'gutenbot_nonce');

        global $wpdb;
        $table = "{$wpdb->prefix}gutenbot_index_queue";
        $wpdb->update($table, ['status' => 'failed'], ['status' => 'processing']);
        update_option('gutenbot_index_run_id', '');

        wp_redirect(admin_url('admin.php?page=gutenbot-index'));
        exit;
    }

    public static function handle_index_progress() {
        check_ajax_referer('gutenbot_index_progress', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }

        $run_id = get_option('gutenbot_index_run_id', '');

        // Drive the queue forward on each poll. WP-Cron's loopback HTTP request
        // fails in Docker when siteurl uses the host-mapped port (e.g. :8007)
        // which is unreachable from inside the container. Polling drives processing
        // without relying on the loopback mechanism.
        if ($run_id !== '') {
            GutenBot_Index_Queue_Processor::process();
            $run_id = get_option('gutenbot_index_run_id', '');
        }

        global $wpdb;
        $table  = "{$wpdb->prefix}gutenbot_index_queue";
        $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $done   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('done','failed')");
        $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'");

        wp_send_json_success([
            'total'   => $total,
            'done'    => $done,
            'failed'  => $failed,
            'running' => $run_id !== '',
            'pct'     => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ]);
    }

    public static function handle_add_rule() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'gutenbot'));
        }
        check_admin_referer('gutenbot_add_rule', 'gutenbot_nonce');

        $key   = sanitize_text_field($_POST['rule_key'] ?? '');
        $value = sanitize_textarea_field($_POST['rule_value'] ?? '');

        if ($key !== '' && $value !== '') {
            global $wpdb;
            $wpdb->replace(
                "{$wpdb->prefix}gutenbot_rules",
                [
                    'rule_key'   => $key,
                    'rule_value' => $value,
                    'created_at' => current_time('mysql'),
                ]
            );
        }

        wp_redirect(add_query_arg(
            'gutenbot_notice', 'rule_saved',
            admin_url('admin.php?page=gutenbot-settings')
        ));
        exit;
    }

    public static function handle_delete_rule() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'gutenbot'));
        }
        check_admin_referer('gutenbot_delete_rule', 'gutenbot_nonce');

        $rule_id = (int) ($_POST['rule_id'] ?? 0);
        if ($rule_id > 0) {
            global $wpdb;
            $wpdb->delete("{$wpdb->prefix}gutenbot_rules", ['id' => $rule_id], ['%d']);
        }

        wp_redirect(add_query_arg(
            'gutenbot_notice', 'rule_deleted',
            admin_url('admin.php?page=gutenbot-settings')
        ));
        exit;
    }

    public static function handle_process_job() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'gutenbot'));
        }
        check_admin_referer('gutenbot_process_job', 'gutenbot_nonce');

        $job_id = (int) ($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_die(__('Invalid job ID.', 'gutenbot'));
        }

        global $wpdb;
        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}gutenbot_generation_jobs WHERE id = %d",
                $job_id
            ),
            ARRAY_A
        );

        if (!$job) {
            wp_die(__('Job not found.', 'gutenbot'));
        }

        $wpdb->update(
            "{$wpdb->prefix}gutenbot_generation_jobs",
            ['status' => 'processing', 'updated_at' => current_time('mysql')],
            ['id' => $job_id]
        );

        try {
            $raw_content = file_get_contents($job['file_path']);
            $text        = GutenBot_Document_Processor::parse($raw_content, $job['file_type']);

            $similar  = GutenBot_Indexer::get_similar_layouts('general');
            $rules    = $wpdb->get_col("SELECT rule_value FROM {$wpdb->prefix}gutenbot_rules");
            $ai       = new GutenBot_AI_Client();
            $plan     = $ai->get_page_plan($text, $similar, [], $rules);

            if (!$plan) {
                $reason = $ai->get_last_error() ?: 'AI did not return a valid page plan.';
                throw new RuntimeException($reason);
            }

            $sections = [];
            foreach ($plan['sections'] as $st) {
                $rows = GutenBot_Indexer::get_sections_by_type($st, 1);
                if ($rows) {
                    $sections[] = $rows[0];
                }
            }

            $markup = GutenBot_Page_Generator::build($plan, $sections);
            GutenBot_Page_Generator::create_draft($plan, $markup, $job_id, $job['file_name']);

        } catch (Exception $e) {
            $wpdb->update(
                "{$wpdb->prefix}gutenbot_generation_jobs",
                [
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                    'updated_at'    => current_time('mysql'),
                ],
                ['id' => $job_id]
            );
        }

        wp_redirect(admin_url('admin.php?page=gutenbot-queue'));
        exit;
    }
}
