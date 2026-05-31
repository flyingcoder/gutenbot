<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use GutenBot\DocumentProcessor;
use PHPUnit\Framework\TestCase;

class DocumentProcessorTest extends TestCase {

	private DocumentProcessor $processor;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->processor = new DocumentProcessor();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_empty_arrays_when_theme_json_absent(): void {
		$tokens = $this->processor->extract_design_tokens( '/nonexistent/path/theme.json' );

		$this->assertSame( [], $tokens['colors'] );
		$this->assertSame( [], $tokens['font_sizes'] );
		$this->assertSame( [], $tokens['spacing'] );
	}

	public function test_extracts_colors_from_theme_json(): void {
		$path = tempnam( sys_get_temp_dir(), 'theme' ) . '.json';
		file_put_contents( $path, json_encode( [
			'settings' => [
				'color' => [
					'palette' => [
						[ 'slug' => 'primary', 'color' => '#111111', 'name' => 'Primary' ],
					],
				],
			],
		] ) );

		$tokens = $this->processor->extract_design_tokens( $path );

		unlink( $path );

		$this->assertCount( 1, $tokens['colors'] );
		$this->assertSame( 'primary', $tokens['colors'][0]['slug'] );
	}

	public function test_extracts_font_sizes_and_spacing_from_theme_json(): void {
		$path = tempnam( sys_get_temp_dir(), 'theme' ) . '.json';
		file_put_contents( $path, json_encode( [
			'settings' => [
				'color'      => [ 'palette' => [] ],
				'typography' => [ 'fontSizes' => [ [ 'slug' => 'sm', 'size' => '14px' ] ] ],
				'spacing'    => [ 'spacingSizes' => [ [ 'slug' => 'sm', 'size' => '8px' ] ] ],
			],
		] ) );

		$tokens = $this->processor->extract_design_tokens( $path );

		unlink( $path );

		$this->assertCount( 1, $tokens['font_sizes'] );
		$this->assertCount( 1, $tokens['spacing'] );
	}

}
