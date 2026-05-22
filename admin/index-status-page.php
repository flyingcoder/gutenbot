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

$run_id = get_option('gutenbot_index_run_id', '');
$notice = $_GET['gutenbot_notice'] ?? '';
$total  = (int) ($_GET['total'] ?? 0);
?>
<div class="wrap">
    <h1><?php esc_html_e('GutenBot — Index Status', 'gutenbot'); ?></h1>

    <?php if ($notice === 'queued'): ?>
        <div class="notice notice-info is-dismissible">
            <p><?php printf(
                esc_html__('Re-index queued: %d items will be processed in the background.', 'gutenbot'),
                $total
            ); ?></p>
        </div>
    <?php elseif ($notice === 'reindexed'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Full re-index complete.', 'gutenbot'); ?></p>
        </div>
    <?php endif; ?>

    <div id="gutenbot-index-progress-wrap" <?php echo $run_id ? '' : 'style="display:none"'; ?>>
        <h2><?php esc_html_e('Indexing in Progress', 'gutenbot'); ?></h2>
        <p id="gutenbot-index-progress-label"><?php esc_html_e('Starting…', 'gutenbot'); ?></p>
        <progress id="gutenbot-index-bar" max="100" value="0" style="width:100%;height:20px;"></progress>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
            <input type="hidden" name="action" value="gutenbot_reset_index">
            <?php wp_nonce_field('gutenbot_reset_index', 'gutenbot_nonce'); ?>
            <?php submit_button(__('Reset Stuck Index', 'gutenbot'), 'secondary small', 'submit', false); ?>
        </form>
    </div>

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
        <?php submit_button(
            $run_id ? __('Re-Index Running…', 'gutenbot') : __('Run Full Re-Index', 'gutenbot'),
            'primary',
            'submit',
            true,
            $run_id ? ['disabled' => 'disabled'] : []
        ); ?>
    </form>
</div>

<?php if ($run_id): ?>
<script>
window.gutenbotIndex = {
    runId:   <?php echo wp_json_encode($run_id); ?>,
    ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
    nonce:   <?php echo wp_json_encode(wp_create_nonce('gutenbot_index_progress')); ?>
};
</script>
<?php endif; ?>
