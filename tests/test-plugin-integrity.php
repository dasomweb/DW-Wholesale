<?php
/**
 * DW Wholesale — Plugin Integrity Tests
 *
 * Standalone PHP tests (no WordPress required).
 * Validates: file structure, class definitions, prefix compliance,
 * security patterns, version consistency, and WordPress API patterns.
 *
 * Run: php tests/test-plugin-integrity.php
 *
 * @package DW_Catalog_WP
 */

$plugin_root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
$errors = array();

function assert_true( $condition, $message ) {
	global $pass, $fail, $errors;
	if ( $condition ) {
		$pass++;
	} else {
		$fail++;
		$errors[] = "FAIL: $message";
	}
}

function assert_false( $condition, $message ) {
	assert_true( ! $condition, $message );
}

echo "=== DW Wholesale — Plugin Integrity Tests ===\n\n";

// ─── 1. File Structure ─────────────────────────────────────

echo "--- 1. File Structure ---\n";

$required_files = array(
	'dw-wholesale.php',
	'uninstall.php',
	// Core (standalone, no WordPress code)
	'core/bootstrap.php',
	'core/src/Catalog.php',
	'core/src/Db/Connection.php',
	'core/src/Db/MysqlDialect.php',
	'core/src/Db/PostgresDialect.php',
	'core/src/Db/Schema.php',
	'core/src/Admin/AdminApp.php',
	'core/src/Front/FrontApp.php',
	'core/src/Security/Csrf.php',
	'core/src/Security/Access.php',
	'core/src/I18n/Languages.php',
	'core/src/I18n/FrontStrings.php',
	'core/src/Migration/Source.php',
	'core/src/Migration/Migrator.php',
	'core/src/Repo/Applications.php',
	'core/templates/admin/migration.php',
	'core/templates/admin/dashboard.php',
	'core/templates/admin/settings.php',
	'core/templates/admin/access.php',
	'core/templates/admin/placeholder.php',
	'core/templates/admin/shortcodes.php',
	'core/assets/front.css',
	'core/assets/catalog.js',
	'core/assets/carousel.js',
	'core/assets/admin.css',
	'core/assets/admin.js',
	// WordPress adapter
	'includes/wp/class-dwcat-wpdb-connection.php',
	'includes/wp/class-dwcat-wp-host.php',
	'includes/wp/class-dwcat-wp-admin.php',
	'includes/wp/class-dwcat-wp-i18n.php',
	'includes/wp/class-dwcat-wp-access.php',
	'includes/wp/class-dwcat-wp-migration-source.php',
	'includes/wp/class-dwcat-wp-migration.php',
	'includes/wp/class-dwcat-wp-front.php',
	// Translations (M9) — Korean shipped, English is the source language
	'languages/dw-wholesale.pot',
	'languages/dw-wholesale-ko_KR.po',
	'languages/dw-wholesale-ko_KR.mo',
	'languages/dw-wholesale-en_US.po',  // license SDK screens in English
	'languages/dw-wholesale-en_US.mo',
	'includes/wp/class-dwcat-wp-rest.php',
	'includes/class-pc-github-updater.php',
	'includes/class-dw-license-manager.php',
	'includes/class-dw-forge-client.php',
	'includes/license/gates.php',
	'includes/keys/dasomforge.pub',
	'includes/class-dwcat-license-rest.php',
	// dasomforge 빌드 파이프라인 (PLUGIN-DEV-GUIDE §7.2 / §8)
	'.gitattributes',
	'composer.lock',
	'build/generate-integrity.js',
	'build/generate-release-manifest.js',
	'build/verify-package.js',
	'.github/scripts/package-release.sh',
	// 테스트 · 문서 (PLUGIN-DEV-GUIDE §9.1 / §7.6)
	'tests/wp-stubs.php',
	'tests/test-bootstrap.php',
	'tests/test-license-manager.php',
	'tests/core/run.php',
	'CHANGELOG.md',
	'docs/MIGRATION-PLAN.md',
	'docs/ARCHITECTURE.md',
);

foreach ( $required_files as $file ) {
	assert_true( file_exists( "$plugin_root/$file" ), "Required file exists: $file" );
}

// ─── 2. Plugin Header ──────────────────────────────────────

echo "--- 2. Plugin Header ---\n";

$main_file = file_get_contents( "$plugin_root/dw-wholesale.php" );

