#!/usr/bin/env bash
#
# dasomforge 표준 릴리스 패키징 (PLUGIN-DEV-GUIDE §7.2 / §7.5)
#
# 결정론이 이 스크립트의 최우선 요구사항입니다 (§11.2.b):
#   같은 소스 + 같은 SOURCE_DATE_EPOCH  →  바이트 동일한 ZIP.
# 이게 깨지면 신선한 설치에서도 TAMPER_DETECTED 가 납니다.
#
# 사용: bash .github/scripts/package-release.sh <output.zip>
# 필요 환경변수: VERSION, SOURCE_DATE_EPOCH

set -euo pipefail

OUT_ZIP="${1:?usage: package-release.sh <output.zip>}"
: "${VERSION:?VERSION is required}"
: "${SOURCE_DATE_EPOCH:?SOURCE_DATE_EPOCH is required (build determinism)}"

export TZ=UTC
export LC_ALL=C

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

STAGE="release"
PLUGIN_DIR="$STAGE/wp-plugin"

rm -rf "$STAGE" "$OUT_ZIP"
mkdir -p "$PLUGIN_DIR"

# ─── 1. wp-plugin/ 스테이징 ────────────────────────────────────────
# 여기서 제외한 것은 integrity.json 에도 안 들어가고 ZIP 에도 안 들어갑니다.
# 서버는 wp-plugin/ 안의 *모든* 파일을 해시하므로 (§7.2.2),
# 이 목록과 integrity.json 이 어긋날 여지 자체가 없어야 합니다
# → generate-integrity.js 를 **이 스테이지 결과물** 위에서 돌리는 이유.
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.claude' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='node_modules' \
  --exclude='/build' \
  --exclude='/tests' \
  --exclude='/docs' \
  --exclude='/release' \
  --exclude='/temp_plugin' \
  --exclude='/composer.json' \
  --exclude='/composer.lock' \
  --exclude='/create-release.sh' \
  --exclude='/build-installable-zip.ps1' \
  --exclude='/verify-domain-agnostic.php' \
  --exclude='/integrity.json' \
  --exclude='/*.md' \
  --exclude='*.zip' \
  --exclude='*.log' \
  --exclude='.DS_Store' \
  --exclude='Thumbs.db' \
  --exclude='*:Zone.Identifier' \
  --exclude='*.swp' \
  --exclude='.idea' \
  --exclude='.vscode' \
  ./ "$PLUGIN_DIR/"

# ─── 2. WordPress readme.txt ───────────────────────────────────────
# integrity 생성 **전에** 써야 매니페스트에 포함됩니다.
cat > "$PLUGIN_DIR/README.txt" <<READMEEOF
=== DW Wholesale ===
Contributors: dasomweb
Tags: catalog, custom fields, custom post type
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: ${VERSION}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Product catalog with dynamic custom fields per post type.

== Description ==

DW Wholesale lets you manage product catalogs with dynamic custom fields.
Each post type gets its own admin menu, list page, bulk import, PDF export,
and field reference.

This plugin requires an active DASOM Forge license to display catalogs on the
front end. Enter your license key under DW Wholesale > License after activation.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Enter your license key under DW Wholesale > License.
4. Visit Settings > Permalinks to flush rewrite rules.

== Changelog ==

= ${VERSION} =
* See GitHub Releases for details.
READMEEOF

# ─── 3. 안전 점검 ──────────────────────────────────────────────────
# wp-config.php 가 섞여 들어가면 dev bypass 가 프로덕션에 새어 나갑니다 (FAQ Q7).
if find "$PLUGIN_DIR" -name 'wp-config.php' -print -quit | grep -q .; then
  echo "::error::wp-config.php found in release payload — refusing to package"
  exit 1
fi
if find "$PLUGIN_DIR" -name '*:Zone.Identifier' -print -quit | grep -q .; then
  echo "::error::Zone.Identifier stream found — would break integrity on fresh install"
  exit 1
fi
if [ ! -f "$PLUGIN_DIR/includes/keys/dasomforge.pub" ]; then
  echo "::error::includes/keys/dasomforge.pub missing — signature verification would fail"
  exit 1
fi
if ! grep -q 'BEGIN PUBLIC KEY' "$PLUGIN_DIR/includes/keys/dasomforge.pub"; then
  echo "::error::dasomforge.pub is not a PEM public key"
  exit 1
fi

# ─── 4. 매니페스트 생성 ────────────────────────────────────────────
node build/generate-integrity.js \
  --stage --dir "$PLUGIN_DIR" --version "$VERSION"

node build/generate-release-manifest.js \
  --version "$VERSION" --stage "$PLUGIN_DIR" --output "$STAGE/manifest.json"

cp build/release-readme.md "$STAGE/README.md"

# ─── 5. 권한·타임스탬프 정규화 ─────────────────────────────────────
# umask·체크아웃 순서에 따라 흔들리는 값을 전부 고정합니다.
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
find "$STAGE" -exec touch -h -d "@${SOURCE_DATE_EPOCH}" {} +

# ─── 6. 결정론적 ZIP ───────────────────────────────────────────────
# -X       : uid/gid/확장 타임스탬프 등 플랫폼 extra field 제거
# sort     : 엔트리 순서 고정 (find 의 디렉터리 순회 순서는 비결정적)
# mindepth : 최상위 '.' 엔트리 제외 → manifest.json 이 ZIP 루트에 오게
(
  cd "$STAGE"
  find . -mindepth 1 | LC_ALL=C sort | zip -X -q "../${OUT_ZIP}" -@
)

echo "[package] ${OUT_ZIP}"
echo "[package] sha256: $(sha256sum "$OUT_ZIP" | cut -d' ' -f1)"
