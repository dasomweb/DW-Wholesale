<?php
/**
 * 라이선스 게이트 — 분산 검증 (PLUGIN-DEV-GUIDE §6)
 *
 * 설계 규칙:
 *  1. 게이트는 **서로 다른 위치**에 5~10개 분산 (단일 지점 nulling 방어)
 *  2. 게이트마다 **서로 다른 검증 경로** 사용 —
 *     has_valid_token() / verify_token_signature() / 토큰 클레임 / 매니페스트 정합
 *     전부 같은 함수를 부르면 한 줄 패치로 뚫린다.
 *  3. Strict(anti-piracy 차단) 와 Loose(사용자 안내 표시) 를 분리 (§3.4)
 *  4. 모든 SDK 호출은 is_callable() 가드 — 다른 DW 플러그인의 구 SDK 가
 *     winner 여도 fatal 대신 silent false 로 떨어지게 (§3.3 / SDK-README)
 *     ※ Prefix 전략이라 이론상 충돌은 없지만 방어선은 유지한다.
 *
 * 하위호환 (KICKOFF FAQ Q8):
 *  v1.x → v2.0 자동 업데이트로 기존 사이트 카탈로그가 즉시 사라지면 안 된다.
 *  업그레이드로 감지된 설치는 DWCAT_LEGACY_GRACE_DAYS 동안 게이트를 통과시키고
 *  관리자에게 점증 안내를 띄운다. 신규 설치에는 유예 없음.
 *
 * @package DW_Catalog_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 기존 v1.x 사용자에게 주는 마이그레이션 유예 (일). */
if ( ! defined( 'DWCAT_LEGACY_GRACE_DAYS' ) ) {
	define( 'DWCAT_LEGACY_GRACE_DAYS', 30 );
}

const DWCAT_SDK_CLASS       = 'DW_DWCAT_License_Manager';
const DWCAT_OPT_LEGACY_TILL = 'dwcat_legacy_grace_until';

// ─────────────────────────────────────────────────────────────────
// 공통 우회 경로 (dev bypass + 레거시 유예) — 이 둘만 여기 모은다.
// 실제 라이선스 검증은 각 게이트가 자기 경로로 직접 수행한다.
// ─────────────────────────────────────────────────────────────────

/**
 * 개발용 우회 + v1.x 마이그레이션 유예.
 *
 * DWCAT_DEV_BYPASS 는 wp-config.php 에서만 정의하십시오.
 * 릴리스 ZIP 에는 wp-config.php 가 포함되지 않습니다 (KICKOFF FAQ Q7).
 */
function dwcat_gate_bypass() {
	if ( defined( 'DWCAT_DEV_BYPASS' ) && DWCAT_DEV_BYPASS ) {
		return true;
	}
	return dwcat_in_legacy_grace();
}

/** 업그레이드 설치에 부여된 유예가 아직 살아 있는가. */
function dwcat_in_legacy_grace() {
	$until = (int) get_option( DWCAT_OPT_LEGACY_TILL, 0 );
	return $until > 0 && $until > time();
}

/** 유예 만료까지 남은 일수 (유예 없으면 0). */
function dwcat_legacy_grace_days_left() {
	$until = (int) get_option( DWCAT_OPT_LEGACY_TILL, 0 );
	if ( $until <= time() ) {
		return 0;
	}
	return (int) ceil( ( $until - time() ) / DAY_IN_SECONDS );
}

/**
 * v1.x 에서 올라온 설치에 유예를 1회 부여.
 * 이미 라이선스가 활성이면 유예가 필요 없다.
 */