assert_true( preg_match( '/Plugin Name:\s*DW Wholesale/', $main_file ), 'Plugin Name header present' );
assert_true( preg_match( '/Version:\s*[\d.]+(-[0-9A-Za-z.\-]+)?/', $main_file ), 'Version header present' );
assert_true( preg_match( '/Text Domain:\s*dw-wholesale/', $main_file ), 'Text Domain header present' );
assert_true( preg_match( '/Update URI:/', $main_file ), 'Update URI header present (WP guideline)' );
assert_true( preg_match( '/License:/', $main_file ), 'License header present' );
assert_true( preg_match( '/Requires at least:/', $main_file ), 'Requires at least header present' );
assert_true( preg_match( '/Requires PHP:/', $main_file ), 'Requires PHP header present' );

// Description under 140 chars
preg_match( '/Description:\s*(.+)/', $main_file, $desc_match );
if ( $desc_match ) {
	$desc_len = strlen( trim( $desc_match[1] ) );
	assert_true( $desc_len <= 140, "Description is under 140 chars (got $desc_len)" );
}

// ─── 3. Version Consistency ─────────────────────────────────

echo "--- 3. Version Consistency ---\n";

preg_match( '/\*\s*Version:\s*([\d.]+(?:-[0-9A-Za-z.\-]+)?)/', $main_file, $header_ver );
preg_match( "/'plugin_version'\s*=>\s*'([\d.]+(?:-[0-9A-Za-z.\-]+)?)'/", $main_file, $config_ver );

assert_true( ! empty( $header_ver[1] ), 'Version found in header' );
assert_true( ! empty( $config_ver[1] ), 'Version found in config function' );
if ( ! empty( $header_ver[1] ) && ! empty( $config_ver[1] ) ) {
	assert_true( $header_ver[1] === $config_ver[1], "Header version ({$header_ver[1]}) matches config version ({$config_ver[1]})" );
}

// ─── 4. Prefix Compliance (min 5 chars) ─────────────────────

echo "--- 4. Prefix Compliance ---\n";

$php_files = array_merge(
	glob( "$plugin_root/includes/*.php" ),
	glob( "$plugin_root/includes/*/*.php" )   // includes/license/gates.php, includes/wp/*
);
$php_files[] = "$plugin_root/dw-wholesale.php";

$old_prefix_found = false;
foreach ( $php_files as $file ) {
	$content = file_get_contents( $file );
	// Check for old 2-char prefix PC_ or pc_ (excluding meta key references dw_pc_)
	$stripped = preg_replace( "/dw_pc_\w+/", '', $content ); // Remove meta key refs
	if ( preg_match( '/\bPC_[A-Z]/', $stripped ) || preg_match( '/\bpc_[a-z]/', $stripped ) ) {
		$old_prefix_found = true;
		$errors[] = "OLD PREFIX in: " . basename( $file );
	}
}
assert_false( $old_prefix_found, 'No old PC_/pc_ prefixes remain (meta keys excluded)' );

// Check new prefix is at least 5 chars
assert_true( preg_match( '/\bDWCAT_/', $main_file ), 'DWCAT_ class prefix used (5+ chars)' );
assert_true( preg_match( '/\bdwcat_/', $main_file ), 'dwcat_ function prefix used (5+ chars)' );

// ─── 5. ABSPATH Security Check ──────────────────────────────

echo "--- 5. ABSPATH Security Check ---\n";

foreach ( $php_files as $file ) {
	$content = file_get_contents( $file );
	$has_abspath = strpos( $content, "defined( 'ABSPATH' )" ) !== false
		|| strpos( $content, "defined('ABSPATH')" ) !== false;
	assert_true( $has_abspath, 'ABSPATH check in: ' . basename( $file ) );
}

// uninstall.php should check WP_UNINSTALL_PLUGIN
$uninstall = file_get_contents( "$plugin_root/uninstall.php" );
assert_true( strpos( $uninstall, 'WP_UNINSTALL_PLUGIN' ) !== false, 'uninstall.php checks WP_UNINSTALL_PLUGIN' );

// ─── 6. Class Definitions ───────────────────────────────────

echo "--- 6. Class Definitions ---\n";

