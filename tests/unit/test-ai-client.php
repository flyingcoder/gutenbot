<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_AI_Client extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            $opts = [
                'gutenbot_ai_api_key'         => 'test-key',
                'gutenbot_ai_api_endpoint'     => 'https://api.anthropic.com/v1/messages',
                'gutenbot_ai_model'            => 'claude-sonnet-4-6',
                'gutenbot_ai_provider'              => 'anthropic',
                'gutenbot_embeddings_endpoint'      => 'https://api.openai.com/v1/embeddings',
                'gutenbot_embeddings_model'         => 'text-embedding-3-small',
                'gutenbot_embeddings_api_key'       => 'embed-test-key',
                'gutenbot_ollama_endpoint'          => 'http://ollama:11434/api/chat',
                'gutenbot_ollama_embeddings_model'  => 'nomic-embed-text',
            ];
            return $opts[$key] ?? $default;
        });

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('sanitize_text_field')->alias('trim');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mock_http_client(string $raw_response) {
        return new class($raw_response) {
            private $response;
            private $call_count = 0;
            public $last_payload;
            public $last_endpoint;

            public function __construct($r) { $this->response = $r; }

            public function post($endpoint, $body) {
                $this->call_count++;
                $this->last_payload  = $body;
                $this->last_endpoint = $endpoint;
                return $this->response;
            }

            public function getCallCount() { return $this->call_count; }
        };
    }

    public function test_valid_response_parsed_to_page_plan() {
        // Arrange
        $raw = json_encode([
            'page_type'     => 'service',
            'title'         => 'Fence Installation',
            'sections'      => ['hero', 'faq', 'cta'],
            'layout_source' => 42,
        ]);
        $http   = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $plan = $client->get_page_plan('raw document text', [], [], []);

        // Assert
        $this->assertSame('service', $plan['page_type']);
        $this->assertContains('hero', $plan['sections']);
        $this->assertSame(42, $plan['layout_source']);
    }

    public function test_payload_includes_document_content() {
        // Arrange
        $raw  = json_encode([
            'page_type' => 'service', 'title' => 'Test', 'sections' => ['hero'], 'layout_source' => null,
        ]);
        $http   = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $client->get_page_plan('my document text', [], [], []);

        // Assert
        $sent_body = json_decode(json_encode($http->last_payload), true);
        $prompt    = $sent_body['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('document_content', $prompt);
    }

    public function test_payload_includes_theme_style_summary() {
        // Arrange
        $raw  = json_encode([
            'page_type' => 'service', 'title' => 'Test', 'sections' => ['hero'], 'layout_source' => null,
        ]);
        $http   = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $client->get_page_plan('doc', [], [], []);

        // Assert
        $sent_body = json_decode(json_encode($http->last_payload), true);
        $prompt    = $sent_body['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('theme_style_summary', $prompt);
    }

    public function test_malformed_json_triggers_retry() {
        // Arrange — null returned twice = malformed.
        $call_count = 0;
        $http = new class($call_count) {
            public $calls = 0;
            public function post($e, $b) { $this->calls++; return 'NOT_JSON'; }
        };
        $client = new GutenBot_AI_Client($http);

        // Act
        $plan = $client->get_page_plan('doc', [], [], []);

        // Assert — two calls made (original + retry), result is null.
        $this->assertNull($plan);
        $this->assertSame(2, $http->calls);
    }

    public function test_missing_sections_key_returns_null() {
        // Arrange — response missing "sections".
        $raw    = json_encode(['page_type' => 'service', 'title' => 'Test']);
        $http   = new class($raw) {
            private $r; public function __construct($r) { $this->r = $r; }
            public function post($e, $b) { return $this->r; }
        };
        $client = new GutenBot_AI_Client($http);

        // Act
        $plan = $client->get_page_plan('doc', [], [], []);

        // Assert
        $this->assertNull($plan);
    }

    // --- get_page_summary ---

    public function test_get_page_summary_returns_page_type_and_summary() {
        // Arrange
        $raw    = json_encode(['page_type' => 'service', 'summary' => 'A page about fence installation.']);
        $http   = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $result = $client->get_page_summary('Fence Installation', 'We install fences quickly.');

        // Assert
        $this->assertSame('service', $result['page_type']);
        $this->assertSame('A page about fence installation.', $result['summary']);
    }

    public function test_get_page_summary_returns_null_on_malformed_response() {
        // Arrange — response missing "summary" key
        $raw    = json_encode(['page_type' => 'service']);
        $http   = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $result = $client->get_page_summary('Test', 'content');

        // Assert
        $this->assertNull($result);
        $this->assertNotEmpty($client->get_last_error());
    }

    // --- get_section_embedding ---

    public function test_get_section_embedding_returns_float_array() {
        // Arrange — standard OpenAI-compatible response envelope
        $raw  = json_encode(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]);
        $http = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $vector = $client->get_section_embedding('some section text');

        // Assert
        $this->assertIsArray($vector);
        $this->assertCount(3, $vector);
        $this->assertEqualsWithDelta(0.1, $vector[0], 0.0001);
    }

    public function test_get_section_embedding_uses_ollama_when_provider_is_ollama() {
        // Arrange — Ollama returns {"embedding":[...]} not OpenAI's data[0].embedding
        Functions\when('get_option')->alias(function ($key, $default = '') {
            $opts = [
                'gutenbot_ai_provider'             => 'ollama',
                'gutenbot_ollama_endpoint'         => 'http://ollama:11434/api/chat',
                'gutenbot_ollama_embeddings_model' => 'nomic-embed-text',
            ];
            return $opts[$key] ?? $default;
        });
        $raw    = json_encode(['embedding' => [0.5, 0.6, 0.7]]);
        $http   = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $vector = $client->get_section_embedding('hero section text');

        // Assert — correct vector returned and endpoint derived from chat URL
        $this->assertCount(3, $vector);
        $this->assertEqualsWithDelta(0.5, $vector[0], 0.0001);
        $this->assertStringContainsString('/api/embeddings', $http->last_endpoint ?? '');
    }

    public function test_get_section_embedding_returns_null_on_unexpected_format() {
        // Arrange — response with no "data" key
        $raw    = json_encode(['object' => 'error', 'message' => 'bad request']);
        $http   = $this->mock_http_client($raw);
        $client = new GutenBot_AI_Client($http);

        // Act
        $vector = $client->get_section_embedding('text');

        // Assert
        $this->assertNull($vector);
        $this->assertNotEmpty($client->get_last_error());
    }
}
