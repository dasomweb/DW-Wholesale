# DW Wholesale — 독립 시스템과 커넥터

DW Wholesale는 **독립된 카탈로그 시스템**입니다. 워드프레스 플러그인으로 배포하는 이유는 설치와
운영이 쉽기 때문이며, 워드프레스는 **커넥터(연결 장치)**를 통해 붙어 있을 뿐입니다.

```
┌──────────────── DW Wholesale 코어 (core/) ────────────────┐
│ 데이터 · 검색 · 필터 · 정렬 · 관리자 화면 · 카탈로그 페이지 │
│ 보안 · 이미지 처리                                         │
│ ※ 워드프레스 코드 0줄 (빌드마다 자동 테스트로 확인)          │
└──────────────────────────────────────────────────────────┘
              ▲  정해진 요청 목록 (Host 인터페이스 + DB 연결)
              │
   ┌──────────┴──────────┐
   │ 워드프레스 커넥터      │   includes/wp/   ← 지금 있는 것
   └─────────────────────┘
   ┌─────────────────────┐
   │ 다른 환경 커넥터       │   라라벨 · 일반 PHP 사이트 · 다른 CMS
   └─────────────────────┘   ← 필요할 때 추가
```

---

## 1. 무엇이 독립되어 있나

| 영역 | 내용 |
|---|---|
| **데이터** | 상품 · 카테고리 · 이미지 정보 · 필드 값 · Variation · 검색 색인 · 필드 설정을 전용 테이블 8개에만 저장합니다. `wp_posts` · `wp_postmeta` · `wp_terms` · 미디어 라이브러리는 쓰지 않습니다. |
| **코드** | `core/` 폴더에는 워드프레스 함수 호출이 전혀 없습니다. |
| **관리자 화면** | 상품 목록 · 편집 · 카테고리 · 필드 · 가져오기/내보내기 · 디자인 화면을 코어가 직접 만듭니다. |
| **카탈로그 페이지** | 목록 · 사이드바 필터 · 상세 페이지 HTML/CSS/JS 를 코어가 만듭니다. |
| **보안** | 권한 확인 · CSRF 토큰 · 입력 검증 · 출력 이스케이프 · 비공개 필드 차단이 코어 안에 있습니다. |
| **이미지** | 크기 조정 · WebP 변환 · 메타데이터 제거를 코어가 처리합니다. |

### 전용 테이블 (접두사 `{호스트 접두사}dwcat_`)

| 테이블 | 용도 |
|---|---|
| `items` | 상품 (이름 · 주소 slug · 설명 · 상태 · 정렬 키 · 등록/수정일) |
| `terms` | 카테고리 · 태그 (트리) |
| `item_terms` | 상품 ↔ 카테고리/태그 연결 |
| `images` | 이미지 파일 정보 (경로 · 크기 · alt · 순서) — 파일 자체는 디스크 |
| `values` | 커스텀 필드 값 |
| `variations` | Variation (한 줄 = 한 레코드) |
| `search` | 검색 색인 (단어 · 상품 · 출처 필드) |
| `settings` | 필드 설정 · Variation 컬럼 · 디자인 등 |

---

## 2. 커넥터가 하는 일

커넥터는 **붙는 환경이 원래 가진 것을 코어에 넘겨주는 역할만** 합니다. 로그인 · 메뉴 틀 · 페이지
머리글/바닥글 · 주소 연결은 어느 환경에나 당연히 있는 것이고, 코어는 그것을 직접 만들지 않고
커넥터를 통해 빌려 씁니다.

### 커넥터가 코어에 넘겨주는 것

| 항목 | 코어가 요청하는 것 (`core/src/Host/Host.php`) | 워드프레스 커넥터의 구현 |
|---|---|---|
| 사용자 | `userId()` — 지금 로그인한 사용자 | `get_current_user_id()` |
| 권한 | `can( 권한 )` — 아래 표의 6가지 | 워드프레스 권한으로 대응 (아래 표) |
| 기능 허용 | `allows( 기능 )` — 렌더 · 저장 · 가져오기 등 | DASOM-Forge 라이선스 게이트 |
| 보안 키 | `secret()` — CSRF 토큰 서명용 | 워드프레스 salt 에서 생성 |
| 번역 | `translate( 문장 )` | 워드프레스 번역 파일 |
| 본문 정리 | `sanitizeHtml( html )` — 상품 설명의 위험한 태그 제거 | `wp_kses_post()` |
| 편집기 | `richText( 이름, html )` — 설명 입력란 | 워드프레스 편집기 (`wp_editor`) |
| 파일 저장 위치 | `storageDir()` / `storageUrl()` | `wp-content/uploads/dw-catalog/` |
| 관리자 주소 | `adminUrl()` / `adminPostUrl()` | `admin.php?page=dw-catalog` / `admin-post.php` |
| 화면 파일 주소 | `assetUrl( 파일 )` — CSS · JS | 플러그인 폴더 URL |
| 버전 | `version()` — 캐시 갱신용 | 플러그인 버전 |
| 캐시 | `remember( 키, 시간, 계산 )` | 워드프레스 transient |

### 권한 대응 (워드프레스 커넥터)

