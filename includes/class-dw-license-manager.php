<?php
/**
 * DW License Manager — dw-wholesale fork (dasomforge-native).
 *
 * ── Prefix 배포 전략 (PLUGIN-DEV-GUIDE §3.5 B / SDK-README §Prefix) ──
 * 정본 클래스 `DW_License_Manager` 를 `DW_DWCAT_License_Manager` 로 rename 한 사본.
 * 다른 DW 플러그인의 SDK 버전 상태와 완전 격리 → winner race 무관.
 * 대가: 라이선스 입력 UI · daily verify cron · 토큰 캐시가 이 플러그인 전용.
 *
 * ── 공개 API 는 정적 메서드만 (PLUGIN-DEV-GUIDE §3.3) ──
 * 호출 측은 반드시 is_callable() 가드를 거칩니다 (includes/license/gates.php).
 *
 * ── 응답 규약 (API-CONTRACT §1.3) ──
 *   legacy flat : /license/activate(success) · /license/verify(valid)
 *                 /license/deactivate(success) · /releases/update-check(update_available)
 *   envelope    : /auth/token · /configs/sign · /integrity/*   → ok / data / error.code
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DW_DWCAT_License_Manager' ) ) :

class DW_DWCAT_License_Manager {

	/** 토큰 만료 이 초 이내면 선제 갱신 (API-CONTRACT §4.1 next_check_after=3300 대응). */
	const REFRESH_WINDOW = 300;

	/** 서버 도달 실패(네트워크 장애) 시 만료된 캐시 토큰을 계속 쓰는 한계. */
	const OFFLINE_GRACE = 86400;

	/**
	 * 라이선스 *만료* 후 유예 — 네트워크 장애와 구분해서 훨씬 길게 잡습니다.
	 *
	 * 결제가 며칠 늦은 정상 고객을 24시간 만에 끊으면 안 됩니다
	 * (PLUGIN-DEV-GUIDE §10.2 "라이선스 만료 즉시 차단 ❌",
	 *  PLUGIN-ERROR-CODES §1 LICENSE_EXPIRED "30일 grace 권장", KICKOFF FAQ Q9).
	 *
	 * ⚠ 문서 간 불일치: API-CONTRACT §9.5 는 90일로 적혀 있고
	 *   ERROR-CODES·FAQ Q9 는 30일입니다. 보수적으로 30일을 채택했습니다
	 *   (MIGRATION-PLAN §11 에 운영자 확인 항목으로 등록).
	 */
	const EXPIRY_GRACE = 2592000;

	/** 이 시간 이상 토큰 재발급이 연속 실패하면 어드민에 안내 (FAQ Q11 #2). */
	const OFFLINE_NOTICE_AFTER = 86400;

	/** 연속 실패 시 재호출 억제 (PLUGIN-DEV-GUIDE §10.2 무한 재시도 금지). */
	const FAILURE_BACKOFF = 600;

	/** @var array<string, self> */
	private static $instances = array();

	/** @var string */
	private static $default_slug = '';

	private $product_slug;
	private $cache_prefix;
	private $plugin_file;
	private $plugin_dir;
	private $plugin_basename;
	private $plugin_name;
	private $plugin_version;
	private $settings_page;
	/** @var string|callable 라이선스 화면을 붙일 부모 메뉴 slug (admin_menu 시점에 해석). */
	private $menu_parent;
	private $public_key_paths;
	private $client;

	/** 옵션 키 */
	private $opt_license;
	private $opt_token;
	private $opt_tamper;
	private $opt_error;
	private $opt_platform;   // INTEGRITY_MANIFEST_NOT_FOUND · VERSION_REVOKED 등
	private $opt_offline;    // 연속 실패 시작 시각

	// ─────────────────────────────────────────────────────────────
	// 등록
	// ─────────────────────────────────────────────────────────────

	public static function init( array $config ) {
		$slug = $config['product_slug'];
		if ( ! isset( self::$instances[ $slug ] ) ) {
			self::$instances[ $slug ] = new self( $config );
		}
		if ( self::$default_slug === '' ) {
			self::$default_slug = $slug;
		}
		return self::$instances[ $slug ];
	}

	private static function get( ?string $slug = null ) : ?self {
		$slug = $slug ? $slug : self::$default_slug;
		return isset( self::$instances[ $slug ] ) ? self::$instances[ $slug ] : null;
	}

	private function __construct( array $config ) {
		$this->product_slug     = $config['product_slug'];
		$this->cache_prefix     = isset( $config['cache_prefix'] ) ? $config['cache_prefix'] : 'dw_';
		$this->plugin_file      = $config['plugin_file'];
		$this->plugin_dir       = rtrim( dirname( $this->plugin_file ), '/\\' );
		$this->plugin_basename  = isset( $config['plugin_basename'] ) ? $config['plugin_basename'] : plugin_basename( $this->plugin_file );
		$this->plugin_name      = isset( $config['plugin_name'] ) ? $config['plugin_name'] : $this->product_slug;
		$this->plugin_version   = $config['plugin_version'];
		$this->settings_page    = isset( $config['settings_page'] ) ? $config['settings_page'] : $this->product_slug . '-license';
		$this->menu_parent      = isset( $config['menu_parent'] ) ? $config['menu_parent'] : 'options-general.php';
		$this->public_key_paths = isset( $config['public_keys'] ) ? (array) $config['public_keys'] : array();

		$this->opt_license  = 'dw_license_' . str_replace( '-', '_', $this->product_slug );
		$this->opt_token    = $this->cache_prefix . 'token';
		$this->opt_tamper   = $this->cache_prefix . 'tamper_notice';
		$this->opt_error    = $this->cache_prefix . 'last_sdk_error';
		$this->opt_platform = $this->cache_prefix . 'platform_notice';
		$this->opt_offline  = $this->cache_prefix . 'offline_since';

		$this->client = new DW_DWCAT_Forge_Client(
			$this->product_slug,
			$this->plugin_version,
			isset( $config['api_base'] ) ? $config['api_base'] : ''
		);

		$this->register_hooks();
	}

	private function register_hooks() {
		$slug = $this->product_slug;

		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 20 );
		add_action( 'admin_post_' . $this->cache_prefix . 'license_action', array( $this, 'handle_admin_post' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );

		// FAQ Q10 — 멀티사이트는 네트워크 어드민에서 한 번만 입력하면 전 사이트 적용.
		add_action( 'network_admin_menu', array( $this, 'add_network_menu_page' ), 20 );
		add_action( 'network_admin_notices', array( $this, 'render_admin_notices' ) );

		// PLUGIN-DEV-GUIDE §11.1.b — 라이선스 화면 진입만으로 토큰이 갱신되어야 한다.
		add_action( 'current_screen', array( $this, 'maybe_lazy_refresh' ) );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

		add_action( 'dw_verify_license_' . $slug, array( $this, 'verify_license' ) );
		add_action( $this->cache_prefix . 'refresh_token', array( $this, 'refresh_token_event' ) );
	}

	/** 활성화 훅에서 호출 — cron 등록. */
	public static function on_activate( $slug = null ) {
		$i = self::get( $slug );
		if ( ! $i ) {
			return;
		}
		$hook = 'dw_verify_license_' . $i->product_slug;
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
		}
	}

	/** 비활성화 훅에서 호출 — cron 해제. */
	public static function on_deactivate_plugin( $slug = null ) {
		$i = self::get( $slug );
		if ( ! $i ) {
			return;
		}
		wp_clear_scheduled_hook( 'dw_verify_license_' . $i->product_slug );
		wp_clear_scheduled_hook( $i->cache_prefix . 'refresh_token' );
	}

	// ─────────────────────────────────────────────────────────────
	// 공개 API (정적) — SDK-README §공개 API 표
	// ─────────────────────────────────────────────────────────────

	public static function activate( $key, $domain = '', $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->do_activate( (string) $key, (string) $domain ) : self::no_instance();
	}

	public static function deactivate( $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->do_deactivate() : self::no_instance();
	}

	public static function get_token( $force = false, $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->resolve_token( (bool) $force ) : null;
	}

	/** Strict 게이트용 (PLUGIN-DEV-GUIDE §3.4). */
	public static function has_valid_token( $slug = null ) {
		$i = self::get( $slug );
		return $i ? (bool) $i->resolve_token( false ) : false;
	}

	public static function clear_token_cache( $slug = null ) {
		$i = self::get( $slug );
		if ( $i ) {
			delete_option( $i->opt_token );
			delete_transient( $i->opt_token . '_backoff' );
		}
	}

	public static function sign_config( $config_id, array $payload, $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->do_sign_config( (string) $config_id, $payload ) : self::no_instance();
	}

	/**
	 * 로컬 서명 검증 (API-CONTRACT §5.2 — 서버 호출 없음).
	 *
	 * @param string $message    서명 대상 바이트열 (JWS 라면 "header.payload")
	 * @param string $sig_b64url base64url 서명
	 */
	public static function verify_signature( $message, $sig_b64url, $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->do_verify_signature( (string) $message, (string) $sig_b64url ) : false;
	}

	public static function get_public_key_pem( $slug = null ) {
		$i = self::get( $slug );
		if ( ! $i ) {
			return '';
		}
		foreach ( $i->public_key_paths as $path ) {
			if ( is_readable( $path ) ) {
				return (string) file_get_contents( $path );
			}
		}
		return '';
	}

	public static function get_runtime_manifest( $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->fetch_runtime_manifest() : null;
	}

	/** 'licensed' | 'grace' | 'unlicensed' | 'tamper_detected' | 'expired' | 'invalidated' */
	public static function get_status( $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->resolve_status() : 'unlicensed';
	}

	public static function get_license_data( $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->license_data() : array();
	}

	/**
	 * 캐시된 JWT 를 dasomforge 공개키로 직접 검증 (게이트 다중 경로용).
	 * has_valid_token() 과 **다른 검증 경로** — PLUGIN-DEV-GUIDE §6 요구.
	 */
	public static function verify_token_signature( $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->do_verify_token_signature() : false;
	}

	/** 캐시된 JWT 의 payload 클레임. 파싱 실패 시 빈 배열. */
	public static function get_token_claims( $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->do_get_token_claims() : array();
	}

	/**
	 * integrity.json 기반 SHA256 맵.
	 * 키 규약: integrity.json 의 files 항목을 **그대로** 전송
	 *          (PLUGIN-DEV-GUIDE §7.2.2 규약 1 · 루트상대).
	 */
	public static function compute_file_hashes( $slug = null ) {
		$i = self::get( $slug );
		return $i ? $i->do_compute_file_hashes() : array();
	}

	private static function no_instance() {
		return array( 'success' => false, 'message' => 'License manager not initialised.' );
	}

	// ─────────────────────────────────────────────────────────────
	// 라이선스 상태 저장소 (option 이 권위 — transient 아님)
	// ─────────────────────────────────────────────────────────────

	// ── 멀티사이트 (KICKOFF FAQ Q10) ────────────────────────────
	//
	//  라이선스 키/상태 : **네트워크 단위** — 메인 어드민에서 한 번 입력하면 전 사이트 적용
	//  토큰 캐시        : **사이트 단위** — 서브사이트마다 도메인이 달라 토큰도 달라야 함
	//                     (각 도메인이 License.max_domains 한 슬롯을 차지)

	private function is_network() {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/** 네트워크 범위 옵션 읽기 (단일 사이트면 일반 옵션). */
	private function net_get( $key, $default = false ) {
		if ( $this->is_network() && function_exists( 'get_network_option' ) ) {
			return get_network_option( null, $key, $default );
		}
		return get_option( $key, $default );
	}

	private function net_set( $key, $value ) {
		if ( $this->is_network() && function_exists( 'update_network_option' ) ) {
			return update_network_option( null, $key, $value );
		}
		return update_option( $key, $value, false );
	}

	private function net_delete( $key ) {
		if ( $this->is_network() && function_exists( 'delete_network_option' ) ) {
			return delete_network_option( null, $key );
		}
		return delete_option( $key );
	}

	private function license_data() {
		$d = $this->net_get( $this->opt_license, array() );
		if ( ! is_array( $d ) ) {
			$d = array();
		}
		return array_merge( array(
			'key'            => '',
			'status'         => 'inactive',
			'domain'         => '',
			'license_id'     => null,
			'tier'           => '',
			'features'       => array(),
			'max_domains'    => 1,
			'active_domains' => array(),
			'expires_at'     => null,
			'checked_at'     => null,
		), $d );
	}

	private function save_license_data( array $data ) {
		$this->net_set( $this->opt_license, $data );
	}

	private function token_cache() {
		$t = get_option( $this->opt_token, array() );
		return is_array( $t ) ? $t : array();
	}

	/** API-CONTRACT §1.5 — 호스트명만. 스킴·경로·포트 제외. */
	public function current_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? strtolower( $host ) : '';
	}

	// ─────────────────────────────────────────────────────────────
	// /license/activate · /deactivate · /verify  (legacy flat)
	// ─────────────────────────────────────────────────────────────

	private function do_activate( $key, $domain = '' ) {
		$key    = trim( $key );
		$domain = '' !== $domain ? $domain : $this->current_domain();

		$r = $this->client->request( 'POST', '/license/activate', array(
			'license_key'  => $key,
			'domain'       => $domain,
			'product_slug' => $this->product_slug,
		) );

		// 전송 실패만 envelope 로 감싸져 온다 (§1.3 분기 규칙).
		if ( isset( $r['ok'] ) && ! $r['ok'] ) {
			$code = isset( $r['error']['code'] ) ? $r['error']['code'] : 'UNKNOWN';
			$this->log_error( 'activate', $code, isset( $r['error']['message'] ) ? $r['error']['message'] : '' );
			return array( 'success' => false, 'message' => $this->translate_error( $code ) );
		}

		// 정상 응답은 legacy flat — success 필드로 판정
		// (envelope 로 읽으면 ok 가 undefined 라 항상 실패로 오독됨. dw-booking v0.1.1 사례)
		if ( empty( $r['success'] ) ) {
			$msg = isset( $r['error'] ) && is_string( $r['error'] )
				? $r['error']
				: __( '라이선스 활성화에 실패했습니다.', 'dw-wholesale' );
			$this->log_error( 'activate', 'ACTIVATE_REJECTED', $msg );
			return array( 'success' => false, 'message' => $msg );
		}

		$this->save_license_data( array_merge( $this->license_data(), array(
			'key'            => $key,
			'status'         => 'active',
			'domain'         => $domain,
			'license_id'     => isset( $r['license_id'] ) ? $r['license_id'] : null,
			'tier'           => isset( $r['tier'] ) ? $r['tier'] : 'basic',
			'features'       => isset( $r['features'] ) ? (array) $r['features'] : array(),
			'max_domains'    => isset( $r['max_domains'] ) ? (int) $r['max_domains'] : 1,
			'active_domains' => isset( $r['active_domains'] ) ? (array) $r['active_domains'] : array( $domain ),
			'expires_at'     => isset( $r['expires_at'] ) ? $r['expires_at'] : null,
			'checked_at'     => current_time( 'mysql' ),
		) ) );

		delete_option( $this->opt_tamper );
		delete_option( $this->opt_error );

		// ★ PLUGIN-DEV-GUIDE §11.1.a — activate 성공 직후 **동기적으로** 토큰 발급.
		//   cron 을 기다리면 운영자 verify 페이지 A2 가 ❌ 로 보여 위양성이 된다.
		$this->resolve_token( true );

		return array(
			'success' => true,
			'message' => isset( $r['message'] ) ? $r['message'] : __( '라이선스가 활성화되었습니다.', 'dw-wholesale' ),
			'data'    => $r,
		);
	}

	private function do_deactivate() {
		$d = $this->license_data();

		if ( ! empty( $d['key'] ) ) {
			$this->client->request( 'POST', '/license/deactivate', array(
				'license_key' => $d['key'],
				'domain'      => '' !== $d['domain'] ? $d['domain'] : $this->current_domain(),
			) );
		}

		$this->net_delete( $this->opt_license );
		delete_option( $this->opt_token );
		delete_option( $this->opt_tamper );
		delete_option( $this->opt_platform );
		delete_option( $this->opt_offline );
		delete_transient( $this->opt_token . '_backoff' );

		return array( 'success' => true, 'message' => __( '라이선스를 비활성화했습니다.', 'dw-wholesale' ) );
	}

	/** 일일 cron. legacy flat — valid 필드로 판정. */
	public function verify_license() {
		$d = $this->license_data();
		if ( empty( $d['key'] ) ) {
			return;
		}

		$r = $this->client->request( 'POST', '/license/verify', array(
			'license_key'  => $d['key'],
			'domain'       => '' !== $d['domain'] ? $d['domain'] : $this->current_domain(),
			'product_slug' => $this->product_slug,
		) );

		// 서버 미도달이면 상태를 건드리지 않는다 — 오프라인에서 라이선스가 꺼지면 안 됨.
		if ( isset( $r['ok'] ) && ! $r['ok'] ) {
			$this->log_error( 'verify', isset( $r['error']['code'] ) ? $r['error']['code'] : 'UNKNOWN', '' );
			return;
		}

		$d['checked_at'] = current_time( 'mysql' );

		if ( ! empty( $r['valid'] ) ) {
			$d['status']         = 'active';
			$d['tier']           = isset( $r['tier'] ) ? $r['tier'] : $d['tier'];
			$d['features']       = isset( $r['features'] ) ? (array) $r['features'] : $d['features'];
			$d['max_domains']    = isset( $r['max_domains'] ) ? (int) $r['max_domains'] : $d['max_domains'];
			$d['active_domains'] = isset( $r['active_domains'] ) ? (array) $r['active_domains'] : $d['active_domains'];
			$d['expires_at']     = isset( $r['expires_at'] ) ? $r['expires_at'] : $d['expires_at'];
		} else {
			// 키는 보존한다 — 사용자가 재입력 없이 갱신 후 회복할 수 있도록.
			$d['status'] = 'invalid';
		}

		$this->save_license_data( $d );
	}

	// ─────────────────────────────────────────────────────────────
	// /auth/token  (envelope)
	// ─────────────────────────────────────────────────────────────

	/**
	 * 프런트엔드 페이지뷰에서 **동기 HTTP 를 절대 하지 않기 위한** 판정.
	 *
	 * 게이트(dwcat_can_render 등)는 매 페이지뷰마다 불립니다. 여기서 토큰을
	 * 동기 발급하면 —
	 *   · 방문자가 최대 20초(타임아웃) 를 기다림
	 *   · dasomforge 가 느려지면 사이트 전체가 느려짐 (FAQ Q11 "페이지 렌더가 깨지면 절대 안 됨")
	 *   · §10.2 "매 페이지뷰마다 토큰 갱신 ❌" 위반
	 *
	 * 따라서 프런트에서는 **캐시/grace 토큰만** 쓰고, 갱신은 cron 으로 미룹니다.
	 */
	private function should_defer_network() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		return ! is_admin();
	}

	/** 다음 페이지뷰가 아니라 백그라운드에서 토큰을 받아 오도록 예약. */
	private function schedule_refresh( $delay = 5 ) {
		$hook = $this->cache_prefix . 'refresh_token';
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + $delay, $hook );
		}
	}

	private function resolve_token( $force = false ) {
		$cache = $this->token_cache();
		$now   = time();
		$exp   = isset( $cache['expires_at'] ) ? (int) strtotime( $cache['expires_at'] ) : 0;

		if ( ! $force && ! empty( $cache['token'] ) ) {
			if ( $exp - self::REFRESH_WINDOW > $now ) {
				return $cache['token'];
			}
			if ( $exp > $now ) {
				// 아직 유효 — 백그라운드 갱신 예약 후 캐시 반환 (페이지뷰 블로킹 방지).
				$this->schedule_refresh();
				return $cache['token'];
			}
		}

		// 연속 실패 백오프 중이면 grace 토큰만 시도 (§10.2 무한 재시도 금지).
		if ( ! $force && get_transient( $this->opt_token . '_backoff' ) ) {
			return $this->grace_token( $cache );
		}

		// ★ 프런트엔드에서는 동기 발급을 하지 않는다. 갱신은 cron 에 위임.
		if ( ! $force && $this->should_defer_network() ) {
			$this->schedule_refresh();
			return $this->grace_token( $cache );
		}

		$fresh = $this->request_new_token();
		if ( null !== $fresh ) {
			return $fresh;
		}

		return $this->grace_token( $cache );
	}

	/**
	 * 만료된 캐시 토큰을 계속 쓸지 판정. 유예 창은 **사유별로 다릅니다**:
	 *
	 *   invalidated  → 유예 없음 (환불·치팅 등 운영자가 끊은 것 — API-CONTRACT §9.5)
	 *   expired      → 30일  (결제 지연 정상 고객 보호 — ERROR-CODES §1 / FAQ Q9)
	 *   그 외(장애)   → 24시간 (FAQ Q11)
	 */
	private function grace_token( array $cache ) {
		if ( empty( $cache['token'] ) || empty( $cache['grace_ok'] ) ) {
			return null;
		}

		$status = $this->license_data();
		$status = isset( $status['status'] ) ? $status['status'] : '';

		if ( 'invalidated' === $status || 'tamper_detected' === $status ) {
			return null;
		}

		$window = ( 'expired' === $status ) ? self::EXPIRY_GRACE : self::OFFLINE_GRACE;
		$exp    = (int) strtotime( isset( $cache['expires_at'] ) ? $cache['expires_at'] : '' );

		return ( $exp + $window > time() ) ? $cache['token'] : null;
	}

	/** 유예가 며칠 남았는지 (어드민 안내용). 유예 대상이 아니면 0. */
	public function grace_days_left() {
		$cache = $this->token_cache();
		if ( empty( $cache['token'] ) ) {
			return 0;
		}
		$d      = $this->license_data();
		$window = ( 'expired' === $d['status'] ) ? self::EXPIRY_GRACE : self::OFFLINE_GRACE;
		$left   = (int) strtotime( isset( $cache['expires_at'] ) ? $cache['expires_at'] : '' ) + $window - time();
		return $left > 0 ? (int) ceil( $left / DAY_IN_SECONDS ) : 0;
	}

	private function request_new_token( $is_retry = false ) {
		$d = $this->license_data();
		if ( empty( $d['key'] ) ) {
			return null;
		}

		$hashes = $this->do_compute_file_hashes();
		if ( empty( $hashes ) ) {
			// integrity.json 없거나 비어 있음 → 서버가 MANIFEST_INCOMPLETE 로 거절한다.
			$this->log_error( 'auth/token', 'LOCAL_MANIFEST_MISSING', 'integrity.json missing or empty' );
			$this->back_off();
			return null;
		}

		$r = $this->client->request( 'POST', '/auth/token', array(
			'license_key'  => $d['key'],
			'domain'       => '' !== $d['domain'] ? $d['domain'] : $this->current_domain(),
			'product_slug' => $this->product_slug,
			'version'      => $this->plugin_version,
			'file_hashes'  => $hashes,
		) );

		if ( ! empty( $r['ok'] ) && isset( $r['data']['token'] ) ) {
			update_option( $this->opt_token, array(
				'token'      => $r['data']['token'],
				'expires_at' => isset( $r['data']['expires_at'] ) ? $r['data']['expires_at'] : gmdate( 'c', time() + 3600 ),
				'tier'       => isset( $r['data']['tier'] ) ? $r['data']['tier'] : '',
				'features'   => isset( $r['data']['features'] ) ? (array) $r['data']['features'] : array(),
				'fetched_at' => time(),
				'grace_ok'   => true,
			), false );

			delete_transient( $this->opt_token . '_backoff' );
			delete_option( $this->opt_tamper );
			delete_option( $this->opt_error );
			delete_option( $this->opt_platform );
			delete_option( $this->opt_offline );   // 연속 실패 추적 리셋

			// 이전에 expired/invalid 로 떨어졌더라도 토큰이 나왔으면 정상 복귀.
			$d = $this->license_data();
			if ( in_array( $d['status'], array( 'expired', 'invalid', 'tamper_detected' ), true ) ) {
				$d['status'] = 'active';
				$this->save_license_data( $d );
			}

			return $r['data']['token'];
		}

		$code = isset( $r['error']['code'] ) ? $r['error']['code'] : 'UNKNOWN';
		$this->handle_token_error( $code, isset( $r['error'] ) ? (array) $r['error'] : array() );

		// API-CONTRACT §9.5 / PLUGIN-CALL-FLOWS §2 — 401 은 **1회만** 재시도.
		if ( ! $is_retry && in_array( $code, array( 'TOKEN_EXPIRED', 'TOKEN_INVALID' ), true ) ) {
			delete_option( $this->opt_token );
			return $this->request_new_token( true );
		}

		return null;
	}

	public function refresh_token_event() {
		$this->resolve_token( true );
	}

	private function handle_token_error( $code, array $error ) {
		$this->log_error( 'auth/token', $code, isset( $error['message'] ) ? $error['message'] : '' );

		// 연속 실패 시작 시각 기록 → 24h 넘으면 "서비스 연결 끊김" 안내 (FAQ Q11 #2).
		if ( ! get_option( $this->opt_offline ) ) {
			update_option( $this->opt_offline, time(), false );
		}

		switch ( $code ) {
			case 'TAMPER_DETECTED':
				// PLUGIN-DEV-GUIDE §11.2.c — mismatched_files 를 절대 삼키지 말 것.
				update_option( $this->opt_tamper, array(
					'at'    => time(),
					'files' => isset( $error['details']['mismatched_files'] ) ? (array) $error['details']['mismatched_files'] : array(),
				), false );
				$this->mark_license_state( 'tamper_detected' );
				break;

			case 'MANIFEST_INCOMPLETE':
				update_option( $this->opt_tamper, array(
					'at'      => time(),
					'files'   => array(),
					'missing' => isset( $error['details']['missing_files'] ) ? (array) $error['details']['missing_files'] : array(),
				), false );
				break;

			case 'LICENSE_EXPIRED':
				$this->mark_license_state( 'expired' );
				break;

			case 'LICENSE_INVALIDATED':
				$this->mark_license_state( 'invalidated' );
				// 무효화는 grace 대상이 아니다 — 캐시 토큰 즉시 폐기 (API-CONTRACT §9.5).
				delete_option( $this->opt_token );
				break;

			case 'INTEGRITY_MANIFEST_NOT_FOUND':
			case 'VERSION_REVOKED':
			case 'DOMAIN_NOT_AUTHORIZED':
			case 'DOMAIN_LIMIT_REACHED':
			case 'LICENSE_NOT_FOUND':
				// 플러그인이 자동으로 고칠 수 없는 것들 — 운영자/사용자 조치가 필요합니다.
				// 자동 재시도는 무의미하므로 길게 물러나고 어드민에 그대로 노출합니다
				// (ERROR-CODES §3 "플러그인 측에서 자동화 ❌").
				update_option( $this->opt_platform, array(
					'at'      => time(),
					'code'    => $code,
					'details' => isset( $error['details'] ) ? (array) $error['details'] : array(),
				), false );
				set_transient( $this->opt_token . '_backoff', 1, HOUR_IN_SECONDS );
				break;

			case 'RATE_LIMITED':
				$retry = isset( $error['details']['retry_after_seconds'] ) ? (int) $error['details']['retry_after_seconds'] : 60;
				set_transient( $this->opt_token . '_backoff', 1, max( 30, $retry ) );
				break;

			case 'NETWORK_ERROR':
			case 'INTERNAL_ERROR':
			case 'SERVICE_UNAVAILABLE':
			case 'INVALID_RESPONSE':
				// grace 로 버틴다. 짧은 백오프만.
				set_transient( $this->opt_token . '_backoff', 1, 300 );
				break;

			default:
				$this->back_off();
				break;
		}
	}

	private function back_off() {
		set_transient( $this->opt_token . '_backoff', 1, self::FAILURE_BACKOFF );
	}

	private function mark_license_state( $state ) {
		$d           = $this->license_data();
		$d['status'] = $state;
		$this->save_license_data( $d );
	}

	// ─────────────────────────────────────────────────────────────
	// 무결성 (PLUGIN-DEV-GUIDE §7.2.2)
	// ─────────────────────────────────────────────────────────────

	private function do_compute_file_hashes() {
		$path = $this->plugin_dir . '/integrity.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true );
		$files    = isset( $manifest['files'] ) ? $manifest['files'] : array();
		if ( ! is_array( $files ) ) {
			return array();
		}

		$hashes = array();
		foreach ( $files as $rel ) {
			if ( ! is_string( $rel ) || '' === $rel ) {
				continue;
			}
			$full = $this->plugin_dir . '/' . $rel;
			if ( is_file( $full ) && is_readable( $full ) ) {
				// 원본 바이트 그대로 해시 — 개행 정규화·BOM·트림 절대 금지 (§7.2.2).
				$hashes[ $rel ] = hash_file( 'sha256', $full );
			}
		}

		return $hashes;
	}

	/** integrity.json 의 version. /auth/token 의 version 필드와 반드시 일치해야 한다. */
	public function manifest_version() {
		$path = $this->plugin_dir . '/integrity.json';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$m = json_decode( (string) file_get_contents( $path ), true );
		return isset( $m['version'] ) ? (string) $m['version'] : '';
	}

	private function fetch_runtime_manifest() {
		$key    = $this->cache_prefix . 'runtime_manifest';
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : $cached;
		}

		$r = $this->client->request( 'GET', sprintf(
			'/integrity/%s/v%s',
			rawurlencode( $this->product_slug ),
			rawurlencode( $this->plugin_version )
		) );

		if ( empty( $r['ok'] ) || empty( $r['data'] ) ) {
			set_transient( $key, array(), HOUR_IN_SECONDS );
			return null;
		}

		$manifest = array(
			'runtime_version' => isset( $r['data']['runtime_version'] ) ? $r['data']['runtime_version'] : null,
			'runtime_sri'     => isset( $r['data']['runtime_sri'] ) ? $r['data']['runtime_sri'] : null,
			'released_at'     => isset( $r['data']['released_at'] ) ? $r['data']['released_at'] : null,
		);
		set_transient( $key, $manifest, DAY_IN_SECONDS );

		return $manifest;
	}

	// ─────────────────────────────────────────────────────────────
	// 서명 (API-CONTRACT §5)
	// ─────────────────────────────────────────────────────────────

	private function do_sign_config( $config_id, array $payload ) {
		$token = $this->resolve_token( false );
		if ( ! $token ) {
			return array( 'ok' => false, 'error' => array( 'code' => 'TOKEN_REQUIRED' ) );
		}

		// ⚠ PLUGIN-CALL-FLOWS §3 — synthetic_test 플래그는 절대 보내지 않는다.
		$r = $this->client->request( 'POST', '/configs/sign', array(
			'product_slug' => $this->product_slug,
			'config_id'    => $config_id,
			'payload'      => $payload,
		), $token );

		if ( empty( $r['ok'] ) ) {
			$this->log_error( 'configs/sign', isset( $r['error']['code'] ) ? $r['error']['code'] : 'UNKNOWN', '' );
		}

		// data.canonical 은 호출 측이 **바이트 그대로** 보존해야 한다 (재직렬화 금지 — §5.1).
		return $r;
	}

	private function public_keys() {
		$keys = array();
		foreach ( $this->public_key_paths as $path ) {
			if ( is_readable( $path ) ) {
				$pem = (string) file_get_contents( $path );
				if ( false !== strpos( $pem, 'BEGIN PUBLIC KEY' ) ) {
					$keys[] = $pem;
				}
			}
		}
		return $keys;
	}

	private function do_verify_signature( $message, $sig_b64url ) {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return false;
		}
		$sig = self::b64url_decode( $sig_b64url );
		if ( '' === $sig ) {
			return false;
		}
		// 구/신 공개키를 모두 시도 (API-CONTRACT §11.3 키 회전 6개월 중첩).
		foreach ( $this->public_keys() as $pem ) {
			if ( 1 === openssl_verify( $message, $sig, $pem, OPENSSL_ALGO_SHA256 ) ) {
				return true;
			}
		}
		return false;
	}

	private function jwt_parts() {
		$cache = $this->token_cache();
		if ( empty( $cache['token'] ) ) {
			return null;
		}
		$parts = explode( '.', $cache['token'] );
		return 3 === count( $parts ) ? $parts : null;
	}

	private function do_verify_token_signature() {
		$parts = $this->jwt_parts();
		if ( ! $parts ) {
			return false;
		}
		if ( ! $this->do_verify_signature( $parts[0] . '.' . $parts[1], $parts[2] ) ) {
			return false;
		}
		$claims = json_decode( self::b64url_decode( $parts[1] ), true );
		if ( ! is_array( $claims ) ) {
			return false;
		}
		// 만료·대상 교차 확인 (API-CONTRACT §2.1). 오프라인 grace 만큼은 허용.
		$exp = isset( $claims['exp'] ) ? (int) $claims['exp'] : 0;
		if ( $exp + self::OFFLINE_GRACE <= time() ) {
			return false;
		}
		if ( ( isset( $claims['aud'] ) ? $claims['aud'] : '' ) !== $this->product_slug ) {
			return false;
		}
		return true;
	}

	private function do_get_token_claims() {
		$parts = $this->jwt_parts();
		if ( ! $parts ) {
			return array();
		}
		$claims = json_decode( self::b64url_decode( $parts[1] ), true );
		return is_array( $claims ) ? $claims : array();
	}

	private static function b64url_decode( $s ) {
		$s   = strtr( (string) $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		$out = base64_decode( $s, true );
		return false === $out ? '' : $out;
	}

	// ─────────────────────────────────────────────────────────────
	// 상태 · 진단
	// ─────────────────────────────────────────────────────────────

	private function resolve_status() {
		$d = $this->license_data();

		if ( get_option( $this->opt_tamper ) ) {
			return 'tamper_detected';
		}
		if ( in_array( $d['status'], array( 'expired', 'invalidated' ), true ) ) {
			return $d['status'];
		}
		if ( empty( $d['key'] ) ) {
			return 'unlicensed';
		}

		$cache = $this->token_cache();
		if ( ! empty( $cache['token'] ) ) {
			$exp = (int) strtotime( isset( $cache['expires_at'] ) ? $cache['expires_at'] : '' );
			return $exp > time() ? 'licensed' : 'grace';
		}

		return 'active' === $d['status'] ? 'grace' : 'unlicensed';
	}

	/** PLUGIN-DEV-GUIDE §3.6 SHOULD #1 — silent fail 가시화. */
	private function log_error( $where, $code, $message = '' ) {
		update_option( $this->opt_error, array(
			'at'      => time(),
			'where'   => $where,
			'code'    => $code,
			'message' => (string) $message,
		), false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[dw-wholesale] %s failed: code=%s %s', $where, $code, $message ) );
		}
	}

	public static function get_last_error( $slug = null ) {
		$i = self::get( $slug );
		if ( ! $i ) {
			return array();
		}
		$e = get_option( $i->opt_error, array() );
		return is_array( $e ) ? $e : array();
	}

	/**
	 * 운영자 진단용 스냅샷 (PLUGIN-DEV-GUIDE §3.6 SHOULD #2).
	 *
	 * ⚠ 라이선스 키·토큰·raw tier 는 **절대 포함하지 않습니다**
	 *   (modalpopup P15/P16 — 진단 패널이 attack surface 가 된 사례).
	 */
	public static function get_diagnostics( $slug = null ) {
		$i = self::get( $slug );
		if ( ! $i ) {
			return array( 'sdk_registered' => false );
		}

		$methods = array();
		foreach ( array(
			'has_valid_token', 'verify_token_signature', 'get_token_claims',
			'get_status', 'compute_file_hashes', 'sign_config', 'get_runtime_manifest',
		) as $m ) {
			$methods[ $m ] = is_callable( array( __CLASS__, $m ) );
		}

		$loaded_from = '';
		$winner      = '';
		if ( class_exists( __CLASS__ ) ) {
			$ref         = new ReflectionClass( __CLASS__ );
			$loaded_from = (string) $ref->getFileName();
			if ( preg_match( '#/plugins/([^/]+)/#', str_replace( '\\', '/', $loaded_from ), $m2 ) ) {
				$winner = $m2[1];
			}
		}

		$cache    = $i->token_cache();
		$license  = $i->license_data();
		$platform = get_option( $i->opt_platform, array() );

		return array(
			'sdk_registered'               => true,
			'sdk_class'                    => __CLASS__,
			'sdk_strategy'                 => 'prefix',
			'sdk_class_loaded_from'        => $loaded_from,
			'sdk_class_loaded_from_plugin' => $winner,
			'sdk_methods_available'        => $methods,
			'last_sdk_error'               => get_option( $i->opt_error, null ),
			'product_slug'                 => $i->product_slug,
			'plugin_version'               => $i->plugin_version,
			'manifest_version'             => $i->manifest_version(),
			'version_matches_manifest'     => $i->manifest_version() === $i->plugin_version,
			'domain'                       => $i->current_domain(),
			'is_multisite'                 => $i->is_network(),
			'status'                       => $i->resolve_status(),
			'license_present'              => ! empty( $license['key'] ),   // 키 자체는 노출 ❌
			'token_present'                => ! empty( $cache['token'] ),   // 토큰 자체는 노출 ❌
			'token_expires_at'             => isset( $cache['expires_at'] ) ? $cache['expires_at'] : null,
			'grace_days_left'              => $i->grace_days_left(),
			'platform_notice'              => ! empty( $platform ) ? $platform : null,
			'offline_since'                => get_option( $i->opt_offline, null ),
		);
	}

	public static function get_tamper_notice( $slug = null ) {
		$i = self::get( $slug );
		if ( ! $i ) {
			return array();
		}
		$t = get_option( $i->opt_tamper, array() );
		return is_array( $t ) ? $t : array();
	}

	public function translate_error( $code ) {
		$map = array(
			'LICENSE_NOT_FOUND'            => __( '라이선스 키가 존재하지 않습니다.', 'dw-wholesale' ),
			'LICENSE_EXPIRED'              => __( '라이선스가 만료되었습니다. 갱신하지 않으면 곧 동작이 중단됩니다.', 'dw-wholesale' ),
			'LICENSE_INVALIDATED'          => __( '라이선스가 무효화되었습니다. 고객지원에 문의해주세요.', 'dw-wholesale' ),
			'LICENSE_FEATURE_NOT_ALLOWED'  => __( '현재 라이선스 등급에서는 사용할 수 없는 기능입니다.', 'dw-wholesale' ),
			'DOMAIN_NOT_AUTHORIZED'        => __( '이 도메인은 라이선스에 등록되지 않았습니다.', 'dw-wholesale' ),
			'DOMAIN_LIMIT_REACHED'         => __( '라이선스의 도메인 한도를 초과했습니다.', 'dw-wholesale' ),
			'TOKEN_EXPIRED'                => __( '인증 토큰이 만료되어 갱신 중입니다.', 'dw-wholesale' ),
			'TOKEN_INVALID'                => __( '인증 정보가 유효하지 않습니다. 라이선스를 재활성화해주세요.', 'dw-wholesale' ),
			'INTEGRITY_MANIFEST_NOT_FOUND' => __( '이 플러그인 버전이 등록되지 않았습니다. 최신 버전으로 업데이트해주세요.', 'dw-wholesale' ),
			'TAMPER_DETECTED'              => __( '플러그인 파일이 변조되었습니다. 정상 버전으로 재설치해주세요.', 'dw-wholesale' ),
			'MANIFEST_INCOMPLETE'          => __( '무결성 정보가 불완전합니다. 정상 버전으로 재설치해주세요.', 'dw-wholesale' ),
			'VERSION_REVOKED'              => __( '이 플러그인 버전은 보안 사유로 지원되지 않습니다. 즉시 업데이트해주세요.', 'dw-wholesale' ),
			'PAYLOAD_TOO_LARGE'            => __( '저장하려는 설정이 너무 큽니다.', 'dw-wholesale' ),
			'RATE_LIMITED'                 => __( '요청이 너무 많습니다. 잠시 후 다시 시도해주세요.', 'dw-wholesale' ),
			'INTERNAL_ERROR'               => __( '서버 오류. 잠시 후 다시 시도해주세요.', 'dw-wholesale' ),
			'SERVICE_UNAVAILABLE'          => __( '서비스 일시 점검 중입니다.', 'dw-wholesale' ),
			'NETWORK_ERROR'                => __( '서버 연결 실패. 인터넷 연결을 확인해주세요.', 'dw-wholesale' ),
			'INVALID_RESPONSE'             => __( '서버 응답 형식 오류. 잠시 후 다시 시도해주세요.', 'dw-wholesale' ),
			'LOCAL_MANIFEST_MISSING'       => __( 'integrity.json 이 없습니다. 정상 릴리스 ZIP 으로 재설치해주세요.', 'dw-wholesale' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : __( '알 수 없는 오류가 발생했습니다.', 'dw-wholesale' );
	}

	// ─────────────────────────────────────────────────────────────
	// 자동 업데이트 (legacy flat — update_available 필드)
	// ─────────────────────────────────────────────────────────────

	public function check_for_update( $transient ) {
		if ( empty( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$info = $this->update_info();
		if ( $info && version_compare( $info['version'], $this->plugin_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => dirname( $this->plugin_basename ),
				'plugin'      => $this->plugin_basename,
				'new_version' => $info['version'],
				'url'         => '',
				'package'     => $info['download_url'],
			);
		} else {
			$transient->no_update[ $this->plugin_basename ] = (object) array(
				'slug'        => dirname( $this->plugin_basename ),
				'plugin'      => $this->plugin_basename,
				'new_version' => $this->plugin_version,
			);
		}

		return $transient;
	}

	private function update_info() {
		$key = $this->cache_prefix . 'update_info';
		$c   = get_transient( $key );
		if ( false !== $c ) {
			return ( is_array( $c ) && ! empty( $c ) ) ? $c : null;
		}

		$d = $this->license_data();
		if ( empty( $d['key'] ) ) {
			set_transient( $key, array(), HOUR_IN_SECONDS );
			return null;
		}

		$r = $this->client->request( 'GET', '/releases/update-check', array(
			'license_key'     => $d['key'],
			'product_slug'    => $this->product_slug,
			'current_version' => $this->plugin_version,
		) );

		if ( isset( $r['ok'] ) && ! $r['ok'] ) {
			set_transient( $key, array(), HOUR_IN_SECONDS );
			return null;
		}

		// legacy flat — update_available 로 판정 (envelope data.latest_version 아님).
		if ( empty( $r['update_available'] ) || empty( $r['version'] ) ) {
			set_transient( $key, array(), 6 * HOUR_IN_SECONDS );
			return null;
		}

		$info = array(
			'version'      => (string) $r['version'],
			'download_url' => isset( $r['download_url'] ) ? (string) $r['download_url'] : '',
			'changelog'    => isset( $r['changelog'] ) ? (string) $r['changelog'] : '',
		);
		// download_url 은 1시간 signed URL — 12h 캐시하면 만료된 URL 을 넘기게 된다.
		set_transient( $key, $info, 30 * MINUTE_IN_SECONDS );

		return $info;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_basename ) ) {
			return $result;
		}
		$info = $this->update_info();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => $this->plugin_name,
			'slug'          => dirname( $this->plugin_basename ),
			'version'       => $info['version'],
			'download_link' => $info['download_url'],
			'sections'      => array(
				'description' => $this->plugin_name,
				'changelog'   => nl2br( esc_html( $info['changelog'] ) ),
			),
			'requires'      => '5.0',
			'requires_php'  => '7.4',
			'tested'        => '6.7',
		);
	}

	// ─────────────────────────────────────────────────────────────
	// 관리자 화면
	// ─────────────────────────────────────────────────────────────

	/** 멀티사이트 네트워크 어드민 전용 라이선스 화면 (FAQ Q10). */
	public function add_network_menu_page() {
		if ( ! $this->is_network() ) {
			return;
		}
		add_menu_page(
			$this->plugin_name . ' License',
			__( 'DW Wholesale', 'dw-wholesale' ),
			'manage_network_options',
			$this->settings_page,
			array( $this, 'render_settings_page' ),
			'dashicons-grid-view',
			80
		);
	}

	public function add_menu_page() {
		$parent = is_callable( $this->menu_parent ) ? call_user_func( $this->menu_parent ) : $this->menu_parent;
		add_submenu_page(
			$parent,
			$this->plugin_name . ' License',
			__( 'License', 'dw-wholesale' ),
			'manage_options',
			$this->settings_page,
			array( $this, 'render_settings_page' )
		);
	}

	/** PLUGIN-DEV-GUIDE §11.1.b — 라이선스 화면 로드 시 lazy refresh. */
	public function maybe_lazy_refresh() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, $this->settings_page ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		// 만료 5분 이내면 resolve_token 이 알아서 재발급한다.
		// is_admin() 이므로 should_defer_network() 가 false → 동기 발급 허용 (§11.1.b).
		$this->resolve_token( false );
	}

	/** §11.1.c — 명시적 "라이선스 상태 새로고침" 액션. */
	/** 라이선스를 바꿀 수 있는 권한 — 멀티사이트면 네트워크 관리자만. */
	private function can_manage_license() {
		return $this->is_network()
			? current_user_can( 'manage_network_options' )
			: current_user_can( 'manage_options' );
	}

	public function handle_admin_post() {
		if ( ! $this->can_manage_license() ) {
			wp_die( esc_html__( '권한이 없습니다.', 'dw-wholesale' ) );
		}
		check_admin_referer( $this->cache_prefix . 'license_action' );

		$op     = isset( $_POST['dwcat_op'] ) ? sanitize_key( wp_unslash( $_POST['dwcat_op'] ) ) : '';
		$notice = '';

		if ( 'activate' === $op ) {
			$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
			$r      = $this->do_activate( $key );
			$notice = $r['success'] ? 'activated' : 'failed';
		} elseif ( 'deactivate' === $op ) {
			$this->do_deactivate();
			$notice = 'deactivated';
		} elseif ( 'refresh' === $op ) {
			delete_option( $this->opt_token );
			delete_option( $this->opt_platform );
			delete_option( $this->opt_offline );
			delete_transient( $this->opt_token . '_backoff' );
			delete_transient( $this->cache_prefix . 'update_info' );
			delete_transient( $this->cache_prefix . 'runtime_manifest' );
			$notice = $this->resolve_token( true ) ? 'refreshed' : 'refresh_failed';
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => $this->settings_page, 'dwcat_notice' => $notice ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	private function action_form( $op, $label, $class = 'button', $confirm = '' ) {
		printf(
			'<form method="post" action="%s" style="display:inline-block;margin-right:8px;"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''
		);
		wp_nonce_field( $this->cache_prefix . 'license_action' );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $this->cache_prefix . 'license_action' ) );
		printf( '<input type="hidden" name="dwcat_op" value="%s" />', esc_attr( $op ) );
		printf( '<button type="submit" class="%s">%s</button>', esc_attr( $class ), esc_html( $label ) );
		echo '</form>';
	}

	public function render_settings_page() {
		$d      = $this->license_data();
		$status = $this->resolve_status();
		$cache  = $this->token_cache();
		$notice = isset( $_GET['dwcat_notice'] ) ? sanitize_key( wp_unslash( $_GET['dwcat_notice'] ) ) : '';
		$is_on  = ! empty( $d['key'] ) && 'active' === $d['status'];
		$labels = array(
			'licensed'        => array( __( '정상', 'dw-wholesale' ), '#00a32a' ),
			'grace'           => array( __( '유예 모드', 'dw-wholesale' ), '#dba617' ),
			'unlicensed'      => array( __( '미활성', 'dw-wholesale' ), '#72777c' ),
			'expired'         => array( __( '만료', 'dw-wholesale' ), '#d63638' ),
			'invalidated'     => array( __( '무효화', 'dw-wholesale' ), '#d63638' ),
			'tamper_detected' => array( __( '파일 변조 감지', 'dw-wholesale' ), '#d63638' ),
		);
		$li    = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unlicensed'];
		$label = $li[0];
		$color = $li[1];
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->plugin_name ); ?> — <?php esc_html_e( '라이선스', 'dw-wholesale' ); ?></h1>

			<?php if ( 'activated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '라이선스가 활성화되었습니다.', 'dw-wholesale' ); ?></p></div>
			<?php elseif ( 'deactivated' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( '라이선스를 비활성화했습니다.', 'dw-wholesale' ); ?></p></div>
			<?php elseif ( 'refreshed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '토큰을 새로 발급받았습니다.', 'dw-wholesale' ); ?></p></div>
			<?php elseif ( 'failed' === $notice || 'refresh_failed' === $notice ) : ?>
				<?php $e = get_option( $this->opt_error, array() ); ?>
				<div class="notice notice-error"><p>
					<?php echo esc_html( $this->translate_error( isset( $e['code'] ) ? $e['code'] : 'UNKNOWN' ) ); ?>
					<?php if ( ! empty( $e['code'] ) ) : ?>
						<code style="margin-left:8px;"><?php echo esc_html( $e['code'] ); ?></code>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<div style="max-width:720px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:24px;margin-top:20px;">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '상태', 'dw-wholesale' ); ?></th>
						<td>
							<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;margin-right:6px;"></span>
							<strong style="color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $label ); ?></strong>
							<?php if ( ! empty( $d['expires_at'] ) ) : ?>
								<span style="color:#72777c;margin-left:8px;">
									(<?php esc_html_e( '만료', 'dw-wholesale' ); ?>: <?php echo esc_html( gmdate( 'Y-m-d', (int) strtotime( $d['expires_at'] ) ) ); ?>)
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '라이선스 키', 'dw-wholesale' ); ?></th>
						<td>
							<?php if ( $is_on ) : ?>
								<code style="padding:8px 12px;background:#f0f0f1;border-radius:4px;">
									<?php echo esc_html( substr( $d['key'], 0, 8 ) . '…' . substr( $d['key'], -4 ) ); ?>
								</code>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( $this->cache_prefix . 'license_action' ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( $this->cache_prefix . 'license_action' ); ?>" />
									<input type="hidden" name="dwcat_op" value="activate" />
									<input type="text" name="license_key" class="regular-text" autocomplete="off" placeholder="DW-XXXX-XXXX-XXXX" required />
									<button type="submit" class="button button-primary"><?php esc_html_e( '활성화', 'dw-wholesale' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $is_on ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( '등급', 'dw-wholesale' ); ?></th>
						<td><?php echo esc_html( '' !== $d['tier'] ? $d['tier'] : '—' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '도메인', 'dw-wholesale' ); ?></th>
						<td>
							<?php echo esc_html( implode( ', ', (array) $d['active_domains'] ) ); ?>
							<span style="color:#72777c;"> (<?php echo (int) $d['max_domains']; ?><?php esc_html_e( '개까지', 'dw-wholesale' ); ?>)</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '토큰 만료', 'dw-wholesale' ); ?></th>
						<td style="color:#72777c;">
							<?php echo esc_html( ! empty( $cache['expires_at'] ) ? $cache['expires_at'] : __( '발급 이력 없음', 'dw-wholesale' ) ); ?>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( '플러그인 버전', 'dw-wholesale' ); ?></th>
						<td>
							<?php echo esc_html( $this->plugin_version ); ?>
							<?php if ( '' !== $this->manifest_version() && $this->manifest_version() !== $this->plugin_version ) : ?>
								<span style="color:#d63638;margin-left:8px;">
									⚠ integrity.json = <?php echo esc_html( $this->manifest_version() ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th></th>
						<td>
							<?php
							// §11.1.c — 운영자가 검증 직전에 누를 수 있는 단일 트리거.
							$this->action_form( 'refresh', __( '라이선스 상태 새로고침', 'dw-wholesale' ) );
							if ( $is_on ) {
								$this->action_form(
									'deactivate',
									__( '비활성화', 'dw-wholesale' ),
									'button',
									__( '이 도메인에서 라이선스를 해제할까요?', 'dw-wholesale' )
								);
							}
							?>
						</td>
					</tr>
				</table>

				<?php $err = get_option( $this->opt_error, array() ); ?>
				<?php if ( ! empty( $err['code'] ) ) : ?>
					<p style="margin:16px 0 0;padding:12px;background:#fcf9e8;border-left:4px solid #dba617;">
						<strong><?php esc_html_e( '마지막 오류', 'dw-wholesale' ); ?>:</strong>
						<code><?php echo esc_html( $err['where'] . ' → ' . $err['code'] ); ?></code>
						<span style="color:#72777c;margin-left:8px;">
							<?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $err['at'] ) . ' UTC' ); ?>
						</span>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function license_page_url() {
		return $this->is_network()
			? network_admin_url( 'admin.php?page=' . $this->settings_page )
			: admin_url( 'admin.php?page=' . $this->settings_page );
	}

	private function notice( $level, $message, $link_label = '' ) {
		printf(
			'<div class="notice notice-%s"><p><strong>%s:</strong> %s%s</p></div>',
			esc_attr( $level ),
			esc_html( $this->plugin_name ),
			esc_html( $message ),
			$link_label
				? sprintf( ' <a href="%s">%s</a>', esc_url( $this->license_page_url() ), esc_html( $link_label ) )
				: ''
		);
	}

	/**
	 * 어드민 알림. 우선순위 = 심각도 순이며, 가장 심각한 것 하나만 띄웁니다
	 * (노티스 폭탄을 만들면 사용자가 전부 무시하게 됩니다).
	 */
	public function render_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		// ① 변조 감지 — §11.2.c: mismatched_files 를 그대로 노출. swallow 금지.
		$tamper = get_option( $this->opt_tamper, array() );
		if ( ! empty( $tamper ) && ( ! empty( $tamper['files'] ) || ! empty( $tamper['missing'] ) ) ) {
			echo '<div class="notice notice-error"><p><strong>';
			echo esc_html( $this->plugin_name . ': ' . __( '플러그인 파일이 변조되었습니다. 정상 버전으로 재설치해주세요.', 'dw-wholesale' ) );
			echo '</strong></p><ul style="margin-left:20px;list-style:disc;">';
			foreach ( (array) ( isset( $tamper['files'] ) ? $tamper['files'] : array() ) as $f ) {
				echo '<li><code>' . esc_html( (string) $f ) . '</code></li>';
			}
			foreach ( (array) ( isset( $tamper['missing'] ) ? $tamper['missing'] : array() ) as $f ) {
				echo '<li><code>' . esc_html( (string) $f ) . '</code> — ' . esc_html__( '누락', 'dw-wholesale' ) . '</li>';
			}
			echo '</ul></div>';
			return;
		}

		// Loose 판정 (§3.4) — 사용자 안내는 option 상태 기준.
		// 토큰 발급이 한 번 실패했다고 "비활성"으로 보이면 안 된다.
		$d = $this->license_data();

		// ② 라이선스 무효화 — 유예 없음.
		if ( 'invalidated' === $d['status'] ) {
			$this->notice(
				'error',
				__( '라이선스가 무효화되었습니다. 고객지원에 문의해주세요.', 'dw-wholesale' ),
				__( '라이선스 설정', 'dw-wholesale' )
			);
			return;
		}

		// ③ 플랫폼 측 조치가 필요한 코드 — 사용자가 재시도해도 안 풀립니다.
		//    ERROR-CODES §3: "플러그인 측에서 자동화 ❌ → 운영자 연락"
		$platform = get_option( $this->opt_platform, array() );
		if ( ! empty( $platform['code'] ) ) {
			$code = $platform['code'];
			$hint = array(
				'INTEGRITY_MANIFEST_NOT_FOUND' => __( '이 버전이 플랫폼에 등록되지 않았습니다. 공급자에게 문의하거나 최신 버전으로 업데이트해주세요.', 'dw-wholesale' ),
				'VERSION_REVOKED'              => __( '이 버전은 보안 사유로 지원 중단되었습니다. 즉시 업데이트해주세요.', 'dw-wholesale' ),
				'DOMAIN_NOT_AUTHORIZED'        => __( '이 도메인이 라이선스에 등록되지 않았습니다. 라이선스를 다시 활성화해주세요.', 'dw-wholesale' ),
				'DOMAIN_LIMIT_REACHED'         => __( '라이선스의 도메인 한도를 초과했습니다. 사용하지 않는 도메인을 해제하거나 상위 플랜이 필요합니다.', 'dw-wholesale' ),
				'LICENSE_NOT_FOUND'            => __( '라이선스 키를 찾을 수 없습니다. 키를 다시 확인해주세요.', 'dw-wholesale' ),
			);
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s <code>%s</code> <a href="%s">%s</a></p></div>',
				esc_html( $this->plugin_name ),
				esc_html( isset( $hint[ $code ] ) ? $hint[ $code ] : $this->translate_error( $code ) ),
				esc_html( $code ),
				esc_url( $this->license_page_url() ),
				esc_html__( '라이선스 설정', 'dw-wholesale' )
			);
			return;
		}

		// ④ 라이선스 만료 — 30일 유예 중. 남은 일수를 정확히 알려 줍니다.
		if ( 'expired' === $d['status'] ) {
			$days = $this->grace_days_left();
			$this->notice(
				$days <= 7 ? 'error' : 'warning',
				$days > 0
					? sprintf(
						/* translators: %d: days remaining */
						__( '라이선스가 만료되었습니다. %d일 후 카탈로그 출력이 중단됩니다.', 'dw-wholesale' ),
						$days
					)
					: __( '라이선스가 만료되어 카탈로그 출력이 중단되었습니다.', 'dw-wholesale' ),
				__( '라이선스 갱신', 'dw-wholesale' )
			);
			return;
		}

		// ⑤ 서비스 연결 끊김 — FAQ Q11 #2: 24시간 이상 토큰 재발급 실패 시 안내.
		//    그 전에는 조용히 캐시 토큰으로 버팁니다 (사용자에게 에러를 보이지 않음).
		$offline_since = (int) get_option( $this->opt_offline, 0 );
		if ( $offline_since > 0 && ( time() - $offline_since ) > self::OFFLINE_NOTICE_AFTER && ! empty( $d['key'] ) ) {
			$hours = (int) floor( ( time() - $offline_since ) / HOUR_IN_SECONDS );
			$this->notice(
				'warning',
				sprintf(
					/* translators: %d: hours since last successful contact */
					__( '라이선스 서버 연결이 %d시간째 끊겨 있습니다. 유예가 끝나면 카탈로그 출력이 중단됩니다.', 'dw-wholesale' ),
					$hours
				),
				__( '상태 확인', 'dw-wholesale' )
			);
			return;
		}

		// ⑥ §3.6 SHOULD #1 — silent fail 가시화.
		//    Prefix 전략이라 클래스 충돌은 없지만, 자기 fork 가 낡아 메서드가
		//    빠진 채로 배포되면 게이트가 조용히 전부 false 로 떨어집니다.
		$missing = array();
		foreach ( array( 'has_valid_token', 'verify_token_signature', 'get_token_claims', 'get_status' ) as $m ) {
			if ( ! is_callable( array( __CLASS__, $m ) ) ) {
				$missing[] = $m;
			}
		}
		if ( $missing ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s <code>%s</code></p></div>',
				esc_html( $this->plugin_name ),
				esc_html__( 'SDK 가 손상되었습니다 (필수 메서드 누락). 정상 버전으로 재설치해주세요.', 'dw-wholesale' ),
				esc_html( implode( ', ', $missing ) )
			);
		}
	}
}

endif;
