<?php
defined('ABSPATH') || exit;

class GutenBot_Stream_Controller {

    public static function handle(): void {
        check_ajax_referer('gutenbot_stream', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }

        $content = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));
        if (trim($content) === '') {
            self::sse_headers();
            self::send_event('error', ['message' => 'Content cannot be empty.']);
            wp_die();
        }

        self::sse_headers();

        try {
            global $wpdb;

            self::send_event('stage', ['stage' => 'parsing', 'message' => 'Parsing content…']);
            $text = GutenBot_Document_Processor::parse_txt($content);

            self::send_event('stage', ['stage' => 'layouts', 'message' => 'Fetching similar layouts…']);
            $layouts = GutenBot_Indexer::get_similar_layouts('general');
            $rules   = $wpdb->get_col("SELECT rule_value FROM {$wpdb->prefix}gutenbot_rules");

            self::send_event('stage', ['stage' => 'ai', 'message' => 'Asking AI to plan the page…']);

            $plan = null;
            $ai   = new GutenBot_AI_Client();
            $ai->stream_page_plan($text, $layouts, [], $rules, static function (string $type, array $data) use (&$plan) {
                if ($type === 'chunk') {
                    GutenBot_Stream_Controller::send_event('chunk', $data);
                } elseif ($type === 'done') {
                    $plan = $data['plan'];
                }
            });

            if (!$plan) {
                $reason = $ai->get_last_error() ?: 'AI did not return a valid page plan.';
                self::send_event('error', ['message' => $reason]);
                wp_die();
            }

            self::send_event('stage', ['stage' => 'building', 'message' => 'Building blocks…']);

            $sections = [];
            foreach ($plan['sections'] as $section_type) {
                $rows = GutenBot_Indexer::get_sections_by_type($section_type, 1);
                if ($rows) {
                    $sections[] = $rows[0];
                }
            }

            $markup = GutenBot_Page_Generator::build($plan, $sections, true);

            self::send_event('done', [
                'plan'   => $plan,
                'markup' => $markup,
            ]);

        } catch (Exception $e) {
            self::send_event('error', ['message' => $e->getMessage()]);
        }

        wp_die();
    }

    public static function send_event(string $event, array $data): void {
        echo "event: {$event}\n";
        echo 'data: ' . wp_json_encode($data) . "\n\n";
        flush();
    }

    private static function sse_headers(): void {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-store, no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', true);
        set_time_limit(0);
    }
}