$expected_classes = array(
	'DWCAT_GitHub_Updater'     => 'class-pc-github-updater.php',
	'DW_DWCAT_License_Manager' => 'class-dw-license-manager.php',
	'DW_DWCAT_Forge_Client'    => 'class-dw-forge-client.php',
	'DWCAT_License_REST'       => 'class-dwcat-license-rest.php',
	'DWCAT_Wpdb_Connection'    => 'wp/class-dwcat-wpdb-connection.php',
	'DWCAT_WP_Host'            => 'wp/class-dwcat-wp-host.php',
	'DWCAT_WP_Admin'           => 'wp/class-dwcat-wp-admin.php',
	'DWCAT_WP_I18n'            => 'wp/class-dwcat-wp-i18n.php',
	'DWCAT_WP_Access'          => 'wp/class-dwcat-wp-access.php',
	'DWCAT_WP_Migration_Source' => 'wp/class-dwcat-wp-migration-source.php',
	'DWCAT_WP_Migration'       => 'wp/class-dwcat-wp-migration.php',
	'DWCAT_WP_Front'           => 'wp/class-dwcat-wp-front.php',
	'DWCAT_WP_Rest'            => 'wp/class-dwcat-wp-rest.php',
);

foreach ( $expected_classes as $class_name => $file_name ) {
	$path = "$plugin_root/includes/$file_name";
	if ( file_exists( $path ) ) {
		$content = file_get_contents( $path );
		assert_true(
			strpos( $content, "class $class_name" ) !== false,
			"Class $class_name defined in $file_name"
		);
	}
}

// ─── 6b. Core independence ─────────────────────────────────

echo "--- 6b. Core has no WordPress code ---\n";

$core_php = array();
$it       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$plugin_root/core", FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' === $f->getExtension() ) {
		$core_php[] = $f->getPathname();
	}
}
$wp_calls = '/\b(\$wpdb|add_action|add_filter|get_option|update_option|esc_html|esc_attr|esc_url|wp_[a-z_]+\(|__\(|_e\(|current_user_can|get_post|WP_Query)/';
foreach ( $core_php as $f ) {
	$code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', file_get_contents( $f ) ); // ignore comments
	assert_false( preg_match( $wp_calls, $code, $m ), 'core is WordPress-free: ' . str_replace( $plugin_root . DIRECTORY_SEPARATOR, '', $f ) . ( $m ? " (found {$m[0]})" : '' ) );
}

// ─── 7. Nonce Patterns ─────────────────────────────────────

echo "--- 7. Security Patterns (Nonce + Sanitize) ---\n";

// All admin_post handlers should have nonce verification
$admin_app = file_get_contents( "$plugin_root/core/src/Admin/AdminApp.php" );
assert_true( strpos( $admin_app, 'csrf()->verify' ) !== false, 'Core admin verifies CSRF token on every POST' );
assert_true( strpos( $admin_app, '$access->level( $menu ) >= $level' ) !== false, 'Core admin checks the menu access level on every POST' );
assert_true( strpos( $admin_app, '$access->allowed( $permission )' ) !== false, 'Core admin checks action permissions (delete, CSV export, …)' );
assert_true( strpos( $admin_app, 'function canOpen' ) !== false, 'Core admin refuses hidden screens on GET' );
$wp_admin = file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-admin.php" );
assert_true( strpos( $wp_admin, "'load-' . \$this->hook" ) !== false && strpos( $wp_admin, "'response' => 403" ) !== false, 'WP adapter: hidden screens refused with 403 before output' );
assert_true( strpos( $wp_admin, "'edit_posts'" ) === false, 'WP adapter: menu visibility no longer tied to edit_posts' );
assert_true( strpos( $admin_app, 'host()->allows(' ) !== false, 'Core admin checks host policy (license)' );
$license_mgr = file_get_contents( "$plugin_root/includes/class-dw-license-manager.php" );
assert_true( strpos( $license_mgr, "'license_action'" ) !== false, "License SDK nonce action present" );

// ─── 8. SQL Safety ──────────────────────────────────────────

echo "--- 8. SQL Safety ---\n";

assert_true(
	strpos( $uninstall, 'wpdb->prepare' ) !== false,
	'uninstall.php uses $wpdb->prepare()'
);

// ─── 9. Sanitize on $_GET['page'] ───────────────────────────

echo "--- 9. Input Sanitization ---\n";

$files_with_get_page = array(
	'wp/class-dwcat-wp-admin.php',
	'wp/class-dwcat-wp-front.php',
);

