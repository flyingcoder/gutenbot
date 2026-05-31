<?php
/**
 * Plugin Name:       GutenBot
 * Plugin URI:        https://github.com/gutenbot/gutenbot
 * Description:       AI-powered Gutenberg page builder using LLM-generated block layouts.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            GutenBot
 * Text Domain:       gutenbot
 */

defined( 'ABSPATH' ) || exit;

define( 'GUTENBOT_VERSION', '1.0.0' );
define( 'GUTENBOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GUTENBOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GUTENBOT_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, [ 'GutenBot\\Activator', 'activate' ] );

add_action( 'plugins_loaded', static function () {
    ( new GutenBot\Hooks() )->register();
} );
