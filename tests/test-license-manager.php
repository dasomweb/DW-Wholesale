<?php
/**
 * DW Wholesale — DASOM-Forge SDK 동작 테스트
 *
 * PLUGIN-DEV-GUIDE §9.1 이 요구하는 커버리지:
 *   · 토큰 발급/캐시            · 401 자동 재발급 (1회만)
 *   · grace 모드                · 서명 검증
 * + PLUGIN-CALL-FLOWS §2 자가 검증 + §9.3 anti-piracy 회귀
 *
 * WordPress 없이 스텁 + HTTP 모킹으로 **실제 SDK 코드 경로**를 실행합니다.
 *
 * 실행: php tests/test-license-manager.php
 *
 * @package DW_Catalog_WP
 */

require_once __DIR__ . '/wp-stubs.php';

$root = dirname( __DIR__ );
require_once $root . '/includes/class-dw-forge-client.php';
require_once $root . '/includes/class-dw-license-manager.php';

const SLUG = 'dw-wholesale';
const PREF = 'dwcat_';

/** 버전을 하드코딩하지 않는다 — 플러그인 헤더가 유일한 출처. */
function plugin_version_from_header( $root ) {
	preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', file_get_contents( $root . '/dw-wholesale.php' ), $m );
	return trim( $m[1] );
}
$VERSION = plugin_version_from_header( $root );

/**
 * SDK 는 integrity.json 이 없으면 /auth/token 을 **아예 호출하지 않습니다**
 * (fail closed — §16-3 에서 그 동작 자체를 검증합니다).
 * 나머지 테스트는 "매니페스트가 있는 정상 설치"를 전제하므로 픽스처를 만듭니다.
 * integrity.json 은 빌드 산출물이라 소스트리에는 커밋되지 않습니다.
 */
$manifest_path    = $root . '/integrity.json';
$created_manifest = false;
if ( ! file_exists( $manifest_path ) ) {
	file_put_contents( $manifest_path, json_encode( array(
		'version' => plugin_version_from_header( $root ),
		'files'   => array( 'dw-wholesale.php', 'includes/license/gates.php' ),
	), JSON_PRETTY_PRINT ) );
	$created_manifest = true;
}
register_shutdown_function( function () use ( $manifest_path, $created_manifest ) {
	if ( $created_manifest && file_exists( $manifest_path ) ) {
		unlink( $manifest_path );
	}
} );

DW_DWCAT_License_Manager::init( array(
	'product_slug'    => SLUG,
	'cache_prefix'    => PREF,
	'plugin_file'     => $root . '/dw-wholesale.php',
	'plugin_basename' => 'dw-wholesale/dw-wholesale.php',
	'plugin_name'     => 'DW Wholesale',
	'plugin_version'  => $VERSION,
	'settings_page'   => 'dw-wholesale-license',
	'public_keys'     => array( $root . '/includes/keys/dasomforge.pub' ),
) );

// ── 테스트 픽스처 ────────────────────────────────────────────

function fixture_license( $status = 'active' ) {
	$GLOBALS['_options']['dw_license_dw_wholesale'] = array(
		'key'    => 'DW-TEST-TEST-TEST',
		'status' => $status,
		'domain' => 'canary.example.com',
	);
}

/** 만료 시각을 지정해 토큰 캐시를 심는다. */
function fixture_token( $expires_in ) {
	$GLOBALS['_options'][ PREF . 'token' ] = array(
		'token'      => 'header.payload.sig',
		'expires_at' => gmdate( 'c', time() + $expires_in ),
		'tier'       => 'basic',
		'features'   => array(),
		'fetched_at' => time(),
		'grace_ok'   => true,
	);
}

function ok_token( $expires_in = 3600 ) {
	return stub_response( 200, array(
		'ok'   => true,
		'data' => array(
			'token'            => 'h.p.s',
			'expires_at'       => gmdate( 'c', time() + $expires_in ),
			'tier'             => 'basic',
			'features'         => array(),
			'next_check_after' => 3300,
		),
	) );
}

