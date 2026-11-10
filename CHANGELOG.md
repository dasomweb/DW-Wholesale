# Changelog

DW Wholesale (구 DW Catalog WP) 의 변경 이력. [SemVer](https://semver.org/lang/ko/) 를 따릅니다
(PLUGIN-DEV-GUIDE §7.1).

릴리스마다 **anti-piracy 관련 변경**을 별도 표기합니다 (§7.6 의무).

---

## [3.0.0] — 개발 시작 (미출시)

### Changed — 제품명 변경: DW Catalog WP → DW Wholesale
- DW Product Catalog 와 DW Wholesale Customer Management 를 하나로 통합하는 제품의 기반으로 전환합니다.
- 플러그인 이름 `DW Wholesale`, 메인 파일 `dw-wholesale.php`, 슬러그 · 텍스트 도메인 `dw-wholesale`.
- 저장소 주소 `github.com/dasomweb/DW-Wholesale` (Plugin URI · Update URI · 설정값).
- 라이선스 SDK 의 product slug · 옵션 키 · 설정 페이지를 새 슬러그로 변경 (`dw_license_dw_wholesale`, `dw-wholesale-license`).
- 내부 코드 접두사(`dwcat_`, `DWCAT_`)와 테이블 이름(`{prefix}dwcat_*`)은 그대로 유지합니다.

### Anti-piracy 관련 변경
- DASOM-Forge 에 등록된 product slug 가 `dw-catalog-wp` → `dw-wholesale` 로 바뀝니다.
  dasomforge 에 `dw-wholesale` 제품을 등록하기 전까지 라이선스 활성화가 되지 않습니다.

---

## [2.0.0-rc.9] — 2026-10-13

### Changed — 카테고리 화면

- **드래그 & 드롭으로 순서와 상위/하위 관계를 한 번에 수정** — ⠿ 손잡이를 끌어 다른 카테고리의
  위·아래에 놓으면 그 옆으로, 가운데에 놓으면 그 안(하위)으로 들어갑니다. 마우스·터치 모두 지원.
- 키보드/버튼으로도 같은 작업: ↑ ↓ (순서), ← (한 단계 밖으로), → (위 카테고리 안으로).
- 변경 후 "Save order" 한 번으로 전체 트리를 저장(한 트랜잭션). 저장 전에는 "Unsaved changes" 표시와
  Undo, 페이지를 떠나려 하면 경고.
- 서버 검증: 다른 카탈로그의 ID 무시, 자기 자신/하위 밑으로 들어가는 순환 차단, 순서 재번호.
- 하위 카테고리 접기/펼치기, 이름·슬러그는 각 줄의 Edit 에서 바로 수정.

### Anti-piracy

- 변경 없음 (새 동작 `save_tree` 도 `dwcat_can_save_meta` 게이트 + 코어 권한/CSRF 검사).

---

## [2.0.0-rc.8] — 2026-10-13

### Changed — 위젯 디자인 (카탈로그 페이지)

- 디자인 시안 적용: 상단 제목 · 품목 수 · 큰 검색창 · 최상위 카테고리 탭(색 점), 왼쪽 사이드바
  (카테고리 그룹별 개수, 필드 필터 — 체크 즉시 적용), 툴바("Showing a–b of N", 정렬, 그리드/리스트 보기),
  카드(4:3 이미지 · 카테고리 배지 · "카테고리 · 브랜드" · 코드/팩 사이즈 고정폭 글꼴), 리스트 표 보기,
  페이지당 개수 선택.
- **상품 상세를 사이드 드로어로** — 목록 위에서 열리고 주소는 상품 페이지 그대로(새로고침·공유 시 전체 페이지).
  Esc / 뒤로가기로 닫힘. "코드 복사" 버튼.
- 휴대폰: 필터는 "Filters" 패널, 카드는 한 줄 요약형.
- 기본 디자인 값 변경(IBM Plex Sans, 액센트 #1466d6, 4열). 숏코드 옵션 추가: `title`, `tabs`, `default_view`.

### Changed — 관리자 디자인

- **All Items**: 통계 카드(전체 · 활성 · 비활성 · 카테고리 · **중복 품목 코드 "Needs review"**), 필터 바
  (검색 · 선택형 필드 · 카테고리 · 브랜드 · 상태, 바로 적용), 선택 시 나타나는 **일괄 작업 바**
  (활성 / 비활성 / 카테고리 이동 / 선택 항목 CSV 내보내기 / 삭제), 행별 메뉴(…), "Shared" 배지,
  상태 표시, 하단 페이지 이동 + 행 수(10/20/50/100).
- **편집 화면**: 기본 정보 · 상세 · Variations · 이미지 섹션, 오른쪽 Publish 상자(상태 · 슬러그 ·
  저장 / 삭제), 카테고리 검색, **카드 미리보기(입력 즉시 반영)**. 품목 코드가 다른 상품과 겹치면 그 상품을 표시,
  브랜드는 기존 값 자동완성. 이미지는 끌어다 놓기 영역.
- WordPress 메뉴: All Items · Add New · Categories · Fields · Import / Export · Settings
  (가져오기/내보내기, 디자인/숏코드는 화면 안의 섹션 탭).
- 일괄 작업 권한 분리: 상태 변경·이동은 편집 권한, 삭제는 삭제 권한, 내보내기는 내보내기 권한 + 라이선스.

### Anti-piracy

- 변경 없음 (새 일괄 작업도 기존 게이트 `dwcat_can_save_meta` + 코어 권한/CSRF 검사를 그대로 거침).

---

## [2.0.0-rc.7] — 2026-10-12

### Changed — 이미지 (웹 기준)

- **업로드 전에 브라우저에서 줄입니다** — 사진을 고르면 1600px(WebP, 미지원 브라우저는 JPEG)로
  변환한 뒤 올립니다. 12MP 휴대폰 사진(4~5MB)이 수백 KB 로 올라갑니다. 브라우저가 못 하면 원본이
  올라가고 서버가 줄입니다.
- **저장 크기를 웹에 맞춤** — 사이트가 보여주는 최대는 1200px(상세, PC 2배·휴대폰 3배 화면)이므로
  1200 / 600 / 300 세 가지만 저장. 2400px 원본 보관 중단.
- **WebP 로 저장** (서버가 지원하면; 아니면 JPEG). 같은 화질에서 JPEG 보다 작습니다.
- 크기를 단계적으로 생성(큰 것 → 작은 것)해 처리 시간 · 메모리 절감: 12MP 기준 0.83s/124MB → 0.58s/62MB.
  메모리가 모자라면 이 요청에 한해 자동으로 늘립니다(공유 호스팅 128MB 대비).
- 표시: 휴대폰·PC 가 각자 필요한 크기(600/1200)만 받도록 srcset, 목록 첫 줄과 상세 대표 이미지는
  우선 로딩(`fetchpriority="high"`), 나머지는 지연 로딩. 이미지 파일에 1년 브라우저 캐시 헤더.
- PDF 는 WebP 이미지를 임시 JPEG 로 변환해 넣습니다.

### Added — CSV 가져오기 / 내보내기

- **CSV 내보내기** — 모든 커스텀 필드(내부 필드 포함), 카테고리("상위 > 하위"), 태그, 이미지 URL,
  **Variations(한 줄에 하나, `var_` 컬럼)**. Excel 용 UTF-8 BOM, 수식으로 실행될 값은 무력화(CSV injection 방지).
- **CSV 가져오기 개편** — 내보내기와 같은 형식이라 왕복 가능(내보내기 → 스프레드시트 편집 → 가져오기).
  같은 slug 의 여러 줄 = 한 상품의 Variations. 기존 상품은 **업데이트 / 건너뛰기** 선택 —
  업데이트는 파일에 있는 컬럼만 바꿉니다(slug + 가격만 있으면 가격만). 구분자 자동 감지,
  이미 저장된 이미지 URL 은 다시 받지 않음. 템플릿 CSV 다운로드.

---

## [2.0.0-rc.6] — 2026-10-12

### Fixed

- **이름순 정렬이 DB 마다 달랐던 문제** — PostgreSQL 의 로케일 정렬은 괄호 등 구두점을 무시하고
  MySQL 은 그렇지 않아 `(Somi) Miso…` 의 위치가 달랐습니다. 항목에 정렬 키(`sort_title`: 소문자 ·
  문자/숫자만)를 두고 이름 정렬은 이것으로 합니다 — 모든 DB 에서 같은 순서.
- 스키마 v2: 기존 설치는 관리자 화면 접속 시 자동으로 컬럼 추가 · 값 채움
  (PostgreSQL 에서 인덱스가 컬럼보다 먼저 만들어지지 않도록 업그레이드를 먼저 실행).
- 스키마 버전을 플러그인 버전과 별도로 추적 (`dwcat_schema`) — 스키마만 바뀌어도 업그레이드됨.

---

## [2.0.0-rc.5] — 2026-10-12

**구조 전환 — 독립 카탈로그 시스템 + 워드프레스 어댑터.** 워드프레스는 디자인만 담당하고,
카탈로그 데이터는 워드프레스 테이블을 전혀 쓰지 않습니다. (설치된 사이트가 없어 데이터 이전 없음)

### Changed

- **코어 분리** (`core/`, 네임스페이스 `DWCatalog\`) — 워드프레스 함수를 쓰지 않는 PHP 라이브러리.
  DB 연결 · 사용자 권한 · 파일 경로 · URL · 번역은 Host 인터페이스로 받습니다. 워드프레스 어댑터는
  `includes/wp/`. 다른 PHP 환경에 붙일 때는 Host 구현만 새로 작성합니다 ([docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)).
- **전용 테이블 8개** — items · terms · item_terms · images · values · variations · search · settings.
  상품 · 카테고리 · 이미지 · 필드 설정까지 모두 여기 저장되며 `wp_posts` · `wp_postmeta` · `wp_terms` ·
  `wp_options` · 미디어 라이브러리는 사용하지 않습니다.
- **DB: MySQL/MariaDB + PostgreSQL** — 기본은 워드프레스 DB 안(`{prefix}dwcat_`, 설정 불필요),
  `DWCAT_DB_*` 상수로 별도 DB 서버 · PostgreSQL 선택.
- **관리자 화면을 코어가 제공** — 항목 목록(검색 · 필터 · 정렬) · 편집(이미지 · 카테고리 · 태그 ·
  필드 · Variations) · 카테고리 · 필드/프리셋/Variation 컬럼 · CSV 가져오기 · PDF 내보내기 · 디자인 ·
  숏코드 안내. 워드프레스에서는 `DW Catalog` 메뉴 안에 표시됩니다.
- **이미지는 카탈로그 전용 저장** — `uploads/dw-catalog/` 에 원본(최대 2400px) + large/medium/thumb.
  모든 이미지를 **다시 인코딩**해 EXIF(위치정보 등)를 제거하고 이미지로 위장한 파일을 무력화합니다.
- **상세 주소** `/{page}/item/{slug}/` (pretty permalink) — 제목 · description · canonical · Open Graph ·
  JSON-LD 출력, 없는 항목 404, XML 사이트맵(`wp-sitemap-dwcatalog-1.xml`).
- `[dw_catalog_magazine]` 숏코드 제거.

### Security

- 모든 SQL 은 파라미터 바인딩(PDO / `$wpdb->prepare`), 테이블 이름은 접두사 + 고정 이름만.
- 관리자 작업마다 권한 + 라이선스 정책 + 사용자 · 동작에 묶인 CSRF 토큰 검증.
- CSV 이미지 URL 가져오기: http(s) · 표준 포트만, 매 리다이렉트마다 사설 · 예약 IP 차단(SSRF), 15MB 상한.
- 이미지 저장 폴더에 목록 노출 차단 + 스크립트 실행 차단 파일.
- PDF 생성 시 원격 리소스 로딩 끔, 이미지는 저장 폴더 안에서만 읽음.
- 항목 API(`/dw-catalog-wp/v1/items`)는 로그인 + `edit_posts` 필수 — 공개 데이터 API 없음.

### Tests

- `tests/core/run.php` — 코어를 **실제 MySQL · PostgreSQL** 에서 검증 (166개: 스키마 · 검색 · 필터 ·
  정렬 · 비공개 필드 차단 · CSRF · 권한 · XSS 출력 이스케이프 · 이미지 처리 · 삭제 정리).
  CI 에 MySQL 8 / PostgreSQL 16 서비스로 추가.

### anti-piracy 관련 변경 (§7.6 의무 표기)

| 항목 | 내용 |
|---|---|
| 해시 변경 사유 | 전면 재구성 — `core/` 신규, `includes/wp/` 신규, 구 클래스 다수 삭제. 매니페스트 재등록 필요 |
| 새 게이트 | 없음 — 9개 게이트를 어댑터 진입점 + `DWCAT_WP_Host::allows()` 두 겹으로 재배치 ([MIGRATION-PLAN §5](docs/MIGRATION-PLAN.md)) |
| 마이그레이션 필요 여부 | 없음 (운영 중인 설치 없음) |

---

## [2.0.0-rc.4] — 2026-10-12

### Changed — 저장 구조 (성능)

- **카탈로그 데이터를 전용 테이블로 이전** — 필드 값 · Variations · 검색 색인을 `wp_postmeta` 에서
  인덱스가 걸린 전용 테이블 4개로 옮겼습니다 (상품 수만 개 규모 기준).
  | 테이블 | 용도 |
  |---|---|
  | `{prefix}dwcat_values` | 필드 값. `(field_key, value)` · `(field_key, value_num)` 인덱스로 필터 · 정렬 |
  | `{prefix}dwcat_variations` | Variation 1행 = 1레코드. Item Code · Price 인덱스 |
  | `{prefix}dwcat_search` | 단어 색인 `(token, post_id, source)` — 검색이 인덱스 범위 조회 |
  | `{prefix}dwcat_items` | 상품별 색인 상태 |
  상품 본문 · 이미지 · 주소 · 카테고리는 `wp_posts` / 분류 테이블에 그대로 둡니다
  (고유 주소 · 미디어 · SEO 플러그인 · 테마 호환).
- 기존 데이터는 **업데이트 후 백그라운드로 자동 이전**되고(크론 + 관리자 화면 방문 시 200건씩),
  검증 후 postmeta 원본을 삭제합니다. 이전 중에도 편집은 정상 동작합니다.
- `get_post_meta()` / `update_post_meta()` 로 카탈로그 필드를 읽고 쓰는 테마 · 페이지 빌더
  (Kadence 동적 필드 등)는 그대로 동작합니다 — 호출이 전용 테이블로 연결됩니다.
  테마 코드용 헬퍼 `dwcat_get_field( $post_id, $key )` 추가.
- 검색이 **단어 앞부분 일치**로 바뀌었습니다 (`miso` → "Miso Ramen" ○, `iso` → ×).
  `WK-009` 와 `wk009` 는 같은 결과를 냅니다.
- 목록 한 페이지의 필드 값을 쿼리 1번에 읽습니다 (상품 수와 무관하게 쿼리 수 일정).
  관련 상품의 `ORDER BY RAND()` 제거.

### Security — 데이터 노출 경로 차단

- **REST API 차단** — `/wp-json/wp/v2/product`, 카테고리 · 태그 REST 는 편집 권한이 없으면 401.
  `/wp-json/wp/v2/search` 결과에서 상품 제외. 로그인한 편집자(DW Admin SPA)는 그대로 사용.
- **RSS 피드 차단** — `/product/feed/` 와 카테고리 피드 404.
- **비공개 필드는 서버 밖으로 나가지 않습니다** — "Detail" 체크가 해제된 필드는 카드 · 상세 ·
  필터 선택지 · 정렬 · **사이트 검색**에서 모두 제외됩니다. 검색 색인이 단어의 출처 필드를
  기록하므로, 검색어를 넣어 결과 유무로 내부 값을 알아내는 방법도 막힙니다.
  관리자 목록 검색은 모든 필드를 찾습니다.
- **Variation 컬럼별 "Public" 플래그** — 원가 같은 내부 컬럼은 사이트 표 · 검색에서 제외.
- 입력 상한 — 검색어 6단어 · 필터당 50개 값 · `per_page` 100.

### anti-piracy 관련 변경 (§7.6 의무 표기)

| 항목 | 내용 |
|---|---|
| 해시 변경 사유 | 신규 파일 `class-dwcat-store.php` · `class-dwcat-access-guard.php`, 데이터 접근 계층 교체로 다수 파일 수정 — 매니페스트 재등록 필요 |
| 새 게이트 | 없음 |
| 마이그레이션 필요 여부 | 자동 (postmeta → 전용 테이블, 백그라운드). 사용자 조치 없음 |

---

## [2.0.0-rc.3] — 2026-10-12

### Changed

- 필드 프리셋을 "업종 선택"으로 변경 — 다른 프리셋을 누르면 직전 프리셋이 추가한 (저장 전) 행을
  교체하고, 선택된 프리셋을 다시 누르면 취소됩니다. 누를 때마다 아래에 계속 쌓이던 동작 수정.
  이미 저장된 필드는 건드리지 않습니다.

---

## [2.0.0-rc.2] — 2026-10-12

> **RC 인 이유**: rc.1 과 같습니다 — 카나리 검증(MP2) 전이므로 **prerelease** 로 게시되어
> 기존 v1.2.1 사이트의 자동 업데이트에 잡히지 않습니다. 이 버전은 **Sync Release 로
> 매니페스트를 등록해야** 토큰이 발급됩니다 (rc.1 매니페스트로는 TAMPER_DETECTED).

### Added

- **`[dw_catalog]` 카탈로그 페이지 숏코드** — 페이지에 숏코드 하나로 목록과 상세를 모두 표시합니다.
  왼쪽 사이드바(검색 · 하위 카테고리 체크박스 · 필드 필터 · Reset), 정렬, 페이지네이션,
  같은 페이지 안의 상세 보기(`?dw_item=`)와 "목록으로" 상태 유지. 필터 변경은 새로고침 없이
  반영되고 URL 이 갱신됩니다(JS 없이도 동작). 상품 고유 주소도 같은 상세 화면을 씁니다.
  사용법은 [docs/SHORTCODES.md](docs/SHORTCODES.md) §0.
- **Variations** — 상품마다 옵션 행을 편집 화면에서 추가합니다. **컬럼은 업종별로 설정**합니다
  (Custom Fields 화면 하단 "Variation Columns" — Text · Number · Select). 기본값은
  Option · Item Code · Pack Size · Price · Stock, 프리셋을 누르면 업종에 맞는 컬럼 세트가 들어옵니다
  (기본 세트 그대로면 교체, 손댄 세트면 없는 컬럼만 추가). 컬럼을 모두 지우면 Variations 가 꺼집니다.
  상세 화면에 표로 표시되고, Variation 값으로도 검색됩니다.
- **필드별 "Detail" 체크박스** — 상세페이지에 표시할 필드를 고릅니다. 기존 필드는 제목 필드와
  internal 필드(내부 메모)만 꺼진 상태로 시작합니다.
- **관리자 목록 검색 · 필터 · 정렬** — 카테고리 · Select 필드 드롭다운 필터, 컬럼 헤더 클릭 정렬,
  검색 범위를 제품명에서 모든 텍스트 필드(Item Code · Brand 등)로 확장. 프런트와 같은
  쿼리 로직(`DWCAT_Catalog_Query`)을 씁니다.
- **커스텀 필드 프리셋** — 필드 관리 화면에 업종별 버튼. Wholesaler: Seafood Market · Jewelry ·
  Beauty Supply · Plants / Retailer: Cafe · Restaurant · Bakery · General Goods · Apparel · Jewelry ·
  Cosmetics. 이미 있는 meta key 는 건너뛰고, "Save Fields" 전까지 저장되지 않습니다.

### Changed

- **관리자 메뉴를 "DW Catalog" 하나로 통합**했습니다. 설정용 "DW Catalog" 와
  콘텐츠용 "Catalog WP" 두 최상위 메뉴가 있었는데, v1.0.1 에서 포스트 타입이
  `product` 하나로 고정된 뒤로는 나눌 이유가 없었습니다. Shortcode Design · License 는
  상품 메뉴 아래로 옮겼고, 포스트 타입 목록뿐이던 설정 첫 화면은 제거했습니다.
  - 기존 설치의 메뉴 이름(`Catalog WP`)은 1회 마이그레이션으로 바뀝니다
    (`dwcat_menu_merged`). 버전 변경 없이 돕니다.
  - 옛 주소 `admin.php?page=dw-catalog-settings` 는 필드 관리 화면으로 리다이렉트합니다.
  - 라이선스 화면 slug(`dw-catalog-license`)는 그대로입니다.
- Custom Fields 서브메뉴가 화면 출력 뒤 리다이렉트하던 방식에서 직접 링크로 바뀌었습니다
  (출력 버퍼링이 없는 호스트에서 "headers already sent" 로 실패하던 경로).

### Fixed

- 블록 테마에서 카드 필드 줄 간격이 크게 벌어지던 문제 (테마 기본 글자 크기가 줄 높이를 결정).

- 유예 기간 중에 라이선스를 활성화해도 "라이선스 인증이 필요합니다" 안내가
  유예가 끝날 때까지 계속 표시되던 버그.

### anti-piracy 관련 변경 (§7.6 의무 표기)

| 항목 | 내용 |
|---|---|
| 해시 변경 사유 | 관리자 메뉴 파일 · `gates.php` · SDK(`class-dw-license-manager.php`) 수정, 신규 파일 4개(`class-dwcat-catalog*.php` · `class-dwcat-variations.php` · `class-dwcat-field-presets.php` · `assets/js/catalog.js`) — 다음 릴리스에서 매니페스트 재등록 필요 |
| 새 게이트 | 없음 — `[dw_catalog]` 와 상품 상세는 기존 게이트 1·2·8, Variations 저장은 게이트 3 을 그대로 씁니다 |

---

## [2.0.0-rc.1] — 2026-08-19

DASOM-Forge 플랫폼 통합. **MAJOR** — 라이선스 흐름이 바뀝니다.

> **RC 인 이유**: 카나리 검증(MP2)이 아직 수행되지 않았습니다. GitHub 에는
> **prerelease** 로 게시되므로 기존 v1.2.1 사이트의 `/releases/latest` 조회에
> 잡히지 않습니다 — 실사용 사이트에 breaking 업데이트가 새어 나가지 않습니다.
> 카나리 검증 통과 후 `v2.0.0` 을 stable 로 태깅합니다
> (PLUGIN-RELEASE-CHECKLIST §9).

### ⚠ 사용자 영향

- **이 플러그인은 이제 유효한 DASOM-Forge 라이선스를 요구합니다.**
  카탈로그 프런트 출력 · 필드 스키마 편집 · CSV 가져오기 · PDF 내보내기가
  라이선스 게이트 뒤로 들어갔습니다.
- **기존 v1.x 설치는 자동 업데이트로 즉시 끊기지 않습니다** — 업그레이드가
  감지되면 **30일 유예**가 1회 부여되고, 남은 일수가 관리자 화면에 표시됩니다
  (KICKOFF FAQ Q8). 유예 중 라이선스를 활성화하면 그대로 이어집니다.
- 라이선스 옵션 키(`dw_license_dw_catalog_wp`)는 v1.x 와 동일합니다 →
  **이미 활성화한 사용자는 키를 다시 넣을 필요가 없습니다.**

### Added

- **DASOM-Forge SDK** (`DW_DWCAT_License_Manager` · `DW_DWCAT_Forge_Client`)
  — Prefix 배포 전략 (PLUGIN-DEV-GUIDE §3.5 B). 다른 DW 플러그인의 SDK 버전과
  완전 격리되어 winner race 가 발생하지 않습니다.
- **라이선스 게이트 9종** (`includes/license/gates.php`) — 6개 파일에 분산,
  4가지 서로 다른 검증 경로 혼합 (§6).
- **무결성 매니페스트** — `integrity.json` 을 CI 가 릴리스 스테이지 실물 위에서
  생성 (§7.2.2 규약 1 · 루트상대 키).
- **릴리스 계약 자가 검증** (`build/verify-package.js`) — 서버가 SyncRelease 에서
  하는 판정(해시 대상 집합 일치)을 빌드 시점에 재현합니다.
- **결정론적 빌드** — `.gitattributes` + `SOURCE_DATE_EPOCH` + `zip -X`,
  CI 가 같은 소스로 두 번 빌드해 SHA256 일치를 강제 (§11.2.b).
- **멀티사이트 지원** (FAQ Q10) — 라이선스는 네트워크 단위, 토큰 캐시는 사이트 단위.
  네트워크 관리자 메뉴 추가.
- **진단 엔드포인트** `GET /wp-json/dw-catalog-wp/v1/license/status` (§3.6 SHOULD #2)
  — 관리자 인증 필수. 라이선스 키·토큰은 노출하지 않습니다.
- **부트스트랩/게이트 런타임 테스트** (`tests/test-bootstrap.php`) —
  게이트 fail-closed · 유예 · dev bypass 를 실행으로 검증.
- `dasomforge.pub` 공개키 동봉 (`includes/keys/`).
- `docs/MIGRATION-PLAN.md` — §0 보호 경계 합의서.

### Changed

- **API 베이스가 `api.dasomforge.com` 으로 전환**되었습니다
  (기존 `api-production-a3f4.up.railway.app`).
- 릴리스 ZIP 이 **플랫폼 표준 멀티루트 구조**로 바뀌었습니다 —
  `manifest.json` + `wp-plugin/` (§7.2). 이 ZIP 은 워드프레스에 직접 업로드하지
  않고 dasomforge "Sync Release" 를 거칩니다.
- **GitHub 업데이터를 완전히 비활성화**했습니다 (§7.4 "GitHub 직접 다운로드 ❌").
  dasomforge 가 유일한 배포 채널입니다. 기술적으로도 필수 — v2.0 부터 GitHub
  Release 자산은 **플랫폼 멀티루트 ZIP** 이라 워드프레스가 직접 설치할 수 없습니다
  (§7.2.1 제출 ZIP ≠ 고객 설치 ZIP). `DWCAT_GitHub_Updater` 클래스 파일은
  롤백 대비로 남기되 인스턴스화하지 않습니다.
- 라이선스 관리자 화면을 admin-post 기반으로 교체 (기존 AJAX → nonce + capability).

### Security

- **dompdf `^2.0` → `^3.1`** — 2.x 전 버전이 보안 권고로 차단된 상태였습니다
  (CVE-2023-50262 포함 10건). v1.2.1 릴리스 ZIP 에는 취약한 dompdf 2.x 가
  실려 있었습니다. `composer.lock` 을 커밋해 vendor 내용을 고정했습니다.
- 릴리스 패키징이 `wp-config.php` · `Zone.Identifier` 혼입 시 빌드를 실패시킵니다.
- **`composer.lock` 을 선언한 최소 PHP(7.4) 기준으로 고정** (`config.platform.php`).
  PHP 8.3 에서 만든 lock 이 `thecodingmachine/safe v3.4.0` (php `^8.1`) 을 끌어와
  `Requires PHP: 7.4` 와 모순됐고, 그대로 출하했다면 **PHP 7.4/8.0 호스트에서
  fatal** 이 났을 것입니다. CI 가 잡았습니다 (MIGRATION-PLAN §12.1).
  → safe v1.3.3 으로 내려가 전 패키지 7.4 호환. 매니페스트 668 → **537개**,
    `/auth/token` 본문 82.7 → **66.5 KB**.

### Fixed

- 레거시 유예 판정이 `version_compare('2.0.0-rc.1', '2.0.0', '>=')` 에 의존해
  프리릴리스를 v1 으로 오인, **v2 사이트에 30일 유예를 잘못 부여**하던 버그.
  메이저 버전 비교로 교체하고 회귀 테스트를 추가했습니다.

### anti-piracy 관련 변경 (§7.6 의무 표기)

| 항목 | 내용 |
|---|---|
| 해시 변경 사유 | **전체 파일 재해싱** — SDK 신규 추가 + vendor(dompdf 3.x) 교체. 매니페스트 재등록 필수 |
| 새 게이트 | 9개 신규 (`dwcat_can_render` · `_load_assets` · `_save_meta` · `_manage_fields` · `_bulk_import` · `_export_pdf` · `_render_admin` · `_use_shortcode` · `_call_rest`) |
| 무결성 키 규약 | **규약 1 (루트상대)** 채택 — MIGRATION-PLAN §4.3 |
| runtime_version 변경 | 해당 없음 — 런타임 JS 미적용 (MIGRATION-PLAN §4.1) |
| 공개키 회전 | 없음 (최초 도입) |
| 마이그레이션 필요 여부 | 사용자 조치 = 라이선스 키 입력 1회. 데이터 변환 없음 |
| 업데이트 채널 | GitHub → **dasomforge R2 signed URL** 로 전환 (§7.4) |

---

## [1.2.1] — 2026-04

- 마케팅 문서에 v1.2.0 디자인 설정 반영.

## [1.2.0] — 2026-04

- 숏코드 디자인 설정 (관리자 타이포그래피 + 컬러).

## [1.1.0] — 2026-04

- 프런트엔드 숏코드 3종 (Grid, Carousel, Magazine).

## [1.0.7] — 2026-04

- WP 플러그인 패키징 가이드 추가.

## [1.0.6] — 2026-04

- DW License Manager SDK (v1) + DW Admin SPA 통합.
