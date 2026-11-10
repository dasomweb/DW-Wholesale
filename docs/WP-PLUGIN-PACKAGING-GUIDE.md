# WordPress Plugin Packaging Guide

> DW 워드프레스 플러그인 배포용 패키징 표준  
> DW-MCP를 통해 모든 DW 플러그인 프로젝트에서 참조 가능

---

## 1. 핵심 원칙: 버전이 포함된 ZIP 파일명 + 고정 폴더명

WordPress 플러그인 배포에서 가장 중요한 두 가지 규칙:

### ZIP 파일명 = 반드시 버전 포함
```
{plugin-slug}-{version}.zip
```
예: `dw-wholesale-1.0.6.zip`, `dw-church-2.3.1.zip`

**이유:**
- 다운로드 시 어떤 버전인지 즉시 식별
- GitHub Releases에서 여러 버전의 ZIP이 공존할 때 혼동 방지
- 라이선스 서버(`/releases/update-check`)에서 버전별 ZIP URL 제공 가능

### ZIP 내부 루트 폴더 = 반드시 플러그인 슬러그 (버전 없이)
```
dw-wholesale-1.0.6.zip
└── dw-wholesale/          ← 이 폴더명이 설치 경로를 결정
    ├── dw-wholesale.php
    ├── uninstall.php
    ├── README.txt
    ├── includes/
    ├── assets/
    └── vendor/
```

**이유:**
- WordPress는 ZIP 안의 **루트 폴더명**을 `wp-content/plugins/` 아래에 그대로 생성
- 폴더명에 버전이 포함되면 (`dw-wholesale-1.0.6/`) 업데이트할 때마다 새 폴더가 생겨 이전 버전이 남음
- 폴더명이 항상 `dw-wholesale/`로 고정되면 업데이트 시 자동 덮어쓰기됨

```
✅ 올바른 구조:
ZIP: dw-wholesale-1.0.6.zip → 폴더: dw-wholesale/
                                  설치: wp-content/plugins/dw-wholesale/

❌ 잘못된 구조:
ZIP: dw-wholesale-1.0.6.zip → 폴더: dw-wholesale-1.0.6/
                                  설치: wp-content/plugins/dw-wholesale-1.0.6/
                                  (업데이트마다 새 폴더 생성 → 이전 버전 잔존)
```

---

## 2. 배포 파이프라인 (GitHub Actions)

### 트리거: v-prefixed 태그 푸시
```yaml
on:
  push:
    tags:
      - 'v*'   # v1.0.0, v1.2.3 등
```

### 버전 추출: 태그에서 자동 파싱
```yaml
- name: Extract version from tag
  run: |
    VERSION=${GITHUB_REF#refs/tags/v}   # v1.0.6 → 1.0.6
    echo "version=$VERSION" >> $GITHUB_OUTPUT
```

### ZIP 생성 핵심 로직
```bash
PLUGIN_NAME="dw-wholesale"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"     # 파일명: 버전 포함

mkdir -p temp_plugin/${PLUGIN_NAME}          # 폴더명: 버전 없이 (고정)
rsync -av --exclude='.git*' \
  --exclude='.github' \
  --exclude='.claude' \
  --exclude='tests' \
  --exclude='*.DS_Store' \
  --exclude='*.log' \
  --exclude='node_modules' \
  --exclude='.gitignore' \
  --exclude='README.md' \
  --exclude='*.md' \                         # 개발 문서 전체 제외
  --exclude='verify-domain-agnostic.php' \
  --exclude='create-release.sh' \
  --exclude='build-installable-zip.ps1' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='temp_plugin' \
  --exclude='*.zip' \
  ./ temp_plugin/${PLUGIN_NAME}/

cd temp_plugin
zip -r "../$ZIP_NAME" "${PLUGIN_NAME}"       # 최종 ZIP
```

---

## 3. ZIP에 포함되는 파일 / 제외되는 파일

### 포함 (배포 필수)
| 파일 | 설명 |
|------|------|
| `{slug}.php` | 메인 플러그인 파일 (헤더 + 부트스트랩) |
| `uninstall.php` | 플러그인 삭제 시 정리 (WP 표준) |
| `README.txt` | WordPress 플러그인 표준 readme (빌드 시 자동 생성) |
| `includes/` | 클래스 파일 |
| `assets/` | CSS, JS, 이미지 |
| `vendor/` | Composer 의존성 (있는 경우, `--no-dev`) |
| `languages/` | 번역 파일 (있는 경우) |

### 제외 (개발 전용)
| 파일/폴더 | 이유 |
|-----------|------|
| `.git*`, `.github/` | 버전 관리 / CI |
| `.claude/` | Claude Code 설정 |
| `tests/` | 테스트 코드 |
| `docs/` | 개발 문서 |
| `*.md` (루트) | README, 가이드 문서 |
| `composer.json`, `composer.lock` | 빌드 도구 (vendor/ 이미 포함) |
| `build-installable-zip.ps1` | 로컬 빌드 스크립트 |
| `create-release.sh` | 릴리즈 스크립트 |
| `node_modules/` | Node 의존성 |
| `verify-domain-agnostic.php` | 검증 스크립트 |

---

## 4. README.txt 자동 생성

빌드 시 ZIP 안에 WordPress 표준 `README.txt`를 자동 생성:

```bash
cat > "temp_plugin/${PLUGIN_NAME}/README.txt" <<EOF
=== Plugin Name ===
Contributors: dasomweb
Tags: relevant, tags
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: ${VERSION}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Short description under 150 chars.

== Description ==

Full description here.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Visit Settings > Permalinks to flush rewrite rules.

== Changelog ==

= ${VERSION} =
* See GitHub Releases for details.
EOF
```

