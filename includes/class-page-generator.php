<?php
defined('ABSPATH') || exit;

class GutenBot_Page_Generator {

    private static $fallback_templates = [
        'hero' => '<!-- wp:group {"className":"hero-section"} --><div class="wp-block-group hero-section"><!-- wp:heading {"level":1} --><h1>%s</h1><!-- /wp:heading --><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        'intro' => '<!-- wp:group {"className":"intro-section"} --><div class="wp-block-group intro-section"><!-- wp:heading {"level":2} --><h2>Overview</h2><!-- /wp:heading --><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        'benefits' => '<!-- wp:group {"className":"benefits-section"} --><div class="wp-block-group benefits-section"><!-- wp:heading {"level":2} --><h2>Benefits</h2><!-- /wp:heading --><!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>%s</li><!-- /wp:list-item --></ul><!-- /wp:list --></div><!-- /wp:group -->',
        'process' => '<!-- wp:group {"className":"process-section"} --><div class="wp-block-group process-section"><!-- wp:heading {"level":2} --><h2>Our Process</h2><!-- /wp:heading --><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        'faq' => '<!-- wp:group {"className":"faq-section"} --><div class="wp-block-group faq-section"><!-- wp:heading {"level":2} --><h2>Frequently Asked Questions</h2><!-- /wp:heading --><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        'cta' => '<!-- wp:group {"className":"cta-section"} --><div class="wp-block-group cta-section"><!-- wp:heading {"level":2} --><h2>Get Started Today</h2><!-- /wp:heading --><!-- wp:buttons --><!-- wp:button --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Contact Us</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->',
        'columns' => '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Column 2</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->',
    ];

    public static function build(array $plan, array $reusable_sections) {
        $title         = $plan['title'];
        $sections      = $plan['sections'];
        $layout_source = $plan['layout_source'] ?? null;

        self::assert_unique_title($title);

        $markup_parts = [];

        if ($layout_source) {
            $layout = GutenBot_Indexer::get_layout_by_id((int) $layout_source);
            if ($layout) {
                $markup_parts[] = self::build_from_layout($layout, $title, $plan);
                $full_markup    = implode("\n", $markup_parts);
                self::validate_markup($full_markup);
                return $full_markup;
            }
        }

        $h1_placed = false;
        foreach ($sections as $section_type) {
            $block = self::build_section($section_type, $title, $reusable_sections, $h1_placed);
            if (!$h1_placed && strpos($block, '"level":1') !== false) {
                $h1_placed = true;
            }
            $markup_parts[] = $block;
        }

        if (!$h1_placed && !empty($markup_parts)) {
            $h1_block    = sprintf(
                '<!-- wp:heading {"level":1} --><h1>%s</h1><!-- /wp:heading -->',
                esc_html($title)
            );
            array_unshift($markup_parts, $h1_block);
        }

        $full_markup = implode("\n", $markup_parts);
        self::validate_markup($full_markup);
        return $full_markup;
    }

    private static function build_from_layout(array $layout, string $title, array $plan) {
        $section_order = json_decode($layout['section_order'], true);
        $parts         = [];
        $h1_placed     = false;

        foreach (($section_order ?: $plan['sections']) as $section_type) {
            $block = self::build_section($section_type, $title, [], $h1_placed);
            if (!$h1_placed && strpos($block, '"level":1') !== false) {
                $h1_placed = true;
            }
            $parts[] = $block;
        }

        if (!$h1_placed) {
            $h1_block = sprintf(
                '<!-- wp:heading {"level":1} --><h1>%s</h1><!-- /wp:heading -->',
                esc_html($title)
            );
            array_unshift($parts, $h1_block);
        }

        return implode("\n", $parts);
    }

    private static function build_section(
        string $section_type,
        string $title,
        array $reusable_sections,
        bool $h1_placed
    ) {
        // Priority 1: reuse stored section from index.
        foreach ($reusable_sections as $s) {
            if ($s['section_type'] === $section_type && !empty($s['block_markup'])) {
                return $s['block_markup'];
            }
        }

        // Priority 2: fallback template.
        if (isset(self::$fallback_templates[$section_type])) {
            $tpl = self::$fallback_templates[$section_type];
            if ($section_type === 'hero') {
                return sprintf($tpl, esc_html($title), esc_html($title . ' — professional and reliable.'));
            }
            return sprintf($tpl, esc_html($title));
        }

        // Priority 3: minimal generic block.
        return sprintf(
            '<!-- wp:group {"className":"%s-section"} --><div class="wp-block-group %s-section"><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
            esc_attr($section_type),
            esc_attr($section_type),
            esc_html(ucfirst($section_type) . ' content goes here.')
        );
    }

    private static function validate_markup(string $markup) {
        if (function_exists('parse_blocks')) {
            $blocks = parse_blocks($markup);
            foreach ($blocks as $block) {
                if ($block['blockName'] === null && trim($block['innerHTML']) === '') {
                    // Empty filler node — acceptable.
                }
            }
        }

        $h1_count = substr_count($markup, '"level":1');
        if ($h1_count !== 1) {
            throw new RuntimeException(
                sprintf('Generated markup must contain exactly one H1; found %d.', $h1_count)
            );
        }
    }

    private static function assert_unique_title(string $title) {
        $existing = get_page_by_title($title, OBJECT, 'page');
        if ($existing && $existing->post_status === 'publish') {
            throw new RuntimeException(
                sprintf('A published page already exists with the title "%s".', esc_html($title))
            );
        }
    }

    public static function create_draft(array $plan, string $markup, int $job_id, string $file_name) {
        global $wpdb;

        $post_id = wp_insert_post([
            'post_title'   => wp_strip_all_tags($plan['title']),
            'post_content' => $markup,
            'post_status'  => 'draft',
            'post_type'    => 'page',
        ]);

        if (is_wp_error($post_id)) {
            throw new RuntimeException('wp_insert_post failed: ' . $post_id->get_error_message());
        }

        update_post_meta($post_id, '_gutenbot_source_file', $file_name);
        update_post_meta($post_id, '_gutenbot_detected_page_type', $plan['page_type']);
        update_post_meta($post_id, '_gutenbot_layout_source', $plan['layout_source'] ?? '');
        update_post_meta($post_id, '_gutenbot_generation_log', wp_json_encode($plan));

        $wpdb->update(
            "{$wpdb->prefix}gutenbot_generation_jobs",
            [
                'status'        => 'draft_created',
                'draft_post_id' => $post_id,
                'updated_at'    => current_time('mysql'),
            ],
            ['id' => $job_id]
        );

        return $post_id;
    }
}
