<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_Indexer extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_classify_service_page_from_slug() {
        // Arrange
        $post = (object) ['post_name' => 'fence-installation-services', 'post_title' => 'Services'];

        // Act
        $type = GutenBot_Indexer::classify_page_type($post, []);

        // Assert
        $this->assertSame('service', $type);
    }

    public function test_classify_location_page_from_slug() {
        // Arrange
        $post = (object) ['post_name' => 'dallas-tx-location', 'post_title' => 'Dallas'];

        // Act
        $type = GutenBot_Indexer::classify_page_type($post, []);

        // Assert
        $this->assertSame('location', $type);
    }

    public function test_classify_guide_from_title() {
        // Arrange
        $post = (object) ['post_name' => 'post-123', 'post_title' => 'How to Install a Fence'];

        // Act — title includes "how to" so mapped to guide.
        $type = GutenBot_Indexer::classify_page_type($post, []);

        // Assert
        $this->assertSame('guide', $type);
    }

    public function test_extract_section_order_from_blocks() {
        // Arrange
        $blocks = [
            ['blockName' => 'core/cover', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => ''],
            ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>text</p>'],
            ['blockName' => 'core/buttons', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => ''],
        ];

        // Act
        $order = GutenBot_Indexer::extract_section_order($blocks);

        // Assert
        $this->assertSame(['hero', 'text', 'cta'], $order);
    }

    public function test_heading_levels_extracted_correctly() {
        // Arrange
        $blocks = [
            [
                'blockName'   => 'core/heading',
                'attrs'       => ['level' => 2],
                'innerBlocks' => [],
                'innerHTML'   => '<h2>Why Us</h2>',
            ],
        ];

        // Act
        $headings = GutenBot_Indexer::extract_headings($blocks);

        // Assert
        $this->assertCount(1, $headings);
        $this->assertSame(2, $headings[0]['level']);
        $this->assertSame('Why Us', $headings[0]['text']);
    }

    public function test_extract_block_names_returns_hierarchy() {
        // Arrange
        $blocks = [
            [
                'blockName'   => 'core/group',
                'attrs'       => [],
                'innerBlocks' => [
                    ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => ''],
                ],
                'innerHTML'   => '',
            ],
        ];

        // Act
        $names = GutenBot_Indexer::extract_block_names($blocks);

        // Assert
        $this->assertCount(1, $names);
        $this->assertSame('core/group', $names[0]['name']);
        $this->assertSame(0, $names[0]['depth']);
        $this->assertSame('core/paragraph', $names[0]['children'][0]['name']);
    }

    public function test_general_fallback_when_no_keyword_matches() {
        // Arrange
        $post = (object) ['post_name' => 'about-us', 'post_title' => 'About Us'];

        // Act
        $type = GutenBot_Indexer::classify_page_type($post, []);

        // Assert
        $this->assertSame('general', $type);
    }

    public function test_extract_plain_text_strips_tags_from_blocks() {
        // Arrange
        $blocks = [
            ['blockName' => 'core/paragraph', 'innerHTML' => '<p>Hello world</p>', 'innerBlocks' => []],
            ['blockName' => 'core/heading',   'innerHTML' => '<h2>Title</h2>',      'innerBlocks' => []],
        ];

        // Act
        Functions\when('wp_strip_all_tags')->alias('strip_tags');
        $text = GutenBot_Indexer::extract_plain_text($blocks);

        // Assert
        $this->assertStringContainsString('Hello world', $text);
        $this->assertStringContainsString('Title', $text);
    }
}
