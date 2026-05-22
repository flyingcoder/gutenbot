<?php
defined('ABSPATH') || exit;

class GutenBot_Hooks {

    public static function register() {
        add_action('admin_menu', ['GutenBot_Admin', 'register_menus']);
        add_action('admin_enqueue_scripts', ['GutenBot_Admin', 'enqueue_assets']);

        add_action('admin_post_gutenbot_upload', ['GutenBot_Admin', 'handle_upload']);
        add_action('admin_post_gutenbot_reindex', ['GutenBot_Admin', 'handle_reindex']);
        add_action('admin_post_gutenbot_reset_index', ['GutenBot_Admin', 'handle_reset_index']);
        add_action('admin_post_gutenbot_process_job', ['GutenBot_Admin', 'handle_process_job']);
        add_action('admin_post_gutenbot_add_rule', ['GutenBot_Admin', 'handle_add_rule']);
        add_action('admin_post_gutenbot_delete_rule', ['GutenBot_Admin', 'handle_delete_rule']);

        add_action('rest_api_init',               ['GutenBot_REST_API', 'register_routes']);
        add_action('enqueue_block_editor_assets', ['GutenBot_Admin',    'enqueue_editor_assets']);
        add_action('wp_ajax_gutenbot_stream_generate', ['GutenBot_Stream_Controller', 'handle']);

        add_action('transition_post_status', [__CLASS__, 'reindex_on_publish'], 10, 3);
        add_action('save_post_page', [__CLASS__, 'reindex_on_save'], 10, 2);

        add_action('gutenbot_process_index_queue', ['GutenBot_Index_Queue_Processor', 'process']);
        add_action('wp_ajax_gutenbot_index_progress', ['GutenBot_Admin', 'handle_index_progress']);
    }

    public static function reindex_on_publish($new_status, $old_status, $post) {
        if ($new_status === 'publish' && $post->post_type === 'page') {
            GutenBot_Indexer::reindex_post($post);
        }
    }

    public static function reindex_on_save($post_id, $post) {
        if ($post->post_status === 'publish') {
            GutenBot_Indexer::reindex_post($post);
        }
    }
}
