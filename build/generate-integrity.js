#!/usr/bin/env node
/**
 * integrity.json 생성 — PLUGIN-DEV-GUIDE §7.2.2 규약 1 (루트상대 키).
 *
 * ── 왜 "스테이징된 wp-plugin/" 을 대상으로 도는가 ──
 * 서버(SyncRelease v3)는 플러그인의 integrity.json 목록을 **참조하지 않고**
 * ZIP 의 `wp-plugin/` 하위 *모든 파일*을 재귀적으로 해시합니다
 * (integrity.json 자기 자신 1건만 제외).
 *
 *   → 플러그인이 보내는 file_hashes 가 서버가 가진 키 집합의 부분집합이면
 *     MANIFEST_INCOMPLETE(missing_files), 다르면 TAMPER_DETECTED.
 *
 * 소스트리를 걷고 제외 패턴을 손으로 맞추면 ZIP 실물과 반드시 어긋납니다
 * (rsync --exclude 와 EXCLUDED_PATTERNS 가 드리프트). 그래서 CI 는
 * rsync 로 release/wp-plugin/ 을 먼저 만든 뒤 --stage 로 이 스크립트를 돌려
 * **ZIP 에 들어갈 실물 그 자체**를 목록화합니다.
 *
 * 사용:
 *   node build/generate-integrity.js --stage --dir release/wp-plugin --version 2.0.0
 *   node build/generate-integrity.js                    # 로컬 개발: 플러그인 루트
 *
 * 결정론: 파일 목록은 정렬. generated_at 은 SOURCE_DATE_EPOCH 가 있을 때만 기록
 * (§11.2.b — 같은 소스 → 같은 ZIP SHA256 을 깨지 않기 위해).
 */

'use strict';

const fs = require('fs');
const path = require('path');

// ── 인자 파싱 ───────────────────────────────────────────────
const argv = process.argv.slice(2);
const flag = (name) => argv.includes('--' + name);
const opt = (name, fallback = null) => {
  const i = argv.indexOf('--' + name);
  return i !== -1 && argv[i + 1] ? argv[i + 1] : fallback;
};

const PLUGIN_ROOT = path.resolve(__dirname, '..');
const STAGE_MODE = flag('stage');
const TARGET_DIR = path.resolve(opt('dir', PLUGIN_ROOT));
const OUT_FILE = path.resolve(opt('output', path.join(TARGET_DIR, 'integrity.json')));

/**
 * 로컬 개발 모드에서만 쓰는 제외 목록.
 * --stage 모드에서는 rsync 가 이미 걸러 냈으므로 integrity.json 만 제외한다.
 */
const DEV_EXCLUDES = [
  /^\.git(\/|$)/,
  /^\.github(\/|$)/,
  /^\.claude(\/|$)/,
  /^node_modules(\/|$)/,
  /^build(\/|$)/,
  /^tests(\/|$)/,
  /^docs(\/|$)/,
  /^release(\/|$)/,
  /^temp_plugin(\/|$)/,
  /\.md$/,
  /^composer\.(json|lock)$/,
  /^create-release\.sh$/,
  /^build-installable-zip\.ps1$/,
  /^verify-domain-agnostic\.php$/,
];

/**
 * 서버도 제외하는 유일한 항목 (§7.2.2). 이것만 매니페스트에서 뺀다.
 *
 * ⚠ 다른 어떤 파일도 "매니페스트에서만 빼는" 처리를 하면 안 됩니다.
 *   서버는 ZIP 의 wp-plugin/ 하위를 전수 해시하므로, 매니페스트에서만 빠진
 *   파일은 곧바로 MANIFEST_INCOMPLETE(missing_files) 가 됩니다.
 *   빼야 할 파일이면 **스테이지에서** 빼십시오 (rsync --exclude).
 */
const SERVER_EXCLUDE = /^integrity\.json$/;

/**
 * ZIP 에 절대 실리면 안 되는 것들. stage 모드에서 발견되면 조용히 건너뛰지 않고
 * **빌드를 실패**시킵니다 — 패키징 제외 목록을 고치라는 신호입니다.
 */
const HAZARDS = [
  /(^|\/)\.DS_Store$/,
  /(^|\/)Thumbs\.db$/,
  /:Zone\.Identifier$/,          // Windows 다운로드 스트림 (CASE-STUDIES §4)
  /\.swp$/,
  /(^|\/)wp-config\.php$/,
];

const hazardsFound = [];

function isExcluded(rel) {
  if (SERVER_EXCLUDE.test(rel)) return true;

  if (HAZARDS.some((re) => re.test(rel))) {
    if (STAGE_MODE) {
      hazardsFound.push(rel);
      return false; // 목록에는 넣지 않고 아래에서 빌드를 중단시킨다
    }
    return true; // dev 모드에서는 편의상 무시
  }

  if (!STAGE_MODE && DEV_EXCLUDES.some((re) => re.test(rel))) return true;
  return false;
}

function walk(dir, base = '') {
  const out = [];
  const entries = fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) =>
    a.name < b.name ? -1 : a.name > b.name ? 1 : 0
  );

  for (const entry of entries) {
    const rel = base ? base + '/' + entry.name : entry.name;
    if (isExcluded(rel)) continue;

    if (entry.isDirectory()) {
      out.push(...walk(path.join(dir, entry.name), rel));
    } else if (entry.isFile()) {
      out.push(rel);
    }
    // 심볼릭 링크는 의도적으로 제외 — ZIP 에 들어가는 실물과 해석이 갈린다.
  }
  return out;
}

// ── 버전 결정 ───────────────────────────────────────────────
function readPluginVersion() {
  const main = path.join(PLUGIN_ROOT, 'dw-wholesale.php');
  const src = fs.readFileSync(main, 'utf8');
  const m = src.match(/^\s*\*\s*Version:\s*(.+)$/m);
  if (!m) throw new Error('Version header not found in dw-wholesale.php');
  return m[1].trim();
}

const version = opt('version') || readPluginVersion();

// ── 생성 ────────────────────────────────────────────────────
if (!fs.existsSync(TARGET_DIR)) {
  console.error(`[integrity] target dir not found: ${TARGET_DIR}`);
  process.exit(1);
}

const files = walk(TARGET_DIR).sort();

if (hazardsFound.length) {
  console.error(
    '[integrity] hazardous files present in the release stage — fix the packaging excludes, not the manifest:'
  );
  hazardsFound.forEach((f) => console.error('           ' + f));
  process.exit(1);
}

if (files.length === 0) {
  console.error('[integrity] refusing to write an empty manifest');
  process.exit(1);
}

const manifest = { version, files };

// 재현 가능한 빌드에서만 타임스탬프를 넣는다.
if (process.env.SOURCE_DATE_EPOCH) {
  manifest.generated_at = new Date(
    Number(process.env.SOURCE_DATE_EPOCH) * 1000
  ).toISOString();
}

fs.writeFileSync(OUT_FILE, JSON.stringify(manifest, null, 2) + '\n', 'utf8');

console.log(
  `[integrity] ${path.relative(PLUGIN_ROOT, OUT_FILE) || OUT_FILE} — v${version}, ${files.length} files (${STAGE_MODE ? 'stage' : 'dev'} mode)`
);

// 눈으로 훑을 수 있게 상위 디렉터리별 집계.
const byTop = files.reduce((acc, f) => {
  const top = f.includes('/') ? f.split('/')[0] + '/' : '(root)';
  acc[top] = (acc[top] || 0) + 1;
  return acc;
}, {});
for (const [k, v] of Object.entries(byTop).sort()) {
  console.log(`           ${String(v).padStart(5)}  ${k}`);
}
