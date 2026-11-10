# DW Wholesale — Release Package

이 ZIP 은 dasomforge 플랫폼 표준 구조입니다 (PLUGIN-DEV-GUIDE §7.2).

- `wp-plugin/`    — 워드프레스 설치 파일. dasomforge SyncRelease 가 `dw-wholesale/`
                    단일 루트로 정규화해 R2 release 버킷에 올립니다.
- `manifest.json` — SyncRelease 가 읽는 통합 매니페스트 (v3 라우팅 트리거).

`runtime/` 디렉터리는 없습니다 — dw-wholesale 는 MIGRATION-PLAN §4.1 합의에 따라
런타임 JS 배포를 적용하지 않는 순수 PHP 플러그인입니다.

## 사용법

이 ZIP 자체를 워드프레스에 직접 업로드하지 마십시오. dasomforge 슈퍼어드민에서
"Sync Release" 를 클릭하면 자동으로 적절한 위치에 배포됩니다.

긴급 수동 설치가 필요하면 `wp-plugin/` 폴더 내용만
`wp-content/plugins/dw-wholesale/` 로 복사하십시오.
