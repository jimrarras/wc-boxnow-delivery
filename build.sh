#!/usr/bin/env bash
#
# Build a clean release zip for wc-boxnow-delivery.
#
# Usage:  ./build.sh          → produces wc-boxnow-delivery.zip
#
set -euo pipefail

PLUGIN_SLUG="wc-boxnow-delivery"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "${SCRIPT_DIR}"

ZIP_NAME="${PLUGIN_SLUG}.zip"
BUILD_DIR=$(mktemp -d)
DEST="${BUILD_DIR}/${PLUGIN_SLUG}"

echo "Building ${ZIP_NAME} ..."

mkdir -p "${DEST}"

# Wrap in a directory so the zip extracts as
# wp-content/plugins/wc-boxnow-delivery/ as WordPress requires.
cp "${PLUGIN_SLUG}.php" "${DEST}/"
cp uninstall.php        "${DEST}/"
cp readme.txt           "${DEST}/"
cp README.md            "${DEST}/"

cp -r includes/  "${DEST}/includes/"
cp -r assets/    "${DEST}/assets/"
cp -r languages/ "${DEST}/languages/"
cp -r templates/ "${DEST}/templates/"

if command -v zip &>/dev/null; then
    (cd "${BUILD_DIR}" && zip -qr "${SCRIPT_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}")
elif command -v python3 &>/dev/null || command -v python &>/dev/null; then
    PYTHON=$(command -v python3 || command -v python)
    "$PYTHON" - "${BUILD_DIR}" "${SCRIPT_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}" <<'PYEOF'
import sys, os, zipfile
build_dir, zip_path, slug = sys.argv[1], sys.argv[2], sys.argv[3]
with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zf:
    base = os.path.join(build_dir, slug)
    for root, dirs, files in os.walk(base):
        for f in files:
            filepath = os.path.join(root, f)
            arcname = slug + '/' + os.path.relpath(filepath, base).replace('\\', '/')
            zf.write(filepath, arcname)
PYEOF
else
    echo "Error: neither zip nor python3 found. Install one to build." >&2
    exit 1
fi

rm -rf "${BUILD_DIR}"

echo "Done → ${ZIP_NAME}"