function dwcat_maybe_grant_legacy_grace( $old_version ) {
	if ( '' === (string) $old_version ) {
		return; // 신규 설치 — 유예 없음
	}
	// ⚠ version_compare('2.0.0-rc.1', '2.0.0', '>=') 는 **false** 입니다
	//   (프리릴리스가 릴리스보다 작음). 그대로 쓰면 rc.1 → rc.2 업그레이드 때
	//   이미 v2 인 사이트에 레거시 유예가 잘못 부여됩니다.
	//   유예는 "v1 에서 올라온 설치" 에만 줘야 하므로 메이저 버전으로 판정합니다.
	if ( (int) strtok( (string) $old_version, '.' ) >= 2 ) {
		return; // 이미 v2 계열 (프리릴리스 포함)
	}
	if ( false !== get_option( DWCAT_OPT_LEGACY_TILL, false ) ) {
		return; // 이미 1회 부여됨 (연장 금지)
	}
	if ( dwcat_is_licensed() ) {
		return;
	}

	add_option(
		DWCAT_OPT_LEGACY_TILL,
		time() + ( (int) DWCAT_LEGACY_GRACE_DAYS * DAY_IN_SECONDS ),
		'',
		false
	);
}

/** SDK 정적 메서드 존재 확인. is_callable 이 method_exists 보다 정확 (SDK-README). */
function dwcat_sdk_has( $method ) {
	return is_callable( array( DWCAT_SDK_CLASS, $method ) );
}

// ─────────────────────────────────────────────────────────────────
// Loose helper — 사용자 안내 라벨 전용 (§3.4). anti-piracy 차단에 쓰지 말 것.
// ─────────────────────────────────────────────────────────────────

/**
 * 관리자 UI 라벨용. 토큰 발급이 한 번 실패했다고 "비활성"으로 보이면 혼란만 준다.
 * option 이 진실의 출처 (CASE-STUDIES P6/P7 — transient 를 권위로 쓰지 말 것).
 */
function dwcat_is_licensed() {
	if ( defined( 'DWCAT_DEV_BYPASS' ) && DWCAT_DEV_BYPASS ) {
		return true;
	}
	$d = get_option( 'dw_license_dw_wholesale', array() );
	return is_array( $d ) && isset( $d['status'] ) && 'active' === $d['status'];
}

// ─────────────────────────────────────────────────────────────────
// Strict 게이트 9종 — 각각 다른 검증 경로
// ─────────────────────────────────────────────────────────────────

/**
 * 게이트 1 — 프런트엔드 카탈로그 렌더.
 * 경로: 토큰 존재/유효성 (grace 포함).
 * 적용: DWCAT_WP_Front 숏코드 3종 진입부 + DWCAT_WP_Host::allows('render')
 */
function dwcat_can_render() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	if ( ! dwcat_sdk_has( 'has_valid_token' ) ) {
		return false;
	}
	return (bool) DW_DWCAT_License_Manager::has_valid_token();
}

/**
 * 게이트 2 — 프런트 CSS/JS enqueue.
 * 경로: 캐시 JWT 의 **RSA 서명을 직접 로컬 검증** (has_valid_token 과 다른 경로).
 * 적용: DWCAT_WP_Front::enqueue + DWCAT_WP_Host::allows('assets')
 */
function dwcat_can_load_assets() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	if ( ! dwcat_sdk_has( 'verify_token_signature' ) ) {
		return false;
	}
	return (bool) DW_DWCAT_License_Manager::verify_token_signature();
}

/**
 * 게이트 3 — 커스텀 필드 값 저장.
 * 경로: 토큰 클레임의 domain 이 현재 사이트와 일치하는지 교차 확인.
 * 적용: DWCAT_WP_Admin::post (항목·카테고리 저장) + DWCAT_WP_Host::allows('save')
 */
function dwcat_can_save_meta() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	if ( ! dwcat_sdk_has( 'get_token_claims' ) ) {
		return false;
	}
	$claims = DW_DWCAT_License_Manager::get_token_claims();
	if ( empty( $claims['domain'] ) ) {
		return false;
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return $host && strtolower( $claims['domain'] ) === strtolower( $host );
}

