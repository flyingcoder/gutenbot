<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// Minimal WP class stubs needed for unit tests (no WordPress bootstrap).
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = []) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $params;
        public function __construct(array $params = []) {
            $this->params = $params;
        }
        public function get_param($key) {
            return $this->params[$key] ?? null;
        }
    }
}

/**
 * Testable subclass that overrides process_content() so unit tests never
 * hit WP database, the indexer, or the AI API.
 */
if (!class_exists('Testable_REST_API')) {
    class Testable_REST_API extends GutenBot_REST_API {
        public static $process_result    = null;
        public static $process_exception = null;

        protected static function process_content(string $content) {
            if (static::$process_exception) {
                throw static::$process_exception;
            }
            if (static::$process_result === null) {
                throw new RuntimeException('No process result configured.');
            }
            return static::$process_result;
        }
    }
}

class Test_REST_API extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->returnArg();
        Functions\when('rest_ensure_response')->returnArg();
        Functions\when('current_user_can')->justReturn(true);

        Testable_REST_API::$process_result    = null;
        Testable_REST_API::$process_exception = null;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- Permission callback ---

    public function test_check_permission_returns_true_for_editor() {
        // Arrange
        Functions\when('current_user_can')->justReturn(true);

        // Act
        $result = GutenBot_REST_API::check_permission();

        // Assert
        $this->assertTrue($result);
    }

    public function test_check_permission_returns_false_without_edit_posts() {
        // Arrange
        Functions\when('current_user_can')->justReturn(false);

        // Act
        $result = GutenBot_REST_API::check_permission();

        // Assert
        $this->assertFalse($result);
    }

    // --- Empty content guard ---

    public function test_generate_returns_400_error_for_empty_content() {
        // Arrange
        $request = new WP_REST_Request(['content' => '   ']);

        // Act
        $response = Testable_REST_API::generate($request);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('empty_content', $response->code);
        $this->assertSame(400, $response->data['status']);
    }

    public function test_generate_returns_400_error_for_missing_content() {
        // Arrange
        $request = new WP_REST_Request([]);

        // Act
        $response = Testable_REST_API::generate($request);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('empty_content', $response->code);
    }

    // --- AI / processing failures ---

    public function test_generate_returns_502_when_processing_throws_runtime_exception() {
        // Arrange
        $request = new WP_REST_Request(['content' => 'Some page content about fence installation.']);
        Testable_REST_API::$process_exception = new RuntimeException('AI plan failed.');

        // Act
        $response = Testable_REST_API::generate($request);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('generation_failed', $response->code);
        $this->assertSame(502, $response->data['status']);
    }

    public function test_generate_returns_500_when_processing_throws_generic_exception() {
        // Arrange
        $request = new WP_REST_Request(['content' => 'Some content.']);
        Testable_REST_API::$process_exception = new Exception('Unexpected error.');

        // Act
        $response = Testable_REST_API::generate($request);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('generation_error', $response->code);
        $this->assertSame(500, $response->data['status']);
    }

    // --- Success path ---

    public function test_generate_returns_markup_title_and_page_type_on_success() {
        // Arrange
        $request = new WP_REST_Request(['content' => 'We install fences for residential properties.']);
        Testable_REST_API::$process_result = [
            'markup'    => '<!-- wp:heading {"level":1} --><h1>Fence Services</h1><!-- /wp:heading -->',
            'title'     => 'Fence Services',
            'page_type' => 'service',
        ];

        // Act
        $response = Testable_REST_API::generate($request);

        // Assert — rest_ensure_response() is stubbed to pass-through, so $response is the array.
        $this->assertIsArray($response);
        $this->assertArrayHasKey('markup', $response);
        $this->assertArrayHasKey('title', $response);
        $this->assertArrayHasKey('page_type', $response);
        $this->assertSame('Fence Services', $response['title']);
        $this->assertSame('service', $response['page_type']);
    }
}
