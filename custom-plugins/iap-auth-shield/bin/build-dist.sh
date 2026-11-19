#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="iap-auth-shield"
PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_ZIP="${PLUGIN_ROOT}/${PLUGIN_SLUG}.zip"

STRAUSS_PHAR="${STRAUSS_PHAR:-/tmp/strauss.phar}"
STRAUSS_URL="https://github.com/BrianHenryIE/strauss/releases/latest/download/strauss.phar"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

BUILD_DIR="${WORK_DIR}/${PLUGIN_SLUG}"
mkdir -p "$BUILD_DIR"

cp "${PLUGIN_ROOT}/${PLUGIN_SLUG}.php" "${BUILD_DIR}/"
cp "${PLUGIN_ROOT}/composer.json" "${BUILD_DIR}/"
cp "${PLUGIN_ROOT}/README.md" "${BUILD_DIR}/"
cp "${PLUGIN_ROOT}/readme.txt" "${BUILD_DIR}/"
cp "${PLUGIN_ROOT}/LICENSE" "${BUILD_DIR}/"
cp -R "${PLUGIN_ROOT}/src" "${BUILD_DIR}/src"

if [[ ! -f "$STRAUSS_PHAR" ]]; then
    echo "Downloading Strauss..."
    curl -fsSL "$STRAUSS_URL" -o "$STRAUSS_PHAR"
fi

echo "Installing production dependencies in temporary build directory..."
php -r '$c = json_decode(file_get_contents($argv[1]), true); unset($c["require-dev"]); file_put_contents($argv[1], json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "${BUILD_DIR}/composer.json"
composer install --working-dir="$BUILD_DIR" --no-dev --no-interaction --prefer-dist --ignore-platform-reqs --quiet

echo "Scoping vendor namespaces with Strauss..."
(cd "$BUILD_DIR" && php "$STRAUSS_PHAR")

rm -rf "${BUILD_DIR}/vendor" "${BUILD_DIR}/composer.json" "${BUILD_DIR}/composer.lock"

rm -f "$OUT_ZIP"
(cd "$WORK_DIR" && zip -qr "$OUT_ZIP" "$PLUGIN_SLUG")

echo "Built standalone plugin archive: ${OUT_ZIP}"