function err( $status, $code, array $details = array() ) {
	return stub_response( $status, array(
		'ok'    => false,
		'error' => array( 'code' => $code, 'message' => 'x', 'details' => $details ),
	) );
}

/** 대부분의 테스트는 어드민 컨텍스트 (프런트는 동기 호출을 하지 않으므로). */
function as_admin() {
	$GLOBALS['_is_admin'] = true;
}

// ─────────────────────────────────────────────────────────────
echo "=== DW Wholesale — SDK behaviour tests ===\n";

// ── 1. /license/activate — legacy flat 파싱 + §11.1.a 즉시 토큰 ──
echo "\n--- 1. activate (legacy flat) + §11.1.a immediate token ---\n";
stub_reset(); as_admin();
stub_http_queue( array(
	stub_response( 200, array(
		'success'        => true,
		'message'        => 'ok',
		'license_id'     => 123,
		'tier'           => 'basic',
		'features'       => array(),
		'max_domains'    => 3,
		'active_domains' => array( 'canary.example.com' ),
		'expires_at'     => gmdate( 'c', time() + 31536000 ),
	) ),
	ok_token(),
) );

$r = DW_DWCAT_License_Manager::activate( 'DW-TEST-TEST-TEST', 'canary.example.com' );
t( ! empty( $r['success'] ), 'activate: legacy flat success 필드로 판정' );
t_eq( 2, stub_http_count(), '§11.1.a: activate 직후 /auth/token 을 동기 호출 (총 2회)' );
$calls = $GLOBALS['_http_calls'];
t( false !== strpos( $calls[0]['url'], '/license/activate' ), '1st call = /license/activate' );
t( false !== strpos( $calls[1]['url'], '/auth/token' ), '2nd call = /auth/token' );
t_eq( 'active', DW_DWCAT_License_Manager::get_license_data()['status'], 'activate: 상태 저장' );
t_eq( 3, DW_DWCAT_License_Manager::get_license_data()['max_domains'], 'activate: v3 필드(max_domains) 저장' );

// envelope 로 오독하지 않는지 — success 없는 응답은 실패여야 함
stub_reset(); as_admin();
stub_http_queue( array( stub_response( 200, array( 'success' => false, 'error' => '만료된 라이선스입니다.' ) ) ) );
$r = DW_DWCAT_License_Manager::activate( 'DW-BAD', 'canary.example.com' );
t( empty( $r['success'] ), 'activate 실패: success=false 를 실패로 판정' );
t_eq( '만료된 라이선스입니다.', $r['message'], 'activate 실패: 서버 message 를 그대로 노출' );

// ── 2. 토큰 캐시 — 반복 호출이 서버를 다시 때리지 않는다 (§10.2) ──
echo "\n--- 2. token cache (§10.2 매 페이지뷰 갱신 ❌) ---\n";
stub_reset(); as_admin(); fixture_license(); fixture_token( 3600 );
for ( $i = 0; $i < 5; $i++ ) {
	DW_DWCAT_License_Manager::has_valid_token();
}
t_eq( 0, stub_http_count(), '유효 캐시가 있으면 5회 호출해도 HTTP 0회' );

// 만료 5분 이내 → 캐시는 그대로 쓰되 백그라운드 갱신 예약
stub_reset(); as_admin(); fixture_license(); fixture_token( 120 );
$tok = DW_DWCAT_License_Manager::get_token();
t( ! empty( $tok ), '만료 임박: 캐시 토큰을 그대로 반환 (블로킹 없음)' );
t_eq( 0, stub_http_count(), '만료 임박: 동기 호출 없음' );
t( false !== wp_next_scheduled( PREF . 'refresh_token' ), '만료 임박: 백그라운드 갱신 예약' );

