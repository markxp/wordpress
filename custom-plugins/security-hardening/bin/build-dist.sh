#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="security-hardening"
PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_ZIP="${PLUGIN_ROOT}/${PLUGIN_SLUG}.zip"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

BUILD_DIR="${WORK_DIR}/${PLUGIN_SLUG}"
mkdir -p "$BUILD_DIR"

cp "${PLUGIN_ROOT}/${PLUGIN_SLUG}.php" "${BUILD_DIR}/"
cp "${PLUGIN_ROOT}/README.md" "${BUILD_DIR}/"
cp "${PLUGIN_ROOT}/readme.txt" "${BUILD_DIR}/"
cp "${PLUGIN_ROOT}/LICENSE" "${BUILD_DIR}/"
cp -R "${PLUGIN_ROOT}/src" "${BUILD_DIR}/src"

rm -f "$OUT_ZIP"
(cd "$WORK_DIR" && zip -qr "$OUT_ZIP" "$PLUGIN_SLUG")

echo "Built standalone plugin archive: ${OUT_ZIP}"