foreach ( $files_with_get_page as $file ) {
	$content = file_get_contents( "$plugin_root/includes/$file" );
	// $_GET['page'] should only appear inside isset() or sanitize_text_field()
	// Find raw uses: $_GET['page'] NOT preceded by isset( or sanitize_text_field(
	$lines = explode( "\n", $content );
	$raw_uses = 0;
	foreach ( $lines as $line ) {
		if ( strpos( $line, "\$_GET['page']" ) === false ) continue;
		// OK patterns: isset( $_GET['page'] ) or sanitize_text_field( $_GET['page'] )
		$safe = preg_match( '/isset\(\s*\$_GET/', $line ) || preg_match( '/sanitize_text_field\(\s*\$_GET/', $line );
		if ( ! $safe ) {
			$raw_uses++;
		}
	}
	assert_true(
		$raw_uses === 0,
		"$file: no raw \$_GET['page'] access (found $raw_uses)"
	);
}

// ─── 10. License Integration ────────────────────────────────

echo "--- 10. License SDK (DW_DWCAT_License_Manager) ---\n";

$license_file = file_get_contents( "$plugin_root/includes/class-dw-license-manager.php" );
$client_file  = file_get_contents( "$plugin_root/includes/class-dw-forge-client.php" );
$gates_file   = file_get_contents( "$plugin_root/includes/license/gates.php" );

// legacy flat endpoints (API-CONTRACT §3 / §7.1)
assert_true( strpos( $license_file, '/license/activate' ) !== false, 'License SDK: /license/activate endpoint' );
assert_true( strpos( $license_file, '/license/verify' ) !== false, 'License SDK: /license/verify endpoint' );
assert_true( strpos( $license_file, '/license/deactivate' ) !== false, 'License SDK: /license/deactivate endpoint' );
assert_true( strpos( $license_file, '/releases/update-check' ) !== false, 'License SDK: /releases/update-check endpoint' );

// v3.0 envelope endpoints (API-CONTRACT §4 / §5 / §6)
assert_true( strpos( $license_file, '/auth/token' ) !== false, 'License SDK: /auth/token endpoint' );
assert_true( strpos( $license_file, '/configs/sign' ) !== false, 'License SDK: /configs/sign endpoint' );
assert_true( strpos( $license_file, '/integrity/' ) !== false, 'License SDK: /integrity manifest endpoint' );

// Prefix 배포 전략 (PLUGIN-DEV-GUIDE §3.5 B)
assert_true( strpos( $license_file, 'class DW_DWCAT_License_Manager' ) !== false, 'License SDK: prefixed class name' );
assert_true( strpos( $client_file, 'class DW_DWCAT_Forge_Client' ) !== false, 'Forge Client: prefixed class name' );
assert_false( preg_match( '/\bclass DW_License_Manager\b/', $license_file ), 'License SDK: unprefixed class NOT declared (winner race avoidance)' );

// 모든 외부 호출은 Forge Client 를 경유 (PLUGIN-DEV-GUIDE §1.3 SHOULD 4)
assert_true( strpos( $client_file, 'wp_remote_request' ) !== false, 'Forge Client: uses wp_remote_request' );
assert_false( strpos( $license_file, 'wp_remote_request' ) !== false, 'License SDK: does NOT bypass Forge Client' );

// 관리자 액션 보안
assert_true( strpos( $license_file, 'check_admin_referer' ) !== false, 'License SDK: admin-post nonce verification' );
assert_true( strpos( $license_file, 'current_user_can' ) !== false, 'License SDK: capability check' );
assert_true( strpos( $license_file, 'pre_set_site_transient_update_plugins' ) !== false, 'License SDK: auto-update hook' );
assert_true( strpos( $license_file, 'plugins_api' ) !== false, 'License SDK: plugin_info hook' );

// §11.1 운영자 통합 검증 호환성
assert_true( strpos( $license_file, 'resolve_token( true )' ) !== false, 'S11.1.a: activate 직후 강제 토큰 발급' );
assert_true( strpos( $license_file, 'maybe_lazy_refresh' ) !== false, 'S11.1.b: 어드민 페이지 lazy refresh' );
assert_true( strpos( $license_file, "'refresh'" ) !== false, 'S11.1.c: 명시적 새로고침 액션' );

// §11.2.c TAMPER_DETECTED 를 삼키지 않는다
assert_true( strpos( $license_file, 'TAMPER_DETECTED' ) !== false, 'S11.2.c: TAMPER_DETECTED 처리' );
assert_true( strpos( $license_file, 'mismatched_files' ) !== false, 'S11.2.c: mismatched_files 보존' );
assert_true( strpos( $license_file, 'render_admin_notices' ) !== false, 'S11.2.c: 어드민 notice 렌더' );

