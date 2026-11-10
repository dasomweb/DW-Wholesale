# GitHub 배포 가이드 (GitHub Deployment Guide)

## 개요 (Overview)

이 가이드는 DW Wholesale 플러그인을 GitHub을 통해 배포하는 방법을 설명합니다.

This guide explains how to deploy the DW Wholesale plugin through GitHub.

## 🚀 빠른 시작 (Quick Start)

### 1단계: 버전 업데이트

플러그인 파일에서 버전 번호를 업데이트합니다:

**파일:** `dw-wholesale.php`

```php
// 플러그인 헤더
* Version: 1.0.1

// pc_get_plugin_config() 함수 내
'plugin_version' => '1.0.1',
```

### 2단계: Git 태그 생성 및 푸시

```bash
# 변경사항 커밋
git add dw-wholesale.php
git commit -m "Bump version to 1.0.1"

# 태그 생성 및 푸시
git tag v1.0.1
git push origin main
git push origin v1.0.1
```

### 3단계: 자동 릴리스

GitHub Actions가 자동으로:
- ✅ 플러그인 ZIP 파일 생성
- ✅ GitHub Release 생성
- ✅ 릴리스 자산으로 ZIP 파일 첨부

## 📋 상세 배포 절차 (Detailed Deployment Process)

### 방법 A: 태그 기반 자동 배포 (권장)

1. **로컬에서 버전 업데이트**
   ```bash
   # 버전 번호 수정 (dw-wholesale.php)
   # Version number update (dw-wholesale.php)
   ```

2. **커밋 및 푸시**
   ```bash
   git add dw-wholesale.php
   git commit -m "Release version 1.0.1"
   git push origin main
   ```

3. **태그 생성 및 푸시**
   ```bash
   git tag v1.0.1
   git push origin v1.0.1
   ```

4. **GitHub Actions 확인**
   - GitHub 저장소 > **Actions** 탭
   - `Create Release` 워크플로우 실행 확인
   - 완료 후 **Releases** 탭에서 확인

### 방법 B: GitHub Actions 워크플로우 사용

1. GitHub 저장소에서 **Actions** 탭으로 이동
2. 왼쪽 사이드바에서 **Update Plugin Version** 선택
3. **Run workflow** 버튼 클릭
4. 새 버전 번호 입력 (예: `1.0.1`)
5. **Run workflow** 클릭

워크플로우가 자동으로:
- 플러그인 파일의 버전 업데이트
- 변경사항 커밋 및 푸시
- 버전 태그 생성
- 릴리스 생성

### 방법 C: 수동 릴리스

1. **플러그인 ZIP 파일 생성**
   ```bash
   # 플러그인 디렉토리에서
   zip -r dw-wholesale-1.0.1.zip . \
     -x "*.git*" \
     -x "*.github*" \
     -x "*.DS_Store" \
     -x "*.log" \
     -x "node_modules/*" \
     -x ".gitignore" \
     -x "README.md" \
     -x "DOMAIN-CHANGE-GUIDE.md" \
     -x "IMPLEMENTATION-SUMMARY.md" \
     -x "DEPLOYMENT-GUIDE.md" \
     -x "verify-domain-agnostic.php"
   ```

2. **GitHub에서 릴리스 생성**
   - 저장소 > **Releases** > **Draft a new release**
   - 태그 선택 또는 생성 (예: `v1.0.1`)
   - 릴리스 제목: `Release v1.0.1`
   - 릴리스 설명 작성
   - ZIP 파일 드래그 앤 드롭
   - **Publish release** 클릭

## 🔍 릴리스 확인 (Verify Release)

릴리스가 성공적으로 생성되었는지 확인:

1. **GitHub Releases 페이지**
   - https://github.com/dasomweb/DW-Wholesale/releases
   - 최신 릴리스 확인
   - ZIP 파일 다운로드 가능 여부 확인

2. **플러그인 업데이트 테스트**
   - WordPress 사이트에 플러그인 설치
   - 관리자 > 플러그인 페이지에서 업데이트 알림 확인
   - 업데이트 실행 및 정상 작동 확인

## 📦 ZIP 파일 구조 (ZIP File Structure)

생성된 ZIP 파일에는 다음이 포함됩니다:

```
dw-wholesale-1.0.1.zip
├── dw-wholesale.php          # 메인 플러그인 파일
├── includes/
│   ├── class-pc-github-updater.php
│   └── class-pc-url-helper.php
└── (기타 플러그인 파일들)
```

