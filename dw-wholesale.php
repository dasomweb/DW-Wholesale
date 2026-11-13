<?php
/**
 * Plugin Name: DW Wholesale
 * Plugin URI: https://github.com/dasomweb/DW-Wholesale
 * Description: Independent product catalog system (own tables, search, filters, variations) placed on WordPress pages by shortcode.
 * Version: 3.0.0-alpha.1
 * Author: Dasom Web
 * Author URI: https://github.com/dasomweb
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/dasomweb/DW-Wholesale
 * Text Domain: dw-wholesale
 * Domain Path: /languages
 * Requires at least: 5.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central Plugin Configuration
 */
function dwcat_get_config() {
	return array(
		'github_repo_owner'  => 'dasomweb',
		'github_repo_name'   => 'DW-Wholesale',
		'plugin_slug'        => 'dw-wholesale',
		'plugin_version'     => '3.0.0-alpha.1',
		'plugin_name'        => 'DW Wholesale',
		'plugin_text_domain' => 'dw-wholesale',
		'github_branch'      => 'main',
		'requires_wp'        => '5.5',
		'requires_php'       => '7.4',
	);
}

function dwcat_get_url() {
	return plugin_dir_url( __FILE__ );
}

function dwcat_get_path() {
	return plugin_dir_path( __FILE__ );
}

function dwcat_get_file() {
	return __FILE__;
}

/*
 * ─── Structure ──────────────────────────────────────────────────────────────
 * core/          DW Wholesale core — standalone PHP, no WordPress code (docs/ARCHITECTURE.md)
 * includes/wp/   WordPress adapter: DB connection, host, admin menu, shortcodes, REST
 * includes/      DASOM-Forge license SDK + gates (WordPress distribution)
 */
require_once dwcat_get_path() . 'core/bootstrap.php';

require_once dwcat_get_path() . 'includes/class-pc-github-updater.php';

// DASOM-Forge SDK — Forge Client 를 먼저 (License Manager 가 생성자에서 사용)
require_once dwcat_get_path() . 'includes/class-dw-forge-client.php';
require_once dwcat_get_path() . 'includes/class-dw-license-manager.php';
require_once dwcat_get_path() . 'includes/license/gates.php';
require_once dwcat_get_path() . 'includes/class-dwcat-license-rest.php';

require_once dwcat_get_path() . 'includes/wp/class-dwcat-wpdb-connection.php';
require_once dwcat_get_path() . 'includes/wp/class-dwcat-wp-host.php';
require_once dwcat_get_path() . 'includes/wp/class-dwcat-wp-i18n.php';
require_once dwcat_get_path() . 'includes/wp/class-dwcat-wp-access.php';
require_once dwcat_get_path() . 'includes/wp/class-dwcat-wp-admin.php';
require_once dwcat_get_path() . 'includes/wp/class-dwcat-wp-front.php';
require_once dwcat_get_path() . 'includes/wp/class-dwcat-wp-rest.php';

/**
 * DASOM-Forge License SDK.
 *
 * Prefix 배포 전략 (PLUGIN-DEV-GUIDE §3.5 B) — 클래스는 DW_DWCAT_* 로 격리되어
 * 다른 DW 플러그인이 ship 한 SDK 버전과 winner race 를 벌이지 않습니다.
 *
 * plugins_loaded 보다 먼저 등록해야 admin_menu / admin_post 훅을 놓치지 않습니다.
 */
DW_DWCAT_License_Manager::init( array(
	'product_slug'    => 'dw-wholesale',
	'cache_prefix'    => 'dwcat_',
	'plugin_file'     => __FILE__,
	'plugin_basename' => plugin_basename( __FILE__ ),
	'plugin_name'     => 'DW Wholesale',
	'plugin_version'  => '3.0.0-alpha.1',
	'settings_page'   => 'dw-wholesale-license',
	'menu_parent'     => DWCAT_WP_Host::ADMIN_PAGE,
	'public_keys'     => array(
		dwcat_get_path() . 'includes/keys/dasomforge.pub',
		dwcat_get_path() . 'includes/keys/dasomforge.previous.pub', // 키 회전 시에만 존재
	),
) );

/**
 * GitHub Updater — v2.0 부터 **비활성화**합니다.
 *
 * PLUGIN-DEV-GUIDE §7.4: "고객은 dasomforge 가 발급한 R2 signed URL 로만 다운로드
 * (GitHub 직접 다운로드 ❌)". dasomforge 가 유일한 배포 채널입니다.
 * 클래스 자체는 롤백 대비로 남겨 둡니다.
 *
 * @see DWCAT_GitHub_Updater — 인스턴스화하지 않음 (의도적)
 */

/**
 * The catalog core for this site.
 *
 * Default: tables in the WordPress database ("{$wpdb->prefix}dwcat_*") over $wpdb — nothing to
 * configure. A separate database (MySQL or PostgreSQL) is opt-in via wp-config.php:
 *
 *   define( 'DWCAT_DB_DRIVER', 'pgsql' );      // or 'mysql'
 *   define( 'DWCAT_DB_HOST', '127.0.0.1' );
 *   define( 'DWCAT_DB_PORT', 5432 );
 *   define( 'DWCAT_DB_NAME', 'catalog' );
 *   define( 'DWCAT_DB_USER', 'catalog' );
 *   define( 'DWCAT_DB_PASSWORD', '…' );
 *   define( 'DWCAT_DB_PREFIX', 'dwcat_' );     // optional
 *
 * @return \DWCatalog\Catalog
 */
