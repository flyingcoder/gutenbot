<?php
/**
 * Integration tests for the Indexer — requires WP test suite.
 *
 * @group integration
 */
class Test_Indexer_DB extends WP_UnitTestCase {

    public function test_indexer_writes_layout_row() {
        // Arrange — create a published page.
        $post_id = $this->factory->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
        ]);

        // Act
        GutenBot_Indexer::reindex_post(get_post($post_id));

        // Assert
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gutenbot_layout_index WHERE post_id = %d",
            $post_id
        ));

        $this->assertNotNull($row);
        $this->assertEquals($post_id, $row->post_id);
    }

    public function test_reindex_updates_existing_row() {
        // Arrange
        $post_id = $this->factory->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Initial</p><!-- /wp:paragraph -->',
        ]);
        $post = get_post($post_id);

        GutenBot_Indexer::reindex_post($post);

        global $wpdb;
        $first_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}gutenbot_layout_index WHERE post_id = %d",
                $post_id
            )
        );

        // Act — re-index the same page.
        GutenBot_Indexer::reindex_post($post);

        $second_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}gutenbot_layout_index WHERE post_id = %d",
                $post_id
            )
        );

        // Assert — still only one row.
        $this->assertSame(1, $first_count);
        $this->assertSame(1, $second_count);
    }
}