// §7.2.2 무결성 키 규약 — integrity.json 항목을 그대로 사용
assert_true( strpos( $license_file, 'integrity.json' ) !== false, 'S7.2.2: integrity.json 을 읽는다' );
assert_true( strpos( $license_file, "hash_file( 'sha256'" ) !== false, 'S7.2.2: SHA-256 hex' );

// PLUGIN-CALL-FLOWS §3 — synthetic_test 플래그는 절대 전송 금지
assert_false( preg_match( "/'synthetic_test'\s*=>/", $license_file ), 'synthetic_test 플래그를 전송하지 않는다' );

// API-CONTRACT §1.1 — 실 사이트 User-Agent 형식
assert_true( strpos( $client_file, 'DW-%s/%s (WordPress/%s; PHP/%s)' ) !== false, 'API-CONTRACT S1.1: User-Agent 형식' );

// Main file loads and initializes SDK
assert_true( strpos( $main_file, 'class-dw-license-manager.php' ) !== false, 'Main file loads license SDK' );
assert_true( strpos( $main_file, 'class-dw-forge-client.php' ) !== false, 'Main file loads Forge Client' );
assert_true( strpos( $main_file, 'license/gates.php' ) !== false, 'Main file loads license gates' );
assert_true( strpos( $main_file, 'DW_DWCAT_License_Manager::init' ) !== false, 'Main file calls DW_DWCAT_License_Manager::init()' );

// Migration (M1) — safety properties that must not regress
$mig_core = (string) file_get_contents( "$plugin_root/core/src/Migration/Migrator.php" );
$mig_src  = (string) file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-migration-source.php" );
$mig_wp   = (string) file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-migration.php" );
$wp_admin = (string) file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-admin.php" );
$wp_host  = (string) file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-host.php" );
assert_true( ! preg_match( '/\b(wp_delete_post|wp_delete_term|wp_delete_attachment|delete_post_meta|update_post_meta|wp_trash_post|DROP\s+TABLE|DELETE\s+FROM|UPDATE\s+\{|\$wpdb->(delete|update|insert|replace)\b)/i', $mig_src ), 'migration source never deletes or changes the original data' );
assert_true( 2 === substr_count( $mig_src, 'wp_update_post(' ), 'migration source writes only in replacePage / restorePage' );
assert_true( false === strpos( $mig_core, 'wpdb' ) && false === strpos( $mig_core, 'get_option' ), 'migrator (core) is WordPress-free' );
assert_true( false !== strpos( $wp_admin, "'migrate'       => 'dwcat_can_bulk_import'" ), 'migration actions are behind the import license gate' );
assert_true( false !== strpos( $mig_wp, 'dwcat_can_bulk_import()' ), 'background migration tick checks the license gate' );
assert_true( false !== strpos( $mig_wp, "current_user_can( 'edit_posts' )" ) && false !== strpos( $mig_wp, 'rest_pre_dispatch' ), 'old open REST list is closed to visitors' );
assert_true( false !== strpos( $wp_host, 'Require all denied' ) && false !== strpos( $wp_host, 'dw-wholesale-private' ), 'private directory is protected' );
assert_true( false !== strpos( (string) file_get_contents( "$plugin_root/core/src/Repo/Applications.php" ), "random_bytes( 20 ) ) . '.bin'" ), 'attachments are stored under random names without their extension' );
assert_true( false !== strpos( (string) file_get_contents( "$plugin_root/uninstall.php" ), 'dw-wholesale-private' ), 'uninstall removes the private files' );

// SPA Integration
$spa_file = file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-rest.php" );
assert_true( strpos( $spa_file, 'dw_spa_modules' ) !== false, 'Adapter registers dw_spa_modules filter' );
assert_true( strpos( $spa_file, "function_exists( 'dw_admin' )" ) !== false, 'SPA integration checks function_exists (no hard dependency)' );

// ─── 10a. 라이선스 게이트 분산 (PLUGIN-DEV-GUIDE §6) ─────────

echo "--- 10a. License Gates (anti-piracy) ---\n";

$gates = array(
	'dwcat_can_render',
	'dwcat_can_load_assets',
	'dwcat_can_save_meta',
	'dwcat_can_manage_fields',
	'dwcat_can_bulk_import',
	'dwcat_can_export_pdf',
	'dwcat_can_render_admin',
	'dwcat_can_use_shortcode',
	'dwcat_can_call_rest',
);
foreach ( $gates as $g ) {
	assert_true( strpos( $gates_file, "function $g(" ) !== false, "Gate defined: $g()" );
}
assert_true( count( $gates ) >= 5, 'S6 SHOULD: 5개 이상 게이트 분산 (' . count( $gates ) . '개)' );

