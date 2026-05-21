<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.', 'gutenbot'));
}

global $wpdb;
$jobs = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}gutenbot_generation_jobs ORDER BY created_at DESC LIMIT 100",
    ARRAY_A
);

$status_labels = [
    'uploaded'      => __('Uploaded', 'gutenbot'),
    'processing'    => __('Processing', 'gutenbot'),
    'draft_created' => __('Draft Created', 'gutenbot'),
    'failed'        => __('Failed', 'gutenbot'),
];
?>
<div class="wrap">
    <h1><?php esc_html_e('GutenBot — Review Queue', 'gutenbot'); ?></h1>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('File', 'gutenbot'); ?></th>
                <th><?php esc_html_e('Page Type', 'gutenbot'); ?></th>
                <th><?php esc_html_e('Draft', 'gutenbot'); ?></th>
                <th><?php esc_html_e('Layout Source', 'gutenbot'); ?></th>
                <th><?php esc_html_e('Status', 'gutenbot'); ?></th>
                <th><?php esc_html_e('Error', 'gutenbot'); ?></th>
                <th><?php esc_html_e('Created', 'gutenbot'); ?></th>
                <th><?php esc_html_e('Actions', 'gutenbot'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($jobs)): ?>
            <tr><td colspan="8"><?php esc_html_e('No jobs yet.', 'gutenbot'); ?></td></tr>
        <?php else: ?>
            <?php foreach ($jobs as $job): ?>
            <tr>
                <td><?php echo esc_html($job['file_name']); ?></td>
                <td><?php echo esc_html($job['detected_page_type'] ?: '—'); ?></td>
                <td>
                    <?php if ($job['draft_post_id']): ?>
                        <a href="<?php echo esc_url(get_edit_post_link($job['draft_post_id'])); ?>">
                            <?php esc_html_e('Edit Draft', 'gutenbot'); ?>
                        </a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($job['layout_source_id']): ?>
                        <a href="<?php echo esc_url(get_permalink((int) $job['layout_source_id'])); ?>">
                            #<?php echo esc_html($job['layout_source_id']); ?>
                        </a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <span class="gutenbot-status gutenbot-status--<?php echo esc_attr($job['status']); ?>">
                        <?php echo esc_html($status_labels[$job['status']] ?? $job['status']); ?>
                    </span>
                </td>
                <td><?php echo esc_html($job['error_message'] ?: '—'); ?></td>
                <td><?php echo esc_html($job['created_at']); ?></td>
                <td>
                    <?php if ($job['status'] === 'uploaded'): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="gutenbot_process_job">
                            <input type="hidden" name="job_id" value="<?php echo esc_attr($job['id']); ?>">
                            <?php wp_nonce_field('gutenbot_process_job', 'gutenbot_nonce'); ?>
                            <?php submit_button(__('Process', 'gutenbot'), 'small', 'submit', false); ?>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
