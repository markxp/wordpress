#!/usr/bin/env bash
set -euo pipefail

FEED_URL="https://storage.googleapis.com/site-kit-dev-plugins/google-site-kit-dev-settings/updates.json"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="${REPO_ROOT}/web/app/plugins/google-site-kit-dev-settings"
MAIN_FILE="${PLUGIN_DIR}/google-site-kit-dev-settings.php"

FORCE=false
if [[ "${1:-}" == "--force" ]]; then
    FORCE=true
fi

if [[ ! -f "$MAIN_FILE" ]]; then
    echo "Error: ${MAIN_FILE} not found." >&2
    exit 1
fi

CURRENT_VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN_FILE" | head -n 1)"
if [[ -z "$CURRENT_VERSION" ]]; then
    echo "Error: Could not determine current version from ${MAIN_FILE}." >&2
    exit 1
fi

FEED_JSON="$(curl -fsSL "$FEED_URL")"
REMOTE_VERSION="$(echo "$FEED_JSON" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["version"] ?? "";')"
DOWNLOAD_URL="$(echo "$FEED_JSON" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["download_url"] ?? "";')"

if [[ -z "$REMOTE_VERSION" || -z "$DOWNLOAD_URL" ]]; then
    echo "Error: Invalid metadata returned from ${FEED_URL}." >&2
    exit 1
fi

echo "Current version: ${CURRENT_VERSION}"
echo "Remote version:  ${REMOTE_VERSION}"

IS_NEWER="$(php -r 'echo version_compare($argv[1], $argv[2], "<") ? "1" : "0";' "$CURRENT_VERSION" "$REMOTE_VERSION")"

if [[ "$IS_NEWER" != "1" && "$FORCE" != "true" ]]; then
    echo "google-site-kit-dev-settings is already up to date."
    exit 0
fi

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

ARCHIVE_PATH="${WORK_DIR}/plugin.zip"
EXTRACT_DIR="${WORK_DIR}/extracted"
mkdir -p "$EXTRACT_DIR"

echo "Downloading ${DOWNLOAD_URL}..."
curl -fsSL "$DOWNLOAD_URL" -o "$ARCHIVE_PATH"
unzip -q "$ARCHIVE_PATH" -d "$EXTRACT_DIR"

SOURCE_DIR="${EXTRACT_DIR}/google-site-kit-dev-settings"
if [[ ! -d "$SOURCE_DIR" ]]; then
    SOURCE_DIR="$EXTRACT_DIR"
fi

if [[ ! -f "${SOURCE_DIR}/google-site-kit-dev-settings.php" ]]; then
    echo "Error: Extracted archive does not contain google-site-kit-dev-settings.php." >&2
    exit 1
fi

rm -rf "$PLUGIN_DIR"
mkdir -p "$(dirname "$PLUGIN_DIR")"
cp -R "$SOURCE_DIR" "$PLUGIN_DIR"

echo "Updated google-site-kit-dev-settings to ${REMOTE_VERSION}."
