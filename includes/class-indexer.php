<?php

declare(strict_types=1);

namespace GutenBot;

class Indexer {

	/** @var array<string, array{count: int, sample_html: string, attrs: array<string, mixed>}> */
	private array $registry = [];

	private const TABLE = 'gutenbot_page_chunks';

	private const PAGE_TYPE_KEYWORDS = [
		'guide'    => [ 'guide', 'how-to', 'howto', 'tutorial', 'learn' ],
		'service'  => [ 'service', 'services' ],
		'location' => [ 'location', 'area', 'city', 'region', 'near' ],
	];

	private const FALLBACK_WORD_LIMIT = 800;

	// ------------------------------------------------------------------
	// Three-phase scan
	// ------------------------------------------------------------------

	/**
	 * Phase 1 — build queue of posts that need scanning.
	 *
	 * @return array{total: int, remaining: int}
	 */
	public function scan_init(): array {
		global $wpdb;

		$posts = get_posts( [
			'post_type'      => [ 'page', 'post' ],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'all',
		] );

		$published_ids = array_map( fn( $p ) => (int) $p->ID, $posts );
		$table         = $wpdb->prefix . self::TABLE;

		$scan_map = json_decode( (string) get_option( 'aipb_post_scan_map', '{}' ), true ) ?: [];

		// Remove entries for deleted posts.
		foreach ( array_keys( $scan_map ) as $id ) {
			if ( ! in_array( (int) $id, $published_ids, true ) ) {
				unset( $scan_map[ $id ] );
			}
		}

		// Delete chunks for posts that no longer exist.
		if ( ! empty( $published_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $published_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE wp_post_id NOT IN ({$placeholders})", ...$published_ids ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DELETE FROM {$table}" );
		}

		$queue = [];
		foreach ( $posts as $post ) {
			$last_scan = $scan_map[ (string) $post->ID ] ?? null;
			if ( ! $last_scan || strtotime( $post->post_modified_gmt ) > strtotime( $last_scan ) ) {
				$queue[] = (int) $post->ID;
			}
		}

		$total = count( $queue );

		update_option( 'aipb_post_scan_map', wp_json_encode( $scan_map ), false );
		update_option( 'aipb_scan_queue', wp_json_encode( $queue ), false );
		update_option( 'aipb_scan_total', $total, false );
		update_option( 'aipb_scan_status', 'scanning', false );

		if ( empty( $queue ) ) {
			$this->scan_finalize();
			return [ 'total' => 0, 'remaining' => 0 ];
		}

		return [ 'total' => $total, 'remaining' => $total ];
	}

	/**
	 * Phase 2 — chunk a single post and persist results.
	 *
	 * @return array{post_id: int, post_title: string, remaining: int, total: int}
	 */
	public function scan_page( int $post_id ): array {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return [
				'post_id'    => $post_id,
				'post_title' => '',
				'remaining'  => count( $this->get_queue() ),
				'total'      => (int) get_option( 'aipb_scan_total', 0 ),
			];
		}

		$this->registry = [];
		$blocks          = parse_blocks( $post->post_content );
		$this->walk_blocks( $blocks );

		$table     = $wpdb->prefix . self::TABLE;
		$page_type = $this->detect_page_type( $post );
		$now       = current_time( 'mysql', true );

		$wpdb->delete( $table, [ 'wp_post_id' => $post_id ], [ '%d' ] );

		$chunk_groups = $this->split_into_chunks( $blocks );
		
		foreach ( $chunk_groups as $order => $chunk_block_list ) {
			$chunk = $this->build_chunk( $chunk_block_list );
			$wpdb->insert(
				$table,
				[
					'wp_post_id'       => $post_id,
					'chunk_order'      => $order + 1,
					'content'          => $chunk['content'],
					'chunk_html'       => $chunk['chunk_html'],
					'chunk_blocks'     => $chunk['chunk_blocks'],
					'block_count'      => $chunk['block_count'],
					'block_types'      => $chunk['block_types'],
					'word_count'       => $chunk['word_count'],
					'character_count'  => $chunk['character_count'],
					'embedding_synced' => 0,
					'metadata'         => wp_json_encode( [ 'page_type' => $page_type ] ),
					'created_at'       => $now,
					'updated_at'       => $now,
				]
			);
		}

