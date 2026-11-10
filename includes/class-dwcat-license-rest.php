<?php
/**
 * 라이선스 진단 REST 엔드포인트 — PLUGIN-DEV-GUIDE §3.6 SHOULD #2.
 *
 *   GET /wp-json/dw-wholesale/v1/license/status
 *
 * 운영자가 카나리·고객 환경에서 이 엔드포인트 하나만 폴링하면
 * "어느 플러그인의 SDK 사본이 실제로 로드됐는지 · 어떤 메서드가 있는지 ·
 *  마지막 실패가 무엇인지" 를 즉시 알 수 있습니다.
 *
 * ── 노출 금지 (modalpopup P15/P16 교훈) ──
 * 진단 패널이 attack surface 가 된 실사례가 있습니다. 따라서:
 *   · license_key  ❌  (존재 여부 boolean 만)
 *   · JWT 토큰      ❌  (존재 여부 + 만료 시각만)
 *   · raw tier slug ❌  (등급 문자열을 그대로 흘리지 않음)
 *   · permission_callback 은 반드시 실제 capability 검사 (__return_true 금지)
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWCAT_License_REST {

	const NAMESPACE_V1 = 'dw-wholesale/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/license/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				// DW Admin SPA 통합 가이드 §7 — permission_callback 에 __return_true 금지.
				// 라이선스 상태는 관리자 전용 정보입니다.
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * 관리자만. Application Password 로 인증한 요청도 여기를 통과합니다
	 * (WP 가 basic auth 를 해석해 current_user 를 세팅해 주므로).
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' );
	}

	public function get_status() {
		$sdk = is_callable( array( 'DW_DWCAT_License_Manager', 'get_diagnostics' ) )
			? DW_DWCAT_License_Manager::get_diagnostics()
			: array( 'sdk_registered' => false, 'error' => 'SDK not loaded' );

		$gates = function_exists( 'dwcat_gate_snapshot' ) ? dwcat_gate_snapshot() : array();

		return rest_ensure_response( array(
			'plugin'     => array(
				'slug'    => 'dw-wholesale',
				'version' => dwcat_get_config()['plugin_version'],
			),
			'sdk'        => $sdk,
			'gates'      => $gates,
			'checked_at' => gmdate( 'c' ),
		) );
	}
}
