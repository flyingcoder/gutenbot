<?php
defined('ABSPATH') || exit;

class GutenBot_AI_Client {

    private $http_client;

    public function __construct($http_client = null) {
        $this->http_client = $http_client;
    }

    public function get_page_plan(
        string $document_content,
        array $similar_layouts,
        array $reusable_sections,
        array $admin_rules
    ) {
        $style_summary = GutenBot_Indexer::get_style_summary();

        $payload = [
            'document_content'   => $document_content,
            'similar_layouts'    => $similar_layouts,
            'reusable_sections'  => $reusable_sections,
            'theme_style_summary' => $style_summary,
            'admin_rules'        => $admin_rules,
        ];

        $response = $this->call_api($payload);

        if ($response === null) {
            // Retry once.
            $response = $this->call_api($payload);
        }

        return $response;
    }

    private function call_api(array $payload) {
        $api_key  = get_option('gutenbot_ai_api_key', '');
        $endpoint = get_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
        $model    = get_option('gutenbot_ai_model', 'claude-sonnet-4-6');

        $prompt = $this->build_prompt($payload);

        $request_body = [
            'model'      => $model,
            'max_tokens' => 1024,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($this->http_client !== null) {
            $raw = $this->http_client->post($endpoint, $request_body);
        } else {
            $args = [
                'method'  => 'POST',
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'body'    => wp_json_encode($request_body),
                'timeout' => 60,
            ];

            $response = wp_remote_post($endpoint, $args);
            if (is_wp_error($response)) {
                error_log('GutenBot AI request error: ' . $response->get_error_message());
                return null;
            }
            $raw = wp_remote_retrieve_body($response);
        }

        return $this->parse_response($raw);
    }

    private function build_prompt(array $payload) {
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT);
        return <<<PROMPT
You are a WordPress page planner. Given the following document content and site context, produce a JSON page plan.

Context:
{$json}

Respond ONLY with valid JSON matching this schema:
{
  "page_type": "service|location|guide|general",
  "title": "string",
  "sections": ["hero","intro","benefits","process","faq","cta"],
  "layout_source": null
}

Rules:
- "page_type" must be one of: service, location, guide, general
- "sections" must be an ordered array of section names
- "layout_source" is an integer ID from similar_layouts or null
- Do NOT include any text outside the JSON object
PROMPT;
    }

    private function parse_response(string $raw) {
        $data = json_decode($raw, true);

        // Handle Anthropic Messages API envelope.
        if (isset($data['content'][0]['text'])) {
            $raw = $data['content'][0]['text'];
            $data = json_decode($raw, true);
        }

        if (!is_array($data)) {
            error_log('GutenBot: malformed AI response — ' . substr($raw, 0, 500));
            return null;
        }

        if (!isset($data['page_type'], $data['title'], $data['sections'])) {
            error_log('GutenBot: AI response missing required keys — ' . wp_json_encode($data));
            return null;
        }

        if (!is_array($data['sections']) || empty($data['sections'])) {
            error_log('GutenBot: AI response has empty sections — ' . wp_json_encode($data));
            return null;
        }

        return [
            'page_type'     => sanitize_text_field($data['page_type']),
            'title'         => sanitize_text_field($data['title']),
            'sections'      => array_map('sanitize_text_field', $data['sections']),
            'layout_source' => isset($data['layout_source']) ? (int) $data['layout_source'] : null,
        ];
    }
}