		// Track last scan time per post for incremental re-scan detection.
		$scan_map = json_decode( (string) get_option( 'aipb_post_scan_map', '{}' ), true ) ?: [];
		$scan_map[ (string) $post_id ] = $post->post_modified_gmt;
		update_option( 'aipb_post_scan_map', wp_json_encode( $scan_map ), false );

		$this->pop_from_queue( $post_id );

		return [
			'post_id'    => $post_id,
			'post_title' => $post->post_title,
			'remaining'  => count( $this->get_queue() ),
			'total'      => (int) get_option( 'aipb_scan_total', 0 ),
		];
	}

	/**
	 * Phase 3 — rebuild aggregated wp_options from all stored chunks.
	 *
	 * @return array{status: string, scanned_at: string}
	 */
	public function scan_finalize(): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		$this->registry = [];
		$chunks_by_post = [];

		foreach ( (array) $rows as $row ) {
			$wp_post_id = (int) $row['wp_post_id'];
			$block_list = json_decode( (string) $row['chunk_blocks'], true ) ?: [];
			$meta       = json_decode( (string) ( $row['metadata'] ?? '{}' ), true ) ?: [];

			$this->walk_blocks( $block_list );

			$chunks_by_post[ $wp_post_id ][] = [
				'chunk_order' => (int) $row['chunk_order'],
				'content'     => (string) $row['content'],
				'block_types' => json_decode( (string) $row['block_types'], true ) ?: [],
				'word_count'  => (int) $row['word_count'],
				'block_tree'  => $block_list,
				'_page_type'  => (string) ( $meta['page_type'] ?? 'landing' ),
			];
		}

		$filtered_registry = array_filter( $this->registry, fn( $data ) => $data['count'] >= 2 );

		$raw_pages = [];
		foreach ( $chunks_by_post as $post_id => $chunks ) {
			usort( $chunks, fn( $a, $b ) => $a['chunk_order'] <=> $b['chunk_order'] );
			$raw_pages[] = [
				'wp_post_id' => $post_id,
				'page_type'  => $chunks[0]['_page_type'] ?? 'landing',
				'sections'   => $chunks,
			];
		}

		$tokens        = ( new DocumentProcessor() )->extract_design_tokens();
		$builder       = new PatternBuilder();
		$patterns      = $builder->build( $filtered_registry );
		$indexed_pages = $builder->build_indexed_pages( $raw_pages );

		update_option( 'aipb_block_registry', $filtered_registry, false );
		update_option( 'aipb_design_tokens', $tokens, false );
		update_option( 'aipb_patterns', $patterns, false );
		update_option( 'aipb_indexed_pages', $indexed_pages, false );
		update_option( 'aipb_scan_status', 'complete', false );
		update_option( 'aipb_scanned_at', current_time( 'mysql', true ), false );
		delete_option( 'aipb_scan_error' );
		delete_option( 'aipb_scan_queue' );
		delete_option( 'aipb_scan_total' );

		return [
			'status'     => 'complete',
			'scanned_at' => (string) get_option( 'aipb_scanned_at', '' ),
		];
	}

	// ------------------------------------------------------------------
	// Queue helpers
	// ------------------------------------------------------------------

	/** @return array<int, int> */
	private function get_queue(): array {
		return json_decode( (string) get_option( 'aipb_scan_queue', '[]' ), true ) ?: [];
	}

	private function pop_from_queue( int $post_id ): void {
		$queue = $this->get_queue();
		$queue = array_values( array_filter( $queue, fn( $id ) => (int) $id !== $post_id ) );
		update_option( 'aipb_scan_queue', wp_json_encode( $queue ), false );
	}

	// ------------------------------------------------------------------
	// Chunking
	// ------------------------------------------------------------------

	/**
	 * Splits a flat top-level block list into chunk groups using boundary rules.
	 *
	 * Boundaries (in priority order):
	 * - core/separator          → flush current, discard separator
	 * - core/group|cover|query  → flush current, become own chunk
	 * - core/heading (with content already accumulated) → flush, start new chunk
	 * - Fallback: flush when word count exceeds FALLBACK_WORD_LIMIT,
	 *   but never after a NO_SPLIT_BLOCKS type.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function split_into_chunks(array $blocks): array
	{
		$chunks = [];
		$current = [];
		$current_words = 0;

		foreach ($blocks as $block) {

			$name = (string) ($block['blockName'] ?? '');

			$block_words = str_word_count(
				$this->extract_text([$block])
			);

			// Separator creates a boundary.
			if ($name === 'core/separator') {

				if (!empty($current)) {
					$chunks[] = $current;
					$current = [];
					$current_words = 0;
				}

				// Separator itself belongs to next chunk
				$current[] = $block;

				continue;
			}

			// Heading starts a new chunk.
			if (
				$name === 'core/heading'
				&& !empty($current)
			) {
				$chunks[] = $current;
				$current = [];
				$current_words = 0;
			}

			// Fallback size limit.
			if (
				!empty($current)
				&& ($current_words + $block_words) > self::FALLBACK_WORD_LIMIT
			) {
				$chunks[] = $current;
				$current = [];
				$current_words = 0;
			}

			$current[] = $block;
			$current_words += $block_words;
		}

		if (!empty($current)) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Builds the row data for one chunk from its block list.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array{content: string, chunk_html: string, chunk_blocks: string, block_count: int, block_types: string, word_count: int, character_count: int}
	 */
	private function build_chunk( array $blocks ): array {
		$content = $this->extract_text($blocks);

		$block_types = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn(array $b): string => (string) ($b['blockName'] ?? ''),
						$blocks
					)
				)
			)
		);

		return [
			'content'         => $content,
			'chunk_html'      => $this->extract_html($blocks),
			'chunk_blocks'    => (string) wp_json_encode( $blocks ),
			'block_count'     => count( $blocks ),
			'block_types'     => (string) wp_json_encode( $block_types ),
			'word_count'      => str_word_count( $content ),
			'character_count' => mb_strlen( $content ),
		];
	}
	
	private function extract_html(array $blocks): string
	{
		return implode(
			'',
			array_map(
				static fn(array $block): string => render_block($block),
				$blocks
			)
		);
	}
	

	// ------------------------------------------------------------------
	// Text extraction
	// ------------------------------------------------------------------

	/** @param array<int, array<string, mixed>> $blocks */
	private function extract_text( array $blocks ): string {
		$text = '';
		foreach ( $blocks as $block ) {
			$text .= ' ' . strip_tags( (string) ( $block['innerHTML'] ?? '' ) );
			if ( ! empty( $block['innerBlocks'] ) ) {
				$text .= $this->extract_text( (array) $block['innerBlocks'] );
			}
		}
		return trim( $text );
	}

	// ------------------------------------------------------------------
	// Classification
	// ------------------------------------------------------------------

	private function detect_page_type( object $post ): string {
		$haystack = strtolower(
			( (string) ( $post->post_title ?? '' ) ) . ' ' . ( (string) ( $post->post_name ?? '' ) )
		);

		foreach ( self::PAGE_TYPE_KEYWORDS as $type => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $haystack, $keyword ) ) {
					return $type;
				}
			}
		}

		return ( (string) ( $post->post_type ?? '' ) ) === 'post' ? 'blog' : 'landing';
	}

	// ------------------------------------------------------------------
	// Registry
	// ------------------------------------------------------------------

	/** @param array<int, array<string, mixed>> $blocks */
	private function walk_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			if ( is_string( $name ) && $name !== '' ) {
				if ( ! isset( $this->registry[ $name ] ) ) {
					$this->registry[ $name ] = [
						'count'       => 0,
						'sample_html' => (string) ( $block['innerHTML'] ?? '' ),
						'attrs'       => (array) ( $block['attrs'] ?? [] ),
					];
				}
				$this->registry[ $name ]['count']++;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( (array) $block['innerBlocks'] );
			}
		}
	}

	/**
	 * @return array<string, array{count: int, sample_html: string, attrs: array<string, mixed>}>
	 */
	public function get_registry(): array {
		return $this->registry;
	}
}
