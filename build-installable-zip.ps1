# 깃허브에 푸시하는 플러그인 파일만 담은 설치용 ZIP (GitHub 릴리즈와 동일 구성)
# Run from repo root. Output: dw-wholesale-<version>.zip
# ZIP filename = versioned; ZIP contents = single root folder "dw-wholesale/" so install dir is always wp-content/plugins/dw-wholesale/

$ErrorActionPreference = "Stop"
$config = Get-Content "dw-wholesale.php" -Raw
if ($config -match "plugin_version'\s*=>\s*'([^']+)'") { $ver = $Matches[1] } else { $ver = "1.0.0" }
$buildDir = "build"
$pluginSlug = "dw-wholesale"
$pluginDir = "$buildDir\$pluginSlug"

if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
New-Item -ItemType Directory -Force -Path $pluginDir | Out-Null

# PDF export: install Composer deps so vendor/ is included
if (Test-Path "composer.json") {
  if (Get-Command composer -ErrorAction SilentlyContinue) {
    composer install --no-dev --optimize-autoloader 2>&1 | Out-Null
  }
}

# 푸시한 파일만 복사 (release.yml과 동일)
Copy-Item "dw-wholesale.php" $pluginDir
if (Test-Path "uninstall.php") { Copy-Item "uninstall.php" $pluginDir }
Copy-Item "includes" $pluginDir -Recurse -Force
Copy-Item "assets" $pluginDir -Recurse -Force
if (Test-Path "vendor") { Copy-Item "vendor" $pluginDir -Recurse -Force }

# WordPress용 요약
@"
=== DW Wholesale ===
Contributors: dasomweb
Tags: catalog, custom fields, custom post type
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: $ver
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Product catalog with dynamic custom fields per post type.

== Description ==

DW Wholesale lets you manage product catalogs with dynamic custom fields.
Each post type gets its own admin menu, list page, bulk import, PDF export, and field reference.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Visit Settings > Permalinks to flush rewrite rules.

== Changelog ==

= $ver =
* See GitHub Releases for details.
"@ | Set-Content "$pluginDir\README.txt" -Encoding UTF8

# ZIP filename = versioned (dw-wholesale-1.5.6.zip). Contents = single root folder "dw-wholesale/" so WordPress always installs to wp-content/plugins/dw-wholesale/
$zipName = "$pluginSlug-$ver.zip"
$zipPath = (Join-Path (Get-Location) $zipName)
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Compress-Archive -Path "$buildDir\$pluginSlug" -DestinationPath $zipPath -Force
Remove-Item $buildDir -Recurse -Force

Write-Host "Created: $zipName (version $ver)" -ForegroundColor Green
Write-Host "Upload this file in WordPress: Plugins > Add New > Upload Plugin"
Write-Host "Install folder will always be: wp-content/plugins/dw-wholesale/"
