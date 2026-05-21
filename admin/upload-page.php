<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.', 'gutenbot'));
}

$notice = $_GET['gutenbot_notice'] ?? '';
$count  = (int) ($_GET['count'] ?? 0);
?>
<div class="wrap">
    <h1><?php esc_html_e('GutenBot — Upload Documents', 'gutenbot'); ?></h1>

    <?php if ($notice === 'uploaded'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(esc_html__('%d file(s) queued for processing.', 'gutenbot'), $count); ?></p>
        </div>
    <?php elseif ($notice === 'no_files'): ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e('No files were selected.', 'gutenbot'); ?></p>
        </div>
    <?php endif; ?>

    <div class="gutenbot-upload-card">
        <p><?php esc_html_e('Upload .txt or .md documents to generate draft pages. Maximum file size: 10 MB.', 'gutenbot'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="gutenbot_upload">
            <?php wp_nonce_field('gutenbot_upload', 'gutenbot_nonce'); ?>

            <div class="gutenbot-drop-zone" id="gutenbot-drop-zone">
                <p><?php esc_html_e('Drag files here or click to browse', 'gutenbot'); ?></p>
                <input type="file" name="gutenbot_files[]" id="gutenbot-file-input" multiple accept=".txt,.md">
            </div>

            <ul id="gutenbot-file-list" class="gutenbot-file-list"></ul>

            <?php submit_button(__('Upload and Queue', 'gutenbot')); ?>
        </form>
    </div>
</div>
