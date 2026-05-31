<?php

declare(strict_types=1);

namespace GutenBot;

class PatternBuilder {

	private const MIN_OCCURRENCES = 2;
	private const MIN_MAPPED_SECTIONS = 2;

	public const SECTION_TYPE_MAP = [
		'core/cover'     => 'hero',
		'core/columns'   => 'services_list',
		'core/details'   => 'faq',
		'core/buttons'   => 'cta',
		'core/pullquote' => 'testimonials',
		'core/quote'     => 'testimonials',
		'core/gallery'   => 'gallery',
		'core/html'      => 'contact',
		'core/embed'     => 'location',
	];

	/**
	 * @param array<string, array{count: int, sample_html: string, attrs: array<string, mixed>}> $registry
	 * @return array<int, array{section_type: string, block_name: string, sample_markup: string}>
	 */
	public function build( array $registry ): array {
		$patterns = [];

		foreach ( $registry as $block_name => $data ) {
			if ( $data['count'] < self::MIN_OCCURRENCES ) {
				continue;
			}

			$section_type = self::SECTION_TYPE_MAP[ $block_name ] ?? null;
			if ( $section_type === null ) {
				continue;
			}

			$patterns[] = [
				'section_type'  => $section_type,
				'block_name'    => $block_name,
				'sample_markup' => $data['sample_html'],
			];
		}

		return $patterns;
	}

	/**
	 * Filters raw page data to pages with ≥ 2 sections whose type was resolved from SECTION_TYPE_MAP.
	 *
	 * @param array<int, array{wp_post_id: int, page_type: string, section_order: array<string>, sections: array<mixed>}> $raw_pages
	 * @return array<int, array{wp_post_id: int, page_type: string, section_order: array<string>, sections: array<mixed>}>
	 */
	public function build_indexed_pages( array $raw_pages ): array {
		$indexed = [];

		foreach ( $raw_pages as $page ) {
			$mapped_count = count( array_filter(
				(array) ( $page['sections'] ?? [] ),
				static fn( array $s ): bool => ( $s['section_type'] ?? 'intro' ) !== 'intro'
			) );

			if ( $mapped_count < self::MIN_MAPPED_SECTIONS ) {
				continue;
			}

			$indexed[] = $page;
		}

		return array_values( $indexed );
	}
}