// 게이트가 서로 다른 검증 경로를 쓰는가 — 전부 같은 함수면 한 줄 패치로 뚫린다
$verify_paths = array( 'has_valid_token', 'verify_token_signature', 'get_token_claims', 'get_status' );
foreach ( $verify_paths as $path_fn ) {
	assert_true( strpos( $gates_file, $path_fn ) !== false, "S6: 검증 경로 사용 — $path_fn" );
}

// is_callable 가드 (§3.3 / SDK-README) — method_exists 보다 정확
assert_true( strpos( $gates_file, 'is_callable' ) !== false, 'S3.3: is_callable() 가드' );

// dev bypass — 플러그인 코드가 아니라 wp-config.php 에서만 true 로 정의되어야 함
assert_true( strpos( $gates_file, 'DWCAT_DEV_BYPASS' ) !== false, 'dev bypass 상수 지원' );
assert_false(
	preg_match( "/define\(\s*'DWCAT_DEV_BYPASS'\s*,\s*true/", $gates_file ),
	'dev bypass 를 플러그인 코드에서 true 로 정의하지 않는다'
);

// Strict vs Loose 분리 (§3.4)
assert_true( strpos( $gates_file, 'function dwcat_is_licensed(' ) !== false, 'S3.4: Loose helper 존재' );

// v1.x 하위호환 유예 (KICKOFF FAQ Q8)
assert_true( strpos( $gates_file, 'dwcat_maybe_grant_legacy_grace' ) !== false, 'FAQ Q8: v1.x 마이그레이션 유예' );
assert_true( strpos( $main_file, 'dwcat_maybe_grant_legacy_grace' ) !== false, 'FAQ Q8: 마이그레이션에서 유예 부여' );

// 게이트가 실제 호출 지점에 적용되었는가
$gate_sites = array(
	'wp/class-dwcat-wp-front.php' => array( 'dwcat_can_render', 'dwcat_can_use_shortcode', 'dwcat_can_load_assets' ),
	'wp/class-dwcat-wp-admin.php' => array( 'dwcat_can_render_admin', 'dwcat_can_save_meta', 'dwcat_can_manage_fields', 'dwcat_can_bulk_import', 'dwcat_can_export_pdf' ),
	'wp/class-dwcat-wp-host.php'  => array( 'dwcat_can_render', 'dwcat_can_save_meta', 'dwcat_can_manage_fields', 'dwcat_can_bulk_import', 'dwcat_can_export_pdf', 'dwcat_can_render_admin', 'dwcat_can_load_assets' ),
	'wp/class-dwcat-wp-rest.php'  => array( 'dwcat_can_call_rest' ),
);
foreach ( $gate_sites as $gs_file => $gs_gates ) {
	$gs_content = file_get_contents( "$plugin_root/includes/$gs_file" );
	foreach ( $gs_gates as $gs_gate ) {
		assert_true( strpos( $gs_content, $gs_gate ) !== false, "Gate applied: $gs_gate in $gs_file" );
	}
}

// ─── 10c. 릴리스 패키징 계약 (§7.2 / §7.3 / §11.2.b) ─────────

echo "--- 10c. Release Packaging Contract ---\n";

$pkg = file_get_contents( "$plugin_root/.github/scripts/package-release.sh" );
assert_true( strpos( $pkg, 'wp-plugin' ) !== false, 'S7.2: wp-plugin/ 스테이징' );
assert_true( strpos( $pkg, 'manifest.json' ) !== false, 'S7.3: manifest.json 생성' );
assert_true( strpos( $pkg, 'SOURCE_DATE_EPOCH' ) !== false, 'S11.2.b: 결정론적 타임스탬프' );
assert_true( strpos( $pkg, 'zip -X' ) !== false, 'S11.2.b: zip -X (extra field 제거)' );
assert_true( strpos( $pkg, 'chmod 644' ) !== false, 'S11.2.b: 파일 권한 표준화' );
assert_true( strpos( $pkg, 'wp-config.php' ) !== false, 'FAQ Q7: wp-config.php 혼입 차단' );

$wf = file_get_contents( "$plugin_root/.github/workflows/release.yml" );
assert_true( strpos( $wf, 'verify-package.js' ) !== false, 'CI: 패키지 계약 검증 실행' );
assert_true( strpos( $wf, 'composer.lock' ) !== false, 'CI: composer.lock 존재 강제' );
assert_true( strpos( $wf, 'build #2' ) !== false, 'S11.3.4: 두 번 빌드해 SHA256 비교' );

