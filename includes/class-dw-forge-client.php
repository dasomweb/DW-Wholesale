<?php
/**
 * DASOM-Forge API Client — dw-wholesale fork.
 *
 * ── Prefix 배포 전략 (PLUGIN-DEV-GUIDE §3.5 B / SDK-README §Prefix) ──
 * 정본 클래스 `DW_Forge_Client` 를 `DW_DWCAT_Forge_Client` 로 rename 한 사본입니다.
 * 클래스명 외 로직·메서드 시그니처는 정본과 동일하게 유지하십시오.
 * 정본이 갱신되면 rename 만 재적용해 rebase 합니다 (로직 diff 는 upstream 그대로).
 *
 * ── 응답 규약 (API-CONTRACT §1.3) ──
 * request() 는 서버 JSON 을 **그대로** 반환합니다. shape 은 endpoint 별로 두 가지:
 *   (a) v3.0 envelope  — /auth/*, /configs/*, /integrity/*
 *                        { ok: bool, data?: array, error?: {code,message,details} }
 *   (b) legacy flat    — /license/activate|verify|deactivate, /releases/update-check
 *                        endpoint 고유 boolean 필드 (success / valid / update_available)
 * 네트워크·비JSON 실패만 클라이언트가 envelope 형태로 감쌉니다.
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DW_DWCAT_Forge_Client' ) ) :

class DW_DWCAT_Forge_Client {

	/** API-CONTRACT §베이스 URL. 상수로 오버라이드 가능(스테이징 대비). */
	const API_BASE_DEFAULT = 'https://api.dasomforge.com/api/v1';

	/** PLUGIN-ERROR-CODES §7: 기본 5초는 느린 호스트에서 조기 실패 → 15~30초 권장. */
	const TIMEOUT = 20;

	/** @var string */
	private $product_slug;

	/** @var string */
	private $plugin_version;

	/** @var string */
	private $api_base;

	public function __construct( string $product_slug, string $plugin_version, string $api_base = '' ) {
		$this->product_slug   = $product_slug;
		$this->plugin_version = $plugin_version;

		if ( $api_base === '' ) {
			$api_base = defined( 'DWCAT_FORGE_API_BASE' ) ? DWCAT_FORGE_API_BASE : self::API_BASE_DEFAULT;
		}
		$this->api_base = rtrim( $api_base, '/' );
	}

	public function get_api_base() : string {
		return $this->api_base;
	}

	/**
	 * 공통 HTTP 요청.
	 *
	 * @param string      $method GET|POST|PUT|PATCH|DELETE
	 * @param string      $path   '/auth/token' 처럼 선행 슬래시 포함
	 * @param array       $body   POST 계열은 JSON 본문, GET 은 쿼리 파라미터
	 * @param string|null $token  Bearer 토큰 (필요한 endpoint 만)
	 * @return array 서버 JSON 그대로. 전송 실패 시 envelope 로 감싼 에러.
	 */
	public function request( string $method, string $path, array $body = array(), ?string $token = null ) : array {
		$method = strtoupper( $method );
		$url    = $this->api_base . $path;

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => $this->build_user_agent(),
			),
		);

		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		if ( ! empty( $body ) ) {
			if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
				$args['body'] = wp_json_encode( $body );
			} else {
				$url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => array(
					'code'    => 'NETWORK_ERROR',
					'message' => $response->get_error_message(),
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $parsed ) ) {
			return array(
				'ok'    => false,
				'error' => array(
					'code'    => 'INVALID_RESPONSE',
					'message' => sprintf( 'Server returned non-JSON (HTTP %d)', $status ),
					'details' => array( 'http_status' => $status ),
				),
			);
		}

		// API-CONTRACT §10.2 — Retry-After 헤더를 details 에 정규화해서 실어 준다.
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( $retry_after !== '' && isset( $parsed['error'] ) && is_array( $parsed['error'] ) ) {
			$parsed['error']['details']['retry_after_seconds'] = (int) $retry_after;
		}

		// 진단용. envelope/legacy 판정에는 쓰지 않는다.
		$parsed['_http_status'] = $status;

		return $parsed;
	}

	/**
	 * API-CONTRACT §1.1 — 실 사이트 User-Agent.
	 *
	 * ⚠ dev 진단 호출은 이 UA 를 쓰지 말 것.
	 *   PLUGIN-AGENT-API-CONDUCT §MUST 3 — `DW-dev-diag/<slug>-agent` 사용.
	 */
	private function build_user_agent() : string {
		global $wp_version;
		return sprintf(
			'DW-%s/%s (WordPress/%s; PHP/%s)',
			$this->product_slug,
			$this->plugin_version,
			isset( $wp_version ) ? $wp_version : 'unknown',
			PHP_VERSION
		);
	}
}

endif;
