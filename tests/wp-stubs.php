<?php
/**
 * WordPress 함수 스텁 + 제어 가능한 HTTP 모킹 — 테스트 공용.
 *
 * PHPUnit/Brain Monkey 없이 돌아가도록 최소 스텁만 둡니다 (의존성 0).
 * 두 테스트(test-bootstrap.php · test-license-manager.php)가 이 파일을 공유하므로
 * 스텁 드리프트가 생기지 않습니다.
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

$GLOBALS['wp_version']    = '6.7';
$GLOBALS['_options']      = array();
$GLOBALS['_net_options']  = array();
$GLOBALS['_transients']   = array();
$GLOBALS['_actions']      = array();
$GLOBALS['_filters']      = array();
$GLOBALS['_scheduled']    = array();
$GLOBALS['_http_calls']   = array();
$GLOBALS['_http_queue']   = array();
$GLOBALS['_is_admin']     = false;
$GLOBALS['_is_multisite'] = false;
$GLOBALS['_notices']      = '';

// ─── 테스트 제어 헬퍼 ────────────────────────────────────────

/** 다음 HTTP 호출들이 돌려줄 응답을 순서대로 큐잉. */
function stub_http_queue( array $responses ) {
	$GLOBALS['_http_queue'] = $responses;
}

/** {status, body(array|string)} 를 wp_remote_request 반환 형태로. */
function stub_response( $status, $body, array $headers = array() ) {
	return array(
		'response' => array( 'code' => $status ),
		'body'     => is_array( $body ) ? json_encode( $body ) : (string) $body,
		'headers'  => $headers,
	);
}

function stub_reset() {
	$GLOBALS['_options']     = array();
	$GLOBALS['_net_options'] = array();
	$GLOBALS['_transients']  = array();
	$GLOBALS['_scheduled']   = array();
	$GLOBALS['_http_calls']  = array();
	$GLOBALS['_http_queue']  = array();
	$GLOBALS['_is_admin']    = false;
	$GLOBALS['_notices']     = '';
}

function stub_http_count() {
	return count( $GLOBALS['_http_calls'] );
}

function stub_last_http() {
	$c = $GLOBALS['_http_calls'];
	return $c ? end( $c ) : null;
}

/** 요청 본문을 배열로. */
function stub_last_body() {
	$c = stub_last_http();
	if ( ! $c || empty( $c['args']['body'] ) ) {
		return array();
	}
	$d = json_decode( $c['args']['body'], true );
	return is_array( $d ) ? $d : array();
}

// ─── WP 함수 스텁 ────────────────────────────────────────────

function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['_actions'][ $h ][] = $cb; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['_filters'][ $h ][] = $cb; return true; }
function do_action( $h ) {
	foreach ( isset( $GLOBALS['_actions'][ $h ] ) ? $GLOBALS['_actions'][ $h ] : array() as $cb ) {
		call_user_func( $cb );
	}
}
function has_action( $h ) { return ! empty( $GLOBALS['_actions'][ $h ] ); }
function add_shortcode( $t, $cb ) { return true; }
function register_activation_hook( $f, $cb ) { return true; }
function register_deactivation_hook( $f, $cb ) { return true; }
function add_submenu_page() { return ''; }
function add_menu_page() { return ''; }
function register_rest_route() { return true; }
function rest_ensure_response( $d ) { return $d; }

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['_options'] ) ? $GLOBALS['_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['_options'][ $k ] = $v; return true; }
function add_option( $k, $v, $x = '', $a = null ) {
	if ( array_key_exists( $k, $GLOBALS['_options'] ) ) { return false; }
	$GLOBALS['_options'][ $k ] = $v; return true;
}
function delete_option( $k ) { unset( $GLOBALS['_options'][ $k ] ); return true; }

function is_multisite() { return (bool) $GLOBALS['_is_multisite']; }
function get_network_option( $id, $k, $d = false ) { return array_key_exists( $k, $GLOBALS['_net_options'] ) ? $GLOBALS['_net_options'][ $k ] : $d; }
function update_network_option( $id, $k, $v ) { $GLOBALS['_net_options'][ $k ] = $v; return true; }
function delete_network_option( $id, $k ) { unset( $GLOBALS['_net_options'][ $k ] ); return true; }
function network_admin_url( $p = '' ) { return 'https://canary.example.com/wp-admin/network/' . $p; }

function get_transient( $k ) {
	if ( ! isset( $GLOBALS['_transients'][ $k ] ) ) { return false; }
	list( $v, $exp ) = $GLOBALS['_transients'][ $k ];
	if ( $exp > 0 && $exp < time() ) { unset( $GLOBALS['_transients'][ $k ] ); return false; }
	return $v;
}
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['_transients'][ $k ] = array( $v, $t > 0 ? time() + $t : 0 ); return true; }
function delete_transient( $k ) { unset( $GLOBALS['_transients'][ $k ] ); return true; }