$ga = file_get_contents( "$plugin_root/.gitattributes" );
assert_true( strpos( $ga, 'text=auto eol=lf' ) !== false, 'S11.2.b: 라인엔딩 통일' );
assert_true( strpos( $ga, 'binary' ) !== false, 'S11.2.b: 바이너리 명시' );

// integrity.json 은 커밋 대상이 아니다 (빌드 산출물)
$gi = file_get_contents( "$plugin_root/.gitignore" );
assert_true( strpos( $gi, '/integrity.json' ) !== false, 'integrity.json 은 gitignore (stale 매니페스트 방지)' );
assert_false( file_exists( "$plugin_root/integrity.json" ), 'integrity.json 이 소스트리에 커밋되어 있지 않다' );

// ─── 10d. 에러 코드 · 유예 · 멀티사이트 · 진단 (§9.5 / FAQ Q10·Q11 / §3.6) ───

echo "--- 10d. Error handling, grace, multisite, diagnostics ---
";

// §9.5 — 운영자 조치가 필요한 코드를 어드민에 노출하는가
foreach ( array( 'VERSION_REVOKED', 'DOMAIN_NOT_AUTHORIZED', 'DOMAIN_LIMIT_REACHED' ) as $ec ) {
	assert_true( strpos( $license_file, $ec ) !== false, "S9.5: $ec 처리" );
}
assert_true( strpos( $license_file, 'platform_notice' ) !== false, 'S9.5: 플랫폼 조치 필요 코드를 어드민에 노출' );

// §10.2 / FAQ Q11 — 프런트엔드에서 동기 HTTP 금지
assert_true( strpos( $license_file, 'should_defer_network' ) !== false, 'S10.2: 프런트엔드 동기 HTTP 차단 로직' );
assert_true( strpos( $license_file, 'schedule_refresh' ) !== false, 'S10.2: 갱신을 cron 으로 위임' );

// ERROR-CODES §1 / FAQ Q9 — 만료 유예를 네트워크 장애와 분리
assert_true( strpos( $license_file, 'EXPIRY_GRACE' ) !== false, 'FAQ Q9: 라이선스 만료 유예 상수' );
assert_true( strpos( $license_file, 'OFFLINE_GRACE' ) !== false, 'FAQ Q11: 네트워크 장애 유예 상수' );
assert_true( strpos( $license_file, 'OFFLINE_NOTICE_AFTER' ) !== false, 'FAQ Q11 #2: 24h 연속 실패 안내' );

// FAQ Q10 — 멀티사이트
assert_true( strpos( $license_file, 'is_multisite' ) !== false, 'FAQ Q10: 멀티사이트 감지' );
assert_true( strpos( $license_file, 'get_network_option' ) !== false, 'FAQ Q10: 네트워크 옵션에 라이선스 저장' );
assert_true( strpos( $license_file, 'network_admin_menu' ) !== false, 'FAQ Q10: 네트워크 어드민 메뉴' );
assert_true( strpos( $license_file, 'manage_network_options' ) !== false, 'FAQ Q10: 네트워크 권한 검사' );

// §3.6 SHOULD #2 — introspection endpoint
$rest_file = file_get_contents( "$plugin_root/includes/class-dwcat-license-rest.php" );
assert_true( strpos( $rest_file, 'license/status' ) !== false, 'S3.6: /license/status 라우트' );
assert_true( strpos( $rest_file, 'sdk_class_loaded_from' ) !== false || strpos( $license_file, 'sdk_class_loaded_from' ) !== false,
	'S3.6: sdk_class_loaded_from 필드' );
assert_true( strpos( $license_file, 'sdk_methods_available' ) !== false, 'S3.6: sdk_methods_available 필드' );
// 주석에 '__return_true 금지' 라고 적어 둔 것까지 잡히면 안 되므로
// **실제 콜백 지정 구문**만 검사한다.
assert_false(
	preg_match( "/'permission_callback'\s*=>\s*'?__return_true/", $rest_file ),
	'REST permission_callback 에 __return_true 미사용'
);
assert_true( strpos( $rest_file, 'current_user_can' ) !== false, 'REST 권한 검사 존재' );

// P15/P16 — 진단이 비밀을 노출하지 않는지 (정적 확인; 동작 확인은 test-bootstrap.php)
assert_true( strpos( $license_file, 'license_present' ) !== false, 'P15: 라이선스 키 대신 boolean 노출' );
assert_true( strpos( $license_file, 'token_present' ) !== false, 'P15: 토큰 대신 boolean 노출' );

