#!/usr/bin/env bash
# Builds build/ruvelo-comment-subscriptions.zip: the plugin as WordPress.org and the
# GitHub release ship it. No Composer needed at runtime (the plugin has its
# own PSR-4 loader), so vendor/ isn't included.
set -euo pipefail
cd "$(dirname "$0")/.."

out=build/ruvelo-comment-subscriptions
rm -rf build && mkdir -p "$out"
cp ruvelo-comment-subscriptions.php uninstall.php readme.txt LICENSE "$out/"
cp -R src blocks templates assets languages "$out/"
mkdir -p "$out/demo"
cp demo/content.php "$out/demo/"
if command -v zip >/dev/null; then
    (cd build && zip -qr ruvelo-comment-subscriptions.zip ruvelo-comment-subscriptions)
else
    (cd build && python3 -m zipfile -c ruvelo-comment-subscriptions.zip ruvelo-comment-subscriptions)
fi
echo "build/ruvelo-comment-subscriptions.zip ($(du -h build/ruvelo-comment-subscriptions.zip | cut -f1))"
