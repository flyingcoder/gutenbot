<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.', 'gutenbot'));
}

global $wpdb;
$layout_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gutenbot_layout_index");
$section_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gutenbot_section_index");
$style_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gutenbot_style_index");
$last_indexed  = $wpdb->get_var("SELECT MAX(indexed_at) FROM {$wpdb->prefix}gutenbot_layout_index");

$notice = $_GET['gutenbot_notice'] ?? '';
?>
<div class="wrap">
    <h1><?php esc_html_e('GutenBot — Index Status', 'gutenbot'); ?></h1>

    <?php if ($notice === 'reindexed'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Full re-index complete.', 'gutenbot'); ?></p>
        </div>
    <?php endif; ?>

    <table class="form-table">
        <tr>
            <th><?php esc_html_e('Indexed Layouts', 'gutenbot'); ?></th>
            <td><?php echo esc_html($layout_count); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Indexed Sections', 'gutenbot'); ?></th>
            <td><?php echo esc_html($section_count); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Style Entries', 'gutenbot'); ?></th>
            <td><?php echo esc_html($style_count); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Last Indexed', 'gutenbot'); ?></th>
            <td><?php echo $last_indexed ? esc_html($last_indexed) : esc_html__('Never', 'gutenbot'); ?></td>
        </tr>
    </table>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="gutenbot_reindex">
        <?php wp_nonce_field('gutenbot_reindex', 'gutenbot_nonce'); ?>
        <?php submit_button(__('Run Full Re-Index', 'gutenbot'), 'primary'); ?>
    </form>
</div>
