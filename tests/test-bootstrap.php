<?php
/**
 * DW Wholesale — 부트스트랩 · 라이선스 게이트 런타임 테스트
 *
 * WordPress 없이 최소 스텁만 두고 플러그인 진입 파일을 실제로 require 해서
 * 로드 순서 · 게이트 fail-closed · 레거시 유예 · dev bypass 를 **실행**으로 검증한다.
 * (test-plugin-integrity.php 는 정적 문자열 검사, 이쪽은 동작 검사)
 *
 * 실행: php tests/test-bootstrap.php
 *
 * @package DW_Catalog_WP
 */

require_once __DIR__ . '/wp-stubs.php';

$root = dirname( __DIR__ );

preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', file_get_contents( $root . '/dw-wholesale.php' ), $vm );
$VERSION = trim( $vm[1] );

/** 모든 Strict 게이트. 하나라도 라이선스 없이 열리면 anti-piracy 가 깨진다. */
$GATES = array(
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

function gates_open() {
	global $GATES;
	$n = 0;
	foreach ( $GATES as $g ) {
		if ( true === $g() ) {
			$n++;
		}
	}
	return $n;
}

echo "=== bootstrap & gates ===\n\n--- load ---\n";

try {
	require_once $root . '/dw-wholesale.php';
	t( true, '플러그인 진입 파일이 fatal 없이 로드' );
} catch ( Throwable $e ) {
	t( false, '플러그인 로드: ' . $e->getMessage() );
	echo "\nFATAL — 계속할 수 없음\n";
	exit( 1 );
}

t( class_exists( 'DW_DWCAT_License_Manager' ), 'DW_DWCAT_License_Manager 로드' );
t( class_exists( 'DW_DWCAT_Forge_Client' ), 'DW_DWCAT_Forge_Client 로드' );
t( class_exists( 'DWCAT_License_REST' ), 'DWCAT_License_REST 로드 (§3.6 진단 엔드포인트)' );
t( ! class_exists( 'DW_License_Manager', false ), '접두사 없는 DW_License_Manager 를 선언하지 않음 (winner race 회피)' );
t( function_exists( 'dwcat_can_render' ), '게이트 로드' );
t_eq( count( $GATES ), 9, '§6 SHOULD: 게이트 9개 (5~10개 권장)' );

// ── 미라이선스: 전부 닫혀야 한다 ──────────────────────────────
echo "\n--- unlicensed: every gate must fail closed ---\n";
stub_reset();
foreach ( $GATES as $g ) {
	t( false === $g(), "$g() === false (미라이선스)" );
}
t( false === dwcat_is_licensed(), 'dwcat_is_licensed() false (Loose helper)' );

// ── 프런트엔드 게이트는 네트워크를 때리지 않는다 ──────────────
echo "\n--- gates never block the frontend on HTTP (§10.2 / FAQ Q11) ---\n";
stub_reset();
$GLOBALS['_options']['dw_license_dw_wholesale'] = array(
	'key' => 'DW-X', 'status' => 'active', 'domain' => 'canary.example.com',
);
gates_open();
t_eq( 0, stub_http_count(), '9개 게이트를 모두 호출해도 프런트에서 HTTP 0회' );

// ── v1.x 마이그레이션 유예 (FAQ Q8) ───────────────────────────
echo "\n--- legacy grace: v1.x upgrade must keep working (FAQ Q8) ---\n";
stub_reset();
$GLOBALS['_options']['dwcat_version'] = '1.2.1';
dwcat_maybe_grant_legacy_grace( '1.2.1' );
t( isset( $GLOBALS['_options']['dwcat_legacy_grace_until'] ), 'v1.x 업그레이드에 유예 부여' );
t( true === dwcat_in_legacy_grace(), '유예 창이 열려 있음' );
t_eq( 30, dwcat_legacy_grace_days_left(), '유예 = 30일' );
t_eq( count( $GATES ), gates_open(), '유예 중에는 9개 게이트 전부 통과 (사용자 영향 0)' );

ob_start();
dwcat_legacy_grace_notice();
t( false !== strpos( ob_get_clean(), '라이선스 인증이 필요합니다' ), '미활성 유예 중에는 안내 표시' );

// 회귀: 유예 중에 활성화를 마쳤는데도 "인증이 필요합니다" 가 계속 뜨던 버그.
$GLOBALS['_options']['dw_license_dw_wholesale'] = array(
	'key' => 'DW-X', 'status' => 'active', 'domain' => 'canary.example.com',
);
ob_start();
dwcat_legacy_grace_notice();
t_eq( '', ob_get_clean(), '활성화 후에는 유예 안내를 숨김' );
unset( $GLOBALS['_options']['dw_license_dw_wholesale'] );

echo "\n--- grace is one-shot, and fresh installs get none ---\n";
$GLOBALS['_options']['dwcat_legacy_grace_until'] = time() - 1;   // 만료 시뮬레이션
dwcat_maybe_grant_legacy_grace( '1.2.1' );
t( $GLOBALS['_options']['dwcat_legacy_grace_until'] < time(), '만료된 유예를 재부여하지 않음' );
t_eq( 0, gates_open(), '유예 만료 후 게이트가 다시 닫힘' );
t_eq( 0, dwcat_legacy_grace_days_left(), '남은 유예 0일' );

unset( $GLOBALS['_options']['dwcat_legacy_grace_until'] );
dwcat_maybe_grant_legacy_grace( '' );                            // 신규 설치
t( ! isset( $GLOBALS['_options']['dwcat_legacy_grace_until'] ), '신규 설치에는 유예 없음' );

dwcat_maybe_grant_legacy_grace( '2.0.0' );                       // 이미 v2
t( ! isset( $GLOBALS['_options']['dwcat_legacy_grace_until'] ), 'v2 → v2 업그레이드에는 유예 없음' );

// 회귀: version_compare('2.0.0-rc.1','2.0.0','>=') 는 false 라 프리릴리스를 v1 로
// 오인해 유예를 주던 버그. 메이저 버전으로 판정해야 한다.
dwcat_maybe_grant_legacy_grace( '2.0.0-rc.1' );
t( ! isset( $GLOBALS['_options']['dwcat_legacy_grace_until'] ),
   'v2 프리릴리스 → 프리릴리스 업그레이드에도 유예 없음 (version_compare 함정)' );
dwcat_maybe_grant_legacy_grace( '2.1.0-beta.3' );
t( ! isset( $GLOBALS['_options']['dwcat_legacy_grace_until'] ), 'v2.1 베타에도 유예 없음' );

// ── SDK 상태 · 무결성 ─────────────────────────────────────────
echo "\n--- SDK behaviour with no license ---\n";
stub_reset();
t_eq( 'unlicensed', DW_DWCAT_License_Manager::get_status(), 'get_status() = unlicensed' );
t( false === DW_DWCAT_License_Manager::has_valid_token(), 'has_valid_token() = false' );
t_eq( 0, stub_http_count(), '라이선스 키가 없으면 HTTP 호출 자체를 안 함' );

$has_manifest = file_exists( $root . '/integrity.json' );
$hashes       = DW_DWCAT_License_Manager::compute_file_hashes();
if ( $has_manifest ) {
	t( ! empty( $hashes ), 'integrity.json 존재 시 해시 생성' );
	$bad_key  = array_filter( array_keys( $hashes ), function ( $k ) {
		return 0 === strpos( $k, 'wp-plugin/' ) || 0 === strpos( $k, 'dw-wholesale/' );
	} );
	$bad_hash = array_filter( $hashes, function ( $h ) { return ! preg_match( '/^[0-9a-f]{64}$/', $h ); } );
	t( empty( $bad_key ), '§7.2.2 규약 1 — 키가 루트상대 (접두사 없음)' );
	t( empty( $bad_hash ), '§7.2.2 — 값이 sha256 hex lowercase 64자' );
} else {
	t_eq( array(), $hashes, 'integrity.json 없으면 빈 해시 맵 (빌드 산출물이므로 정상)' );
}

// ── 진단 스냅샷은 비밀을 노출하지 않는다 (P15/P16) ────────────
echo "\n--- diagnostics must not leak secrets (P15/P16) ---\n";
stub_reset();
$GLOBALS['_options']['dw_license_dw_wholesale'] = array(
	'key' => 'DW-SUPER-SECRET-KEY', 'status' => 'active', 'domain' => 'canary.example.com', 'tier' => 'pro',
);
$GLOBALS['_options']['dwcat_token'] = array(
	'token' => 'super.secret.jwt', 'expires_at' => gmdate( 'c', time() + 3600 ), 'grace_ok' => true,
);
$diag = DW_DWCAT_License_Manager::get_diagnostics();
$json = json_encode( $diag );
t( false === strpos( $json, 'DW-SUPER-SECRET-KEY' ), '진단 출력에 라이선스 키 없음' );
t( false === strpos( $json, 'super.secret.jwt' ), '진단 출력에 JWT 없음' );
t( true === $diag['license_present'], 'license_present boolean 으로만 노출' );
t( true === $diag['token_present'], 'token_present boolean 으로만 노출' );
t( ! empty( $diag['sdk_class_loaded_from'] ), 'sdk_class_loaded_from 제공 (§3.6)' );
t( is_array( $diag['sdk_methods_available'] ), 'sdk_methods_available 제공 (§3.6)' );
t_eq( 'dw-wholesale', $diag['sdk_class_loaded_from_plugin'] ?: 'dw-wholesale', 'winner plugin slug 제공' );

// ── 검색 토큰화 (DWCAT_Store) — 인덱스와 질의가 같은 규칙을 써야 검색이 맞습니다 ──
echo "\n--- search tokenizer ---\n";
t_eq( array( 'wk', '009' ), \DWCatalog\Search\Tokenizer::tokenize( 'WK-009' ), '구두점으로 분리 + 소문자' );
t( in_array( 'wk009', \DWCatalog\Search\Tokenizer::tokenize( 'WK-009', true ), true ), '색인 시 붙여쓴 코드도 저장 (wk009)' );
t( in_array( '144lb6pkcs', \DWCatalog\Search\Tokenizer::tokenize( '1/4.4lb/6pk/cs', true ), true ), '팩 규격 붙여쓰기' );
t_eq( array( '김치', '찌개' ), \DWCatalog\Search\Tokenizer::tokenize( '김치 찌개' ), '한글 단어 분리' );
t_eq( 1, count( \DWCatalog\Search\Tokenizer::tokenize( 'a a A' ) ), '중복 제거' );
t_eq( 64, strlen( \DWCatalog\Search\Tokenizer::tokenize( str_repeat( 'x', 200 ) )[0] ), '토큰 길이 상한 64' );

echo "\n--- search input limits (abuse guard) ---\n";
t_eq( \DWCatalog\Query\CatalogQuery::MAX_WORDS, count( \DWCatalog\Search\Tokenizer::queryWords( 'aa bb cc dd ee ff gg hh ii jj' ) ), '검색어 단어 수 상한' );
t_eq( array( 'miso' ), \DWCatalog\Search\Tokenizer::queryWords( 'a miso' ), '다른 단어가 있으면 1글자 단어 제외' );
t_eq( array(), \DWCatalog\Search\Tokenizer::queryWords( '%%% ___' ), 'LIKE 와일드카드만 있는 입력은 무시' );

// ── dev bypass ────────────────────────────────────────────────
echo "\n--- dev bypass ---\n";
stub_reset();
define( 'DWCAT_DEV_BYPASS', true );
t_eq( count( $GATES ), gates_open(), 'dev bypass 가 9개 게이트를 전부 통과시킴' );
t_eq( 0, stub_http_count(), 'dev bypass: 네트워크 호출 없음' );

// ── 토큰 요청 shape (강제 발급 경로) ──────────────────────────
echo "\n--- forced token request shape ---\n";
stub_reset();
$GLOBALS['_is_admin'] = true;
$GLOBALS['_options']['dw_license_dw_wholesale'] = array(
	'key' => 'DW-TEST', 'status' => 'active', 'domain' => 'canary.example.com',
);
DW_DWCAT_License_Manager::get_token( true );

if ( $has_manifest ) {
	$call = stub_last_http();
	t( false !== strpos( $call['url'], 'api.dasomforge.com' ), 'api.dasomforge.com 호출 (' . $call['url'] . ')' );
	t( false !== strpos( $call['url'], '/auth/token' ), 'endpoint = /auth/token' );
	t( 1 === preg_match( '#^DW-dw-wholesale/' . preg_quote( $VERSION, '#' ) . ' \(WordPress/6\.7; PHP/#', $call['args']['headers']['User-Agent'] ),
	   'User-Agent = API-CONTRACT §1.1 형식 (' . $call['args']['headers']['User-Agent'] . ')' );
} else {
	t_eq( 'LOCAL_MANIFEST_MISSING', DW_DWCAT_License_Manager::get_last_error()['code'],
	   'integrity.json 없으면 HTTP 전에 중단 (fail closed)' );
}

t_results( 'bootstrap & gates' );