// ── 3. 401 은 1회만 재시도 (§9.5 / CALL-FLOWS §2) ──
echo "\n--- 3. 401 retry exactly once ---\n";
stub_reset(); as_admin(); fixture_license();
stub_http_queue( array( err( 401, 'TOKEN_EXPIRED' ), ok_token() ) );
$tok = DW_DWCAT_License_Manager::get_token( true );
t( ! empty( $tok ), '401 → 1회 재시도로 복구' );
t_eq( 2, stub_http_count(), '401 복구: 정확히 2회 호출' );

stub_reset(); as_admin(); fixture_license();
stub_http_queue( array( err( 401, 'TOKEN_EXPIRED' ), err( 401, 'TOKEN_EXPIRED' ), ok_token() ) );
$tok = DW_DWCAT_License_Manager::get_token( true );
t( null === $tok, '401 연속: 무한 루프 없이 포기' );
t_eq( 2, stub_http_count(), '401 연속: 재시도는 1회뿐 (총 2회, 3번째 큐는 미소비)' );

// ── 4. grace — 네트워크 장애 24h ──
echo "\n--- 4. offline grace 24h (FAQ Q11) ---\n";
stub_reset(); as_admin(); fixture_license(); fixture_token( -60 );  // 1분 전 만료
stub_http_queue( array( 'network_error' ) );
$tok = DW_DWCAT_License_Manager::get_token();
t( ! empty( $tok ), '서버 미도달: 만료된 캐시 토큰으로 계속 동작' );

stub_reset(); as_admin(); fixture_license(); fixture_token( -( 25 * HOUR_IN_SECONDS ) );
stub_http_queue( array( 'network_error' ) );
t( null === DW_DWCAT_License_Manager::get_token(), '25시간 경과: grace 종료 → null' );

// ── 5. grace — 라이선스 만료 30일 (ERROR-CODES §1 / FAQ Q9) ──
echo "\n--- 5. expiry grace 30d — 결제 지연 고객 보호 ---\n";
stub_reset(); as_admin(); fixture_license( 'expired' ); fixture_token( -( 10 * DAY_IN_SECONDS ) );
stub_http_queue( array( err( 403, 'LICENSE_EXPIRED' ) ) );
t( ! empty( DW_DWCAT_License_Manager::get_token() ), '만료 10일차: 아직 동작 (즉시 차단 ❌)' );

stub_reset(); as_admin(); fixture_license( 'expired' ); fixture_token( -( 31 * DAY_IN_SECONDS ) );
stub_http_queue( array( err( 403, 'LICENSE_EXPIRED' ) ) );
t( null === DW_DWCAT_License_Manager::get_token(), '만료 31일차: 차단' );

// ── 6. invalidated 는 grace 없음 (API-CONTRACT §9.5) ──
echo "\n--- 6. LICENSE_INVALIDATED — no grace ---\n";
stub_reset(); as_admin(); fixture_license(); fixture_token( 3600 );
stub_http_queue( array( err( 403, 'LICENSE_INVALIDATED' ) ) );
DW_DWCAT_License_Manager::get_token( true );
t_eq( 'invalidated', DW_DWCAT_License_Manager::get_license_data()['status'], 'invalidated 상태 기록' );
t( empty( $GLOBALS['_options'][ PREF . 'token' ] ), 'invalidated: 캐시 토큰 즉시 폐기' );
t( false === DW_DWCAT_License_Manager::has_valid_token(), 'invalidated: 게이트가 즉시 닫힘' );

// ── 7. TAMPER_DETECTED — mismatched_files 보존 (§11.2.c) ──
echo "\n--- 7. TAMPER_DETECTED (§11.2.c swallow 금지) ---\n";
stub_reset(); as_admin(); fixture_license();
stub_http_queue( array( err( 403, 'TAMPER_DETECTED', array(
	'mismatched_files' => array( 'dw-wholesale.php', 'includes/class-pc-config.php' ),
) ) ) );
DW_DWCAT_License_Manager::get_token( true );
$tamper = DW_DWCAT_License_Manager::get_tamper_notice();
t_eq( 2, count( $tamper['files'] ), 'mismatched_files 2건 그대로 보존' );
t( in_array( 'dw-wholesale.php', $tamper['files'], true ), '파일명 원본 유지' );
t_eq( 'tamper_detected', DW_DWCAT_License_Manager::get_status(), 'status = tamper_detected' );

