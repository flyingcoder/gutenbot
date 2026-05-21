<?php
/**
 * Integration tests for the auto re-index hook.
 *
 * @group integration
 */
class Test_Reindex_Hook extends WP_UnitTestCase {

    public function test_reindex_fires_on_publish_transition() {
        // Arrange — register the hook (normally done in plugins_loaded).
        GutenBot_Hooks::register();

        $post_id = $this->factory->post->create([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_content' => '<!-- wp:paragraph --><p>Draft content</p><!-- /wp:paragraph -->',
        ]);

        // Act — transition to publish.
        wp_publish_post($post_id);

        // Assert — a layout row now exists.
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}gutenbot_layout_index WHERE post_id = %d",
                $post_id
            )
        );

        $this->assertGreaterThan(0, $count);
    }
}