**주의:** `Stable tag`에 빌드 버전이 자동 삽입됨 → 메인 PHP 헤더의 `Version`과 일치해야 함.

---

## 5. 버전 관리 규약

### 버전이 명시되는 3곳 (반드시 일치)
```php
// 1. 플러그인 헤더 (WordPress가 읽음)
 * Version: 1.0.6

// 2. 중앙 설정 함수 (플러그인 내부 + GitHub Updater가 읽음)
'plugin_version' => '1.0.6',

// 3. Git 태그 (GitHub Actions 트리거 + 릴리즈명)
git tag v1.0.6
```

### 릴리즈 플로우
```bash
# 1. 버전 올리기 (플러그인 헤더 + config 함수)
# 2. 커밋
git add -A && git commit -m "Release 1.0.6: 변경사항 요약"
# 3. 태그 생성
git tag v1.0.6
# 4. 푸시 (커밋 + 태그)
git push origin main && git push origin v1.0.6
# 5. GitHub Actions가 자동으로:
#    - ZIP 생성 (dw-wholesale-1.0.6.zip)
#    - Release 생성 (v1.0.6)
#    - ZIP을 Release asset으로 첨부
```

---

## 6. 로컬 빌드 (PowerShell)

GitHub Actions 없이 로컬에서 ZIP을 생성하려면:

```powershell
# 루트에서 실행
.\build-installable-zip.ps1
```

핵심 로직:
```powershell
# 버전 자동 파싱 (메인 PHP에서)
$config = Get-Content "{slug}.php" -Raw
if ($config -match "plugin_version'\s*=>\s*'([^']+)'") { $ver = $Matches[1] }

# 폴더 구조: build/{slug}/ (버전 없이)
$pluginSlug = "dw-wholesale"
$pluginDir = "build\$pluginSlug"

# 필요한 파일만 복사 (whitelist 방식)
Copy-Item "{slug}.php" $pluginDir
Copy-Item "uninstall.php" $pluginDir
Copy-Item "includes" $pluginDir -Recurse
Copy-Item "assets" $pluginDir -Recurse
Copy-Item "vendor" $pluginDir -Recurse  # Composer deps

# README.txt 생성
# ... (Stable tag: $ver)

# ZIP 파일명: 버전 포함
$zipName = "$pluginSlug-$ver.zip"        # dw-wholesale-1.0.6.zip
Compress-Archive -Path "build\$pluginSlug" -DestinationPath $zipName
```

---

## 7. Composer 의존성 처리

PDF 내보내기 등에서 Composer 패키지를 사용하는 경우:

```yaml
# GitHub Actions에서 빌드 시
- name: Install Composer dependencies
  run: |
    if [ -f composer.json ]; then
      composer install --no-dev --optimize-autoloader
    fi
```

- `--no-dev`: 개발 전용 의존성 제외
- `--optimize-autoloader`: 오토로더 최적화
- `vendor/` 폴더가 ZIP에 포함됨
- `composer.json`, `composer.lock`은 ZIP에서 **제외** (이미 vendor/에 빌드됨)

---

## 8. GitHub Release 자동 생성

```yaml
- name: Create GitHub Release
  uses: softprops/action-gh-release@v1
  with:
    tag_name: v${{ steps.tag_version.outputs.version }}
    files: ${{ steps.create_zip.outputs.zip_file }}
    name: Release v${{ steps.tag_version.outputs.version }}
    body: |
      ## Plugin Name v${{ steps.tag_version.outputs.version }}
      
      ### 설치 방법
      1. **{slug}-{version}.zip** 다운로드
      2. WordPress 관리자 > 플러그인 > 새로 추가 > 플러그인 업로드
      3. ZIP 업로드 후 설치·활성화
      → 설치 경로: `wp-content/plugins/{slug}/`
```

---

## 9. 라이선스 서버 연동 (자동 업데이트)

DW License Manager SDK가 `pre_set_site_transient_update_plugins` 훅으로 자동 업데이트를 처리. 라이선스 서버의 `/releases/update-check` 응답에서 `download_url`이 GitHub Release의 ZIP URL을 반환:

```
GET /releases/update-check?license_key=xxx&product_slug=dw-wholesale&current_version=1.0.5

Response:
{
  "update_available": true,
  "version": "1.0.6",
  "download_url": "https://github.com/.../dw-wholesale-1.0.6.zip"
}
```

→ WordPress가 이 URL에서 ZIP을 다운로드 → 기존 `dw-wholesale/` 폴더를 덮어쓰기

---

## 10. 체크리스트

릴리즈 전 확인사항:

- [ ] 플러그인 헤더 `Version` 업데이트
- [ ] 중앙 설정 `plugin_version` 업데이트
- [ ] 두 버전이 일치하는지 확인
- [ ] `php tests/test-plugin-integrity.php` 통과
- [ ] `git tag v{version}` 생성
- [ ] `git push origin main && git push origin v{version}`
- [ ] GitHub Actions 성공 확인
- [ ] Release에 ZIP 파일 첨부 확인
- [ ] ZIP 내부 폴더명이 `{slug}/`인지 확인 (버전 없이)
- [ ] ZIP 파일명이 `{slug}-{version}.zip`인지 확인

---

## 적용된 프로젝트

| 플러그인 | 저장소 | ZIP 패턴 |
|---------|--------|---------|
| DW Wholesale | `dasomweb/dw-wholesale` | `dw-wholesale-{ver}.zip` → `dw-wholesale/` |

---

*DASOMWEB · DW-MCP · WordPress Plugin Packaging Guide*
