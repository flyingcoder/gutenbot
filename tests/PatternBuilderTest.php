<?php

declare(strict_types=1);

use GutenBot\PatternBuilder;
use PHPUnit\Framework\TestCase;

class PatternBuilderTest extends TestCase {

	private PatternBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->builder = new PatternBuilder();
	}

	public function test_excludes_blocks_below_threshold(): void {
		$registry = [
			'core/cover' => [ 'count' => 1, 'sample_html' => '<div></div>', 'attrs' => [] ],
		];

		$patterns = $this->builder->build( $registry );

		$this->assertSame( [], $patterns );
	}

	public function test_includes_blocks_at_threshold(): void {
		$registry = [
			'core/cover' => [ 'count' => 2, 'sample_html' => '<div>hero</div>', 'attrs' => [] ],
		];

		$patterns = $this->builder->build( $registry );

		$this->assertCount( 1, $patterns );
		$this->assertSame( 'hero', $patterns[0]['section_type'] );
		$this->assertSame( 'core/cover', $patterns[0]['block_name'] );
		$this->assertSame( '<div>hero</div>', $patterns[0]['sample_markup'] );
	}

	public function test_skips_unmapped_block_names(): void {
		$registry = [
			'core/paragraph' => [ 'count' => 5, 'sample_html' => '<p>text</p>', 'attrs' => [] ],
		];

		$patterns = $this->builder->build( $registry );

		$this->assertSame( [], $patterns );
	}

	public function test_returns_only_blocks_above_threshold_when_mixed(): void {
		$registry = [
			'core/cover'   => [ 'count' => 2, 'sample_html' => '', 'attrs' => [] ],
			'core/gallery' => [ 'count' => 2, 'sample_html' => '', 'attrs' => [] ],
			'core/buttons' => [ 'count' => 1, 'sample_html' => '', 'attrs' => [] ],
		];

		$patterns = $this->builder->build( $registry );

		$this->assertCount( 2, $patterns );
		$section_types = array_column( $patterns, 'section_type' );
		$this->assertContains( 'hero', $section_types );
		$this->assertContains( 'gallery', $section_types );
	}

	// ------------------------------------------------------------------
	// build_indexed_pages
	// ------------------------------------------------------------------

	public function test_build_indexed_pages_returns_empty_for_no_input(): void {
		$this->assertSame( [], $this->builder->build_indexed_pages( [] ) );
	}

	public function test_build_indexed_pages_excludes_pages_with_fewer_than_two_mapped_sections(): void {
		$raw_pages = [
			[
				'wp_post_id'    => 1,
				'page_type'     => 'landing',
				'section_order' => [ 'hero' ],
				'sections'      => [
					[ 'section_type' => 'hero', 'section_signature' => '', 'block_tree' => [], 'content_density' => 'short', 'semantic_intent' => 'conversion' ],
				],
			],
		];

		$this->assertSame( [], $this->builder->build_indexed_pages( $raw_pages ) );
	}

	public function test_build_indexed_pages_includes_pages_with_two_or_more_mapped_sections(): void {
		$raw_pages = [
			[
				'wp_post_id'    => 2,
				'page_type'     => 'service',
				'section_order' => [ 'hero', 'cta' ],
				'sections'      => [
					[ 'section_type' => 'hero', 'section_signature' => '', 'block_tree' => [], 'content_density' => 'short', 'semantic_intent' => 'conversion' ],
					[ 'section_type' => 'cta',  'section_signature' => '', 'block_tree' => [], 'content_density' => 'short', 'semantic_intent' => 'conversion' ],
				],
			],
		];

		$result = $this->builder->build_indexed_pages( $raw_pages );

		$this->assertCount( 1, $result );
		$this->assertSame( 2, $result[0]['wp_post_id'] );
	}

	public function test_build_indexed_pages_intro_sections_do_not_count_as_mapped(): void {
		$raw_pages = [
			[
				'wp_post_id'    => 3,
				'page_type'     => 'landing',
				'section_order' => [ 'hero', 'intro' ],
				'sections'      => [
					[ 'section_type' => 'hero',  'section_signature' => '', 'block_tree' => [], 'content_density' => 'short', 'semantic_intent' => 'conversion' ],
					[ 'section_type' => 'intro', 'section_signature' => '', 'block_tree' => [], 'content_density' => 'short', 'semantic_intent' => 'informational' ],
				],
			],
		];

		// Only 1 mapped section (hero); intro does not count → excluded
		$this->assertSame( [], $this->builder->build_indexed_pages( $raw_pages ) );
	}
}
