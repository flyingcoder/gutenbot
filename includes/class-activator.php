<?php
defined('ABSPATH') || exit;

class GutenBot_Activator {

    public static function activate() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $tables = [
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gutenbot_layout_index (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL,
                page_type VARCHAR(64) NOT NULL DEFAULT '',
                template_slug VARCHAR(128) NOT NULL DEFAULT '',
                block_structure LONGTEXT NOT NULL,
                section_order TEXT NOT NULL,
                indexed_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY post_id (post_id)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gutenbot_section_index (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                layout_id BIGINT UNSIGNED NOT NULL,
                section_type VARCHAR(64) NOT NULL DEFAULT '',
                block_markup LONGTEXT NOT NULL,
                css_classes TEXT NOT NULL DEFAULT '',
                source_post_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY layout_id (layout_id),
                KEY section_type (section_type)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gutenbot_style_index (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                style_key VARCHAR(128) NOT NULL DEFAULT '',
                style_value LONGTEXT NOT NULL,
                source VARCHAR(64) NOT NULL DEFAULT '',
                indexed_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY style_key (style_key)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gutenbot_generation_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                file_name VARCHAR(255) NOT NULL DEFAULT '',
                file_path VARCHAR(512) NOT NULL DEFAULT '',
                file_type VARCHAR(16) NOT NULL DEFAULT '',
                status VARCHAR(32) NOT NULL DEFAULT 'uploaded',
                detected_page_type VARCHAR(64) NOT NULL DEFAULT '',
                draft_post_id BIGINT UNSIGNED DEFAULT NULL,
                layout_source_id BIGINT UNSIGNED DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) $charset;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gutenbot_rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                rule_key VARCHAR(128) NOT NULL DEFAULT '',
                rule_value TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY rule_key (rule_key)
            ) $charset;",
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        $upload = wp_upload_dir();
        $gutenbot_dir = trailingslashit($upload['basedir']) . 'gutenbot';
        if (!file_exists($gutenbot_dir)) {
            wp_mkdir_p($gutenbot_dir);
            file_put_contents($gutenbot_dir . '/.htaccess', "deny from all\n");
        }

        if (!get_option('gutenbot_file_size_limit')) {
            update_option('gutenbot_file_size_limit', 10485760);
        }
        if (get_option('gutenbot_ai_api_key') === false) {
            update_option('gutenbot_ai_api_key', '');
        }
        if (!get_option('gutenbot_ai_api_endpoint')) {
            update_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
        }
        if (!get_option('gutenbot_ai_model')) {
            update_option('gutenbot_ai_model', 'claude-sonnet-4-6');
        }
        if (!get_option('gutenbot_ai_provider')) {
            update_option('gutenbot_ai_provider', 'anthropic');
        }
        if (!get_option('gutenbot_ollama_endpoint')) {
            update_option('gutenbot_ollama_endpoint', 'http://ollama:11434/api/chat');
        }
        if (!get_option('gutenbot_ollama_model')) {
            update_option('gutenbot_ollama_model', 'llama3.2');
        }

        update_option('gutenbot_db_version', GUTENBOT_VERSION);
    }

    public static function deactivate() {
        // Data preserved on deactivation — tables cleaned only on uninstall.
    }
}
