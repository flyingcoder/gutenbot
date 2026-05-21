<?php
/**
 * Integration tests for draft page creation.
 *
 * @group integration
 */
class Test_Page_Creation extends WP_UnitTestCase {

    public function test_draft_page_created_with_correct_meta() {
        // Arrange
        $plan = [
            'page_type'     => 'service',
            'title'         => 'Test Service Page ' . uniqid(),
            'sections'      => ['hero', 'cta'],
            'layout_source' => null,
        ];

        $markup = GutenBot_Page_Generator::build($plan, []);

        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}gutenbot_generation_jobs",
            [
                'file_name'  => 'test-doc.md',
                'file_path'  => '/tmp/test-doc.md',
                'file_type'  => 'md',
                'status'     => 'processing',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]
        );
        $job_id = $wpdb->insert_id;

        // Act
        $post_id = GutenBot_Page_Generator::create_draft($plan, $markup, $job_id, 'test-doc.md');

        // Assert
        $this->assertGreaterThan(0, $post_id);
        $this->assertSame('test-doc.md', get_post_meta($post_id, '_gutenbot_source_file', true));
        $this->assertSame('service', get_post_meta($post_id, '_gutenbot_detected_page_type', true));
        $this->assertSame('draft', get_post($post_id)->post_status);
    }
}
