<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_Activator extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = $this->createMock(stdClass::class);

        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir(),
        ]);
        Functions\when('wp_mkdir_p')->justReturn(true);
        Functions\when('trailingslashit')->alias(fn($p) => rtrim($p, '/') . '/');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_default_options_set_on_activation() {
        $set = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$set) {
            $set[$key] = $value;
            return true;
        });
        Functions\when('get_option')->justReturn(false);

        // Stub dbDelta (requires ABSPATH + WP include).
        if (!function_exists('dbDelta')) {
            eval('function dbDelta($sql) {}');
        }

        GutenBot_Activator::activate();

        $this->assertArrayHasKey('gutenbot_file_size_limit', $set);
        $this->assertSame(10485760, $set['gutenbot_file_size_limit']);
    }

    public function test_upload_directory_created() {
        $dir_created = false;
        Functions\when('wp_mkdir_p')->alias(function ($path) use (&$dir_created) {
            $dir_created = true;
            return true;
        });
        Functions\when('get_option')->justReturn(false);

        if (!function_exists('dbDelta')) {
            eval('function dbDelta($sql) {}');
        }

        GutenBot_Activator::activate();

        $this->assertTrue($dir_created);
    }
}
