<?php

declare(strict_types=1);

namespace GutenBot;

class Deactivator {

	public static function deactivate(): void {
		self::drop_tables();
		self::delete_options();
		self::clear_cron_events();
	}

	private static function drop_tables(): void {
		global $wpdb;

		$tables = [
			$wpdb->prefix . 'gutenbot_page_chunks',
			$wpdb->prefix . 'gutenbot_indexed_posts',
		];

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	private static function delete_options(): void {
		global $wpdb;

		$like = $wpdb->esc_like( 'aipb_' ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	private static function clear_cron_events(): void {
		$hooks = [ 'aipb_site_scan', 'aipb_scan_next', 'aipb_supabase_sync' ];

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
