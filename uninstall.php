<?php
/**
 * Uninstall DW Wholesale
 *
 * Deleting the plugin removes the catalog: core tables, image files under
 * uploads/dw-catalog/, settings and license data.
 *
 * @package DW_Catalog_WP
 */

// Abort if not called by WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Catalog core: tables + image files ─────────────────────────────
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/includes/wp/class-dwcat-wpdb-connection.php';
try {
	if ( defined( 'DWCAT_DB_DRIVER' ) ) {
		$dwcat_prefix = defined( 'DWCAT_DB_PREFIX' ) ? DWCAT_DB_PREFIX : 'dwcat_';
		$dwcat_db     = \DWCatalog\Db\PdoConnection::fromConfig( array(
			'driver'   => DWCAT_DB_DRIVER,
			'host'     => defined( 'DWCAT_DB_HOST' ) ? DWCAT_DB_HOST : 'localhost',
			'port'     => defined( 'DWCAT_DB_PORT' ) ? DWCAT_DB_PORT : null,
			'dbname'   => DWCAT_DB_NAME,
			'user'     => defined( 'DWCAT_DB_USER' ) ? DWCAT_DB_USER : '',
			'password' => defined( 'DWCAT_DB_PASSWORD' ) ? DWCAT_DB_PASSWORD : '',
			'prefix'   => $dwcat_prefix,
		) );
	} else {
		$dwcat_db = new DWCAT_Wpdb_Connection( $wpdb, $wpdb->prefix . 'dwcat_' );
	}
	\DWCatalog\Db\Schema::drop( $dwcat_db );
} catch ( \Throwable $e ) { // phpcs:ignore — nothing more to do on uninstall
}

$dwcat_upload = wp_upload_dir( null, false );
$dwcat_dir    = realpath( rtrim( $dwcat_upload['basedir'], '/\\' ) . '/dw-catalog' );
if ( $dwcat_dir && 'dw-catalog' === basename( $dwcat_dir ) && is_dir( $dwcat_dir ) ) {
	$dwcat_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dwcat_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $dwcat_files as $dwcat_file ) {
		if ( $dwcat_file->isDir() ) {
			@rmdir( $dwcat_file->getPathname() );
		} else {
			@unlink( $dwcat_file->getPathname() );
		}
	}
	@rmdir( $dwcat_dir );
}

// ── Per-user admin language choice (EN / 한국어 buttons) ─────────────
delete_metadata( 'user', 0, 'dwcat_admin_lang', '', true );

// ── Plugin options ──────────────────────────────────────────────────
delete_option( 'dwcat_version' );
delete_option( 'dwcat_activated' );
delete_option( 'dwcat_install_error' );
delete_option( 'dwcat_schema' );
delete_transient( 'dwcat_catalog_page' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_transient\_dwcat\_fc\_%', '\_transient\_timeout\_dwcat\_fc\_%' ) );

// Leftovers of the v1 / v2.0-rc post-type era
delete_option( 'dw_catalog_post_types' );
delete_option( 'dwcat_design_settings' );
delete_option( 'dwcat_menu_merged' );
delete_option( 'dwcat_db_version' );
delete_option( 'dwcat_migration' );
delete_option( 'dwcat_cache_ver' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'dw\_catalog\_fields\_%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'dw\_catalog\_variation\_cols\_%' ) );

// ── DASOM-Forge License SDK (DW_DWCAT_License_Manager) ──────────────
// 옵션 키는 SDK 의 cache_prefix('dwcat_') + product_slug 규약을 따릅니다.
delete_option( 'dw_license_dw_wholesale' );  // 라이선스 상태 (권위 저장소)
delete_option( 'dwcat_token' );               // JWT 캐시
delete_option( 'dwcat_tamper_notice' );       // TAMPER_DETECTED mismatched_files
delete_option( 'dwcat_last_sdk_error' );      // 마지막 SDK 오류 (§3.6)
delete_option( 'dwcat_platform_notice' );     // INTEGRITY_MANIFEST_NOT_FOUND 등
delete_option( 'dwcat_offline_since' );       // 연속 실패 추적
delete_option( 'dwcat_legacy_grace_until' );  // v1.x 마이그레이션 유예

// 멀티사이트: 라이선스는 네트워크 옵션에 저장됩니다 (FAQ Q10).
if ( is_multisite() ) {
	delete_network_option( null, 'dw_license_dw_wholesale' );
}

delete_transient( 'dwcat_token_backoff' );
delete_transient( 'dwcat_update_info' );
delete_transient( 'dwcat_runtime_manifest' );

wp_clear_scheduled_hook( 'dw_verify_license_dw-wholesale' );
wp_clear_scheduled_hook( 'dwcat_refresh_token' );
wp_clear_scheduled_hook( 'dwcat_migrate_batch' );

// v1.x 잔여물 정리
delete_transient( 'dw_license_check_dw-wholesale' );
