<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_Page_Generator extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('parse_blocks')->alias(function ($markup) {
            // Minimal stub — returns one block per comment opener found.
            preg_match_all('/<!-- wp:(\S+)/', $markup, $m);
            return array_map(fn($n) => ['blockName' => $n, 'innerHTML' => '', 'innerBlocks' => []], $m[1]);
        });
        Functions\when('get_page_by_title')->justReturn(null);
        Functions\when('esc_html')->alias('htmlspecialchars');
        Functions\when('esc_attr')->alias('htmlspecialchars');
        Functions\when('GutenBot_Indexer::get_layout_by_id')->justReturn(null);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_plan(array $sections = ['hero', 'cta'], ?int $source = null) {
        return [
            'page_type'     => 'service',
            'title'         => 'Fence Services',
            'sections'      => $sections,
            'layout_source' => $source,
        ];
    }

    public function test_exactly_one_h1_in_output() {
        // Arrange
        $plan = $this->make_plan(['hero', 'cta']);

        // Act
        $markup = GutenBot_Page_Generator::build($plan, []);

        // Assert
        $count = substr_count($markup, '"level":1');
        $this->assertSame(1, $count);
    }

    public function test_output_contains_all_sections() {
        // Arrange
        $plan = $this->make_plan(['hero', 'faq', 'cta']);

        // Act
        $markup = GutenBot_Page_Generator::build($plan, []);

        // Assert
        $this->assertStringContainsString('hero-section', $markup);
        $this->assertStringContainsString('faq-section', $markup);
        $this->assertStringContainsString('cta-section', $markup);
    }

    public function test_fallback_blocks_used_when_no_source() {
        // Arrange
        $plan = $this->make_plan(['hero'], null);

        // Act
        $markup = GutenBot_Page_Generator::build($plan, []);

        // Assert — hero fallback template includes wp:group.
        $this->assertStringContainsString('wp:group', $markup);
    }

    public function test_reusable_section_preferred_over_fallback() {
        // Arrange
        $reusable_markup = '<!-- wp:heading {"level":1} --><h1>Custom Hero</h1><!-- /wp:heading -->';
        $reusable        = [['section_type' => 'hero', 'block_markup' => $reusable_markup]];
        $plan            = $this->make_plan(['hero']);

        // Act
        $markup = GutenBot_Page_Generator::build($plan, $reusable);

        // Assert
        $this->assertStringContainsString('Custom Hero', $markup);
    }

    public function test_duplicate_title_throws_exception() {
        // Arrange — simulate a published page with same title.
        Functions\when('get_page_by_title')->justReturn((object) ['post_status' => 'publish']);

        // Assert
        $this->expectException(RuntimeException::class);

        // Act
        GutenBot_Page_Generator::build($this->make_plan(), []);
    }

    public function test_section_order_matches_plan() {
        // Arrange
        $plan = $this->make_plan(['intro', 'benefits', 'faq', 'cta']);

        // Act
        $markup = GutenBot_Page_Generator::build($plan, []);

        // Assert positions are in order.
        $pos_intro    = strpos($markup, 'intro-section');
        $pos_benefits = strpos($markup, 'benefits-section');
        $pos_faq      = strpos($markup, 'faq-section');
        $pos_cta      = strpos($markup, 'cta-section');

        $this->assertGreaterThan(0, $pos_intro);
        $this->assertGreaterThan($pos_intro, $pos_benefits);
        $this->assertGreaterThan($pos_benefits, $pos_faq);
        $this->assertGreaterThan($pos_faq, $pos_cta);
    }
}
