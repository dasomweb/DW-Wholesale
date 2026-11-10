#!/usr/bin/env node
/**
 * 릴리스 ZIP 자가 검증 — 서버가 실제로 하는 판정을 빌드 시점에 재현.
 *
 * 검사하는 불변식 (PLUGIN-DEV-GUIDE §7.2.2 / §7.3):
 *
 *  1. ZIP **최상위**에 manifest.json 이 있는가          → 없으면 legacy fallback
 *  2. wp-plugin/ 프리픽스 엔트리가 있는가               → 없으면 legacy fallback
 *  3. manifest.json.version == integrity.json.version  → 어긋나면 매니페스트 미스
 *  4. 서버가 해시할 집합 == 플러그인이 보낼 집합
 *
 * 4번이 이 스크립트의 존재 이유입니다. 서버는 integrity.json 목록을 무시하고
 * `wp-plugin/` 하위 전체를 해시하는 반면 (integrity.json 자기 자신만 제외),
 * 플러그인은 integrity.json 목록만 보내므로 —
 *
 *    서버에만 있는 키  → MANIFEST_INCOMPLETE (missing_files)
 *    플러그인에만 있는 키 → 해시 계산 불가 / mismatch
 *
 * 두 집합이 정확히 같아야 /auth/token 이 통과합니다. 릴리스 후에 알면
 * 이미 늦습니다 (재릴리스 + 재SyncRelease).
 *
 * 사용: node build/verify-package.js dw-wholesale-2.0.0.zip
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const zipPath = process.argv[2];
if (!zipPath) {
  console.error('usage: node build/verify-package.js <release.zip>');
  process.exit(2);
}

const PREFIX = 'wp-plugin/';
let failures = 0;

function fail(msg) {
  console.error(`  ✗ ${msg}`);
  failures++;
}
function ok(msg) {
  console.log(`  ✓ ${msg}`);
}

// ── ZIP 엔트리 목록 (unzip -Z1 은 디렉터리 엔트리를 / 로 끝내 표시) ──
let entries;
try {
  entries = execFileSync('unzip', ['-Z1', zipPath], { encoding: 'utf8' })
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean);
} catch (e) {
  console.error(`cannot read ${zipPath}: ${e.message}`);
  process.exit(2);
}

const files = entries.filter((e) => !e.endsWith('/'));

console.log(`\n=== verify-package: ${path.basename(zipPath)} ===`);
console.log(`entries: ${entries.length} (${files.length} files)\n`);

// ── 1. v3 라우팅 트리거 ────────────────────────────────────────
console.log('--- v3 routing triggers (§7.3) ---');

if (files.includes('manifest.json')) {
  ok('manifest.json at ZIP top level');
} else {
  const nested = files.find((f) => f.endsWith('/manifest.json'));
  fail(
    nested
      ? `manifest.json is nested at "${nested}" — must be at ZIP top level, exact entryName "manifest.json"`
      : 'manifest.json missing — SyncRelease will fall back to legacy mode'
  );
}

const pluginFiles = files.filter((f) => f.startsWith(PREFIX));
if (pluginFiles.length > 0) {
  ok(`wp-plugin/ present (${pluginFiles.length} files)`);
} else {
  fail('no wp-plugin/ entries — SyncRelease will fall back to legacy mode');
}

// 여기서 더 못 가면 나머지 검사가 무의미
if (failures > 0) {
  console.error('\nFATAL: v3 layout is broken. Aborting further checks.\n');
  process.exit(1);
}

function read(entry) {
  return execFileSync('unzip', ['-p', zipPath, entry], {
    maxBuffer: 64 * 1024 * 1024,
  });
}

const manifest = JSON.parse(read('manifest.json').toString('utf8'));

// ── 2. integrity.json ──────────────────────────────────────────
console.log('\n--- integrity manifest (§7.2.2) ---');

const integrityEntry = PREFIX + 'integrity.json';
if (!files.includes(integrityEntry)) {
  fail(`${integrityEntry} missing — /auth/token cannot compute file_hashes`);
  console.error('');
  process.exit(1);
}
ok('wp-plugin/integrity.json present');

const integrity = JSON.parse(read(integrityEntry).toString('utf8'));

if (manifest.version === integrity.version) {
  ok(`version agreement: ${manifest.version}`);
} else {
  fail(
    `version mismatch — manifest.json=${manifest.version} integrity.json=${integrity.version}`
  );
}

// ── 3. 핵심: 서버 집합 == 플러그인 집합 ────────────────────────
console.log('\n--- hash-set agreement (server vs plugin) ---');

// 서버: wp-plugin/ 하위 전체에서 integrity.json 자기 자신만 제외.
const serverKeys = new Set(
  pluginFiles
    .map((f) => f.slice(PREFIX.length))
    .filter((k) => k !== 'integrity.json')
);

// 플러그인: integrity.json 의 files 를 그대로 (규약 1 · 루트상대).
const pluginKeys = new Set(integrity.files);

const onlyServer = [...serverKeys].filter((k) => !pluginKeys.has(k)).sort();
const onlyPlugin = [...pluginKeys].filter((k) => !serverKeys.has(k)).sort();

if (onlyServer.length === 0 && onlyPlugin.length === 0) {
  ok(`${serverKeys.size} keys agree exactly`);
} else {
  if (onlyServer.length) {
    fail(
      `${onlyServer.length} file(s) in ZIP but NOT in integrity.json → MANIFEST_INCOMPLETE (missing_files):`
    );
    onlyServer.slice(0, 20).forEach((k) => console.error(`      + ${k}`));
    if (onlyServer.length > 20) console.error(`      … +${onlyServer.length - 20} more`);
  }
  if (onlyPlugin.length) {
    fail(
      `${onlyPlugin.length} file(s) in integrity.json but NOT in ZIP → hash computation will silently skip them:`
    );
    onlyPlugin.slice(0, 20).forEach((k) => console.error(`      - ${k}`));
    if (onlyPlugin.length > 20) console.error(`      … +${onlyPlugin.length - 20} more`);
  }
}

// 매니페스트가 신고한 개수와 실제가 맞는지 (CI 자기 점검)
if (
  manifest.wp_plugin &&
  manifest.wp_plugin.integrity_files_count !== integrity.files.length
) {
  fail(
    `manifest.wp_plugin.integrity_files_count=${manifest.wp_plugin.integrity_files_count} but integrity.json lists ${integrity.files.length}`
  );
}

// ── 4. 위생 검사 ───────────────────────────────────────────────
console.log('\n--- hygiene ---');

const forbidden = [
  { re: /(^|\/)wp-config\.php$/, why: 'dev bypass would leak to production (FAQ Q7)' },
  { re: /:Zone\.Identifier$/, why: 'breaks integrity on fresh install (CASE-STUDIES §4)' },
  { re: /(^|\/)\.git\//, why: 'repository metadata' },
  { re: /(^|\/)\.DS_Store$/, why: 'macOS metadata' },
  { re: /(^|\/)Thumbs\.db$/, why: 'Windows metadata' },
  { re: /(^|\/)node_modules\//, why: 'dev dependencies' },
  // vendor/*/composer.json 은 정상 배포 파일 — 플러그인 **루트**에서만 금지
  { re: /^wp-plugin\/composer\.(json|lock)$/, why: 'build-only file at plugin root' },
];

