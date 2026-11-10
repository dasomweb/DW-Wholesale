# GitHub 배포 및 업데이트 가이드

## ✅ 현재 상태

플러그인은 이미 GitHub을 통한 배포 및 자동 업데이트 시스템이 구현되어 있습니다.

## 🔄 자동 업데이트 작동 방식

### 1. 플러그인 설치 후
- 플러그인이 자동으로 GitHub API를 통해 최신 릴리스를 확인합니다
- 12시간마다 업데이트를 체크합니다
- 새 버전이 있으면 WordPress 관리자 > 플러그인 페이지에 업데이트 알림이 표시됩니다

### 2. 업데이트 프로세스
1. WordPress 관리자 > **플러그인** 페이지
2. **DW Wholesale** 플러그인에 "새 버전 사용 가능" 알림 표시
3. **지금 업데이트** 버튼 클릭
4. 자동으로 GitHub에서 최신 버전 다운로드 및 설치
5. 플러그인 자동 재활성화

## 📦 릴리스 생성 방법

### 방법 1: 태그 기반 자동 릴리스 (권장)

```bash
# 1. 버전 번호 업데이트 (dw-wholesale.php)
# Version: 1.3.1 → 1.3.2
# 'plugin_version' => '1.3.1' → '1.3.2'

# 2. 변경사항 커밋 및 푸시
git add dw-wholesale.php
git commit -m "Update version to 1.3.2"
git push origin main

# 3. 태그 생성 및 푸시
git tag v1.3.2
git push origin v1.3.2
```

**GitHub Actions가 자동으로:**
- ✅ 플러그인 ZIP 파일 생성
- ✅ GitHub Release 생성
- ✅ ZIP 파일을 릴리스에 첨부
- ✅ 사용자는 WordPress에서 자동으로 업데이트 확인 가능

### 방법 2: GitHub Actions 워크플로우 사용

1. GitHub 저장소 > **Actions** 탭
2. **Update Plugin Version** 워크플로우 선택
3. **Run workflow** 클릭
4. 새 버전 번호 입력 (예: `1.3.2`)
5. 워크플로우가 자동으로:
   - 버전 번호 업데이트
   - 커밋 및 푸시
   - 태그 생성
   - 릴리스 생성

## 🔍 업데이트 확인

### 수동으로 업데이트 확인하기

WordPress 관리자에서:
1. **플러그인** > **설치된 플러그인**
2. **DW Wholesale** 옆의 **업데이트 확인** 클릭
3. 또는 페이지 새로고침

### 업데이트 캐시 초기화

업데이트가 즉시 표시되지 않으면:

```php
// WordPress functions.php 또는 플러그인에 추가
delete_transient( 'pc_github_latest_release_' . md5( 'dasomweb' . 'dw-wholesale' ) );
```

또는 WordPress 관리자에서:
- **플러그인** 페이지 새로고침
- 또는 **업데이트** 페이지 방문

## ⚙️ 설정 확인

플러그인 설정이 올바른지 확인:

**파일:** `dw-wholesale.php`

```php
function pc_get_plugin_config() {
    return array(
        'github_repo_owner' => 'dasomweb',        // ✅ 확인
        'github_repo_name'  => 'dw-wholesale', // ✅ 확인
        'plugin_slug'       => 'dw-wholesale', // ✅ 확인
        'plugin_version'    => '1.3.1',            // 릴리스마다 업데이트 필요
        // ...
    );
}
```

## 🐛 문제 해결

### 업데이트가 표시되지 않음

1. **버전 번호 확인**
   - 플러그인 파일의 버전이 GitHub 릴리스 버전보다 낮은지 확인
   - 버전 형식: `1.3.1` (숫자.숫자.숫자)

2. **GitHub 릴리스 확인**
   - https://github.com/dasomweb/DW-Wholesale/releases
   - 최신 릴리스에 ZIP 파일이 첨부되어 있는지 확인

3. **캐시 확인**
   - WordPress 업데이트 캐시는 12시간마다 갱신됩니다
   - 수동으로 캐시 삭제 (위 참조)

4. **GitHub API 접근 확인**
   - 저장소가 Public인지 확인
   - 또는 GitHub Personal Access Token 설정 (Private 저장소의 경우)

### 업데이트 설치 실패

1. **파일 권한 확인**
   - WordPress 플러그인 디렉토리에 쓰기 권한이 있는지 확인

2. **ZIP 파일 확인**
   - GitHub 릴리스의 ZIP 파일이 올바르게 생성되었는지 확인
   - ZIP 파일을 다운로드하여 수동으로 설치 시도

3. **에러 로그 확인**
   - WordPress 디버그 로그 확인
   - `WP_DEBUG` 활성화하여 에러 메시지 확인

## 📋 릴리스 체크리스트

릴리스 전 확인:

- [ ] 코드 테스트 완료
- [ ] 버전 번호 업데이트 (플러그인 헤더 및 설정 함수)
- [ ] 변경사항 문서화
- [ ] Git 커밋 및 푸시
- [ ] 태그 생성 및 푸시 (`v1.3.2` 형식)
- [ ] GitHub Actions 실행 확인
- [ ] 릴리스 생성 확인
- [ ] ZIP 파일 다운로드 테스트
- [ ] WordPress에서 업데이트 테스트

## 🎯 빠른 시작

### 첫 릴리스 생성

```bash
# 버전 업데이트 후
git tag v1.0.0
git push origin v1.0.0
```

### 업데이트 릴리스 생성

```bash
# 버전 업데이트 후
git tag v1.0.1
git push origin v1.0.1
```

## 📚 관련 문서

- [README.md](README.md) - 전체 가이드
- [DEPLOYMENT-GUIDE.md](DEPLOYMENT-GUIDE.md) - 상세 배포 가이드
- [DOMAIN-CHANGE-GUIDE.md](DOMAIN-CHANGE-GUIDE.md) - 도메인 변경 가이드

---

**✅ GitHub 배포 및 자동 업데이트 시스템이 완전히 구현되어 있습니다!**

플러그인을 설치하면 자동으로 GitHub에서 업데이트를 확인하고 설치할 수 있습니다.