| 코어 권한 | 뜻 | 워드프레스 권한 |
|---|---|---|
| `catalog.edit` | 상품 추가 · 편집 · 이미지 업로드 | `edit_posts` |
| `catalog.delete` | 상품 삭제 | `delete_posts` |
| `catalog.terms` | 카테고리 관리 | `manage_categories` |
| `catalog.manage` | 필드 · Variation 컬럼 · 디자인 설정 | `manage_options` |
| `catalog.import` | CSV 가져오기 | `edit_posts` |
| `catalog.export` | CSV · PDF 내보내기 | `edit_posts` |

### DB 연결

코어는 DB 연결 규약(`core/src/Db/Connection.php`)만 압니다.

| 방식 | 설정 | 비고 |
|---|---|---|
| 워드프레스와 같은 DB (기본) | 없음 | `$wpdb` 로 연결, 테이블은 `wp_dwcat_*` |
| 별도 DB 서버 · PostgreSQL | `wp-config.php` 에 `DWCAT_DB_*` 상수 | PDO 로 연결 |

지원 DB: **MySQL / MariaDB**, **PostgreSQL**. 다른 DB 는 DB 방언(Dialect) 하나만 추가하면 됩니다.

### 화면을 끼워 넣는 자리

| 화면 | 워드프레스 커넥터에서 |
|---|---|
| 관리자 화면 | `DW Wholesale` 메뉴 안에 코어 화면을 표시, 폼 전송은 `admin-post.php` 로 받아 코어에 전달 |
| 카탈로그 페이지 | 숏코드 `[dw_catalog]` 자리에 코어가 만든 HTML 을 넣음 |
| 상세 주소 | `/{페이지}/item/{slug}/` 를 워드프레스 주소 규칙으로 받아 코어에 전달 |
| SEO | 코어가 만든 제목 · 설명 · canonical · OG · JSON-LD 를 `<head>` 에 출력 |
| 사이트맵 | 코어의 상품 목록을 워드프레스 사이트맵에 추가 |

---

## 3. 워드프레스 쪽에 남아 있는 것 (카탈로그 데이터 아님)

| 항목 | 저장 위치 | 이유 |
|---|---|---|
| 라이선스 키 · 토큰 | `wp_options` | 라이선스는 워드프레스 배포판에 속함 |
| 플러그인 버전 · 스키마 버전 | `wp_options` | 업데이트 판단용 |
| 필터 선택지 · 메인 카탈로그 페이지 위치 캐시 | 워드프레스 transient | 커넥터가 제공하는 캐시 저장소 |

---

## 4. 다른 환경에 붙이려면

코어와 데이터는 그대로 두고 **커넥터 하나를 새로 만듭니다.**

1. **Host 구현** — 위 표의 요청 14가지를 그 환경 방식으로 구현
   (로그인 사용자 · 권한 · 파일 위치 · 주소 · 번역 · 캐시 · 편집기 등)
2. **DB 연결** — `PdoConnection::fromConfig()` 로 MySQL/PostgreSQL 연결 (대부분 그대로 사용)
3. **관리자 화면 자리** — 그 환경의 관리자 페이지에 `$catalog->admin()->render( $_GET )` 출력,
   폼 전송은 `$catalog->admin()->handle( $_POST, $_FILES )` 로 전달.
   그 환경의 메뉴에 화면 목록(All Items · Add New · Categories · Fields · Import / Export · Settings)을
   직접 넣었다면 상위 요소에 `dwc-host-nav` 클래스를 붙여 코어의 탭 줄을 숨깁니다 (WordPress 는 body 에 붙임)
4. **카탈로그 페이지 자리** — 원하는 페이지에 `$catalog->front()->catalog( $옵션, $context )` 출력
5. **주소 연결** — `/{페이지}/item/{slug}/` 같은 상세 주소를 받아 `Context` 에 넘김
6. **라이선스** — 그 환경용 라이선스 방식을 `allows()` 에 연결

```php
require 'core/bootstrap.php';

$db      = DWCatalog\Db\PdoConnection::fromConfig( array(
	'driver' => 'mysql', 'host' => '127.0.0.1', 'dbname' => 'shop',
	'user' => 'shop', 'password' => '…', 'prefix' => 'dwcat_',
) );
$host    = new MyHost();                       // 이 환경용 커넥터 (Host 인터페이스 구현)
$catalog = new DWCatalog\Catalog( $db, $host, 'dwcat_' );
$catalog->install();                           // 최초 1회: 테이블 생성

echo $catalog->front()->catalog(
	array( 'category' => 'refrigerated' ),
	new DWCatalog\Front\Context( 'https://shop.example/refrigerated/', 'https://shop.example/refrigerated/item/', $_GET, $slug )
);
```

참고: 테스트용 Host 구현 예시는 [`tests/core/TestHost.php`](../tests/core/TestHost.php) 에 있습니다.

---

## 5. 지켜지는 원칙

- **코어는 워드프레스를 모릅니다.** 워드프레스 함수가 코어에 들어오면 빌드 테스트가 실패합니다.
- **방문자는 완성된 HTML 만 받습니다.** 공개 데이터 API 가 없어 F12 로 데이터를 통째로 가져갈 통로가 없습니다.
- **비공개 필드는 커넥터와 상관없이 코어가 막습니다.** 화면 · 검색 · 필터 · 정렬 어디에도 나가지 않습니다.
- **속도는 코어의 설계로 보장합니다.** 검색 · 필터 · 정렬은 모두 인덱스로 처리되고, 목록 한 페이지의
  쿼리 수는 상품 수와 상관없이 일정합니다.