// ── 8. MANIFEST_INCOMPLETE — missing_files 보존 ──
echo "\n--- 8. MANIFEST_INCOMPLETE ---\n";
stub_reset(); as_admin(); fixture_license();
stub_http_queue( array( err( 400, 'MANIFEST_INCOMPLETE', array( 'missing_files' => array( 'vendor/x.php' ) ) ) ) );
DW_DWCAT_License_Manager::get_token( true );
$tamper = DW_DWCAT_License_Manager::get_tamper_notice();
t_eq( array( 'vendor/x.php' ), $tamper['missing'], 'missing_files 보존' );

// ── 9. 운영자 조치가 필요한 코드는 재시도하지 않는다 (ERROR-CODES §3) ──
echo "\n--- 9. operator-actionable codes: back off, surface, do not retry ---\n";
foreach ( array( 'INTEGRITY_MANIFEST_NOT_FOUND', 'VERSION_REVOKED', 'DOMAIN_NOT_AUTHORIZED' ) as $code ) {
	stub_reset(); as_admin(); fixture_license();
	stub_http_queue( array( err( 404, $code ) ) );
	DW_DWCAT_License_Manager::get_token( true );
	$n = get_option( PREF . 'platform_notice' );
	t( is_array( $n ) && $n['code'] === $code, "$code: 어드민 노출용 platform_notice 기록" );

	$before = stub_http_count();
	DW_DWCAT_License_Manager::get_token();          // 백오프 중
	t_eq( $before, stub_http_count(), "$code: 백오프 중 재호출 없음" );
}

// ── 10. 프런트엔드에서 동기 HTTP 금지 (§10.2 / FAQ Q11) ──
echo "\n--- 10. no blocking HTTP on frontend ---\n";
stub_reset(); fixture_license();   // is_admin = false
$GLOBALS['_is_admin'] = false;
$tok = DW_DWCAT_License_Manager::get_token();
t_eq( 0, stub_http_count(), '프런트엔드: 캐시가 없어도 동기 HTTP 0회' );
t( null === $tok, '프런트엔드: 토큰 없으면 null (게이트가 닫힘)' );
t( false !== wp_next_scheduled( PREF . 'refresh_token' ), '프런트엔드: 갱신을 cron 으로 위임' );

stub_reset(); as_admin(); fixture_license();
stub_http_queue( array( ok_token() ) );
DW_DWCAT_License_Manager::get_token();
t_eq( 1, stub_http_count(), '어드민: 동기 발급 허용 (§11.1.b)' );

// ── 11. 요청 shape — UA · synthetic_test 미전송 · 무결성 필드 ──
echo "\n--- 11. request shape (API-CONTRACT §1.1 / §4.1) ---\n";
$call = stub_last_http();
t( 1 === preg_match( '#^DW-dw-wholesale/' . preg_quote( $VERSION, '#' ) . ' \(WordPress/6\.7; PHP/#', $call['args']['headers']['User-Agent'] ),
   'User-Agent = DW-{slug}/{ver} (WordPress/x; PHP/y) — ' . $call['args']['headers']['User-Agent'] );
t_eq( 'application/json', $call['args']['headers']['Content-Type'], 'Content-Type: application/json' );
$body = stub_last_body();
foreach ( array( 'license_key', 'domain', 'product_slug', 'version', 'file_hashes' ) as $f ) {
	t( array_key_exists( $f, $body ), "/auth/token 본문에 $f 포함" );
}
t_eq( 'canary.example.com', $body['domain'], 'domain 은 호스트명만 (§1.5)' );
t( ! array_key_exists( 'synthetic_test', $body ), 'synthetic_test 플래그 미전송 (CALL-FLOWS §3)' );

