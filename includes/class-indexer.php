<?php
defined('ABSPATH') || exit;

class GutenBot_Indexer {

    private static $service_keywords = [
        'service', 'services', 'installation', 'repair', 'replacement',
        'maintenance', 'cleaning', 'painting', 'plumbing', 'electrical',
        'roofing', 'landscaping', 'moving', 'consulting',
    ];

    private static $location_keywords = [
        'location', 'locations', 'area', 'city', 'county',
        'near', 'local', 'dallas', 'houston', 'austin',
    ];

    private static $guide_keywords = [
        'guide', 'how-to', 'tutorial', 'tips', 'advice',
        'learn', 'understanding', 'what-is', 'best-practices',
    ];

    public static function run_full_index() {
        $posts = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        foreach ($posts as $post) {
            self::reindex_post($post);
        }

        self::scan_theme_styles();
    }

    public static function enqueue_full_index(): int {
        global $wpdb;

        $table = "{$wpdb->prefix}gutenbot_index_queue";
        $wpdb->delete($table, ['status' => 'pending']);

        $post_ids = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        $now = current_time('mysql');
        foreach ($post_ids as $post_id) {
            $wpdb->insert($table, [
                'post_id'   => $post_id,
                'status'    => 'pending',
                'queued_at' => $now,
            ]);
        }

        // Sentinel row (post_id = 0) triggers scan_theme_styles() at end of queue.
        $wpdb->insert($table, ['post_id' => 0, 'status' => 'pending', 'queued_at' => $now]);

        update_option('gutenbot_index_run_id', uniqid('gbidx_', true));
        wp_schedule_single_event(time(), 'gutenbot_process_index_queue');

        return count($post_ids) + 1;
    }

    public static function reindex_post($post, ?GutenBot_AI_Client $ai_client = null) {
        global $wpdb;

        $blocks       = parse_blocks($post->post_content);
        $plain_text   = self::extract_plain_text($blocks);
        $content_hash = md5($post->post_title . $plain_text);

        $wpdb->delete("{$wpdb->prefix}gutenbot_layout_index", ['post_id' => $post->ID]);
        $wpdb->delete("{$wpdb->prefix}gutenbot_section_index", ['source_post_id' => $post->ID]);

        $page_type     = self::classify_page_type($post, $blocks);
        $ai_summary    = '';
        $block_names   = self::extract_block_names($blocks);
        $section_order = self::extract_section_order($blocks);
        $template_slug = get_page_template_slug($post->ID) ?: 'default';

        $ai_enabled = get_option('gutenbot_ai_indexing_enabled', '0') === '1';
        if ($ai_enabled) {
            if ($ai_client === null) {
                $ai_client = new GutenBot_AI_Client();
            }
            $summary_result = $ai_client->get_page_summary($post->post_title, $plain_text);
            if ($summary_result !== null) {
                $page_type  = $summary_result['page_type'];
                $ai_summary = $summary_result['summary'];
            }
        }

        $wpdb->insert(
            "{$wpdb->prefix}gutenbot_layout_index",
            [
                'post_id'         => $post->ID,
                'page_type'       => $page_type,
                'template_slug'   => $template_slug,
                'block_structure' => wp_json_encode($block_names),
                'section_order'   => wp_json_encode($section_order),
                'ai_summary'      => $ai_summary,
                'content_hash'    => $content_hash,
                'indexed_at'      => current_time('mysql'),
            ]
        );

        $layout_id = $wpdb->insert_id;
        self::index_sections($post->ID, $layout_id, $blocks, $ai_enabled ? $ai_client : null);
    }

    public static function classify_page_type($post, array $blocks) {
        $slug     = strtolower($post->post_name ?? '');
        $title    = strtolower($post->post_title ?? '');
        $combined = $slug . ' ' . $title;

        foreach (self::$guide_keywords as $kw) {
            if (strpos($combined, $kw) !== false) {
                return 'guide';
            }
        }

        foreach (self::$location_keywords as $kw) {
            if (strpos($combined, $kw) !== false) {
                return 'location';
            }
        }

        foreach (self::$service_keywords as $kw) {
            if (strpos($combined, $kw) !== false) {
                return 'service';
            }
        }

        $flat = wp_list_pluck($blocks, 'blockName');
        if (in_array('core/map', $flat, true)) {
            return 'location';
        }

        return 'general';
    }

