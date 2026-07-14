#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
	echo "Usage: $0 SOURCE_ROOT [OUTPUT_DIRECTORY]" >&2
	exit 2
fi

SOURCE_ROOT="$(cd "$1" && pwd)"
OUTPUT_DIRECTORY="${2:-$SOURCE_ROOT/build/artifacts/appstore}"
APP_NAME="educai"
INFO_XML="$SOURCE_ROOT/appinfo/info.xml"

if [[ ! -f "$INFO_XML" ]]; then
	echo "Missing app metadata: $INFO_XML" >&2
	exit 1
fi

VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO_XML")"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
	echo "Invalid or missing app version in appinfo/info.xml: $VERSION" >&2
	exit 1
fi

BUILD_ROOT="$(mktemp -d)"
trap 'rm -rf "$BUILD_ROOT"' EXIT

WORK_ROOT="$BUILD_ROOT/source"
PACKAGE_PARENT="$BUILD_ROOT/package"
PACKAGE_ROOT="$PACKAGE_PARENT/$APP_NAME"
ARTIFACT="$OUTPUT_DIRECTORY/$APP_NAME-$VERSION.tar.gz"

mkdir -p "$WORK_ROOT" "$PACKAGE_ROOT" "$OUTPUT_DIRECTORY"

rsync -a \
	--exclude '/.git/' \
	--exclude '/build/' \
	--exclude '/node_modules/' \
	--exclude '/vendor/' \
	--exclude '/vendor-bin/*/vendor/' \
	"$SOURCE_ROOT/" "$WORK_ROOT/"

(
	cd "$WORK_ROOT"
	npm ci --no-audit --no-fund
	npm run build
	composer install \
		--no-dev \
		--no-scripts \
		--prefer-dist \
		--no-interaction \
		--no-progress \
		--optimize-autoloader
)

for path in appinfo img js lib templates vendor LICENSE README.md; do
	if [[ ! -e "$WORK_ROOT/$path" ]]; then
		echo "Required release path is missing after build: $path" >&2
		exit 1
	fi
	cp -a "$WORK_ROOT/$path" "$PACKAGE_ROOT/"
done

find "$PACKAGE_ROOT" -type f \( -name '*.map' -o -name '.DS_Store' \) -delete

SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$SOURCE_ROOT" log -1 --format=%ct 2>/dev/null || printf '0')}"
tar \
	--sort=name \
	--mtime="@$SOURCE_DATE_EPOCH" \
	--owner=0 \
	--group=0 \
	--numeric-owner \
	-czf "$ARTIFACT" \
	-C "$PACKAGE_PARENT" \
	"$APP_NAME"

echo "$ARTIFACT"
