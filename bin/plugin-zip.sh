#!/usr/bin/env bash
#
# Stages tracked files (via .gitattributes export-ignore) plus the built
# assets into a single {slug}/ folder before zipping, so WordPress's
# "Upload Plugin" flow finds one plugin folder instead of loose files.
set -euo pipefail
cd "$(dirname "$0")/.."

NAME=$(node -p "require('./package.json').name")
VERSION=$(node -p "require('./package.json').version")
RELEASES_DIR="../../releases"
STAGE_DIR=$(mktemp -d)

npm run build

mkdir -p "$STAGE_DIR/$NAME"
git archive --worktree-attributes HEAD | tar -x -C "$STAGE_DIR/$NAME"
cp -r build "$STAGE_DIR/$NAME/build"

mkdir -p "$RELEASES_DIR"
( cd "$STAGE_DIR" && zip -qr "$NAME-$VERSION.zip" "$NAME" -x '*.map' '*.log' )
mv "$STAGE_DIR/$NAME-$VERSION.zip" "$RELEASES_DIR/"
rm -rf "$STAGE_DIR"

echo "Created $RELEASES_DIR/$NAME-$VERSION.zip"