function dwcat() {
	static $catalog = null;
	if ( null === $catalog ) {
		$host = new DWCAT_WP_Host();
		if ( defined( 'DWCAT_DB_DRIVER' ) ) {
			$prefix = defined( 'DWCAT_DB_PREFIX' ) ? DWCAT_DB_PREFIX : 'dwcat_';
			$db     = \DWCatalog\Db\PdoConnection::fromConfig( array(
				'driver'   => DWCAT_DB_DRIVER,
				'host'     => defined( 'DWCAT_DB_HOST' ) ? DWCAT_DB_HOST : 'localhost',
				'port'     => defined( 'DWCAT_DB_PORT' ) ? DWCAT_DB_PORT : null,
				'dbname'   => DWCAT_DB_NAME,
				'user'     => defined( 'DWCAT_DB_USER' ) ? DWCAT_DB_USER : '',
				'password' => defined( 'DWCAT_DB_PASSWORD' ) ? DWCAT_DB_PASSWORD : '',
				'prefix'   => $prefix,
			) );
		} else {
			global $wpdb;
			$prefix = $wpdb->prefix . 'dwcat_';
			$db     = new DWCAT_Wpdb_Connection( $wpdb, $prefix );
		}
		$catalog = new \DWCatalog\Catalog( $db, $host, $prefix );
	}
	return $catalog;
}

// Initialize the WordPress adapter
add_action( 'plugins_loaded', 'dwcat_init', 10 );
function dwcat_init() {
	// Translations: the language chosen in DW Wholesale → Settings (admin and site widgets apart),
	// loaded on `init` from languages/dw-wholesale-{locale}.mo.
	DWCAT_WP_I18n::init();
	new DWCAT_WP_Access();     // operator mode (DW Wholesale → Access)
	new DWCAT_WP_Admin();
	new DWCAT_WP_Front();
	new DWCAT_WP_Rest();
	new DWCAT_License_REST();   // §3.6 SHOULD #2 진단 엔드포인트
}

// Activation hook
register_activation_hook( __FILE__, 'dwcat_activate' );
function dwcat_activate() {
	$config      = dwcat_get_config();
	$old_version = get_option( 'dwcat_version', '' );

	update_option( 'dwcat_version', $config['plugin_version'] );
	update_option( 'dwcat_activated', time() );

	dwcat_migrate( $old_version, $config['plugin_version'] );

	// 라이선스 일일 검증 cron 등록
	DW_DWCAT_License_Manager::on_activate();

	( new DWCAT_WP_Front() )->endpoint();
	flush_rewrite_rules();
}

/**
 * Install / upgrade the catalog tables and grant the v1.x grace window when needed.
 */
function dwcat_migrate( $old_version, $new_version ) {
	// v1.x → v2.0 하위호환 (KICKOFF FAQ Q8) —
	// 업그레이드 설치에만 1회 유예를 부여하고, 신규 설치는 즉시 게이트 적용.
	dwcat_maybe_grant_legacy_grace( $old_version );

	try {
		if ( dwcat()->needsInstall() ) {
			dwcat()->install();
		}
		update_option( 'dwcat_schema', \DWCatalog\Db\Schema::VERSION, true );
	} catch ( \Throwable $e ) {
		update_option( 'dwcat_install_error', $e->getMessage(), false );
		return;
	}
	delete_option( 'dwcat_install_error' );
}

// Version check on every admin load — handles upgrades without re-activation
add_action( 'admin_init', 'dwcat_check_version' );
function dwcat_check_version() {
	$config = dwcat_get_config();
	$stored = get_option( 'dwcat_version', '' );

	// Catalog schema upgrades are tracked separately (cheap autoloaded option, no DB query).
	if ( (int) get_option( 'dwcat_schema', 0 ) !== \DWCatalog\Db\Schema::VERSION ) {
		try {
			dwcat()->install();
			update_option( 'dwcat_schema', \DWCatalog\Db\Schema::VERSION, true );
			delete_option( 'dwcat_install_error' );
		} catch ( \Throwable $e ) {
			update_option( 'dwcat_install_error', $e->getMessage(), false );
		}
	}

	if ( $stored !== $config['plugin_version'] ) {
		dwcat_migrate( $stored, $config['plugin_version'] );
		update_option( 'dwcat_version', $config['plugin_version'] );

		// PLUGIN-CALL-FLOWS §4 — 새 버전은 새 file_hashes 를 갖습니다.
		// 캐시 토큰은 구 버전 매니페스트로 발급된 것이라 즉시 폐기하고
		// 다음 요청에서 새 버전으로 재발급받아야 운영자 verify 가 ✅ 로 뜹니다.
		DW_DWCAT_License_Manager::clear_token_cache();

		flush_rewrite_rules();
	}
}

/** Database problems (e.g. an external DB that cannot be reached) are shown to admins. */
add_action( 'admin_notices', 'dwcat_install_notice' );
function dwcat_install_notice() {
	$err = get_option( 'dwcat_install_error' );
	if ( $err && current_user_can( 'manage_options' ) ) {
		echo '<div class="notice notice-error"><p><strong>DW Wholesale:</strong> ' . esc_html( $err ) . '</p></div>';
	}
}

// Deactivation hook
register_deactivation_hook( __FILE__, 'dwcat_deactivate' );
function dwcat_deactivate() {
	// 라이선스 cron 해제 (라이선스 자체는 보존 — 재활성화 시 그대로 복구)
	DW_DWCAT_License_Manager::on_deactivate_plugin();
	flush_rewrite_rules();
}