let dirty = false;
for (const { re, why } of forbidden) {
  const hits = files.filter((f) => re.test(f));
  if (hits.length) {
    dirty = true;
    fail(`${hits.length} forbidden entr(y|ies) matching ${re} — ${why}`);
    hits.slice(0, 5).forEach((h) => console.error(`      ${h}`));
  }
}
if (!dirty) ok('no forbidden entries');

const pubKey = PREFIX + 'includes/keys/dasomforge.pub';
if (files.includes(pubKey)) {
  const pem = read(pubKey).toString('utf8');
  if (pem.includes('BEGIN PUBLIC KEY')) {
    ok('dasomforge.pub is a PEM public key');
  } else if (pem.includes('PRIVATE KEY')) {
    fail('dasomforge.pub contains a PRIVATE KEY — abort release immediately');
  } else {
    fail('dasomforge.pub is not a recognisable PEM public key');
  }
} else {
  fail(`${pubKey} missing — signature verification impossible`);
}

// runtime/ 는 이 플러그인에 없어야 정상 (MIGRATION-PLAN §4.1)
if (files.some((f) => f.startsWith('runtime/'))) {
  console.log('  · runtime/ present — ensure MIGRATION-PLAN §4.1 was updated');
} else {
  ok('no runtime/ bundle (pure PHP plugin, as agreed)');
}

// ── 결과 ───────────────────────────────────────────────────────
console.log(
  `\nsha256(${path.basename(zipPath)}) = ${crypto
    .createHash('sha256')
    .update(fs.readFileSync(zipPath))
    .digest('hex')}`
);

if (failures > 0) {
  console.error(`\n${failures} CHECK(S) FAILED — do not publish this release.\n`);
  process.exit(1);
}
console.log('\nALL CHECKS PASSED\n');
