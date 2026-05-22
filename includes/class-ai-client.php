<?php
defined('ABSPATH') || exit;

class GutenBot_AI_Client {

    private $http_client;
    private string $last_error = '';

    public function __construct($http_client = null) {
        $this->http_client = $http_client;
    }

    public function get_last_error(): string {
        return $this->last_error;
    }

    public function get_page_plan(
        string $document_content,
        array $similar_layouts,
        array $reusable_sections,
        array $admin_rules
    ) {
        $style_summary = GutenBot_Indexer::get_style_summary();

        $payload = [
            'document_content'    => $document_content,
            'similar_layouts'     => $similar_layouts,
            'reusable_sections'   => $reusable_sections,
            'theme_style_summary' => $style_summary,
            'admin_rules'         => $admin_rules,
        ];

        $response = $this->call_api($payload);

        if ($response === null) {
            $response = $this->call_api($payload);
        }

        return $response;
    }

    private function call_api(array $payload) {
        $provider = get_option('gutenbot_ai_provider', 'anthropic');
        $prompt   = $this->build_prompt($payload);

        return $provider === 'ollama'
            ? $this->call_ollama($prompt)
            : $this->call_anthropic($prompt);
    }

    private function call_anthropic(string $prompt) {
        $api_key  = get_option('gutenbot_ai_api_key', '');
        $endpoint = get_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
        $model    = get_option('gutenbot_ai_model', 'claude-sonnet-4-6');

        if ($api_key === '' && getenv('ANTHROPIC_API_KEY') === false) {
            $this->last_error = 'No Anthropic API key configured. Add your key under GutenBot › Settings.';
            return null;
        }

        $body = [
            'model'      => $model,
            'max_tokens' => 1024,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        if ($this->http_client !== null) {
            $raw = $this->http_client->post($endpoint, $body);
        } else {
            $response = wp_remote_post($endpoint, [
                'method'  => 'POST',
                'headers' => [
                    'x-api-key'         => $api_key ?: getenv('ANTHROPIC_API_KEY'),
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 60,
            ]);

            if (is_wp_error($response)) {
                $this->last_error = 'Could not reach Anthropic API: ' . $response->get_error_message();
                error_log('GutenBot Anthropic error: ' . $response->get_error_message());
                return null;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status === 401) {
                $this->last_error = 'Anthropic API key is invalid or expired (HTTP 401). Update it under GutenBot › Settings.';
                return null;
            }
            if ($status !== 200) {
                $body_excerpt = substr(wp_remote_retrieve_body($response), 0, 200);
                $this->last_error = "Anthropic API returned HTTP {$status}: {$body_excerpt}";
                error_log("GutenBot Anthropic HTTP {$status}: " . wp_remote_retrieve_body($response));
                return null;
            }

            $raw = wp_remote_retrieve_body($response);
        }

        return $this->parse_anthropic_response($raw);
    }

    private function call_ollama(string $prompt) {
        $endpoint = get_option('gutenbot_ollama_endpoint', 'http://ollama:11434/api/chat');
        $model    = get_option('gutenbot_ollama_model', 'llama3.2');

        $body = [
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream'   => false,
        ];

        if ($this->http_client !== null) {
            $raw = $this->http_client->post($endpoint, $body);
        } else {
            $response = wp_remote_post($endpoint, [
                'method'  => 'POST',
                'headers' => ['content-type' => 'application/json'],
                'body'    => wp_json_encode($body),
                'timeout' => 120, // Local models are slower than cloud APIs.
            ]);

            if (is_wp_error($response)) {
                $this->last_error = "Cannot reach Ollama at {$endpoint}. Make sure `ollama serve` is running.";
                error_log('GutenBot Ollama error: ' . $response->get_error_message());
                return null;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status === 404) {
                $this->last_error = "Ollama model \"{$model}\" not found. Run `ollama pull {$model}` and try again.";
                return null;
            }
            if ($status !== 200) {
                $body_excerpt = substr(wp_remote_retrieve_body($response), 0, 200);
                $this->last_error = "Ollama returned HTTP {$status}: {$body_excerpt}";
                error_log("GutenBot Ollama HTTP {$status}: " . wp_remote_retrieve_body($response));
                return null;
            }

            $raw = wp_remote_retrieve_body($response);
        }

        return $this->parse_ollama_response($raw);
    }

    // --- Indexing helpers (called once per page / per section during index) ---

    /**
     * Returns ['page_type' => string, 'summary' => string] or null on failure.
     * Uses the configured chat provider (Anthropic or Ollama).
     */
    public function get_page_summary(string $title, string $plain_text): ?array {
        $provider = get_option('gutenbot_ai_provider', 'anthropic');
        $prompt   = $this->build_summary_prompt($title, $plain_text);

        $raw = $provider === 'ollama'
            ? $this->call_ollama_raw($prompt)
            : $this->call_anthropic_raw($prompt);

        if ($raw === null) {
            return null;
        }

        return $this->parse_summary_json($raw);
    }

    /**
     * Returns a float[] embedding vector or null on failure.
     * Uses the configurable embeddings endpoint (provider-agnostic).
     */
    public function get_section_embedding(string $section_text): ?array {
        $provider = get_option('gutenbot_ai_provider', 'anthropic');

        return $provider === 'ollama'
            ? $this->call_ollama_embeddings($section_text)
            : $this->call_external_embeddings($section_text);
    }

    private function call_ollama_embeddings(string $section_text): ?array {
        $chat_endpoint = get_option('gutenbot_ollama_endpoint', 'http://ollama:11434/api/chat');
        $endpoint      = preg_replace('#/api/chat$#', '/api/embeddings', $chat_endpoint);
        if ($endpoint === $chat_endpoint) {
            $endpoint = rtrim($chat_endpoint, '/') . '/../embeddings';
        }
        $model = get_option('gutenbot_ollama_embeddings_model', 'nomic-embed-text');
        $body  = ['model' => $model, 'prompt' => $section_text];

        if ($this->http_client !== null) {
            $raw = $this->http_client->post($endpoint, $body);
        } else {
            $response = wp_remote_post($endpoint, [
                'method'  => 'POST',
                'headers' => ['content-type' => 'application/json'],
                'body'    => wp_json_encode($body),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                $this->last_error = 'Could not reach Ollama embeddings endpoint: ' . $response->get_error_message();
                error_log('GutenBot Ollama embeddings error: ' . $response->get_error_message());
                return null;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                $excerpt = substr(wp_remote_retrieve_body($response), 0, 200);
                $this->last_error = "Ollama embeddings returned HTTP {$status}: {$excerpt}";
                error_log("GutenBot Ollama embeddings HTTP {$status}: " . wp_remote_retrieve_body($response));
                return null;
            }

            $raw = wp_remote_retrieve_body($response);
        }

        return $this->parse_ollama_embedding_response($raw);
    }

    private function call_external_embeddings(string $section_text): ?array {
        $endpoint = get_option('gutenbot_embeddings_endpoint', 'https://api.openai.com/v1/embeddings');
        $model    = get_option('gutenbot_embeddings_model', 'text-embedding-3-small');
        $api_key  = get_option('gutenbot_embeddings_api_key', '') ?: (string) getenv('EMBEDDINGS_API_KEY');

        if ($api_key === '') {
            $this->last_error = 'No embeddings API key configured. Add it under GutenBot › Settings.';
            return null;
        }

        $body = ['model' => $model, 'input' => $section_text];

        if ($this->http_client !== null) {
            $raw = $this->http_client->post($endpoint, $body);
        } else {
            $response = wp_remote_post($endpoint, [
                'method'  => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'content-type'  => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                $this->last_error = 'Could not reach embeddings endpoint: ' . $response->get_error_message();
                error_log('GutenBot embeddings error: ' . $response->get_error_message());
                return null;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status === 401) {
                $this->last_error = 'Embeddings API key is invalid or expired (HTTP 401).';
                return null;
            }
            if ($status !== 200) {
                $excerpt = substr(wp_remote_retrieve_body($response), 0, 200);
                $this->last_error = "Embeddings API returned HTTP {$status}: {$excerpt}";
                error_log("GutenBot embeddings HTTP {$status}: " . wp_remote_retrieve_body($response));
                return null;
            }

            $raw = wp_remote_retrieve_body($response);
        }

        return $this->parse_embedding_response($raw);
    }

    private function call_anthropic_raw(string $prompt): ?string {
        $api_key  = get_option('gutenbot_ai_api_key', '') ?: (string) getenv('ANTHROPIC_API_KEY');
        $endpoint = get_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
        $model    = get_option('gutenbot_ai_model', 'claude-sonnet-4-6');

        if ($api_key === '') {
            $this->last_error = 'No Anthropic API key configured.';
            return null;
        }

        $body = [
            'model'      => $model,
            'max_tokens' => 256,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        if ($this->http_client !== null) {
            $raw = $this->http_client->post($endpoint, $body);
        } else {
            $response = wp_remote_post($endpoint, [
                'method'  => 'POST',
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                $this->last_error = 'Could not reach Anthropic API: ' . $response->get_error_message();
                error_log('GutenBot Anthropic summary error: ' . $response->get_error_message());
                return null;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                $this->last_error = "Anthropic API returned HTTP {$status}.";
                return null;
            }

            $raw = wp_remote_retrieve_body($response);
        }

        $data = json_decode($raw, true);
        return $data['content'][0]['text'] ?? $raw;
    }

    private function call_ollama_raw(string $prompt): ?string {
        $endpoint = get_option('gutenbot_ollama_endpoint', 'http://ollama:11434/api/chat');
        $model    = get_option('gutenbot_ollama_model', 'llama3.2');

        $body = [
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream'   => false,
        ];

        if ($this->http_client !== null) {
            $raw = $this->http_client->post($endpoint, $body);
        } else {
            $response = wp_remote_post($endpoint, [
                'method'  => 'POST',
                'headers' => ['content-type' => 'application/json'],
                'body'    => wp_json_encode($body),
                'timeout' => 60,
            ]);

            if (is_wp_error($response)) {
                $this->last_error = "Cannot reach Ollama at {$endpoint}.";
                return null;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                $this->last_error = "Ollama returned HTTP {$status}.";
                return null;
            }

            $raw = wp_remote_retrieve_body($response);
        }

        $data = json_decode($raw, true);
        return $data['message']['content'] ?? null;
    }

    private function build_summary_prompt(string $title, string $plain_text): string {
        $excerpt = mb_substr($plain_text, 0, 1500);
        return <<<PROMPT
Classify this WordPress page and write a one-sentence summary.

Title: {$title}
Content (excerpt): {$excerpt}

Respond ONLY with valid JSON:
{"page_type":"service|location|guide|general","summary":"one sentence describing the page"}
PROMPT;
    }

    private function parse_summary_json(string $text): ?array {
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', $text);

        $data = json_decode(trim($text), true);

        if (!is_array($data) || !isset($data['page_type'], $data['summary'])) {
            $this->last_error = 'AI summary response was missing required fields.';
            error_log('GutenBot: malformed summary response — ' . substr($text, 0, 500));
            return null;
        }

        return [
            'page_type' => sanitize_text_field($data['page_type']),
            'summary'   => sanitize_text_field($data['summary']),
        ];
    }

    private function parse_embedding_response(string $raw): ?array {
        $data   = json_decode($raw, true);
        $vector = $data['data'][0]['embedding'] ?? null;

        if (!is_array($vector) || empty($vector)) {
            $this->last_error = 'Embeddings API returned an unexpected response format.';
            error_log('GutenBot: unexpected embedding response — ' . substr($raw, 0, 500));
            return null;
        }

        return array_map('floatval', $vector);
    }

    private function parse_ollama_embedding_response(string $raw): ?array {
        $data   = json_decode($raw, true);
        $vector = $data['embedding'] ?? null;

        if (!is_array($vector) || empty($vector)) {
            $this->last_error = 'Ollama embeddings returned an unexpected response format.';
            error_log('GutenBot: unexpected Ollama embedding response — ' . substr($raw, 0, 500));
            return null;
        }

        return array_map('floatval', $vector);
    }

    // --- Streaming API (curl-based, for SSE output) ---

    public function stream_page_plan(
        string $document_content,
        array $similar_layouts,
        array $reusable_sections,
        array $admin_rules,
        callable $on_event
    ): void {
        $style_summary = GutenBot_Indexer::get_style_summary();

        $payload = [
            'document_content'    => $document_content,
            'similar_layouts'     => $similar_layouts,
            'reusable_sections'   => $reusable_sections,
            'theme_style_summary' => $style_summary,
            'admin_rules'         => $admin_rules,
        ];

        $prompt   = $this->build_prompt($payload);
        $provider = get_option('gutenbot_ai_provider', 'anthropic');

        if ($provider === 'ollama') {
            $this->stream_ollama($prompt, $on_event);
        } else {
            $this->stream_anthropic($prompt, $on_event);
        }
    }

    private function stream_anthropic(string $prompt, callable $on_event): void {
        $api_key  = get_option('gutenbot_ai_api_key', '') ?: (string) getenv('ANTHROPIC_API_KEY');
        $endpoint = get_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
        $model    = get_option('gutenbot_ai_model', 'claude-sonnet-4-6');

        if ($api_key === '') {
            $this->last_error = 'No Anthropic API key configured. Add your key under GutenBot › Settings.';
            return;
        }

        if (!function_exists('curl_init')) {
            $this->last_error = 'PHP curl extension is required for streaming.';
            return;
        }

        $body = wp_json_encode([
            'model'      => $model,
            'max_tokens' => 1024,
            'stream'     => true,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $sse_buf     = '';
        $accumulated = '';

        $write_fn = function ($ch, $data) use ($on_event, &$sse_buf, &$accumulated) {
            $sse_buf .= $data;

            while (($pos = strpos($sse_buf, "\n\n")) !== false) {
                $block   = substr($sse_buf, 0, $pos);
                $sse_buf = substr($sse_buf, $pos + 2);

                foreach (explode("\n", $block) as $line) {
                    if (strpos($line, 'data: ') !== 0) {
                        continue;
                    }
                    $json_str = substr($line, 6);
                    if ($json_str === '[DONE]') {
                        continue;
                    }
                    $event = json_decode($json_str, true);
                    if (
                        isset($event['type'], $event['delta']['type']) &&
                        $event['type'] === 'content_block_delta' &&
                        $event['delta']['type'] === 'text_delta'
                    ) {
                        $text         = $event['delta']['text'];
                        $accumulated .= $text;
                        $on_event('chunk', ['text' => $text]);
                    }
                }
            }

            return strlen($data);
        };

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL           => $endpoint,
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $body,
            CURLOPT_HTTPHEADER    => [
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT       => 120,
            CURLOPT_WRITEFUNCTION => $write_fn,
        ]);

        curl_exec($ch);
        $errno       = curl_errno($ch);
        $error       = curl_error($ch);
        $http_status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            $this->last_error = "Connection error: {$error}";
            return;
        }
        if ($http_status === 401) {
            $this->last_error = 'Anthropic API key is invalid or expired (HTTP 401). Update it under GutenBot › Settings.';
            return;
        }
        if ($http_status !== 200) {
            $this->last_error = "Anthropic returned HTTP {$http_status}.";
            return;
        }
        if (trim($accumulated) === '') {
            $this->last_error = 'No content received from Anthropic.';
            return;
        }

        $plan = $this->parse_plan_json($accumulated);
        if ($plan) {
            $on_event('done', ['plan' => $plan]);
        }
    }

    private function stream_ollama(string $prompt, callable $on_event): void {
        $endpoint = get_option('gutenbot_ollama_endpoint', 'http://ollama:11434/api/chat');
        $model    = get_option('gutenbot_ollama_model', 'llama3.2');

        if (!function_exists('curl_init')) {
            $this->last_error = 'PHP curl extension is required for streaming.';
            return;
        }

        $body = wp_json_encode([
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream'   => true,
        ]);

        $line_buf    = '';
        $accumulated = '';

        $write_fn = function ($ch, $data) use ($on_event, &$line_buf, &$accumulated) {
            $line_buf .= $data;

            while (($pos = strpos($line_buf, "\n")) !== false) {
                $line     = substr($line_buf, 0, $pos);
                $line_buf = substr($line_buf, $pos + 1);

                $json = json_decode(trim($line), true);
                if (!is_array($json)) {
                    continue;
                }
                $text = $json['message']['content'] ?? '';
                if ($text !== '') {
                    $accumulated .= $text;
                    $on_event('chunk', ['text' => $text]);
                }
            }

            return strlen($data);
        };

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL           => $endpoint,
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $body,
            CURLOPT_HTTPHEADER    => ['content-type: application/json'],
            CURLOPT_TIMEOUT       => 120,
            CURLOPT_WRITEFUNCTION => $write_fn,
        ]);

        curl_exec($ch);
        $errno       = curl_errno($ch);
        $error       = curl_error($ch);
        $http_status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            $this->last_error = "Cannot reach Ollama: {$error}";
            return;
        }
        if ($http_status === 404) {
            $this->last_error = "Ollama model \"{$model}\" not found. Run `ollama pull {$model}`.";
            return;
        }
        if ($http_status !== 200) {
            $this->last_error = "Ollama returned HTTP {$http_status}.";
            return;
        }
        if (trim($accumulated) === '') {
            $this->last_error = 'No content received from Ollama.';
            return;
        }

        $plan = $this->parse_plan_json($accumulated);
        if ($plan) {
            $on_event('done', ['plan' => $plan]);
        }
    }

    // --- Prompt builder ---

    private function build_prompt(array $payload) {
        $admin_rules = $payload['admin_rules'] ?? [];

        $context = $payload;
        unset($context['admin_rules']);
        $json = wp_json_encode($context, JSON_PRETTY_PRINT);

        $rules_block = '';
        if (!empty($admin_rules)) {
            $lines = array_map(static function ($rule, $i) {
                return ($i + 1) . '. ' . $rule;
            }, array_values($admin_rules), array_keys($admin_rules));
            $rules_block = "\n\nSite-specific instructions (must be followed):\n" . implode("\n", $lines);
        }

        return <<<PROMPT
You are a WordPress page planner. Given the following document content and site context, produce a JSON page plan.

Context:
{$json}{$rules_block}

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

    private function parse_anthropic_response(string $raw) {
        $data = json_decode($raw, true);

        // Unwrap Anthropic Messages API envelope when present.
        if (isset($data['content'][0]['text'])) {
            $raw = $data['content'][0]['text'];
        }

        return $this->parse_plan_json($raw);
    }

    private function parse_ollama_response(string $raw) {
        $data = json_decode($raw, true);

        if (!isset($data['message']['content'])) {
            $this->last_error = 'Unexpected response format from Ollama. Check that the model supports chat.';
            error_log('GutenBot: unexpected Ollama response — ' . substr($raw, 0, 500));
            return null;
        }

        return $this->parse_plan_json($data['message']['content']);
    }

    private function parse_plan_json(string $text) {
        // Strip markdown code fences that some models wrap around JSON output.
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', $text);

        $data = json_decode(trim($text), true);

        if (!is_array($data)) {
            $this->last_error = 'AI returned non-JSON output. Try a different model or check the endpoint.';
            error_log('GutenBot: malformed AI response — ' . substr($text, 0, 500));
            return null;
        }

        if (!isset($data['page_type'], $data['title'], $data['sections'])) {
            $this->last_error = 'AI response was missing required fields (page_type, title, or sections).';
            error_log('GutenBot: AI response missing required keys — ' . wp_json_encode($data));
            return null;
        }

        if (!is_array($data['sections']) || empty($data['sections'])) {
            $this->last_error = 'AI response contained an empty sections array.';
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
