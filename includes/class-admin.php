<?php

declare(strict_types=1);

namespace GutenBot;

class Admin {

    public function register_settings_page(): void {
        add_options_page(
            __( 'AI Page Builder', 'gutenbot' ),
            __( 'AI Page Builder', 'gutenbot' ),
            'manage_options',
            'gutenbot-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting(
            'gutenbot_settings',
            'aipb_edge_function_url',
            [
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => '',
            ]
        );

        register_setting(
            'gutenbot_settings',
            'aipb_anon_key',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        register_setting(
            'gutenbot_settings',
            'aipb_provider',
            [
                'type'              => 'string',
                'sanitize_callback' => static function ( string $value ): string {
                    return in_array( $value, [ 'anthropic', 'openai' ], true ) ? $value : 'anthropic';
                },
                'default'           => 'anthropic',
            ]
        );
    }

    public function enqueue_settings_assets( string $hook ): void {
        if ( $hook !== 'settings_page_gutenbot-settings' ) {
            return;
        }

        $asset_file = GUTENBOT_PLUGIN_DIR . 'assets/admin.asset.php';
        $asset      = file_exists( $asset_file )
            ? require $asset_file
            : [ 'dependencies' => [ 'jquery' ], 'version' => GUTENBOT_VERSION ];

        wp_enqueue_script(
            'gutenbot-admin',
            GUTENBOT_PLUGIN_URL . 'assets/admin.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_localize_script( 'gutenbot-admin', 'gutenbotData', [
            'ajaxurl'   => admin_url( 'admin-ajax.php' ),
            'scanNonce' => wp_create_nonce( 'gutenbot_scan_nonce' ),
            'syncNonce' => wp_create_nonce( 'gutenbot_sync_nonce' ),
        ] );
    }

    public function handle_sync_supabase(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'gutenbot' ) );
        }

        check_admin_referer( 'gutenbot_sync_supabase' );

        do_action( 'aipb_supabase_sync' );

        $result = get_option( 'aipb_sync_status', 'error' ) === 'complete' ? 'success' : 'error';

        wp_safe_redirect(
            add_query_arg( 'gutenbot_synced', $result, admin_url( 'options-general.php?page=gutenbot-settings' ) )
        );
        exit;
    }

    public static function is_local_mode(): bool {
        return defined( 'GUTENBOT_LOCAL_MODE' ) && (bool) GUTENBOT_LOCAL_MODE;
    }

    public function render_settings_page(): void {
        $client_id  = get_option( 'aipb_client_id', '' );
        $scan_status  = get_option( 'aipb_scan_status', 'pending' );
        $scanned_at   = get_option( 'aipb_scanned_at', '' );
        $scan_error   = get_option( 'aipb_scan_error', '' );
        $sync_status  = get_option( 'aipb_sync_status', 'pending_settings' );
        $synced_at    = get_option( 'aipb_synced_at', '' );
        $sync_error   = get_option( 'aipb_sync_error', '' );

        include GUTENBOT_PLUGIN_DIR . 'admin/settings-page.php';
    }

    public function show_notices(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $scan_status = get_option( 'aipb_scan_status', 'pending' );

        if ( $scan_status !== 'complete' ) {
            $settings_url = admin_url( 'options-general.php?page=gutenbot-settings' );
            printf(
                '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
                wp_kses(
                    sprintf(
                        /* translators: %s: link to settings page */
                        __( 'GutenBot: Run a <a href="%s">site scan</a> to index your blocks before using AI page generation.', 'gutenbot' ),
                        esc_url( $settings_url )
                    ),
                    [ 'a' => [ 'href' => [] ] ]
                )
            );
        }
    }
}