// ── 12. compute_file_hashes 키 규약 (§7.2.2 규약 1) ──
echo "\n--- 12. compute_file_hashes key convention ---\n";
$hashes = DW_DWCAT_License_Manager::compute_file_hashes();
t( ! empty( $hashes ), 'integrity.json 기반 해시 생성' );
$bad_key  = array_filter( array_keys( $hashes ), function ( $k ) {
	return 0 === strpos( $k, 'wp-plugin/' ) || 0 === strpos( $k, 'dw-wholesale/' );
} );
$bad_hash = array_filter( $hashes, function ( $h ) { return ! preg_match( '/^[0-9a-f]{64}$/', $h ); } );
t( empty( $bad_key ), '규약 1 — 키에 접두사 없음 (루트상대)' );
t( empty( $bad_hash ), '값 = sha256 hex lowercase 64자' );
t( ! array_key_exists( 'integrity.json', $hashes ), 'integrity.json 자기 자신은 제외' );

// ── 13. RSA 서명 검증 — 실제 키로 정상/변조 양쪽 확인 ──
echo "\n--- 13. RSA signature verification (real crypto) ---\n";
/**
 * 키 생성은 openssl.cnf 를 요구합니다 (Windows PHP 는 기본 미설정).
 * SDK 가 실제로 쓰는 openssl_verify() 는 cnf 가 필요 없으므로,
 * 키 생성이 안 되는 환경에서도 **음성 테스트(위조 서명 거부)** 는 그대로 돌립니다.
 */
function test_keypair() {
	if ( ! function_exists( 'openssl_pkey_new' ) ) {
		return null;
	}
	$candidates = array_filter( array(
		getenv( 'OPENSSL_CONF' ) ?: null,
		dirname( PHP_BINARY ) . '/extras/ssl/openssl.cnf',
		'/etc/ssl/openssl.cnf',
		'/usr/lib/ssl/openssl.cnf',
	) );

	$args = array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA );
	$try  = array( $args );
	foreach ( $candidates as $c ) {
		if ( is_readable( $c ) ) {
			$try[] = array_merge( $args, array( 'config' => $c ) );
		}
	}

	foreach ( $try as $a ) {
		while ( openssl_error_string() ) { /* 이전 오류 비우기 */ }
		$res = @openssl_pkey_new( $a );
		if ( $res ) {
			return array( $res, isset( $a['config'] ) ? $a['config'] : null );
		}
	}
	return null;
}

$kp = test_keypair();

