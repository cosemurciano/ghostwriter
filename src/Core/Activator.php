<?php
declare(strict_types=1);

namespace Ghostwriter\Core;

/**
 * Attivazione: tabelle custom (dbDelta), ruoli e capability, cartelle protette.
 */
final class Activator {

	public const DB_VERSION        = '1.0.0';
	public const DB_VERSION_OPTION = 'ghostwriter_db_version';

	public static function activate(): void {
		self::create_tables();
		Capabilities::grant();
		self::create_upload_dirs();

		PostTypes::register();
		flush_rewrite_rules();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$usage           = $wpdb->prefix . 'gw_usage';
		$log             = $wpdb->prefix . 'gw_log';
		$revisions       = $wpdb->prefix . 'gw_block_revisions';

		dbDelta(
			"CREATE TABLE {$usage} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				project_id BIGINT(20) UNSIGNED NOT NULL,
				job VARCHAR(64) NOT NULL,
				provider VARCHAR(32) NOT NULL DEFAULT '',
				model VARCHAR(64) NOT NULL DEFAULT '',
				input_tokens INT(11) UNSIGNED NOT NULL DEFAULT 0,
				output_tokens INT(11) UNSIGNED NOT NULL DEFAULT 0,
				images INT(11) UNSIGNED NOT NULL DEFAULT 0,
				cost_estimate DECIMAL(10,4) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY project_created (project_id, created_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				project_id BIGINT(20) UNSIGNED NOT NULL,
				chapter_id BIGINT(20) UNSIGNED NULL,
				level VARCHAR(16) NOT NULL DEFAULT 'info',
				event VARCHAR(64) NOT NULL,
				context LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY project_created (project_id, created_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$revisions} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				chapter_id BIGINT(20) UNSIGNED NOT NULL,
				block_id CHAR(36) NOT NULL,
				version INT(11) UNSIGNED NOT NULL,
				origin VARCHAR(16) NOT NULL,
				block LONGTEXT NOT NULL,
				feedback TEXT NULL,
				restored_from_version INT(11) UNSIGNED NULL,
				generated_with LONGTEXT NULL,
				author_user_id BIGINT(20) UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY block_version (block_id, version),
				KEY chapter (chapter_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Crea uploads/ghostwriter con protezione: niente listing, niente accesso
	 * diretto (i download passano da endpoint autenticati).
	 */
	private static function create_upload_dirs(): void {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ) . 'ghostwriter';

		foreach ( array( $base, $base . '/skills', $base . '/themes', $base . '/cache' ) as $dir ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$index = $base . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
