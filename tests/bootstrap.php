<?php
/*
 * PHPUnit bootstrap for GutenBot.
 *
 * - Unit tests:        Brain\Monkey stubs WordPress functions, no WP loaded.
 * - Integration tests: Set WP_TESTS_DIR to the wordpress-develop test library path.
 */

// Composer autoloader — provides Brain\Monkey, Mockery, and PHPUnit.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$wp_tests_dir = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR') ?: '';

if ($wp_tests_dir && file_exists($wp_tests_dir . '/includes/bootstrap.php')) {
    // Integration mode — load the full WordPress test suite.
    define('GUTENBOT_VERSION', '1.0.0');
    define('GUTENBOT_PLUGIN_DIR', dirname(__DIR__) . '/');
    define('GUTENBOT_PLUGIN_URL', 'http://localhost/wp-content/plugins/guten-bot/');
    require_once $wp_tests_dir . '/includes/bootstrap.php';

    // Load plugin classes after WP bootstrap so global WP functions are available.
    foreach (glob(GUTENBOT_PLUGIN_DIR . 'includes/class-*.php') as $file) {
        require_once $file;
    }
} else {
    // Unit test mode — define minimal stubs only.
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    define('GUTENBOT_VERSION', '1.0.0');
    define('GUTENBOT_PLUGIN_DIR', dirname(__DIR__) . '/');
    define('GUTENBOT_PLUGIN_URL', 'http://localhost/wp-content/plugins/guten-bot/');

    // Load plugin classes — WordPress functions are stubbed per-test by Brain\Monkey.
    foreach (glob(GUTENBOT_PLUGIN_DIR . 'includes/class-*.php') as $file) {
        require_once $file;
    }
}
