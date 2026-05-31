<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use GutenBot\Indexer;
use PHPUnit\Framework\TestCase;

class IndexerTest extends TestCase {

	private Indexer $indexer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->indexer = new Indexer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registry_is_empty_before_scan(): void {
		$this->assertSame( [], $this->indexer->get_registry() );
	}

	public function test_scan_records_block_name_and_sample_html(): void {
		$post               = new stdClass();
		$post->post_content = '';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			[
				'blockName'   => 'core/cover',
				'innerHTML'   => '<div class="cover"></div>',
				'attrs'       => [ 'align' => 'full' ],
				'innerBlocks' => [],
			],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result   = $this->indexer->scan();
		$registry = $result['registry'];

		$this->assertArrayHasKey( 'core/cover', $registry );
		$this->assertSame( 1, $registry['core/cover']['count'] );
		$this->assertSame( '<div class="cover"></div>', $registry['core/cover']['sample_html'] );
	}

	public function test_scan_increments_count_across_multiple_posts(): void {
		$post               = new stdClass();
		$post->post_content = '';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post, $post ] );
		Functions\expect( 'parse_blocks' )->twice()->andReturn( [
			[ 'blockName' => 'core/cover', 'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result = $this->indexer->scan();

		$this->assertSame( 2, $result['registry']['core/cover']['count'] );
	}

	public function test_scan_walks_inner_blocks_recursively(): void {
		$post               = new stdClass();
		$post->post_content = '';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			[
				'blockName'   => 'core/columns',
				'innerHTML'   => '',
				'attrs'       => [],
				'innerBlocks' => [
					[
						'blockName'   => 'core/column',
						'innerHTML'   => '<div>col</div>',
						'attrs'       => [],
						'innerBlocks' => [],
					],
				],
			],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result   = $this->indexer->scan();
		$registry = $result['registry'];

		$this->assertArrayHasKey( 'core/columns', $registry );
		$this->assertArrayHasKey( 'core/column', $registry );
	}

	public function test_scan_ignores_null_block_names(): void {
		$post               = new stdClass();
		$post->post_content = '';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			[ 'blockName' => null, 'innerHTML' => 'freeform', 'attrs' => [], 'innerBlocks' => [] ],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result = $this->indexer->scan();

		$this->assertSame( [], $result['registry'] );
	}

	public function test_scan_result_contains_all_required_keys(): void {
		Functions\expect( 'get_posts' )->once()->andReturn( [] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result = $this->indexer->scan();

		$this->assertArrayHasKey( 'registry', $result );
		$this->assertArrayHasKey( 'tokens', $result );
		$this->assertArrayHasKey( 'patterns', $result );
		$this->assertArrayHasKey( 'indexed_pages', $result );
	}

	public function test_section_splitter_splits_at_separator(): void {
		$post               = new \stdClass();
		$post->post_content = '';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			[ 'blockName' => 'core/cover',     'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
			[ 'blockName' => 'core/separator', 'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
			[ 'blockName' => 'core/buttons',   'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result = $this->indexer->scan();

		// core/cover → hero, core/buttons → cta — both mapped, page qualifies
		$this->assertCount( 1, $result['indexed_pages'] );
		$this->assertSame( [ 'hero', 'cta' ], $result['indexed_pages'][0]['section_order'] );
	}

	public function test_page_type_detection_uses_title_keywords(): void {
		$post               = new \stdClass();
		$post->post_content = '';
		$post->post_title   = 'Our Services';
		$post->post_name    = 'our-services';
		$post->post_type    = 'page';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			[ 'blockName' => 'core/cover',   'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
			[ 'blockName' => 'core/columns', 'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result = $this->indexer->scan();

		$this->assertCount( 1, $result['indexed_pages'] );
		$this->assertSame( 'service', $result['indexed_pages'][0]['page_type'] );
	}

	public function test_post_post_type_detected_as_blog(): void {
		$post               = new \stdClass();
		$post->post_content = '';
		$post->post_title   = 'Hello World';
		$post->post_name    = 'hello-world';
		$post->post_type    = 'post';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			[ 'blockName' => 'core/cover',   'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
			[ 'blockName' => 'core/columns', 'innerHTML' => '', 'attrs' => [], 'innerBlocks' => [] ],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result = $this->indexer->scan();

		$this->assertSame( 'blog', $result['indexed_pages'][0]['page_type'] );
	}

	public function test_section_fields_are_present(): void {
		$post               = new \stdClass();
		$post->post_content = '';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			[ 'blockName' => 'core/cover',   'innerHTML' => '<h2>Hero</h2>', 'attrs' => [], 'innerBlocks' => [] ],
			[ 'blockName' => 'core/buttons', 'innerHTML' => '<a>CTA</a>',   'attrs' => [], 'innerBlocks' => [] ],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result  = $this->indexer->scan();
		$section = $result['indexed_pages'][0]['sections'][0];

		$this->assertArrayHasKey( 'section_type', $section );
		$this->assertArrayHasKey( 'section_signature', $section );
		$this->assertArrayHasKey( 'block_tree', $section );
		$this->assertArrayHasKey( 'content_density', $section );
		$this->assertArrayHasKey( 'semantic_intent', $section );
	}

	public function test_pages_with_only_unmapped_sections_excluded_from_indexed_pages(): void {
		$post               = new \stdClass();
		$post->post_content = '';

		Functions\expect( 'get_posts' )->once()->andReturn( [ $post ] );
		Functions\expect( 'parse_blocks' )->once()->andReturn( [
			// core/paragraph has no entry in SECTION_TYPE_MAP → section_type = 'intro'
			[ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>text</p>', 'attrs' => [], 'innerBlocks' => [] ],
			[ 'blockName' => 'core/heading',   'innerHTML' => '<h2>Hi</h2>', 'attrs' => [], 'innerBlocks' => [] ],
		] );
		Functions\expect( 'get_template_directory' )->once()->andReturn( '/nonexistent' );
		Functions\expect( 'get_option' )->zeroOrMoreTimes()->andReturn( false );

		$result = $this->indexer->scan();

		$this->assertSame( [], $result['indexed_pages'] );
	}
}
