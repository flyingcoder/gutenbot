<?php

declare(strict_types=1);

namespace GutenBot;

class Hooks {

	private Admin $admin;
	private Rest $rest;

	public function __construct() {
		$this->admin = new Admin();
		$this->rest  = new Rest();
	}

	public function register(): void {
		add_action( 'init', [ $this, 'on_init' ] );
		add_action( 'rest_api_init', [ $this->rest, 'register_routes' ] );
		add_action( 'admin_menu', [ $this->admin, 'register_settings_page' ] );
		add_action( 'admin_init', [ $this->admin, 'register_settings' ] );
		add_action( 'admin_init', [ Activator::class, 'maybe_upgrade' ] );
		add_action( 'admin_notices', [ $this->admin, 'show_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this->admin, 'enqueue_settings_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'aipb_site_scan', [ $this, 'run_site_scan' ] );
		add_action( 'aipb_supabase_sync', [ $this, 'run_supabase_sync' ] );
		add_action( 'aipb_scan_next', [ $this, 'run_scan_next' ] );
		add_action( 'update_option_aipb_edge_function_url', [ $this, 'on_connection_setting_saved' ] );
		add_action( 'update_option_aipb_anon_key', [ $this, 'on_connection_setting_saved' ] );
		add_action( 'admin_post_gutenbot_sync_supabase', [ $this->admin, 'handle_sync_supabase' ] );
		add_action( 'wp_ajax_gutenbot_scan_init',     [ $this, 'ajax_scan_init' ] );
		add_action( 'wp_ajax_gutenbot_scan_page',     [ $this, 'ajax_scan_page' ] );
		add_action( 'wp_ajax_gutenbot_scan_finalize', [ $this, 'ajax_scan_finalize' ] );
		add_action( 'wp_ajax_gutenbot_sync_supabase', [ $this, 'ajax_sync_supabase' ] );
	}

	public function enqueue_editor_assets(): void {
		$asset_file = GUTENBOT_PLUGIN_DIR . 'assets/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [ 'dependencies' => [], 'version' => GUTENBOT_VERSION ];

		wp_enqueue_script(
			'gutenbot-sidebar',
			GUTENBOT_PLUGIN_URL . 'assets/index.js',
			$asset['dependencies'],
			$asset['version'],
			false
		);

		if ( file_exists( GUTENBOT_PLUGIN_DIR . 'assets/index.css' ) ) {
			wp_enqueue_style(
				'gutenbot-sidebar',
				GUTENBOT_PLUGIN_URL . 'assets/index.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}
	}

	public function on_init(): void {}

	public function run_site_scan(): void {
		try {
			$indexer = new Indexer();
			$indexer->scan_init();
			if ( ! wp_next_scheduled( 'aipb_scan_next' ) ) {
				wp_schedule_single_event( time() + 1, 'aipb_scan_next' );
			}
		} catch ( \Throwable $e ) {
			update_option( 'aipb_scan_status', 'error', false );
			update_option( 'aipb_scan_error', $e->getMessage(), false );
		}
	}

	public function run_scan_next(): void {
		$queue = json_decode( (string) get_option( 'aipb_scan_queue', '[]' ), true ) ?: [];

		if ( empty( $queue ) ) {
			try {
				( new Indexer() )->scan_finalize();
			} catch ( \Throwable $e ) {
				update_option( 'aipb_scan_status', 'error', false );
				update_option( 'aipb_scan_error', $e->getMessage(), false );
			}
			return;
		}

		$post_id = (int) array_values( $queue )[0];

		try {
			( new Indexer() )->scan_page( $post_id );
		} catch ( \Throwable $e ) {
			update_option( 'aipb_scan_status', 'error', false );
			update_option( 'aipb_scan_error', $e->getMessage(), false );
			return;
		}

		$remaining = json_decode( (string) get_option( 'aipb_scan_queue', '[]' ), true ) ?: [];

		if ( ! empty( $remaining ) ) {
			wp_schedule_single_event( time() + 1, 'aipb_scan_next' );
		} else {
			try {
				( new Indexer() )->scan_finalize();
			} catch ( \Throwable $e ) {
				update_option( 'aipb_scan_status', 'error', false );
				update_option( 'aipb_scan_error', $e->getMessage(), false );
			}
		}
	}

	public function ajax_scan_init(): void {
		check_ajax_referer( 'gutenbot_scan_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		try {
			$result = ( new Indexer() )->scan_init();
			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_scan_page(): void {
		check_ajax_referer( 'gutenbot_scan_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$queue = json_decode( (string) get_option( 'aipb_scan_queue', '[]' ), true ) ?: [];

		if ( empty( $queue ) ) {
			wp_send_json_error( [ 'message' => 'Queue is empty.' ] );
		}

		$post_id = (int) array_values( $queue )[0];

		try {
			$result = ( new Indexer() )->scan_page( $post_id );
			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_scan_finalize(): void {
		check_ajax_referer( 'gutenbot_scan_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		try {
			$result = ( new Indexer() )->scan_finalize();
			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_sync_supabase(): void {
		check_ajax_referer( 'gutenbot_sync_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$this->run_supabase_sync();

		$status = get_option( 'aipb_sync_status', 'error' );

		$raw = get_option( 'aipb_sync_raw_response', '' );

		if ( $status === 'complete' ) {
			wp_send_json_success( [ 'synced_at' => get_option( 'aipb_synced_at', '' ) ] );
		} else {
			wp_send_json_error( [
				'message'  => get_option( 'aipb_sync_error', 'Unknown error.' ),
				'response' => $raw ? json_decode( $raw, true ) : null,
			] );
		}
	}

	public function run_supabase_sync(): void {
		$edge_url = get_option( 'aipb_edge_function_url', '' );
		$anon_key = get_option( 'aipb_anon_key', '' );

		if ( empty( $edge_url ) || empty( $anon_key ) ) {
			update_option( 'aipb_sync_status', 'pending_settings', false );
			return;
		}

		$registry      = get_option( 'aipb_block_registry', [] );
		$tokens        = get_option( 'aipb_design_tokens', [] );
		$patterns      = get_option( 'aipb_patterns', [] );
		$indexed_pages = get_option( 'aipb_indexed_pages', [] );

		if ( empty( $registry ) ) {
			update_option( 'aipb_sync_status', 'error', false );
			update_option( 'aipb_sync_error', 'No scan data found. Run a site scan first.', false );
			return;
		}

		try {
			$sync = ( new AiClient() )->sync_client( $registry, $tokens, $patterns, $indexed_pages );

			update_option( 'aipb_sync_raw_response', wp_json_encode( $sync ), false );

			if ( $sync['success'] ?? false ) {
				if ( ! empty( $sync['data']['client_id'] ) ) {
					update_option( 'aipb_client_id', $sync['data']['client_id'], false );
				}
				update_option( 'aipb_sync_status', 'complete', false );
				update_option( 'aipb_synced_at', current_time( 'mysql', true ), false );
				delete_option( 'aipb_sync_error' );
			} else {
				$sync_error = $sync['error'] ?? 'Unknown error.';
				update_option( 'aipb_sync_status', 'error', false );
				update_option( 'aipb_sync_error', is_string( $sync_error ) ? $sync_error : wp_json_encode( $sync_error ), false );
			}
		} catch ( \Throwable $e ) {
			update_option( 'aipb_sync_raw_response', wp_json_encode( [ 'exception' => $e->getMessage() ] ), false );
			update_option( 'aipb_sync_status', 'error', false );
			update_option( 'aipb_sync_error', $e->getMessage(), false );
		}
	}

	public function on_connection_setting_saved(): void {
		update_option( 'aipb_sync_status', 'pending', false );
	}
}
