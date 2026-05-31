<?php

declare(strict_types=1);

namespace GutenBot;

class Activator {

	private const DB_VERSION = '1.3';

	public static function activate(): void {
		self::maybe_generate_client_id();
		self::create_db_tables();
		self::maybe_schedule_scan();
	}

	/**
	 * Run on admin_init so schema changes apply without requiring a manual
	 * deactivate/reactivate cycle after plugin code updates.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'aipb_db_version' ) !== self::DB_VERSION ) {
			self::create_db_tables();
			update_option( 'aipb_db_version', self::DB_VERSION, false );
		}
	}

	private static function maybe_generate_client_id(): void {
		if ( ! get_option( 'aipb_client_id' ) ) {
			update_option( 'aipb_client_id', wp_generate_uuid4(), false );
		}
	}

	private static function create_db_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $wpdb->prefix . 'gutenbot_page_chunks';

		$sql = "CREATE TABLE {$table} (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id       BIGINT UNSIGNED NOT NULL,
			chunk_order      INT             NOT NULL DEFAULT 0,
			content          LONGTEXT        NOT NULL,
			chunk_html       LONGTEXT                 NULL,
			chunk_blocks     LONGTEXT                 NULL,
			block_count      INT             NOT NULL DEFAULT 0,
			block_types      LONGTEXT                 NULL,
			word_count       INT             NOT NULL DEFAULT 0,
			character_count  INT             NOT NULL DEFAULT 0,
			embedding_synced TINYINT(1)      NOT NULL DEFAULT 0,
			metadata         LONGTEXT                 NULL,
			created_at       DATETIME        NOT NULL,
			updated_at       DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_wp_post_id (wp_post_id),
			KEY idx_chunk_order (chunk_order)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	private static function maybe_schedule_scan(): void {
		if ( ! wp_next_scheduled( 'aipb_site_scan' ) ) {
			wp_schedule_single_event( time() + 5, 'aipb_site_scan' );
		}
	}
}