    public static function extract_block_names(array $blocks, int $depth = 0) {
        $result = [];
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }
            $entry = ['name' => $block['blockName'], 'depth' => $depth];
            if (!empty($block['innerBlocks'])) {
                $entry['children'] = self::extract_block_names($block['innerBlocks'], $depth + 1);
            }
            $result[] = $entry;
        }
        return $result;
    }

    public static function extract_section_order(array $blocks) {
        $section_map = [
            'hero'    => ['core/cover', 'core/image'],
            'faq'     => ['core/freeform'],
            'cta'     => ['core/buttons', 'core/button'],
            'columns' => ['core/columns'],
            'heading' => ['core/heading'],
            'text'    => ['core/paragraph'],
            'list'    => ['core/list'],
        ];

        $sections = [];
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }
            $matched = false;
            foreach ($section_map as $label => $names) {
                if (in_array($block['blockName'], $names, true)) {
                    $sections[] = $label;
                    $matched    = true;
                    break;
                }
            }
            if (!$matched) {
                $sections[] = $block['blockName'];
            }
        }
        return $sections;
    }

    public static function extract_headings(array $blocks) {
        $headings = [];
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/heading') {
                $level      = $block['attrs']['level'] ?? 2;
                $text       = wp_strip_all_tags($block['innerHTML'] ?? '');
                $headings[] = ['level' => (int) $level, 'text' => trim($text)];
            }
            if (!empty($block['innerBlocks'])) {
                $headings = array_merge($headings, self::extract_headings($block['innerBlocks']));
            }
        }
        return $headings;
    }

    private static function index_sections(int $post_id, int $layout_id, array $blocks, ?GutenBot_AI_Client $ai_client = null) {
        global $wpdb;

        $type_map = [
            'core/cover'   => 'hero',
            'core/columns' => 'columns',
            'core/buttons' => 'cta',
            'core/button'  => 'cta',
            'core/group'   => 'group',
        ];

        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }
            $section_type = $type_map[$block['blockName']] ?? $block['blockName'];
            $css_classes  = $block['attrs']['className'] ?? '';
            $markup       = serialize_blocks([$block]);
            $section_text = wp_strip_all_tags($block['innerHTML'] ?? '');
            $content_hash = md5($markup);
            $embedding    = '';

            if ($ai_client !== null && $section_text !== '') {
                $vector = $ai_client->get_section_embedding($section_text);
                if ($vector !== null) {
                    $embedding = wp_json_encode($vector);
                }
            }

            $wpdb->insert(
                "{$wpdb->prefix}gutenbot_section_index",
                [
                    'layout_id'      => $layout_id,
                    'section_type'   => $section_type,
                    'block_markup'   => $markup,
                    'css_classes'    => $css_classes,
                    'source_post_id' => $post_id,
                    'embedding'      => $embedding,
                    'content_hash'   => $content_hash,
                ]
            );
        }
    }

    public static function extract_plain_text(array $blocks): string {
        $parts = [];
        foreach ($blocks as $block) {
            if (!empty($block['innerHTML'])) {
                $text = wp_strip_all_tags($block['innerHTML']);
                if (trim($text) !== '') {
                    $parts[] = trim($text);
                }
            }
            if (!empty($block['innerBlocks'])) {
                $parts[] = self::extract_plain_text($block['innerBlocks']);
            }
        }
        return implode(' ', $parts);
    }

    public static function scan_theme_styles() {
        global $wpdb;

        $wpdb->delete("{$wpdb->prefix}gutenbot_style_index", ['source' => 'theme.json']);
        $wpdb->delete("{$wpdb->prefix}gutenbot_style_index", ['source' => 'style.css']);

        $path = get_template_directory() . '/theme.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return;
        }

        $settings = $data['settings'] ?? [];
        $entries  = [
            'color.palette'           => $settings['color']['palette'] ?? null,
            'typography.fontSizes'    => $settings['typography']['fontSizes'] ?? null,
            'typography.fontFamilies' => $settings['typography']['fontFamilies'] ?? null,
            'spacing.spacingSizes'    => $settings['spacing']['spacingSizes'] ?? null,
            'layout'                  => $settings['layout'] ?? null,
        ];

        foreach ($entries as $key => $value) {
            if ($value === null) {
                continue;
            }
            $wpdb->insert(
                "{$wpdb->prefix}gutenbot_style_index",
                [
                    'style_key'   => $key,
                    'style_value' => wp_json_encode($value),
                    'source'      => 'theme.json',
                    'indexed_at'  => current_time('mysql'),
                ]
            );
        }
    }

    public static function get_style_summary() {
        global $wpdb;

        $rows    = $wpdb->get_results("SELECT style_key, style_value FROM {$wpdb->prefix}gutenbot_style_index", ARRAY_A);
        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['style_key']] = json_decode($row['style_value'], true);
        }
        return $summary;
    }

    public static function get_similar_layouts(string $page_type, int $limit = 3) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}gutenbot_layout_index WHERE page_type = %s LIMIT %d",
                $page_type,
                $limit
            ),
            ARRAY_A
        );
    }

    public static function get_sections_by_type(string $section_type, int $limit = 5) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}gutenbot_section_index WHERE section_type = %s LIMIT %d",
                $section_type,
                $limit
            ),
            ARRAY_A
        );
    }

    public static function get_layout_by_id(int $id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}gutenbot_layout_index WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
    }
}
