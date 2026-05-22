<?php
defined('ABSPATH') || exit;

class GutenBot_Index_Queue_Processor {

    const BATCH_SIZE = 5;

    public static function process(): void {
        global $wpdb;

        $table = "{$wpdb->prefix}gutenbot_index_queue";

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id FROM {$table} WHERE status = 'pending' LIMIT %d",
                self::BATCH_SIZE
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            update_option('gutenbot_index_run_id', '');
            return;
        }

        $ai_enabled = get_option('gutenbot_ai_indexing_enabled', '0') === '1';
        $ai_client  = $ai_enabled ? new GutenBot_AI_Client() : null;

        foreach ($rows as $row) {
            $wpdb->update($table, ['status' => 'processing'], ['id' => $row['id']]);

            try {
                if ((int) $row['post_id'] === 0) {
                    GutenBot_Indexer::scan_theme_styles();
                } else {
                    $post = get_post((int) $row['post_id']);
                    if ($post) {
                        GutenBot_Indexer::reindex_post($post, $ai_client);
                    }
                }
                $wpdb->update(
                    $table,
                    ['status' => 'done', 'processed_at' => current_time('mysql')],
                    ['id' => $row['id']]
                );
            } catch (Exception $e) {
                $wpdb->update(
                    $table,
                    ['status' => 'failed', 'error_msg' => $e->getMessage()],
                    ['id' => $row['id']]
                );
                error_log('GutenBot index queue error post_id=' . $row['post_id'] . ': ' . $e->getMessage());
            }
        }

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
        if ($remaining > 0) {
            wp_schedule_single_event(time() + 2, 'gutenbot_process_index_queue');
        } else {
            update_option('gutenbot_index_run_id', '');
        }
    }
}
