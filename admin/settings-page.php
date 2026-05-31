<?php
/**
 * Settings page template — rendered by Admin::render_settings_page().
 * Variables in scope: $client_id, $scan_status, $scanned_at, $scan_error,
 *                     $sync_status, $synced_at, $sync_error.
 */

defined( 'ABSPATH' ) || exit;

$badge_map = [
    'complete'         => [ 'label' => 'Complete',         'class' => 'success' ],
    'error'            => [ 'label' => 'Error',            'class' => 'error' ],
    'scanning'         => [ 'label' => 'Scanning…',        'class' => 'info' ],
    'pending_settings' => [ 'label' => 'Pending settings', 'class' => 'warning' ],
    'pending'          => [ 'label' => 'Pending',          'class' => 'warning' ],
];

$scan_badge = $badge_map[ $scan_status ] ?? [ 'label' => ucfirst( $scan_status ), 'class' => 'warning' ];
$sync_badge = $badge_map[ $sync_status ] ?? [ 'label' => ucfirst( $sync_status ), 'class' => 'warning' ];

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$scanned_result = isset( $_GET['gutenbot_scanned'] ) ? sanitize_key( $_GET['gutenbot_scanned'] ) : '';
$synced_result  = isset( $_GET['gutenbot_synced'] )  ? sanitize_key( $_GET['gutenbot_synced'] )  : '';
// phpcs:enable

$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

$can_sync = get_option( 'aipb_edge_function_url', '' )
         && get_option( 'aipb_anon_key', '' )
         && get_option( 'aipb_provider', '' )
         && get_option( 'aipb_scan_status', '' ) === 'complete';
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <?php if ( $scanned_result === 'success' ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site scan complete.', 'gutenbot' ); ?></p></div>
    <?php elseif ( $scanned_result === 'error' ) : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Site scan failed. See details below.', 'gutenbot' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $synced_result === 'success' ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Supabase sync complete.', 'gutenbot' ); ?></p></div>
    <?php elseif ( $synced_result === 'error' ) : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Supabase sync failed. See details below.', 'gutenbot' ); ?></p></div>
    <?php endif; ?>

    <!-- Connection Settings -->
    <form method="post" action="options.php">
        <?php settings_fields( 'gutenbot_settings' ); ?>
        <h2><?php esc_html_e( 'Connection', 'gutenbot' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="aipb_edge_function_url"><?php esc_html_e( 'Edge Function URL', 'gutenbot' ); ?></label></th>
                <td>
                    <input type="url" id="aipb_edge_function_url" name="aipb_edge_function_url"
                        value="<?php echo esc_attr( get_option( 'aipb_edge_function_url', '' ) ); ?>"
                        class="regular-text"
                        placeholder="https://your-project.supabase.co/functions/v1/gutenbot" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="aipb_anon_key"><?php esc_html_e( 'Anon Key', 'gutenbot' ); ?></label></th>
                <td>
                    <input type="password" id="aipb_anon_key" name="aipb_anon_key"
                        value="<?php echo esc_attr( get_option( 'aipb_anon_key', '' ) ); ?>"
                        class="regular-text" autocomplete="new-password" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="aipb_provider"><?php esc_html_e( 'LLM Provider', 'gutenbot' ); ?></label></th>
                <td>
                    <select id="aipb_provider" name="aipb_provider">
                        <option value="anthropic" <?php selected( get_option( 'aipb_provider', 'anthropic' ), 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'gutenbot' ); ?></option>
                        <option value="openai"    <?php selected( get_option( 'aipb_provider', 'anthropic' ), 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'gutenbot' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php submit_button( __( 'Save Settings', 'gutenbot' ) ); ?>
    </form>

    <hr />

    <!-- Group 1: Site Scan -->
    <h2><?php esc_html_e( 'Site Scan', 'gutenbot' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Indexes your blocks, design tokens, and page patterns locally. Does not require connection settings.', 'gutenbot' ); ?></p>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'Status', 'gutenbot' ); ?></th>
            <td>
                <span id="gutenbot-scan-status" class="notice notice-<?php echo esc_attr( $scan_badge['class'] ); ?>" style="display:inline-block;padding:2px 10px;margin:0;border-left-width:4px;">
                    <?php echo esc_html( $scan_badge['label'] ); ?>
                </span>
            </td>
        </tr>
        <?php if ( $scanned_at ) : ?>
        <tr>
            <th scope="row"><?php esc_html_e( 'Last Scanned', 'gutenbot' ); ?></th>
            <td id="gutenbot-scanned-at"><?php echo esc_html( wp_date( $date_format, strtotime( $scanned_at ) ) ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( $scan_error ) : ?>
        <tr>
            <th scope="row"><?php esc_html_e( 'Error', 'gutenbot' ); ?></th>
            <td><span style="color:#d63638;"><?php echo esc_html( $scan_error ); ?></span></td>
        </tr>
        <?php endif; ?>
    </table>
    <div style="margin-top:1rem;">
        <progress id="gutenbot-scan-progress" max="1" value="0" style="display:none;width:100%;margin-bottom:.5rem;"></progress>
        <p id="gutenbot-scan-label" style="display:none;margin:.25rem 0 .75rem;"></p>
        <button type="button" id="gutenbot-scan-btn" class="button button-secondary">
            <?php esc_html_e( 'Scan Site', 'gutenbot' ); ?>
        </button>
    </div>

    <hr />

    <!-- Group 2: Supabase Sync -->
    <h2><?php esc_html_e( 'Supabase Sync', 'gutenbot' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Sends scan results to the edge function. Requires Edge Function URL, Anon Key, and LLM Provider to be saved.', 'gutenbot' ); ?></p>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'Status', 'gutenbot' ); ?></th>
            <td>
                <span id="gutenbot-sync-status" class="notice notice-<?php echo esc_attr( $sync_badge['class'] ); ?>" style="display:inline-block;padding:2px 10px;margin:0;border-left-width:4px;">
                    <?php echo esc_html( $sync_badge['label'] ); ?>
                </span>
            </td>
        </tr>
        <?php if ( $synced_at ) : ?>
        <tr>
            <th scope="row"><?php esc_html_e( 'Last Synced', 'gutenbot' ); ?></th>
            <td id="gutenbot-synced-at"><?php echo esc_html( wp_date( $date_format, strtotime( $synced_at ) ) ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th scope="row"><?php esc_html_e( 'Client UUID', 'gutenbot' ); ?></th>
            <td><code><?php echo esc_html( $client_id ?: __( 'Not yet assigned', 'gutenbot' ) ); ?></code></td>
        </tr>
        <?php if ( $sync_error ) : ?>
        <tr>
            <th scope="row"><?php esc_html_e( 'Error', 'gutenbot' ); ?></th>
            <td><span style="color:#d63638;"><?php echo esc_html( $sync_error ); ?></span></td>
        </tr>
        <?php endif; ?>
    </table>
    <?php if ( $can_sync ) : ?>
    <div style="margin-top:1rem;">
        <button type="button" id="gutenbot-sync-btn" class="button button-primary">
            <?php esc_html_e( 'Sync to Supabase', 'gutenbot' ); ?>
        </button>
    </div>
    <?php else : ?>
    <p class="description" style="margin-top:1rem;">
        <?php esc_html_e( 'Save Edge Function URL, Anon Key, and LLM Provider to enable syncing.', 'gutenbot' ); ?>
    </p>
    <?php endif; ?>

</div>
