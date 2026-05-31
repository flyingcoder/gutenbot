<?php

declare(strict_types=1);

namespace GutenBot;

class AiClient {

	private const TIMEOUT = 25;

	private string $edge_url;
	private string $client_id;
	private string $anon_key;
	private string $provider;

	public function __construct() {
		$this->edge_url  = (string) get_option( 'aipb_edge_function_url', '' );
		$this->client_id = (string) get_option( 'aipb_client_id', '' );
		$this->anon_key  = (string) get_option( 'aipb_anon_key', '' );
		$this->provider  = (string) get_option( 'aipb_provider', 'anthropic' );
	}

	// ------------------------------------------------------------------
	// Onboarding
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $registry
	 * @param array<string, mixed> $tokens
	 * @param array<int, array{section_type: string, block_name: string, sample_markup: string}> $patterns
	 * @param array<int, array{wp_post_id: int, page_type: string, section_order: array<string>, sections: array<mixed>}> $indexed_pages
	 */
	/**
	 * @return array{success: bool, error?: string}
	 */
	public function sync_client( array $registry, array $tokens, array $patterns, array $indexed_pages ): array {
		return $this->post( 'sync_client', [
			'client_id'      => $this->client_id,
			'site_url'       => get_site_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'theme'          => get_template(),
			'page_builder'   => $this->detect_page_builder(),
			'block_registry' => $registry,
			'design_tokens'  => $tokens,
			'patterns'       => $patterns,
			'indexed_pages'  => $indexed_pages,
		] );
	}

	// ------------------------------------------------------------------
	// Page generation
	// ------------------------------------------------------------------

	/**
	 * @return array{success: bool, data?: array{sections: array<mixed>, full_markup: string}, error?: string}
	 */
	public function generate( string $content, string $page_type, int $wp_post_id ): array {
		$patterns = $this->fetch_best_patterns();
		$sections = $this->analyse_content( $content, $page_type );

		if ( empty( $sections ) ) {
			return [ 'success' => false, 'error' => 'Content analysis returned no sections.' ];
		}

		$start     = microtime( true );
		$generated = $this->generate_all_blocks( $sections, $patterns );
		$latency   = ( microtime( true ) - $start ) * 1000;

		$full_markup = implode( "\n", array_column( $generated, 'block_markup' ) );
		$page_id     = $this->log_sections( $wp_post_id, $page_type, $generated );

		$this->log_generation( $page_id, round( $latency, 2 ) );

		return [
			'success' => true,
			'data'    => [
				'sections'    => $generated,
				'full_markup' => $full_markup,
			],
		];
	}

	public function rate( string $section_id, int $rating, bool $was_edited ): bool {
		$response = $this->post( 'update_rating', [
			'client_id'  => $this->client_id,
			'section_id' => $section_id,
			'rating'     => $rating,
			'was_edited' => $was_edited,
		] );

		return (bool) ( $response['success'] ?? false );
	}

	// ------------------------------------------------------------------
	// Private — generation helpers
	// ------------------------------------------------------------------

	/** @return array<string, array<string, mixed>> keyed by section_type */
	private function fetch_best_patterns(): array {
		$response = $this->post( 'get_best_patterns', [ 'client_id' => $this->client_id ] );
		$list     = $response['data']['patterns'] ?? [];

		$by_type = [];
		foreach ( $list as $pattern ) {
			$by_type[ $pattern['section_type'] ] = $pattern;
		}

		return $by_type;
	}

	/** @return array<int, array<string, mixed>> */
	private function analyse_content( string $content, string $page_type ): array {
		$response = $this->post( 'analyse_content', [
			'client_id' => $this->client_id,
			'content'   => $content,
			'page_type' => $page_type,
		] );

		return $response['data']['sections'] ?? [];
	}

	/**
	 * @param array<int, array<string, mixed>> $sections
	 * @param array<string, array<string, mixed>> $patterns keyed by section_type
	 * @return array<int, array<string, mixed>>
	 */
	private function generate_all_blocks( array $sections, array $patterns ): array {
		$generated = [];

		foreach ( $sections as $section ) {
			$pattern    = $patterns[ $section['section_type'] ] ?? null;
			$markup     = $this->generate_section_blocks( $section, $pattern );
			$block_name = $pattern['block_name'] ?? 'core/group';

			$generated[] = array_merge( $section, [
				'block_name'   => $block_name,
				'block_markup' => $markup,
			] );
		}

		return $generated;
	}

	/**
	 * @param array<string, mixed> $section
	 * @param array<string, mixed>|null $pattern
	 */
	private function generate_section_blocks( array $section, ?array $pattern ): string {
		$response = $this->post( 'generate_blocks', [
			'client_id' => $this->client_id,
			'section'   => $section,
			'pattern'   => $pattern,
		] );

		return (string) ( $response['data']['markup'] ?? '' );
	}

	/**
	 * @param array<int, array<string, mixed>> $sections
	 */
	private function log_sections( int $wp_post_id, string $page_type, array $sections ): ?string {
		$rows = array_values( array_map( static function ( array $s, int $i ): array {
			return [
				'section_type' => $s['section_type'],
				'position'     => $i + 1,
				'headline'     => (string) ( $s['headline'] ?? '' ),
				'body_text'    => (string) ( $s['body_text'] ?? '' ),
				'block_name'   => (string) ( $s['block_name'] ?? '' ),
				'block_markup' => (string) ( $s['block_markup'] ?? '' ),
			];
		}, $sections, array_keys( $sections ) ) );

		$response = $this->post( 'log_sections', [
			'client_id'  => $this->client_id,
			'wp_post_id' => $wp_post_id,
			'page_type'  => $page_type,
			'sections'   => $rows,
		] );

		return $response['data']['page_id'] ?? null;
	}

	private function log_generation( ?string $page_id, float $latency_ms ): void {
		if ( $page_id === null ) {
			return;
		}

		$this->post( 'log_generation', [
			'client_id'  => $this->client_id,
			'page_id'    => $page_id,
			'latency_ms' => $latency_ms,
			'model'      => Admin::is_local_mode() ? 'ollama' : 'claude-sonnet-4-6',
		] );
	}

	// ------------------------------------------------------------------
	// Private — HTTP
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function post( string $action, array $body ): array {
		if ( empty( $this->edge_url ) ) {
			return [ 'success' => false, 'error' => 'Edge Function URL not configured.' ];
		}

		$body['action']    = $action;
		$body['provider']  = $this->provider;
		$body['localMode'] = Admin::is_local_mode();

		$headers = [ 'Content-Type' => 'application/json' ];
		if ( $this->anon_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $this->anon_key;
		}

		$response = wp_remote_post( $this->edge_url, [
			'timeout'     => self::TIMEOUT,
			'headers'     => $headers,
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$snippet = substr( $raw, 0, 300 );
			return [ 'success' => false, 'error' => "HTTP {$status}: " . ( $snippet ?: 'Empty response.' ) ];
		}

		// Normalise error field — Supabase and other APIs use different keys.
		if ( empty( $data['success'] ) && empty( $data['error'] ) ) {
			$message = $data['message'] ?? $data['msg'] ?? $data['detail'] ?? null;
			if ( $message !== null ) {
				$data['error'] = is_string( $message ) ? $message : wp_json_encode( $message );
			} elseif ( (int) $status >= 400 ) {
				$data['error'] = "HTTP {$status}: " . substr( $raw, 0, 300 );
			}
		}

		return $data;
	}

	private function detect_page_builder(): string {
		return 'core';
	}
}