// 공개키 위생
$pub = file_get_contents( "$plugin_root/includes/keys/dasomforge.pub" );
assert_true( strpos( $pub, 'BEGIN PUBLIC KEY' ) !== false, 'dasomforge.pub 은 PEM 공개키' );
assert_false( strpos( $pub, 'PRIVATE KEY' ) !== false, 'dasomforge.pub 에 비밀키가 없다' );

// ─── 11. Deactivation Hook ─────────────────────────────────

echo "--- 10b. Frontend + data exposure ---\n";

$front = file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-front.php" );
foreach ( array( 'dw_catalog', 'dw_catalog_grid', 'dw_catalog_carousel' ) as $sc ) {
	assert_true( strpos( $front, "add_shortcode( '$sc'" ) !== false, "Shortcode registered: $sc" );
}
assert_true( strpos( $front, 'shortcode_atts' ) !== false, 'Shortcodes use shortcode_atts()' );
assert_true( strpos( $front, 'add_rewrite_endpoint' ) !== false, 'Pretty item URLs (rewrite endpoint)' );
assert_true( strpos( $front, 'application/ld+json' ) !== false, 'Item pages print JSON-LD' );
assert_true( strpos( $front, 'WP_Sitemaps_Provider' ) !== false, 'Items in the XML sitemap' );

$rest = file_get_contents( "$plugin_root/includes/wp/class-dwcat-wp-rest.php" );
assert_true( strpos( $rest, "current_user_can( 'edit_posts' )" ) !== false, 'Item REST route requires edit_posts' );
assert_false( preg_match( "/'permission_callback'\s*=>\s*'?__return_true/", $rest ), 'No public REST route for items' );

$front_app = file_get_contents( "$plugin_root/core/src/Front/FrontApp.php" );
assert_true( strpos( $front_app, "'public'   => true" ) !== false, 'Visitor queries run with public=true (non-public fields excluded)' );
assert_true( strpos( $front_app, 'FieldConfig::isPublic' ) !== false, 'Visitor output filters non-public fields' );

// No catalog data in WordPress content tables
$all_wp_side = '';
foreach ( glob( "$plugin_root/includes/wp/*.php" ) as $f ) {
	$all_wp_side .= file_get_contents( $f );
}
assert_false( preg_match( '/register_post_type|register_taxonomy|update_post_meta|wp_insert_post|media_handle_/', $all_wp_side ), 'Adapter stores no catalog data in WordPress tables' );

$css = file_get_contents( "$plugin_root/core/assets/front.css" );
assert_true( strpos( $css, 'var(--dwcat-title-size' ) !== false, 'CSS uses --dwcat-title-size variable' );
assert_true( strpos( $css, 'var(--dwcat-card-bg' ) !== false, 'CSS uses --dwcat-card-bg variable' );

$uninstall2 = file_get_contents( "$plugin_root/uninstall.php" );
assert_true( strpos( $uninstall2, 'Schema::drop' ) !== false, 'uninstall.php drops the catalog tables' );
assert_true( strpos( $uninstall2, 'dw-catalog' ) !== false, 'uninstall.php removes catalog image files' );

echo "--- 11. Activation/Deactivation Hooks ---\n";

assert_true( strpos( $main_file, 'register_activation_hook' ) !== false, 'Activation hook registered' );
assert_true( strpos( $main_file, 'register_deactivation_hook' ) !== false, 'Deactivation hook registered' );
assert_true( strpos( $main_file, 'flush_rewrite_rules' ) !== false, 'Activation/deactivation flushes rewrite rules' );

// ─── 12. Migration Logic ────────────────────────────────────

echo "--- 12. Migration Logic ---\n";

assert_true( strpos( $main_file, 'dwcat_migrate' ) !== false, 'Migration function exists' );
assert_true( strpos( $main_file, 'dwcat_check_version' ) !== false, 'Version check on admin_init exists' );

// ─── Results ────────────────────────────────────────────────

echo "\n=== RESULTS ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";

if ( ! empty( $errors ) ) {
	echo "\nFailed tests:\n";
	foreach ( $errors as $e ) {
		echo "  - $e\n";
	}
}

echo "\n" . ( $fail === 0 ? "ALL TESTS PASSED" : "$fail TEST(S) FAILED" ) . "\n";
exit( $fail > 0 ? 1 : 0 );
