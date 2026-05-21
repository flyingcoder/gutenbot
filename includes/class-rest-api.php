<?php
defined('ABSPATH') || exit;

class GutenBot_REST_API {

    public static function register_routes() {
        register_rest_route('gutenbot/v1', '/generate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'generate'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'content' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route('gutenbot/v1', '/test-connection', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'test_connection'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'args'                => [
                'provider' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => ['anthropic', 'ollama'],
                ],
            ],
        ]);
    }

    public static function check_permission() {
        return current_user_can('edit_posts');
    }

    public static function generate(WP_REST_Request $request) {
        $content = (string) $request->get_param('content');

        if (trim($content) === '') {
            return new WP_Error(
                'empty_content',
                __('Content cannot be empty.', 'gutenbot'),
                ['status' => 400]
            );
        }

        try {
            $result = static::process_content($content);
        } catch (RuntimeException $e) {
            return new WP_Error(
                'generation_failed',
                $e->getMessage(),
                ['status' => 502]
            );
        } catch (Exception $e) {
            return new WP_Error(
                'generation_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        return rest_ensure_response($result);
    }

    public static function test_connection(WP_REST_Request $request) {
        $provider = $request->get_param('provider');
        return $provider === 'ollama' ? static::test_ollama() : static::test_anthropic();
    }

    protected static function test_anthropic() {
        $api_key  = get_option('gutenbot_ai_api_key', '') ?: (string) getenv('ANTHROPIC_API_KEY');
        $endpoint = get_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
        $model    = get_option('gutenbot_ai_model', 'claude-sonnet-4-6');

        if ($api_key === '') {
            return new WP_Error('no_api_key', 'No API key configured. Add your key under GutenBot › Settings.', ['status' => 400]);
        }

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 1,
                'messages'   => [['role' => 'user', 'content' => 'hi']],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', 'Could not reach Anthropic API: ' . $response->get_error_message(), ['status' => 502]);
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status === 401) {
            return new WP_Error('invalid_key', 'API key is invalid or expired (HTTP 401). Update it under GutenBot › Settings.', ['status' => 401]);
        }
        if ($status !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $msg  = $body['error']['message'] ?? "Anthropic returned HTTP {$status}.";
            return new WP_Error('api_error', $msg, ['status' => 502]);
        }

        return rest_ensure_response(['ok' => true, 'message' => "Connected to Anthropic using model \"{$model}\"."]);
    }

    protected static function test_ollama() {
        $endpoint = get_option('gutenbot_ollama_endpoint', 'http://ollama:11434/api/chat');
        $model    = get_option('gutenbot_ollama_model', 'llama3.2');

        $response = wp_remote_post($endpoint, [
            'headers' => ['content-type' => 'application/json'],
            'body'    => wp_json_encode([
                'model'    => $model,
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'stream'   => false,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', "Cannot reach Ollama at {$endpoint}. Make sure `ollama serve` is running.", ['status' => 502]);
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status === 404) {
            return new WP_Error('model_not_found', "Model \"{$model}\" is not pulled. Run `ollama pull {$model}`.", ['status' => 502]);
        }
        if ($status !== 200) {
            return new WP_Error('ollama_error', "Ollama returned HTTP {$status}.", ['status' => 502]);
        }

        $data  = json_decode(wp_remote_retrieve_body($response), true);
        $reply = $data['message']['content'] ?? '';

        if ($reply === '') {
            return new WP_Error('empty_response', "Ollama responded but returned no content. The model may still be loading.", ['status' => 502]);
        }

        return rest_ensure_response(['ok' => true, 'message' => "Ollama model \"{$model}\" is working. Response: \"{$reply}\""]);
    }

    // Protected so tests can subclass and override without touching WP/AI dependencies.
    protected static function process_content(string $content) {
        global $wpdb;

        $text    = GutenBot_Document_Processor::parse_txt($content);
        $layouts = GutenBot_Indexer::get_similar_layouts('general');
        $rules   = $wpdb->get_col("SELECT rule_value FROM {$wpdb->prefix}gutenbot_rules");

        $ai   = new GutenBot_AI_Client();
        $plan = $ai->get_page_plan($text, $layouts, [], $rules);

        if (!$plan) {
            $reason = $ai->get_last_error() ?: __('GutenBot could not generate a page plan. Check your API key and try again.', 'gutenbot');
            throw new RuntimeException($reason);
        }

        $sections = [];
        foreach ($plan['sections'] as $section_type) {
            $rows = GutenBot_Indexer::get_sections_by_type($section_type, 1);
            if ($rows) {
                $sections[] = $rows[0];
            }
        }

        // Skip title uniqueness check — content is going into an existing editor session.
        $markup = GutenBot_Page_Generator::build($plan, $sections, true);

        return [
            'markup'    => $markup,
            'title'     => $plan['title'],
            'page_type' => $plan['page_type'],
        ];
    }
}
