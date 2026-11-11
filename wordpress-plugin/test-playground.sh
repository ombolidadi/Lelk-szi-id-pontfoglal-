#!/usr/bin/env bash
# A Playground-linkek ellenőrzése a valódi Playground-motorral (PHP WebAssembly), mielőtt bárkinek elküldjük őket.
# Használat: ./test-playground.sh <WordPress-könyvtár> [blueprint.json] [port]
# A blueprintet a GitHubról, pontosan a link szerinti címről tölti le, és lefuttatja.
set -euo pipefail
WP_DIR=$1; BP=${2:-blueprint.json}; PORT=${3:-9400}
VERSION=$(grep -m1 "define( 'LIF_VERSION'" "$(dirname "$0")/lelkeszi-idopontfoglalo/lelkeszi-idopontfoglalo.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")
URL="https://raw.githubusercontent.com/ombolidadi/Lelk-szi-id-pontfoglal-/claude/nice-darwin-ld6omg/wordpress-plugin/${BP}?v=${VERSION}"
TMP=$(mktemp -d)
curl -fsSL "$URL" -o "$TMP/bp.json"
echo "Blueprint: $URL"
NODE_USE_ENV_PROXY=1 npx -y @wp-playground/cli@3.1.64 server --php=8.2 --port="$PORT" --internal-cookie-store \
  --blueprint="$TMP/bp.json" --mount-dir-before-install "$WP_DIR" /wordpress \
  --wordpress-install-mode=install-from-existing-files
