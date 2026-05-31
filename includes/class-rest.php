<?php

declare(strict_types=1);

namespace GutenBot;

class Rest {

	private const NAMESPACE    = 'ai-pagebuilder/v1';
	private const PAGE_TYPES   = [ 'landing', 'about', 'services', 'contact', 'blog', 'custom' ];
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	private AiClient $ai_client;

	public function __construct( ?AiClient $ai_client = null ) {
		$this->ai_client = $ai_client ?? new AiClient();
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_status' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/generate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'generate' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'content'   => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => static function ( $value ): bool {
						$len = strlen( (string) $value );
						return $len > 0 && $len <= 5000;
					},
				],
				'page_type' => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => static function ( $value ): bool {
						return in_array( (string) $value, self::PAGE_TYPES, true );
					},
				],
				'post_id'   => [
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => static function ( $value ): bool {
						return is_numeric( $value ) && (int) $value > 0;
					},
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/rate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rate' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'section_id' => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => static function ( $value ): bool {
						return (bool) preg_match( self::UUID_PATTERN, (string) $value );
					},
				],
				'rating'     => [
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => static function ( $value ): bool {
						return in_array( (int) $value, [ 1, -1 ], true );
					},
				],
				'was_edited' => [
					'required' => true,
					'type'     => 'boolean',
				],
			],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( 'edit_pages' );
	}

	public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
		$edge_url = get_option( 'aipb_edge_function_url', '' );

		return new \WP_REST_Response(
			[
				'success' => true,
				'data'    => [
					'client_id'         => get_option( 'aipb_client_id', '' ),
					'onboarding_status' => get_option( 'aipb_onboarding_status', 'pending' ),
					'onboarded_at'      => get_option( 'aipb_onboarded_at', null ),
					'edge_function_url' => $edge_url ? substr( $edge_url, 0, 30 ) . '...' : '',
				],
			],
			200
		);
	}

	public function generate( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->ai_client->generate(
			(string) $request->get_param( 'content' ),
			(string) $request->get_param( 'page_type' ),
			(int) $request->get_param( 'post_id' )
		);

		$status = ( $result['success'] ?? false ) ? 200 : 500;

		return new \WP_REST_Response( $result, $status );
	}

	public function rate( \WP_REST_Request $request ): \WP_REST_Response {
		$success = $this->ai_client->rate(
			(string) $request->get_param( 'section_id' ),
			(int) $request->get_param( 'rating' ),
			(bool) $request->get_param( 'was_edited' )
		);

		return new \WP_REST_Response( [ 'success' => $success ], $success ? 200 : 500 );
	}
}
