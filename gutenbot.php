<?php
/**
 * Plugin Name: GutenBot
 * Description: AI-powered Gutenberg page generator that learns from existing site layouts.
 * Version: 1.0.0
 * Author: GutenBot
 * Text Domain: gutenbot
 */

defined('ABSPATH') || exit;

define('GUTENBOT_VERSION', '1.0.0');
define('GUTENBOT_PLUGIN_FILE', __FILE__);
define('GUTENBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GUTENBOT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GUTENBOT_PLUGIN_DIR . 'includes/class-activator.php';
require_once GUTENBOT_PLUGIN_DIR . 'includes/class-indexer.php';
require_once GUTENBOT_PLUGIN_DIR . 'includes/class-document-processor.php';
require_once GUTENBOT_PLUGIN_DIR . 'includes/class-ai-client.php';
require_once GUTENBOT_PLUGIN_DIR . 'includes/class-page-generator.php';
require_once GUTENBOT_PLUGIN_DIR . 'includes/class-admin.php';
require_once GUTENBOT_PLUGIN_DIR . 'includes/class-hooks.php';

register_activation_hook(__FILE__, ['GutenBot_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['GutenBot_Activator', 'deactivate']);

add_action('plugins_loaded', function () {
    GutenBot_Hooks::register();
});
