#!/usr/bin/env sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
OUTPUT_DIR="${1:-${ROOT_DIR}/.maestro/playbooks/Working}"
BUILD_DIR="${ROOT_DIR}/.maestro/playbooks/Working/.package-build"
PACKAGE_NAME="clicklink"
PACKAGE_DIR="${BUILD_DIR}/${PACKAGE_NAME}"

VERSION="$(sed -n 's/^ \* Version: //p' "${ROOT_DIR}/clicklink.php" | head -n 1 | tr -d '\r')"

if [ -z "${VERSION}" ]; then
    echo "Unable to determine plugin version from clicklink.php" >&2
    exit 1
fi

ZIP_PATH="${OUTPUT_DIR}/${PACKAGE_NAME}-${VERSION}.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${PACKAGE_DIR}" "${OUTPUT_DIR}"

cp "${ROOT_DIR}/clicklink.php" "${PACKAGE_DIR}/"
cp "${ROOT_DIR}/uninstall.php" "${PACKAGE_DIR}/"
cp "${ROOT_DIR}/README.md" "${PACKAGE_DIR}/"
cp "${ROOT_DIR}/CHANGELOG.md" "${PACKAGE_DIR}/"
cp -R "${ROOT_DIR}/admin" "${PACKAGE_DIR}/admin"
cp -R "${ROOT_DIR}/includes" "${PACKAGE_DIR}/includes"

mkdir -p "${PACKAGE_DIR}/assets" "${PACKAGE_DIR}/languages"

if [ -d "${ROOT_DIR}/assets" ]; then
    cp -R "${ROOT_DIR}/assets/." "${PACKAGE_DIR}/assets/"
fi

if [ -d "${ROOT_DIR}/languages" ]; then
    cp -R "${ROOT_DIR}/languages/." "${PACKAGE_DIR}/languages/"
fi

rm -f "${ZIP_PATH}"
(
    cd "${BUILD_DIR}"
    zip -qr "${ZIP_PATH}" "${PACKAGE_NAME}"
)

rm -rf "${BUILD_DIR}"

echo "Created release package: ${ZIP_PATH}"
