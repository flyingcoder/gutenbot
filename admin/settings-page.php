<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.', 'gutenbot'));
}

if (isset($_POST['gutenbot_save_settings'])) {
    check_admin_referer('gutenbot_settings', 'gutenbot_nonce');

    update_option('gutenbot_ai_api_key', sanitize_text_field($_POST['gutenbot_ai_api_key'] ?? ''));
    update_option('gutenbot_ai_api_endpoint', esc_url_raw($_POST['gutenbot_ai_api_endpoint'] ?? ''));
    update_option('gutenbot_ai_model', sanitize_text_field($_POST['gutenbot_ai_model'] ?? ''));
    update_option('gutenbot_file_size_limit', max(1, (int) ($_POST['gutenbot_file_size_limit'] ?? 10485760)));

    $saved = true;
}

$api_key    = get_option('gutenbot_ai_api_key', '');
$endpoint   = get_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
$model      = get_option('gutenbot_ai_model', 'claude-sonnet-4-6');
$size_limit = (int) get_option('gutenbot_file_size_limit', 10485760);
?>
<div class="wrap">
    <h1><?php esc_html_e('GutenBot — Settings', 'gutenbot'); ?></h1>

    <?php if (!empty($saved)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Settings saved.', 'gutenbot'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field('gutenbot_settings', 'gutenbot_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="gutenbot_ai_api_key"><?php esc_html_e('AI API Key', 'gutenbot'); ?></label></th>
                <td>
                    <input type="password" id="gutenbot_ai_api_key" name="gutenbot_ai_api_key"
                        value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Your Anthropic API key.', 'gutenbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_ai_api_endpoint"><?php esc_html_e('API Endpoint', 'gutenbot'); ?></label></th>
                <td>
                    <input type="url" id="gutenbot_ai_api_endpoint" name="gutenbot_ai_api_endpoint"
                        value="<?php echo esc_attr($endpoint); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_ai_model"><?php esc_html_e('AI Model', 'gutenbot'); ?></label></th>
                <td>
                    <input type="text" id="gutenbot_ai_model" name="gutenbot_ai_model"
                        value="<?php echo esc_attr($model); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('E.g., claude-sonnet-4-6', 'gutenbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_file_size_limit"><?php esc_html_e('Max File Size (bytes)', 'gutenbot'); ?></label></th>
                <td>
                    <input type="number" id="gutenbot_file_size_limit" name="gutenbot_file_size_limit"
                        value="<?php echo esc_attr($size_limit); ?>" class="small-text" min="1">
                    <p class="description"><?php esc_html_e('Default: 10485760 (10 MB)', 'gutenbot'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Settings', 'gutenbot'), 'primary', 'gutenbot_save_settings'); ?>
    </form>
</div>
