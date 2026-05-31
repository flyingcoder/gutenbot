<?php

declare(strict_types=1);

namespace GutenBot;

class DocumentProcessor {

	/**
	 * @return array{colors: array<mixed>, font_sizes: array<mixed>, spacing: array<mixed>}
	 */
	public function extract_design_tokens( ?string $theme_json_path = null ): array {
		$path   = $theme_json_path ?? ( get_template_directory() . '/theme.json' );
		$tokens = $this->read_theme_json( $path );

		return ! empty( $tokens ) ? $tokens : [ 'colors' => [], 'font_sizes' => [], 'spacing' => [] ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_theme_json( string $path ): array {
		if ( ! file_exists( $path ) ) {
			return [];
		}

		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			return [];
		}

		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) ) {
			return [];
		}

		$settings = $data['settings'] ?? [];

		return [
			'colors'     => $settings['color']['palette'] ?? [],
			'font_sizes' => $settings['typography']['fontSizes'] ?? [],
			'spacing'    => $settings['spacing']['spacingSizes'] ?? [],
		];
	}

}
