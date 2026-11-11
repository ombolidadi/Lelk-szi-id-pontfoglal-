#!/usr/bin/env bash
# A bővítmény csomagolása és a Playground-blueprintek frissítése.
# Minden link verziószámot kap (?v=…), így a GitHub és a böngésző gyorsítótára nem adhat régi változatot.
set -euo pipefail
cd "$(dirname "$0")"

VERSION=$(grep -m1 "define( 'LIF_VERSION'" lelkeszi-idopontfoglalo/lelkeszi-idopontfoglalo.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
BRANCH=${BRANCH:-claude/nice-darwin-ld6omg}
RAW="https://raw.githubusercontent.com/ombolidadi/Lelk-szi-id-pontfoglal-/${BRANCH}/wordpress-plugin"

rm -f lelkeszi-idopontfoglalo.zip
zip -qr lelkeszi-idopontfoglalo.zip lelkeszi-idopontfoglalo -x '*.DS_Store'

python3 - "$VERSION" "$RAW" <<'EOF'
import json, sys
version, raw = sys.argv[1], sys.argv[2]
base_steps = [
    {"step": "setSiteOptions", "options": {
        "blogname": "Bemutató Egyházközség",
        "permalink_structure": "/%postname%/",
        "timezone_string": "Europe/Budapest"}},
    {"step": "installPlugin",
     "pluginData": {"resource": "url", "url": f"{raw}/lelkeszi-idopontfoglalo.zip?v={version}"},
     "options": {"activate": True}},
    {"step": "runPHP", "code": "<?php require_once '/wordpress/wp-load.php'; lif_seed_demo(); flush_rewrite_rules();"},
]
def bp(landing, extra=()):
    return {
        "$schema": "https://playground.wordpress.net/blueprint-schema.json",
        "meta": {"title": f"Lelkészi Időpontfoglaló {version}"},
        "landingPage": landing,
        "preferredVersions": {"php": "8.2", "wp": "latest"},
        "features": {"networking": True},
        "steps": base_steps + list(extra),
    }
json.dump(bp("/idopont/budavari-gyulekezet-x7k2q/"), open("blueprint.json", "w"), ensure_ascii=False, indent=2)
json.dump(bp("/idopont/lelkeszi/", [{"step": "login", "username": "kiss.peter", "password": "demo1234"}]),
          open("blueprint-lelkesz.json", "w"), ensure_ascii=False, indent=2)
print(f"Verzió: {version}")
print(f"Vendég:   https://playground.wordpress.net/?blueprint-url={raw}/blueprint.json?v={version}")
print(f"Lelkészi: https://playground.wordpress.net/?blueprint-url={raw}/blueprint-lelkesz.json?v={version}")
EOF
