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
                'gutenbot_ai_api_key'      => 'test-key',
                'gutenbot_ai_api_endpoint' => 'https://api.anthropic.com/v1/messages',
                'gutenbot_ai_model'        => 'claude-sonnet-4-6',
            ];
            return $opts[$key] ?? $default;
        });

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('GutenBot_Indexer::get_style_summary')->justReturn([]);
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

            public function __construct($r) { $this->response = $r; }

            public function post($endpoint, $body) {
                $this->call_count++;
                $this->last_payload = $body;
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
}