function home_url( $p = '' ) { return 'https://canary.example.com' . $p; }
function admin_url( $p = '' ) { return 'https://canary.example.com/wp-admin/' . $p; }
function rest_url( $p = '' ) { return home_url( '/wp-json/' . $p ); }
function plugin_dir_path( $f ) { return rtrim( str_replace( '\\', '/', dirname( $f ) ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'https://canary.example.com/wp-content/plugins/dw-wholesale/'; }
function plugin_basename( $f ) { return 'dw-wholesale/' . basename( $f ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo $s; }
function __( $s, $d = '' ) { return $s; }
function _e( $s, $d = '' ) { echo $s; }

function current_user_can( $c ) { return true; }
function is_admin() { return (bool) $GLOBALS['_is_admin']; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }

function wp_next_scheduled( $h ) { return isset( $GLOBALS['_scheduled'][ $h ] ) ? $GLOBALS['_scheduled'][ $h ] : false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['_scheduled'][ $h ] = $t; return true; }
function wp_schedule_single_event( $t, $h, $a = array() ) { $GLOBALS['_scheduled'][ $h ] = $t; return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['_scheduled'][ $h ] ); return true; }

function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function wp_die( $m = '' ) { throw new RuntimeException( 'wp_die: ' . $m ); }
function is_wp_error( $t ) { return $t instanceof WP_Error_Stub; }

class WP_Error_Stub {
	public $msg;
	public function __construct( $m ) { $this->msg = $m; }
	public function get_error_message() { return $this->msg; }
}

/**
 * 큐에 넣어 둔 응답을 순서대로 반환. 큐가 비면 503 (서버 미도달)으로 취급.
 * 'network_error' 문자열을 큐에 넣으면 WP_Error 를 흉내 냅니다.
 */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['_http_calls'][] = array( 'url' => $url, 'args' => $args );

	if ( ! empty( $GLOBALS['_http_queue'] ) ) {
		$next = array_shift( $GLOBALS['_http_queue'] );
		if ( 'network_error' === $next ) {
			return new WP_Error_Stub( 'cURL error 28: timeout' );
		}
		return $next;
	}

	return stub_response( 503, array( 'ok' => false, 'error' => array( 'code' => 'SERVICE_UNAVAILABLE' ) ) );
}

function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
function add_query_arg( $a, $u = '' ) { return $u . '?' . http_build_query( $a ); }

function get_current_screen() { return null; }
function wp_nonce_field() {}
function check_admin_referer() { return true; }
function wp_safe_redirect( $u ) {}
function wp_create_nonce( $a ) { return 'nonce'; }
function version_compare_stub() {}

// ─── 어서션 헬퍼 ─────────────────────────────────────────────

$GLOBALS['_pass'] = 0;
$GLOBALS['_fail'] = 0;

function t( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['_pass']++;
		echo "  ✓ $msg\n";
	} else {
		$GLOBALS['_fail']++;
		echo "  ✗ $msg\n";
	}
}

function t_eq( $expected, $actual, $msg ) {
	$ok = ( $expected === $actual );
	t( $ok, $msg . ( $ok ? '' : sprintf(
		' (expected %s, got %s)',
		var_export( $expected, true ),
		var_export( $actual, true )
	) ) );
}

function t_results( $title ) {
	echo "\n=== $title ===\n";
	echo 'PASS: ' . $GLOBALS['_pass'] . "\n";
	echo 'FAIL: ' . $GLOBALS['_fail'] . "\n";
	echo $GLOBALS['_fail'] === 0 ? "\nALL PASSED\n" : "\n" . $GLOBALS['_fail'] . " FAILED\n";
	exit( $GLOBALS['_fail'] > 0 ? 1 : 0 );
}
