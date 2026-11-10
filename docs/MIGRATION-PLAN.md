# dw-wholesale 마이그레이션 — 보호 경계 합의서

> 본 합의서는 [PLUGIN-AGENT-KICKOFF.md §0](https://github.com/dasomweb/DASOM-Forge/blob/main/docs/platform/PLUGIN-AGENT-KICKOFF.md) 에 따른 의무 산출물입니다.
> 합의서에 없는 변경은 별도 운영자 합의가 필요합니다.

| 항목 | 값 |
|---|---|
| 작성일 | 2026-08-19 |
| 운영자 합의 | dasomweb@gmail.com |
| 에이전트 | Claude Code (Opus 5) |
| 대상 버전 | v1.2.1 → **v2.0.0** |
| 참조 패키지 | dasomforge-plugin-kickoff-2026-08-07-2a7f5e1 (HEAD `2a7f5e1`) |
| 적용 범위 | **A — 게이트 + 무결성 + ZIP 구조** (런타임 JS 미적용) |
| 제품 정본 | **`dw-wholesale`** (2026-08-20 운영자 결정 — §0.1) |

---

## 0.1 ⚠ 제품 정본 결정 — 두 갈래 포크 정리 (2026-08-20)

**다음 담당자가 가장 먼저 읽어야 할 절입니다.** 이 플러그인에는 **같은 조상에서
갈라진 두 개의 repo** 가 있고, 양쪽 모두 dasomforge 마이그레이션을 시도했습니다.

### 발견 경위

v2.0.0-rc.1 을 게시한 뒤 운영자가 dasomforge 어드민의 SyncRelease 결과를
확인했는데, 그 화면의 제품이 **우리 repo 가 아니었습니다**. 읽기 전용 조회
(`GET /integrity/*` — PLUGIN-AGENT-API-CONDUCT 상 안전) 로 확인한 사실:

| | `dasomweb/DW-Product-Catalog` | `dasomweb/dw-wholesale` |
|---|---|---|
| repo 생성 | 2026-01-23 | 2026-04-14 |
| main 최신 커밋 | **2026-04-14 에서 멈춤** (v1.8.0) | 활발 (v1.2.1 → v2.0.0-rc.1) |
| dasomforge 브랜치 | `v2-anti-piracy` | main 에서 직접 |
| 마이그레이션 릴리스 | v2.0.0-alpha.5 (2026-07-26) | v2.0.0-rc.1 (2026-08-20) |
| 플랫폼 슬러그 | `dw-product-catalog` — 매니페스트 **200** | `dw-wholesale` — **404** |
| runtime JS | 적용 (`main.min.js`) | 미적용 (§4.1) |
| 매니페스트 | 23개 파일 (vendor 없음) | 537개 (dompdf 번들) |

분기점은 커밋 `47b734c "Rename project from DW-Product-Catalog to dw-wholesale"`
(2026-04-14) 입니다. 이름만 바꾼 게 아니라 **이후 코드베이스가 실질적으로
갈라졌습니다**:

- **우리 쪽에만 있는 것**: 프런트 숏코드 3종(`class-dwcat-shortcodes.php`),
  디자인 설정(`class-dwcat-design-settings.php`), `assets/css/frontend.css`,
  `assets/js/carousel.js` — 즉 **v1.1.0·v1.2.0 기능 전체**
- **저쪽에만 있는 것**: `class-dwpc-field-store.php` · `class-dwpc-field-presets.php` ·
  `class-dwpc-license-bridge.php` · `class-dwpc-spa-module.php` — 필드 시스템을
  다르게 리팩터링 (`class-pc-config.php` · `class-pc-settings.php` 없음)

### 결정 — **`dw-wholesale` 가 정본**

근거:
1. 저쪽 main 은 2026-04-14 이후 정지, 우리 쪽은 계속 개발 중
2. 고객이 실제로 쓰는 기능(숏코드·디자인 설정)이 **우리 쪽에만** 있음
3. 저쪽 마이그레이션은 **숏코드 도입 이전 기능 세트** 위에 얹혀 있음

`dw-product-catalog` 는 **동결**합니다. 저쪽의 runtime JS 작업은 §4.1 재검토 시
참고 자산으로 활용할 수 있습니다 (버리지 말고 브랜치 보존).

### ⚠ 두 플러그인을 한 사이트에 동시 활성화하지 마십시오

| 항목 | 충돌? | 이유 |
|---|---|---|
| SDK 클래스 | **안전** | 우리는 `DW_DWCAT_*` (Prefix), 저쪽은 canonical `DW_License_Manager` → §3.5 winner race 없음 |
| 라이선스 옵션 | 안전 | `dw_license_dw_wholesale` vs `dw_license_dw_product_catalog` |
| 토큰 캐시 · cron | 안전 | 접두사 `dwcat_` vs `dwpc_`, cron 훅에 slug 포함 |
| **CPT `product`** | **충돌** | 공통 조상이라 **둘 다 `register_post_type('product')`** — 나중에 등록한 쪽이 이기고, 같은 포스트를 서로 다른 필드 스키마로 해석하게 됩니다 |

즉 §9 에서 Prefix 전략을 택한 판단이 여기서 실제로 값을 했습니다(클래스 충돌 회피).
다만 CPT 충돌은 전략과 무관하므로 **구 제품 동결이 정리 차원이 아니라 필수**입니다.

---

## 1. 핵심 기능 (한 줄 정의)

> **"포스트 타입마다 관리자가 직접 정의한 동적 커스텀 필드 스키마로 카탈로그를 구성하고, 그 데이터를 프런트 숏코드 3종으로 노출한다."**

---

## 2. 가치 창출 로직 (보호 대상)

경쟁자가 복제하면 가치 있는 부분:

- [x] **동적 필드 스키마 엔진** — 위치: [`includes/class-pc-config.php`](../includes/class-pc-config.php), [`includes/class-pc-settings.php`](../includes/class-pc-settings.php)
      포스트 타입별 필드 정의·타입·select 옵션·list 노출·title 필드 지정을 UI 로 관리. 이 플러그인의 진짜 자산.
- [x] **필드 값 저장/해석 파이프라인** — 위치: [`includes/class-pc-meta-box.php`](../includes/class-pc-meta-box.php)
- [x] **CSV 대량 가져오기 매핑 로직** — 위치: [`includes/class-pc-bulk-import.php`](../includes/class-pc-bulk-import.php)
      헤더 정규화 · 중복 스킵 · 이미지 사이드로드 · 분류 자동 생성
- [x] **PDF 카탈로그 조판** — 위치: [`includes/class-pc-pdf-export.php`](../includes/class-pc-pdf-export.php)
- [x] **숏코드 렌더 + 디자인 변수 주입** — 위치: [`includes/class-dwcat-shortcodes.php`](../includes/class-dwcat-shortcodes.php), [`includes/class-dwcat-design-settings.php`](../includes/class-dwcat-design-settings.php)

## 3. 사포화되는 부분 (PHP 평문 OK)

- [x] 어드민 UI 껍데기 · 설정 폼 마크업
- [x] 표준 WP hook 연결 (CPT/taxonomy 등록, 메뉴, 컬럼)
- [x] URL/경로 헬퍼 ([`class-pc-url-helper.php`](../includes/class-pc-url-helper.php))
- [x] DW Admin SPA 모듈 등록 (dw-admin 이 소유한 프로토콜)

---

## 4. 적용할 anti-piracy 메커니즘 합의

### 4.1 메커니즘 1 — 런타임 JS 서버 배포

**❌ 미적용** (범위 A 결정).

근거와 트레이드오프:

- 이 플러그인의 가치 로직 대부분은 **관리자 측 스키마 엔진**이지 프런트 렌더가 아닙니다.
  숏코드 3종은 표준 WP_Query + 필드 출력으로, "경쟁자가 베껴도 별 가치 없는" 축에 가깝습니다.
  KICKOFF §0 이 경고하는 *"사포화되는 코드를 과보호"* 케이스에 해당합니다.
- 대신 **게이트 9종**(§5)이 렌더·에셋·저장·스키마 편집·가져오기·PDF·REST 전 경로를 차단합니다.
- 런타임 JS 를 적용하면 운영자가 CDN 번들을 배포하기 전까지 프런트가 완전히 죽습니다 —
  카나리 검증을 먼저 통과시키는 편이 안전하다는 판단.

**재검토 시점**: v2.1 이후 숏코드 렌더가 복잡한 레이아웃 엔진으로 성장하면 그때 §4.1 을 갱신하고
`build/build-runtime.js` + `runtime/` 을 도입합니다. `manifest.json` 은 `runtime` 섹션이 생략된 상태이므로
추가만 하면 됩니다 (`build/generate-release-manifest.js`).

### 4.2 메커니즘 2 — 설정 서명 (config signing)

**❌ 미적용** (범위 A 결정).

- 이 플러그인의 공개 노출 데이터는 `wp_postmeta` 의 일반 포스트 필드 값입니다.
  런타임 JS 가 없으므로 서명을 검증할 주체(브라우저 측 runtime)가 없어 서명만 붙이면 장식이 됩니다.
- SDK 는 `sign_config()` / `verify_signature()` 를 **구현해 둔 상태**이므로 §4.1 재검토 시 즉시 사용 가능.
- 30일 grace 정책은 적용 시점에 재논의.

### 4.3 메커니즘 3 — 무결성 검사

**✅ 적용** (전면).

매니페스트 포함 파일: **릴리스 ZIP `wp-plugin/` 하위 전체** (v2.0.0-rc.1 실제 릴리스 기준 **537개** — PHP·CSS·JS·`vendor/` 포함).

- `integrity.json` 은 **CI 가 스테이징된 실물 위에서** 생성합니다 (`build/generate-integrity.js --stage`).
  소스트리를 걷고 제외 패턴을 손으로 맞추면 ZIP 실물과 반드시 어긋나고, 그 즉시 `MANIFEST_INCOMPLETE` 입니다.
- `build/verify-package.js` 가 릴리스 ZIP 에 대해 **서버가 할 판정을 그대로 재현**합니다
  (서버 해시 대상 집합 == 플러그인 전송 집합). CI 필수 단계.
- 키 규약: **§7.2.2 규약 1 (루트상대)**. `integrity.json` 의 `files` 를 SDK 가 그대로 전송합니다.
  ※ 다음 담당자 주의 — 규약 2(슬러그 접두사)로 바꾸지 마십시오.

---

## 5. 라이선스 게이트 분산 위치 (9개)

정의: [`includes/license/gates.php`](../includes/license/gates.php)

| 게이트 | 호출 위치 | 검증 경로 | 차단 효과 |
|---|---|---|---|
| `dwcat_can_render()` | `DWCAT_WP_Front` 숏코드 진입부 · `item_page` · `DWCAT_WP_Host::allows('render')` | `has_valid_token()` | 프런트 카탈로그 전체 |
| `dwcat_can_load_assets()` | `DWCAT_WP_Front::enqueue` · `allows('assets')` | `verify_token_signature()` (로컬 RSA) | CSS/JS 미로딩 |
| `dwcat_can_save_meta()` | `DWCAT_WP_Admin::post` (항목·카테고리·디자인) · `allows('save')` | 토큰 클레임 `domain` 교차 확인 | 카탈로그 저장 |
| `dwcat_can_manage_fields()` | `DWCAT_WP_Admin::post` (save_fields) · `allows('manage_fields')` | 라이선스 option + 클레임 `aud` | **스키마 편집 = 핵심 자산** |
| `dwcat_can_bulk_import()` | `DWCAT_WP_Admin::post` (import) · `allows('import')` | 서명 검증 + `exp` 직접 확인 | CSV 가져오기 |
| `dwcat_can_export_pdf()` | `DWCAT_WP_Admin::post` (export) · `allows('export')` | `has_valid_token()` **and** `verify_token_signature()` | PDF 생성 |
| `dwcat_can_render_admin()` | `DWCAT_WP_Admin::page` · `allows('admin')` | `get_status()` ∈ {licensed, grace} | 관리 화면 |
| `dwcat_can_use_shortcode()` | 숏코드 3종 진입부 · `allows('render')` | 토큰 + `integrity.json` 버전 정합 | 매니페스트 제거·교체 탐지 |
| `dwcat_can_call_rest()` | `DWCAT_WP_Rest` (라우트 권한 + SPA 모듈 등록) | 토큰 option 직접 판독 (SDK 우회 경로) | SPA 모듈 · 항목 API |

v2.0.0-rc.5 부터 카탈로그는 독립 코어(`core/`)가 처리하고 WordPress 쪽은 어댑터(`includes/wp/`)입니다.
게이트는 **두 겹**으로 걸립니다 — WordPress 진입점(어댑터 각 파일)에서 한 번, 코어가 호출하는
`DWCAT_WP_Host::allows()` 에서 기능별로 한 번. 한 곳만 무력화해서는 열리지 않습니다.

**설계 의도**: 네 가지 서로 다른 검증 경로를 섞었습니다. 전부 `has_valid_token()` 한 곳을 거치면
`return true;` 한 줄로 9개가 동시에 뚫립니다.

**dev bypass**: `DWCAT_DEV_BYPASS` — `wp-config.php` 에서만 정의. 릴리스 ZIP 에 `wp-config.php` 가
섞이면 `.github/scripts/package-release.sh` 가 빌드를 실패시킵니다.

---

## 6. tier · feature 정의 (운영자 결정)

- **basic**: v2.0.0 은 단일 tier (KICKOFF FAQ Q16 권장 따름). 기능 분기 없음.
- plus / pro: v2.1+ 별도 sprint. `License.features` 배열은 SDK 가 이미 저장·노출하므로
  분기 지점만 추가하면 됩니다.
- **max_domains**: 운영자 결정 필요 (기본 제안 basic=1).

---

## 7. 백워드 호환 (v1.x 사용자)

**정책: 자동 업데이트로 v2.0.0 을 받아도 기존 사이트 카탈로그가 즉시 사라지지 않습니다** (FAQ Q8).

구현:

- `dwcat_maybe_grant_legacy_grace()` — `dwcat_version` 옵션이 존재하고 `< 2.0.0` 이면
  **1회만** `dwcat_legacy_grace_until = now + 30일` 부여. 신규 설치는 유예 없음.
- 유예 중에는 9개 게이트가 모두 통과하고, 관리자에게 잔여 일수 notice 를 띄웁니다
  (7일 이하면 `notice-error` 로 격상).
- 유예 기간: `DWCAT_LEGACY_GRACE_DAYS` 상수 (기본 30). 운영자가 늘리려면 `wp-config.php` 에서 재정의 가능.

| 항목 | 값 |
|---|---|
| v1.x 활성 라이선스 수 | **운영자 확인 필요** |
| 깨질 수 있는 데이터 형태 | 없음 — 옵션/포스트메타 스키마 무변경 |
| 마이그레이션 전략 | 30일 유예 + 점증 notice, 데이터 변환 없음 |
| Legacy API grace | `/license/verify` 계속 사용 (일일 cron) |

**v1 옵션 키 보존**: 라이선스 상태는 v1 과 동일한 `dw_license_dw_wholesale` 에 저장합니다 —
기존 활성화 사용자는 키 재입력 없이 승계됩니다.

### 7.1 유예(grace) 창 세 가지 — 사유별로 다릅니다

KICKOFF FAQ Q9·Q18 이 요구하는 "서로 다른 grace" 를 구분해 구현했습니다.
전부 24시간으로 뭉뚱그리면 결제가 이틀 늦은 정상 고객이 끊깁니다.

| 사유 | 창 | 상수 | 근거 |
|---|---|---|---|
| **네트워크 장애** (서버 미도달·5xx) | 24시간 | `OFFLINE_GRACE` | FAQ Q11 |
| **라이선스 만료** (`LICENSE_EXPIRED`) | **30일** | `EXPIRY_GRACE` | ERROR-CODES §1 "30일 grace 권장" · FAQ Q9 |
| **라이선스 무효화** (`LICENSE_INVALIDATED`) | **없음** — 캐시 토큰 즉시 폐기 | — | API-CONTRACT §9.5 |
| **v1.x 마이그레이션** | 30일 (1회) | `DWCAT_LEGACY_GRACE_DAYS` | FAQ Q8 |

만료 유예 중에는 남은 일수를 어드민 notice 로 표시하고, 7일 이하면 `notice-error` 로
격상합니다. 24시간 이상 토큰 재발급이 연속 실패하면 "서버 연결 끊김" 을 별도 안내합니다
(FAQ Q11 #2) — 그 전에는 사용자에게 에러를 보이지 않고 조용히 캐시로 버팁니다.

---

## 8. 카나리 라이선스

- 운영자 발급 받음? **[ ] 아직**
- 도메인: (운영자 결정)
- tier: `basic`
- 만료: 발급일 + 1년
- max_domains: 5
- 발급 시점: **첫 SyncRelease 직후, 통합 검증 이전**

---

## 9. SDK 배포 전략 — **Prefix** (PLUGIN-DEV-GUIDE §3.5 B)

| 항목 | 값 |
|---|---|
| 클래스명 | `DW_DWCAT_License_Manager` · `DW_DWCAT_Forge_Client` |
| 파일명 | 정본과 동일 (`class-dw-license-manager.php` · `class-dw-forge-client.php`) — rebase 편의 |
| 선택 이유 | dw-wholesale 는 DW Admin SPA 의 **소비자**이지 shared admin surface 제공자가 아니며, 릴리스 주기가 dw-admin 과 독립적. canonical 이면 dw-admin 이 구 SDK winner 일 때 게이트 9개가 전부 조용히 false 로 떨어짐 |
| 대가 | 라이선스 입력 UI · daily verify cron · 토큰 캐시가 이 플러그인 전용 (사용자가 플러그인별로 키 입력) |

**SDK 출처 (중요 — 다음 담당자 필독)**:

정본 SDK v2.1.0 두 파일은 **DW-MCP ref `7f7eafff` 에 인덱싱되어 있지 않습니다** (해당 ref 는
`docs/platform/*.md` 11개만 포함). 따라서 이 플러그인의 SDK 는 운영자 승인 하에
**API-CONTRACT v1.2 · API-USAGE v1.1 · SDK-README 공개 API 표를 기준으로 새로 작성**한 것입니다.

→ 정본과 내부 구현이 다를 수 있습니다. 정본을 입수하면 diff 후 rebase 하십시오.
   공개 API 시그니처는 SDK-README 표와 1:1 로 맞춰 두었습니다.

---

## 9.1 멀티사이트 (FAQ Q10)

| 데이터 | 범위 | 이유 |
|---|---|---|
| 라이선스 키·상태 | **네트워크** (`get_network_option`) | 메인 어드민에서 한 번 입력 → 전 사이트 적용 |
| 토큰 캐시 | **사이트별** | 서브사이트마다 도메인이 달라 토큰의 `domain` 클레임도 달라야 함 |
| tamper·오류·유예 노티스 | 사이트별 | 사이트마다 상태가 다를 수 있음 |

- 네트워크 어드민에 별도 라이선스 메뉴를 등록하고, 권한은 `manage_network_options` 로 검사합니다.
- 각 서브사이트 도메인이 `License.max_domains` 슬롯을 하나씩 차지합니다 →
  **서브사이트 수에 맞는 `max_domains` 가 필요합니다** (§11 운영자 확인 #4 와 연결).
- `uninstall.php` 가 멀티사이트에서 네트워크 옵션도 정리합니다.

## 9.2 진단 엔드포인트 (§3.6 SHOULD #2)

```
GET /wp-json/dw-wholesale/v1/license/status
```

권한: `manage_options` 또는 `manage_network_options` (Application Password 로도 접근 가능).
`permission_callback` 에 `__return_true` 를 쓰지 않습니다.

표준 필드(`sdk_class_loaded_from` · `sdk_class_loaded_from_plugin` ·
`sdk_methods_available` · `last_sdk_error`) 에 더해 게이트 스냅샷·매니페스트 정합·
유예 잔여일을 반환합니다.

**노출 금지 (modalpopup P15/P16 교훈)** — 진단 패널이 attack surface 가 된 실사례가 있어
다음은 절대 싣지 않습니다: 라이선스 키 · JWT · raw tier slug.
존재 여부는 `license_present` / `token_present` boolean 으로만 노출하며,
`tests/test-bootstrap.php` 가 이 불변식을 실행으로 검증합니다.

## 9.3 프런트엔드 성능·가용성 규칙

게이트는 매 페이지뷰마다 호출되므로 **프런트엔드에서는 동기 HTTP 를 절대 하지 않습니다**:

- 캐시/grace 토큰만 사용하고, 갱신이 필요하면 `wp_schedule_single_event` 로 cron 에 위임
- 동기 발급이 허용되는 컨텍스트: wp-admin · cron · WP-CLI · AJAX
- 근거: §10.2 "매 페이지뷰마다 토큰 갱신 ❌", FAQ Q11 "어떤 경우에도 페이지 렌더가
  깨지면 절대 안 됨"

이게 없으면 dasomforge 가 느려질 때 고객 사이트 전체가 20초(타임아웃) 씩 느려집니다.

---

## 10. 빌드 파이프라인

```
tag v2.0.0 push
  → 버전 정합 확인 (헤더 == config == 태그)      ← 어긋나면 INTEGRITY_MANIFEST_NOT_FOUND
  → composer install (lock 필수)                  ← lock 없으면 빌드 실패
  → package-release.sh
       rsync → release/wp-plugin/
       README.txt 생성
       위생 점검 (wp-config.php · Zone.Identifier · 공개키 PEM)
       generate-integrity.js --stage             ← ZIP 실물 기준
       generate-release-manifest.js              ← 버전·파일존재 자기 점검
       chmod 644/755 + touch SOURCE_DATE_EPOCH
       zip -X (정렬된 엔트리)
  → 같은 소스로 두 번 빌드 → SHA256 일치 확인      ← §11.3.4
  → verify-package.js                            ← 서버 판정 재현
  → GitHub Release
```

`SOURCE_DATE_EPOCH` = 태그 커밋 시각. `.gitattributes` 가 라인엔딩을 LF 로 고정합니다.

---

## 11. 미해결 항목 — 운영자 확인 필요

| # | 항목 | 상세 | 왜 에이전트가 못 정하는가 |
|---|---|---|---|
| 1 | **`/auth/token` 요청 본문 크기** | **게시된 v2.0.0-rc.1 실물 기준 66.5 KB** (537개 파일 해시). §12.1 lock 수정으로 82.7 → 66.5 KB 로 줄었으나 여전히 큼. 서버 body-parser 한도가 이보다 작으면 매 요청 실패 | 서버 코드 미열람. API-CONTRACT 는 50KB 한도를 `/configs/sign` **payload** 에만 명시하고 `/auth/token` 전체 본문 한도는 규정하지 않음 |
| 2 | **`dw-wholesale` Product 신규 등록** | 확인 완료 — `GET /integrity/dw-wholesale/v2.0.0-rc.1` · `v1.2.1` 모두 **404**. 등록된 것은 `dw-product-catalog` 뿐. **Product row 를 새로 만들고 `githubRepo = dasomweb/dw-wholesale` 로 지정**해야 합니다 | 운영자 어드민 전용 (FAQ Q13). ※ Product row 자체의 존재 여부는 어드민에서만 확인 가능 — 매니페스트 부재만 확인된 사실 |
| 2b | **`dw-product-catalog` 동결** | §0.1 결정. Product row 비활성화 또는 신규 Sync 중단. `v2-anti-piracy` 브랜치는 runtime JS 참고 자산이므로 **삭제하지 말 것** | 운영자 결정 |
| 2c | **구 제품 라이선스 이관** | `dw-product-catalog` 슬러그로 이미 발급된 라이선스가 있으면 `dw-wholesale` 로 재발급 필요 (토큰 `aud` 가 슬러그에 묶임) | 운영자만 발급 현황을 앎 |
| 3 | 카나리 라이선스 발급 | §8 | 운영자 전용 |
| 4 | `max_domains` (basic) | §6 | 운영자 결정 |
| 5 | v1.x 활성 라이선스 수 | §7 유예 정책 검증용 | 운영자 데이터 |
| 6 | 정본 SDK v2.1.0 diff | §9 | 정본 파일 미입수 |
| 7 | **`LICENSE_EXPIRED` 유예가 30일인가 90일인가** | API-CONTRACT §9.5 는 **90일**, ERROR-CODES §1 과 FAQ Q9 는 **30일** 로 서로 다릅니다. 보수적으로 **30일** 을 구현했습니다 (`EXPIRY_GRACE`) | binding spec 과 하위 문서가 충돌 — 어느 쪽이 정본인지 운영자 판정 필요. 90일이 맞다면 상수 한 줄만 바꾸면 됩니다 |
| 8 | 멀티사이트 `max_domains` | 서브사이트마다 도메인 슬롯을 하나씩 씁니다 (§9.1). 카나리가 멀티사이트면 서브사이트 수 이상이 필요 | 운영자 발급 정책 |

**#1 이 가장 급합니다.** 한도에 걸리면 선택지:
- (a) 운영자가 `/auth/token` body limit 상향 — 가장 저렴
- (b) vendor 를 ZIP 에서 제외 → PDF 내보내기 포기 (기능 손실)
- (c) 서버가 파일 수 기반 청크 프로토콜 도입 → API-CONTRACT 변경 (v2 엔드포인트)

읽기 전용 확인은 `GET /integrity/dw-wholesale/v2.0.0` 로 가능하지만
(`User-Agent: DW-dev-diag/dw-wholesale-agent`), **본문 한도는 그것으로 알 수 없습니다** —
`/auth/token` 은 부작용 있는 endpoint 라 [PLUGIN-AGENT-API-CONDUCT](https://github.com/dasomweb/DASOM-Forge/blob/main/docs/platform/PLUGIN-AGENT-API-CONDUCT.md) §MUST 2 에 따라
운영자 확인 없이 호출하지 않았습니다.

---

## 12. 범위 밖에서 발생한 필수 변경 — dompdf 2.x → 3.x

결정론적 빌드(§11.2.b)는 `composer.lock` 을 요구하는데, `dompdf/dompdf ^2.0` 은
**2.x 전 버전이 보안 권고로 차단**되어 lock 생성 자체가 불가능했습니다
(CVE-2023-50262 포함 10건, `composer audit`).

- `^2.0` → `^3.1` (설치본 v3.1.6), `composer audit` 클린
- 사용 중인 API 5개 (`new Dompdf(array)` · `setPaper` · `loadHtml` · `render` · `stream`) 는 3.x 에서 동일
- `php ^7.1 || ^8.0` 이므로 플러그인의 PHP 7.4 하한 유지
- 신규 전이 의존성: `masterminds/html5`, `sabberworm/php-css-parser`, `thecodingmachine/safe`

**즉, v1.2.1 릴리스 ZIP 에는 취약한 dompdf 2.x 가 실려 있었습니다.** v2.0.0 에서 해소.

### 12.1 lock 은 **선언한 최소 PHP** 기준으로 만들어야 합니다

첫 CI 실행이 잡아낸 사고입니다. PHP 8.3 개발 머신에서 만든 `composer.lock` 이
`thecodingmachine/safe v3.4.0` (php `^8.1` 요구) 을 끌어와, 플러그인 헤더의
`Requires PHP: 7.4` 와 모순됐습니다. 그대로 출하했다면 **PHP 7.4/8.0 호스트에서
플러그인이 fatal** 로 죽었을 것입니다 — 게이트나 라이선스와 무관하게.

```json
"config": { "platform": { "php": "7.4.33" } }
```

이 한 줄이 "빌드 머신의 PHP" 가 아니라 "우리가 지원한다고 선언한 PHP" 로
의존성을 풀게 합니다. lock 재생성 후 `thecodingmachine/safe` 가 v1.3.3 으로
내려가 전 패키지가 7.4 호환이 되었고, 부수적으로 매니페스트 파일이 668 → **537개**,
`/auth/token` 요청 본문이 82.7 KB → **66.5 KB** 로 줄었습니다 (§11 #1 완화).

> 로컬 빌드에서는 595개가 나왔는데 CI 는 537개였습니다. 원인은 로컬에서
> `thecodingmachine/safe` 의 dist 다운로드가 504 로 실패해 **source(git clone) 설치**
> 로 폴백했기 때문입니다 (tests·generator 등이 딸려 옴). **CI 산출물이 정본**이며,
> 게시된 ZIP 을 실제로 풀어 SDK 해시 537 == 서버 해시 537 을 확인했습니다.

**재발 방지** — `.github/workflows/test.yml` 의 `composer-integrity` 잡이
**PHP 7.4 러너**에서 돕니다. 최신 PHP 로만 검증하면 이 부류를 영원히 못 잡습니다:

| 검사 | 잡는 것 |
|---|---|
| `composer validate --check-lock` | composer.json ↔ lock 불일치 |
| `composer audit --locked` | vendor 설치 없이 취약점 (이전엔 이 플래그가 없어 CI 가 죽었음) |
| `composer check-platform-reqs` | 최소 PHP 에서 실제로 설치되는지 |

> 교훈: **PHP 매트릭스 테스트만으로는 부족합니다.** 소스는 7.4 문법이어도
> vendor 가 8.1 전용이면 통과해 버립니다 — 의존성 해석 자체를 최소 PHP 로 고정해야
> 합니다.

---

## 13. Sprint 일정

| Sprint | 범위 | 상태 |
|---|---|---|
| MP1 | SDK 교체 + 게이트 분산 + 무결성 + ZIP 구조 | ✅ 완료 |
| MP1.5 | 에러 경로 전면화 · 멀티사이트 · 진단 엔드포인트 · 테스트 스위트 3종 | ✅ 완료 |
| MP2 | 카나리 검증 (활성화 → 토큰 → 게이트 개방 → tamper 감지) | 대기 — 운영자 §11 |
| MP3 | v2.0.0 stable 출시 + 7일 모니터링 | 대기 |
| MP4 | (선택) 런타임 JS + config 서명 — §4.1 재검토 | 미정 |

### 테스트 커버리지 (§9.1 대비)

| 요구 | 파일 | 상태 |
|---|---|---|
| 토큰 발급/캐시 · 401 1회 재시도 · grace · 서명 검증 | `tests/test-license-manager.php` | ✅ 68 assertions |
| 모든 게이트 · dev bypass · 토큰 없을 때 false | `tests/test-bootstrap.php` | ✅ 41 assertions |
| 구조·보안 패턴·패키징 계약 정적 검사 | `tests/test-plugin-integrity.php` | ✅ 219 assertions |
| anti-piracy 회귀 (§9.3) | `test-license-manager.php` §16 | ✅ option 위조 · 토큰 위조 · 매니페스트 삭제 |
| 설정 서명 (§9.1 test-config-signing) | — | N/A — §4.2 미적용 |
| 런타임 로더 (§9.1 test-runtime-loader) | — | N/A — §4.1 미적용 |
| dasomforge staging E2E | — | ⏳ 카나리 라이선스 필요 (§11) |

CI: `.github/workflows/test.yml` 이 PHP 7.4~8.3 다섯 버전에서 전부 실행하며,
릴리스 워크플로도 패키징 **전에** 같은 스위트를 게이트로 돌립니다.

---

## 14. 운영자 승인

- [ ] 운영자(dasomweb) 검토 완료
- [ ] §11 미해결 항목 6건 회신
- [ ] 카나리 라이선스 발급
- [ ] v2.0.0 태그 → SyncRelease 승인

승인 일자: ____
서명: ____ (이메일)

---

> 합의서에 *없는* 변경은 별도 운영자 합의 필요. 합의 변경 시 본 문서를 갱신하고 commit 으로 추적.