if ( null === $kp ) {
	// 양성 경로는 못 돌리지만, 보안상 더 중요한 **음성 경로**는 실 공개키로 검증한다.
	echo "  · 키 생성 불가 (openssl.cnf 미설정) — 양성 서명 테스트 SKIP, 음성 테스트는 수행
";
	$GLOBALS['_options'][ PREF . 'token' ] = array(
		'token'      => 'eyJhbGciOiJSUzI1NiJ9.eyJhdWQiOiJkdy1jYXRhbG9nLXdwIn0.Zm9yZ2Vk',
		'expires_at' => gmdate( 'c', time() + 3600 ),
		'grace_ok'   => true,
	);
	t( false === DW_DWCAT_License_Manager::verify_token_signature(),
	   '위조 서명(실 dasomforge.pub 대조): 검증 실패' );

	$GLOBALS['_options'][ PREF . 'token' ] = array(
		'token' => 'garbage', 'expires_at' => gmdate( 'c', time() + 3600 ), 'grace_ok' => true,
	);
	t( false === DW_DWCAT_License_Manager::verify_token_signature(),
	   'JWT 형식이 아닌 토큰: 검증 실패' );
} else {
	list( $res, $conf ) = $kp;
	$export_args = $conf ? array( 'config' => $conf ) : null;
	openssl_pkey_export( $res, $priv, null, $export_args );
	$pub     = openssl_pkey_get_details( $res )['key'];
	$pubfile = sys_get_temp_dir() . '/dwcat-test-' . getmypid() . '.pub';
	file_put_contents( $pubfile, $pub );

	DW_DWCAT_License_Manager::init( array(
		'product_slug'    => 'sig-test',
		'cache_prefix'    => 'sigtest_',
		'plugin_file'     => $root . '/dw-wholesale.php',
		'plugin_basename' => 'dw-wholesale/dw-wholesale.php',
		'plugin_name'     => 'Sig Test',
		'plugin_version'  => '2.0.0',
		'public_keys'     => array( $pubfile ),
	) );

	$b64 = function ( $d ) { return rtrim( strtr( base64_encode( $d ), '+/', '-_' ), '=' ); };
	$mk  = function ( array $claims ) use ( $b64, $priv ) {
		$h = $b64( json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$p = $b64( json_encode( $claims ) );
		openssl_sign( "$h.$p", $sig, $priv, OPENSSL_ALGO_SHA256 );
		return "$h.$p." . $b64( $sig );
	};

	$claims = array(
		'iss'    => 'dasomforge',
		'aud'    => 'sig-test',
		'domain' => 'canary.example.com',
		'exp'    => time() + 3600,
	);

	$put = function ( $jwt ) {
		$GLOBALS['_options']['sigtest_token'] = array(
			'token' => $jwt, 'expires_at' => gmdate( 'c', time() + 3600 ), 'grace_ok' => true,
		);
	};

	$put( $mk( $claims ) );
	t( true === DW_DWCAT_License_Manager::verify_token_signature( 'sig-test' ), '정상 JWT: 서명 검증 통과' );

	// payload 변조 → 서명 불일치
	$parts    = explode( '.', $mk( $claims ) );
	$parts[1] = $b64( json_encode( array_merge( $claims, array( 'aud' => 'sig-test', 'tier' => 'pro' ) ) ) );
	$put( implode( '.', $parts ) );
	t( false === DW_DWCAT_License_Manager::verify_token_signature( 'sig-test' ), '변조된 payload: 검증 실패' );

	// 다른 product 를 겨냥한 토큰 → aud 불일치
	$put( $mk( array_merge( $claims, array( 'aud' => 'other-plugin' ) ) ) );
	t( false === DW_DWCAT_License_Manager::verify_token_signature( 'sig-test' ), 'aud 불일치: 검증 실패' );

	// 만료 + 오프라인 grace 초과
	$put( $mk( array_merge( $claims, array( 'exp' => time() - ( 2 * DAY_IN_SECONDS ) ) ) ) );
	t( false === DW_DWCAT_License_Manager::verify_token_signature( 'sig-test' ), '한참 만료된 토큰: 검증 실패' );

	// 다른 키로 서명된 토큰 (가짜 dasomforge)
	$kp2 = test_keypair();
	list( $res2, $conf2 ) = $kp2;
	openssl_pkey_export( $res2, $priv2, null, $conf2 ? array( 'config' => $conf2 ) : null );
	$h  = $b64( json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$p  = $b64( json_encode( $claims ) );
	openssl_sign( "$h.$p", $sig2, $priv2, OPENSSL_ALGO_SHA256 );
	$put( "$h.$p." . $b64( $sig2 ) );
	t( false === DW_DWCAT_License_Manager::verify_token_signature( 'sig-test' ),
	   '가짜 발급자 키로 서명: 검증 실패 (복제 시나리오 #4 차단)' );

	unlink( $pubfile );
}

// ── 14. 멀티사이트 — 라이선스는 네트워크, 토큰은 사이트 (FAQ Q10) ──
echo "\n--- 14. multisite scoping (FAQ Q10) ---\n";
stub_reset(); as_admin();
$GLOBALS['_is_multisite'] = true;
stub_http_queue( array(
	stub_response( 200, array( 'success' => true, 'license_id' => 1, 'tier' => 'basic' ) ),
	ok_token(),
) );
DW_DWCAT_License_Manager::activate( 'DW-NET-KEY', 'canary.example.com' );
t( isset( $GLOBALS['_net_options']['dw_license_dw_wholesale'] ), '멀티사이트: 라이선스가 네트워크 옵션에 저장' );
t( ! isset( $GLOBALS['_options']['dw_license_dw_wholesale'] ), '멀티사이트: 사이트 옵션에는 저장 안 함' );
t( isset( $GLOBALS['_options'][ PREF . 'token' ] ), '멀티사이트: 토큰 캐시는 사이트별 (도메인이 다르므로)' );
$GLOBALS['_is_multisite'] = false;

// ── 15. update-check — legacy flat 파싱 (API-CONTRACT §7.1) ──
echo "\n--- 15. /releases/update-check (legacy flat) ---\n";
stub_reset(); as_admin(); fixture_license();
stub_http_queue( array( stub_response( 200, array(
	'update_available' => true,
	'version'          => '3.1.0',
	'changelog'        => '- fix',
	'download_url'     => 'https://r2.example/signed.zip',
) ) ) );
$transient = new stdClass();
$transient->checked  = array( 'dw-wholesale/dw-wholesale.php' => '2.0.0' );
$transient->response = array();
$transient->no_update = array();
$i = DW_DWCAT_License_Manager::init( array( 'product_slug' => SLUG ) );  // 기존 인스턴스 반환
$out = $i->check_for_update( $transient );
t( isset( $out->response['dw-wholesale/dw-wholesale.php'] ), 'update_available=true → 업데이트 제안' );
t_eq( '3.1.0', $out->response['dw-wholesale/dw-wholesale.php']->new_version, '새 버전 파싱' );
t_eq( 'https://r2.example/signed.zip', $out->response['dw-wholesale/dw-wholesale.php']->package, 'signed R2 URL 사용' );

// 라이선스가 없으면 아예 묻지 않는다
stub_reset(); as_admin();
$t2 = new stdClass(); $t2->checked = array( 'x' => '1' ); $t2->response = array(); $t2->no_update = array();
$i->check_for_update( $t2 );
t_eq( 0, stub_http_count(), '라이선스 없음: update-check 호출 안 함' );

// ── 16. anti-piracy 회귀 (§9.3) ──
echo "\n--- 16. anti-piracy regression (§9.3) ---\n";
stub_reset();
$GLOBALS['_is_admin'] = false;
// (1) 라이선스 옵션만 'active' 로 위조 — 토큰이 없으면 게이트는 열리면 안 된다
$GLOBALS['_options']['dw_license_dw_wholesale'] = array( 'key' => 'FAKE', 'status' => 'active' );
t( false === DW_DWCAT_License_Manager::has_valid_token(),
   'option 만 active 로 위조: 토큰 없으면 has_valid_token() = false' );
t_eq( 'grace', DW_DWCAT_License_Manager::get_status(),
   'option 위조: status 는 licensed 가 아님' );

// (2) 토큰 캐시에 아무 문자열이나 넣어도 서명 검증은 통과 못 한다
$GLOBALS['_options'][ PREF . 'token' ] = array(
	'token' => 'not.a.jwt', 'expires_at' => gmdate( 'c', time() + 3600 ), 'grace_ok' => true,
);
t( false === DW_DWCAT_License_Manager::verify_token_signature(),
   '위조 토큰 문자열: RSA 서명 검증 실패 (게이트 2·5·6 차단)' );

// (3) integrity.json 이 없으면 토큰 요청 자체가 나가지 않는다 (fail closed).
//     매니페스트를 지우고 토큰을 받아 가려는 시도를 재현합니다.
stub_reset(); as_admin(); fixture_license();
$backup = $manifest_path . '.bak';
rename( $manifest_path, $backup );

t_eq( array(), DW_DWCAT_License_Manager::compute_file_hashes(), 'integrity.json 삭제: 해시 맵 비어 있음' );
DW_DWCAT_License_Manager::get_token( true );
t_eq( 0, stub_http_count(), 'integrity.json 삭제: /auth/token 호출조차 안 함 (fail closed)' );
t_eq( 'LOCAL_MANIFEST_MISSING', DW_DWCAT_License_Manager::get_last_error()['code'], '원인을 어드민 노출용으로 기록' );

rename( $backup, $manifest_path );

t_results( 'SDK behaviour' );
