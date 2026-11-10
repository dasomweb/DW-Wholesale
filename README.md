# DW Wholesale

**독립 카탈로그 시스템**을 워드프레스 플러그인으로 배포합니다. 워드프레스는 디자인(테마 · 페이지)만
맡고, 카탈로그 데이터는 플러그인 전용 테이블에만 있습니다 (`wp_posts` · `wp_postmeta` · 미디어 라이브러리 미사용).

- 코어(`core/`)는 워드프레스 코드 없는 PHP 라이브러리 — MySQL/MariaDB · PostgreSQL 지원
- 페이지에 숏코드 하나: 목록 · 검색 · 필터 · 정렬 · 상세 (`/{page}/item/{slug}/`) · SEO 태그
- 비공개 필드는 사이트 어디에도 나가지 않음 (출력 · 검색 · 필터 · 정렬 · API)
- 구조: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) · 독립 시스템과 커넥터: [docs/CONNECTORS.md](docs/CONNECTORS.md) · 숏코드: [docs/SHORTCODES.md](docs/SHORTCODES.md)

### 별도 DB 사용 (선택)

기본은 워드프레스 DB 안의 `{prefix}dwcat_*` 테이블입니다 (설정 불필요). 다른 DB 서버나
PostgreSQL 을 쓰려면 `wp-config.php` 에:

```php
define( 'DWCAT_DB_DRIVER', 'pgsql' );   // 또는 'mysql'
define( 'DWCAT_DB_HOST', '127.0.0.1' );
define( 'DWCAT_DB_PORT', 5432 );
define( 'DWCAT_DB_NAME', 'catalog' );
define( 'DWCAT_DB_USER', 'catalog' );
define( 'DWCAT_DB_PASSWORD', '…' );
```

### 테스트

```bash
php tests/test-plugin-integrity.php && php tests/test-bootstrap.php && php tests/test-license-manager.php
# 코어 (실제 DB):
DWC_MYSQL="mysql:host=127.0.0.1;dbname=dwc_test" DWC_MYSQL_USER=root \
DWC_PGSQL="pgsql:host=127.0.0.1;dbname=dwc_test" DWC_PGSQL_USER=postgres \
php -d extension=gd tests/core/run.php
```

---

## ⚠️ v2.0 부터 DASOM-Forge 라이선스가 필요합니다

v2.0.0 부터 이 플러그인은 **DASOM-Forge 플랫폼에 의존**합니다.
유효한 라이선스로 활성화하지 않으면 다음이 동작하지 않습니다:

- 프런트엔드 카탈로그 출력 (숏코드 3종)
- 커스텀 필드 스키마 편집 · 필드 값 저장
- CSV 대량 가져오기 · PDF 내보내기
- DW Admin SPA 모듈 노출

**기존 v1.x 사용자**: 자동 업데이트로 즉시 끊기지 않습니다. 업그레이드가 감지되면
**30일 유예**가 부여되고 관리자 화면에 남은 일수가 표시됩니다. 유예 기간 안에
`DW Wholesale → License` 에서 라이선스 키를 입력하십시오.
(기존에 키를 넣었다면 그대로 승계되어 재입력이 불필요합니다.)

**개발자**: 로컬 개발은 `wp-config.php` 에 `define('DWCAT_DEV_BYPASS', true);` 로 우회합니다.
이 상수는 절대 플러그인 코드나 릴리스 ZIP 에 들어가지 않습니다.

### 릴리스 흐름 (v2.0+)

`v*` 태그 → GitHub Actions 가 **플랫폼 표준 멀티루트 ZIP**
(`manifest.json` + `wp-plugin/`) 생성 → 운영자가 dasomforge 어드민에서
**Sync Release** → R2 배포 + IntegrityManifest 자동 등록 → 고객 자동 업데이트.

> 이 ZIP 은 워드프레스에 **직접 업로드하지 마십시오**. Sync Release 를 거쳐야
> 무결성 매니페스트가 등록되고, 그래야 플러그인이 토큰을 발급받습니다.

관련 문서:
- [docs/MIGRATION-PLAN.md](docs/MIGRATION-PLAN.md) — 보호 경계 합의서 (§0)
- [CHANGELOG.md](CHANGELOG.md) — anti-piracy 관련 변경 표기 포함

### 이 repo 가 정본입니다

`dasomweb/DW-Product-Catalog` 는 같은 조상에서 갈라진 **옛 갈래**이며 2026-08-20
운영자 결정으로 동결되었습니다. 두 플러그인은 **같은 CPT `product` 를 등록**하므로
한 사이트에서 동시에 활성화하지 마십시오. 자세한 경위와 근거는
[MIGRATION-PLAN §0.1](docs/MIGRATION-PLAN.md) 참조.

---

## 📦 GitHub을 통한 배포 (GitHub Deployment)

이 플러그인은 GitHub Releases를 통해 자동으로 배포되고 업데이트됩니다.

This plugin is automatically deployed and updated through GitHub Releases.

### 🚀 릴리스 생성 방법 (How to Create a Release)

#### 방법 1: 자동 릴리스 (권장) - Automated Release (Recommended)