**제외되는 항목:**
- `.git/` 폴더
- `.github/` 폴더 (워크플로우 파일)
- 문서 파일 (README.md, 가이드 파일들)
- 검증 스크립트

## 🔄 업데이트 프로세스 (Update Process)

### 사용자 관점

1. WordPress 관리자 > **플러그인** 페이지
2. 업데이트 알림 확인
3. **지금 업데이트** 클릭
4. 자동으로 GitHub에서 최신 버전 다운로드 및 설치

### 개발자 관점

1. 코드 변경 및 테스트
2. 버전 번호 업데이트
3. 태그 생성 및 푸시
4. GitHub Actions가 자동으로 릴리스 생성
5. 사용자는 WordPress에서 자동으로 업데이트 확인

## ⚙️ GitHub Actions 워크플로우

### release.yml

**트리거:** `v*` 태그가 푸시될 때

**작업:**
1. 코드 체크아웃
2. 버전 추출
3. 플러그인 ZIP 파일 생성
4. GitHub Release 생성 및 ZIP 파일 첨부

### update-version.yml

**트리거:** 수동 실행 (workflow_dispatch)

**작업:**
1. 버전 번호 입력 받기
2. 플러그인 파일의 버전 업데이트
3. 변경사항 커밋 및 푸시
4. 버전 태그 생성 및 푸시

## 🐛 문제 해결 (Troubleshooting)

### 릴리스가 생성되지 않음

1. **태그 형식 확인**
   - 올바른 형식: `v1.0.0`, `v1.2.3`
   - 잘못된 형식: `1.0.0`, `version-1.0.0`

2. **GitHub Actions 로그 확인**
   - Actions 탭에서 워크플로우 실행 로그 확인
   - 에러 메시지 확인

3. **권한 확인**
   - GitHub Actions에 `contents: write` 권한이 있는지 확인

### ZIP 파일이 생성되지 않음

1. **워크플로우 로그 확인**
   - ZIP 생성 단계에서 에러 확인

2. **파일 구조 확인**
   - 플러그인 파일이 올바른 위치에 있는지 확인

### 업데이트가 작동하지 않음

1. **플러그인 설정 확인**
   - `pc_get_plugin_config()` 함수의 GitHub 정보 확인
   - 저장소 이름과 소유자 확인

2. **GitHub API 접근 확인**
   - 저장소가 Public인지 확인
   - 또는 GitHub Token 설정 필요 (Private 저장소의 경우)

3. **캐시 확인**
   - WordPress 업데이트 캐시 삭제
   - 브라우저 캐시 삭제

## 🔐 Private 저장소 지원 (Private Repository Support)

Private 저장소의 경우 GitHub Personal Access Token이 필요합니다:

1. **GitHub Token 생성**
   - GitHub Settings > Developer settings > Personal access tokens
   - `repo` 권한 부여

2. **WordPress에서 설정**
   - 플러그인에 토큰 설정 기능 추가 (선택사항)
   - 또는 환경 변수로 설정

## 📝 릴리스 노트 템플릿 (Release Notes Template)

```markdown
## DW Wholesale v1.0.1

### 새로운 기능 (New Features)
- 기능 1
- 기능 2

### 개선사항 (Improvements)
- 개선 1
- 개선 2

### 버그 수정 (Bug Fixes)
- 수정 1
- 수정 2

### 설치 방법 (Installation)
1. WordPress 관리자 > 플러그인 > 새로 추가
2. 플러그인 업로드 클릭
3. 아래 ZIP 파일 업로드

### 업데이트 방법 (Update)
플러그인이 자동으로 GitHub에서 업데이트를 확인합니다.
WordPress 관리자 > 플러그인 페이지에서 업데이트 알림을 확인하세요.
```

## ✅ 배포 체크리스트 (Deployment Checklist)

릴리스 전 확인:

- [ ] 코드 테스트 완료
- [ ] 버전 번호 업데이트 (플러그인 헤더 및 설정 함수)
- [ ] 변경사항 문서화
- [ ] 도메인 독립성 검증 (`verify-domain-agnostic.php` 실행)
- [ ] 하드코딩된 URL 확인
- [ ] Git 커밋 및 푸시
- [ ] 태그 생성 및 푸시
- [ ] GitHub Actions 실행 확인
- [ ] 릴리스 생성 확인
- [ ] ZIP 파일 다운로드 테스트
- [ ] WordPress에서 업데이트 테스트

---

**참고:** 이 가이드는 GitHub을 통한 배포를 위한 것입니다. WordPress.org 플러그인 디렉토리에 제출하는 경우 다른 절차가 필요합니다.