/**
 * 게이트 4 — 필드 정의(스키마) 편집. 이 플러그인의 핵심 가치 로직.
 * 경로: 라이선스 option + 토큰 클레임 aud 이중 확인.
 * 적용: DWCAT_WP_Admin::post (save_fields) + DWCAT_WP_Host::allows('manage_fields')
 */
function dwcat_can_manage_fields() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	$d = get_option( 'dw_license_dw_wholesale', array() );
	if ( ! is_array( $d ) || empty( $d['key'] ) ) {
		return false;
	}
	if ( ! dwcat_sdk_has( 'get_token_claims' ) ) {
		return false;
	}
	$claims = DW_DWCAT_License_Manager::get_token_claims();
	return isset( $claims['aud'] ) && 'dw-wholesale' === $claims['aud'];
}

/**
 * 게이트 5 — 대량 가져오기(CSV).
 * 경로: 서명 검증 + 만료 시각 직접 확인.
 * 적용: DWCAT_WP_Admin::post (import) + DWCAT_WP_Host::allows('import')
 */
function dwcat_can_bulk_import() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	if ( ! dwcat_sdk_has( 'verify_token_signature' ) || ! dwcat_sdk_has( 'get_token_claims' ) ) {
		return false;
	}
	if ( ! DW_DWCAT_License_Manager::verify_token_signature() ) {
		return false;
	}
	$claims = DW_DWCAT_License_Manager::get_token_claims();
	return isset( $claims['exp'] ) && ( (int) $claims['exp'] + DAY_IN_SECONDS ) > time();
}

/**
 * 게이트 6 — PDF 내보내기.
 * 경로: 토큰 유효 + 서명 검증 (두 경로 모두 통과해야 함).
 * 적용: DWCAT_WP_Admin::post (export) + DWCAT_WP_Host::allows('export')
 */
function dwcat_can_export_pdf() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	if ( ! dwcat_sdk_has( 'has_valid_token' ) || ! dwcat_sdk_has( 'verify_token_signature' ) ) {
		return false;
	}
	return DW_DWCAT_License_Manager::has_valid_token()
		&& DW_DWCAT_License_Manager::verify_token_signature();
}

/**
 * 게이트 7 — 관리자 카탈로그 화면 렌더.
 * 경로: SDK 상태 문자열 (licensed | grace 만 통과).
 * 적용: DWCAT_WP_Admin::page + DWCAT_WP_Host::allows('admin')
 */
function dwcat_can_render_admin() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	if ( ! dwcat_sdk_has( 'get_status' ) ) {
		return false;
	}
	return in_array( DW_DWCAT_License_Manager::get_status(), array( 'licensed', 'grace' ), true );
}

/**
 * 게이트 8 — 숏코드 출력 (렌더와 별개 지점).
 * 경로: 토큰 유효 + 로컬 매니페스트 버전이 플러그인 버전과 정합한지.
 *       (integrity.json 을 지우거나 갈아끼우면 여기서 걸린다)
 * 적용: DWCAT_WP_Front 각 숏코드 진입부 + DWCAT_WP_Front::item_page
 */
function dwcat_can_use_shortcode() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	if ( ! dwcat_sdk_has( 'has_valid_token' ) ) {
		return false;
	}
	if ( ! DW_DWCAT_License_Manager::has_valid_token() ) {
		return false;
	}

	return dwcat_manifest_version_matches();
}

/**
 * integrity.json 의 version 이 플러그인 버전과 맞는지.
 *
 * 숏코드마다 파일을 다시 읽으면 페이지당 디스크 I/O 가 늘어나므로
 * 요청 단위로 1회만 읽습니다 (정적 캐시 — 요청이 끝나면 사라짐).
 */
function dwcat_manifest_version_matches() {
	static $matches = null;
	if ( null !== $matches ) {
		return $matches;
	}

	$manifest = dwcat_get_path() . 'integrity.json';
	if ( ! is_readable( $manifest ) ) {
		$matches = false;
		return $matches;
	}

	$m       = json_decode( (string) file_get_contents( $manifest ), true );
	$config  = dwcat_get_config();
	$matches = is_array( $m ) && isset( $m['version'] ) && $m['version'] === $config['plugin_version'];

	return $matches;
}