1. **버전 업데이트 및 태그 생성:**
   ```bash
   # 버전 번호 업데이트 (플러그인 파일에서 수동으로)
   # Update version number (manually in plugin file)
   
   # Git 태그 생성
   git tag v1.0.0
   git push origin v1.0.0
   ```

2. **GitHub Actions가 자동으로:**
   - 플러그인 ZIP 파일 생성
   - GitHub Release 생성
   - 릴리스 자산으로 ZIP 파일 첨부

#### 방법 2: GitHub Actions 워크플로우 사용

1. GitHub 저장소에서 **Actions** 탭으로 이동
2. **Update Plugin Version** 워크플로우 선택
3. **Run workflow** 클릭
4. 새 버전 번호 입력 (예: `1.0.1`)
5. 워크플로우가 자동으로:
   - 플러그인 파일의 버전 업데이트
   - 커밋 및 푸시
   - 버전 태그 생성
   - 릴리스 생성

#### 방법 3: 수동 릴리스

1. GitHub 저장소에서 **Releases** > **Draft a new release** 클릭
2. 태그 선택 또는 새 태그 생성 (예: `v1.0.0`)
3. 릴리스 제목 및 설명 작성
4. 플러그인 ZIP 파일을 수동으로 업로드
   - ZIP 파일명: `dw-wholesale-{version}.zip`
   - ZIP에는 `.git`, `.github` 폴더 제외

### 📥 설치 방법 (Installation)

#### WordPress에서 직접 설치

1. WordPress 관리자 페이지로 이동
2. **플러그인** > **새로 추가** 클릭
3. **플러그인 업로드** 클릭
4. GitHub Releases에서 다운로드한 ZIP 파일 업로드
5. **지금 설치** 클릭
6. **플러그인 활성화** 클릭

#### 자동 업데이트

플러그인이 설치되면 자동으로 GitHub에서 업데이트를 확인합니다:

- WordPress 관리자 > **플러그인** 페이지에서 업데이트 알림 확인
- **지금 업데이트** 버튼 클릭하여 최신 버전 설치
- 도메인 변경 후에도 업데이트 기능이 정상 작동합니다

### 🔧 개발 환경 설정 (Development Setup)

```bash
# 저장소 클론
git clone https://github.com/dasomweb/DW-Wholesale.git
cd dw-wholesale

# WordPress 플러그인 디렉토리에 심볼릭 링크 생성 (선택사항)
# Create symbolic link to WordPress plugins directory (optional)
ln -s $(pwd) /path/to/wordpress/wp-content/plugins/dw-wholesale
```

### 📋 릴리스 체크리스트 (Release Checklist)

릴리스 전 확인사항:

- [ ] 플러그인 버전 번호 업데이트 (`dw-wholesale.php`)
- [ ] `pc_get_plugin_config()` 함수의 버전 번호 업데이트
- [ ] 변경사항 문서화 (CHANGELOG 또는 릴리스 노트)
- [ ] 코드 테스트 완료
- [ ] 도메인 변경 테스트 완료 (`verify-domain-agnostic.php` 실행)
- [ ] 하드코딩된 URL 확인 (없어야 함)
- [ ] Git 태그 생성 및 푸시

### 🔄 버전 관리 (Version Management)

버전 번호는 다음 위치에서 관리됩니다:

1. **플러그인 헤더** (`dw-wholesale.php`):
   ```php
   * Version: 1.0.0
   ```

2. **중앙 설정 함수** (`pc_get_plugin_config()`):
   ```php
   'plugin_version' => '1.0.0',
   ```

**중요:** 릴리스 전에 두 위치 모두 업데이트해야 합니다.

### 🌐 도메인 변경 친화적 설계 (Domain-Change Friendly)

이 플러그인은 도메인 변경에 완전히 독립적으로 설계되었습니다:

- ✅ 하드코딩된 사이트 URL 없음
- ✅ WordPress 함수 사용 (`site_url()`, `home_url()`, `admin_url()`)
- ✅ 중앙 설정 시스템 (`pc_get_plugin_config()`)
- ✅ 도메인 독립적인 GitHub 업데이터

자세한 내용은 [DOMAIN-CHANGE-GUIDE.md](DOMAIN-CHANGE-GUIDE.md)를 참조하세요.

### 📚 문서 (Documentation)

- [도메인 변경 가이드](DOMAIN-CHANGE-GUIDE.md) - Domain Change Guide
- [구현 요약](IMPLEMENTATION-SUMMARY.md) - Implementation Summary
- [도메인 독립성 검증 스크립트](verify-domain-agnostic.php) - Verification Script

### 🛠️ 기술 스택 (Tech Stack)

- **PHP**: 7.4+
- **WordPress**: 5.0+
- **GitHub API**: Releases API
- **배포**: GitHub Actions

### 📝 라이선스 (License)

GPL v2 or later

### 👥 기여 (Contributing)

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

### 📞 지원 (Support)

이슈가 있으시면 [GitHub Issues](https://github.com/dasomweb/DW-Wholesale/issues)에 등록해주세요.

---

**Made with ❤️ by Dasom Web**
