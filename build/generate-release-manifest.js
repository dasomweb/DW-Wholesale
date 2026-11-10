#!/usr/bin/env node
/**
 * 통합 manifest.json 생성 — PLUGIN-DEV-GUIDE §7.3.
 *
 * dasomforge "Sync Release" 가 v3 라우팅으로 판정하는 조건은 두 가지뿐:
 *   1. ZIP 최상위에 manifest.json 존재
 *   2. ZIP 에 wp-plugin/ 디렉터리 존재
 * 둘 중 하나라도 빠지면 legacy 모드로 떨어져 IntegrityManifest 가 만들어지지
 * 않고, 플러그인의 /auth/token 이 INTEGRITY_MANIFEST_NOT_FOUND 로 계속 거절됩니다
 * (TROUBLESHOOTING §1.7 후보 1).
 *
 * 서버가 실제로 읽는 필드는 `version` 뿐. 나머지는 CI·휴먼 참조용입니다.
 *
 * `runtime` 섹션은 **의도적으로 생략** — dw-wholesale 는 MIGRATION-PLAN §4.1
 * 합의에 따라 런타임 JS 배포를 적용하지 않는 순수 PHP 플러그인입니다.
 * 런타임 번들을 도입하면 이 스크립트에 sri 맵을 추가하십시오.
 *
 * 사용:
 *   node build/generate-release-manifest.js \
 *     --version 2.0.0 --stage release/wp-plugin --output release/manifest.json
 */

'use strict';

const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const opt = (name, fallback = null) => {
  const i = argv.indexOf('--' + name);
  return i !== -1 && argv[i + 1] ? argv[i + 1] : fallback;
};

const PLUGIN_ROOT = path.resolve(__dirname, '..');
const STAGE_DIR = path.resolve(opt('stage', path.join(PLUGIN_ROOT, 'release/wp-plugin')));
const OUT_FILE = path.resolve(opt('output', path.join(PLUGIN_ROOT, 'release/manifest.json')));

function readPluginVersion() {
  const src = fs.readFileSync(path.join(PLUGIN_ROOT, 'dw-wholesale.php'), 'utf8');
  const m = src.match(/^\s*\*\s*Version:\s*(.+)$/m);
  if (!m) throw new Error('Version header not found in dw-wholesale.php');
  return m[1].trim();
}

const version = opt('version') || readPluginVersion();

const integrityPath = path.join(STAGE_DIR, 'integrity.json');
if (!fs.existsSync(integrityPath)) {
  console.error(
    `[manifest] ${integrityPath} not found — run generate-integrity.js --stage first`
  );
  process.exit(1);
}

const integrity = JSON.parse(fs.readFileSync(integrityPath, 'utf8'));

// 자기 점검: integrity.json 의 version 과 릴리스 version 이 어긋나면
// /auth/token 의 version 필드가 매니페스트와 안 맞아 즉시 404 가 난다.
if (integrity.version !== version) {
  console.error(
    `[manifest] version mismatch: integrity.json=${integrity.version} release=${version}`
  );
  process.exit(1);
}

// 자기 점검: 매니페스트에 적힌 파일이 스테이지에 실제로 다 있는가.
const missing = integrity.files.filter(
  (f) => !fs.existsSync(path.join(STAGE_DIR, f))
);
if (missing.length) {
  console.error('[manifest] files listed in integrity.json but absent from stage:');
  missing.forEach((f) => console.error('           ' + f));
  process.exit(1);
}

const manifest = {
  version,
  wp_plugin: {
    integrity_files_count: integrity.files.length,
    main_php_file: 'dw-wholesale.php',
  },
  compatibility: {
    min_wp_version: '5.0',
    min_php_version: '7.4',
  },
  release_notes_url: `https://github.com/dasomweb/DW-Wholesale/releases/tag/v${version}`,
};

if (process.env.SOURCE_DATE_EPOCH) {
  manifest.released_at = new Date(
    Number(process.env.SOURCE_DATE_EPOCH) * 1000
  ).toISOString();
}

fs.mkdirSync(path.dirname(OUT_FILE), { recursive: true });
fs.writeFileSync(OUT_FILE, JSON.stringify(manifest, null, 2) + '\n', 'utf8');

console.log(
  `[manifest] ${OUT_FILE} — v${version}, ${integrity.files.length} integrity files, no runtime bundle`
);