/**
 * 게이트 9 — REST / SPA 모듈 노출.
 * 경로: 토큰 캐시 옵션을 직접 읽어 만료 확인 (SDK 메서드를 거치지 않는 경로).
 * 적용: dw_spa_modules 필터 등록
 */
function dwcat_can_call_rest() {
	if ( dwcat_gate_bypass() ) {
		return true;
	}
	$cache = get_option( 'dwcat_token', array() );
	if ( ! is_array( $cache ) || empty( $cache['token'] ) ) {
		return false;
	}
	$exp = isset( $cache['expires_at'] ) ? (int) strtotime( $cache['expires_at'] ) : 0;
	// 오프라인 grace 24h 까지는 허용 (API-CONTRACT §9.5).
	return ( $exp + DAY_IN_SECONDS ) > time();
}

// ─────────────────────────────────────────────────────────────────
// 진단
// ─────────────────────────────────────────────────────────────────

/** 관리자/진단용 게이트 스냅샷. */
function dwcat_gate_snapshot() {
	return array(
		'bypass'            => dwcat_gate_bypass(),
		'legacy_grace_days' => dwcat_legacy_grace_days_left(),
		'sdk_loaded_from'   => class_exists( DWCAT_SDK_CLASS )
			? ( new ReflectionClass( DWCAT_SDK_CLASS ) )->getFileName()
			: null,
		'gates'             => array(
			'can_render'         => dwcat_can_render(),
			'can_load_assets'    => dwcat_can_load_assets(),
			'can_save_meta'      => dwcat_can_save_meta(),
			'can_manage_fields'  => dwcat_can_manage_fields(),
			'can_bulk_import'    => dwcat_can_bulk_import(),
			'can_export_pdf'     => dwcat_can_export_pdf(),
			'can_render_admin'   => dwcat_can_render_admin(),
			'can_use_shortcode'  => dwcat_can_use_shortcode(),
			'can_call_rest'      => dwcat_can_call_rest(),
		),
	);
}

/** 프런트에서 게이트가 닫혔을 때 남길 안내. 로그인 관리자에게만 보인다. */
function dwcat_gate_placeholder() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	return '<p class="dwcat-unlicensed" style="padding:12px;border:1px dashed #d63638;color:#d63638;">'
		. esc_html__( 'DW Wholesale: 라이선스가 활성화되지 않아 카탈로그를 표시할 수 없습니다.', 'dw-wholesale' )
		. ' <a href="' . esc_url( admin_url( 'admin.php?page=dw-wholesale-license' ) ) . '">'
		. esc_html__( '라이선스 설정', 'dw-wholesale' ) . '</a></p>';
}

/** 레거시 유예 안내 — 만료가 가까울수록 강하게. */
add_action( 'admin_notices', 'dwcat_legacy_grace_notice' );
function dwcat_legacy_grace_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// 유예 기간 중에 활성화를 마친 사이트 — 더 안내할 것이 없다.
	if ( dwcat_is_licensed() ) {
		return;
	}
	$days = dwcat_legacy_grace_days_left();
	if ( $days <= 0 ) {
		return;
	}

	$class = $days <= 7 ? 'notice-error' : 'notice-warning';
	printf(
		'<div class="notice %s"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
		esc_attr( $class ),
		esc_html__( 'DW Wholesale:', 'dw-wholesale' ),
		esc_html( sprintf(
			/* translators: %d: days remaining */
			__( '라이선스 인증이 필요합니다. %d일 후 카탈로그 출력이 중단됩니다.', 'dw-wholesale' ),
			$days
		) ),
		esc_url( admin_url( 'admin.php?page=dw-wholesale-license' ) ),
		esc_html__( '지금 활성화', 'dw-wholesale' )
	);
}
