#!/usr/bin/env bash
# Builds the JavaScript (AMD modules) and packs the plugin as installable ZIP:
#   dist/mod_mpl-<release>.zip  →  Moodle: Website-Administration › Plugins › Plugin installieren
set -euo pipefail
cd "$(dirname "$0")"
[[ -d node_modules ]] || npm install --silent
npx rollup -c
cd ..
release=$(grep -oP "release\s*=\s*'\K[^']+" mpl/version.php)
mkdir -p dist
rm -f "dist/mod_mpl-$release.zip"
zip -qr "dist/mod_mpl-$release.zip" mpl -x '*.DS_Store'
echo "dist/mod_mpl-$release.zip"
