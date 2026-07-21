#!/usr/bin/env bash
set -euo pipefail

STORAGE_ROOT="/home/storage/orders"

echo "[INFO] Fixing storage permissions: $STORAGE_ROOT"

mkdir -p "$STORAGE_ROOT"

chown -R apache:apache "$STORAGE_ROOT"

find "$STORAGE_ROOT" -type d -exec chmod 2775 {} \;
find "$STORAGE_ROOT" -type f -exec chmod 664 {} \;

if command -v setfacl >/dev/null 2>&1; then
  setfacl -R -m u:apache:rwx,g:apache:rwx "$STORAGE_ROOT"
  setfacl -R -d -m u:apache:rwx,g:apache:rwx "$STORAGE_ROOT"
fi

echo "[INFO] Test write as apache..."
runuser -u apache -- mkdir -p "$STORAGE_ROOT/.permission_test/sessions/test/videos"
runuser -u apache -- touch "$STORAGE_ROOT/.permission_test/sessions/test/videos/test.txt"
rm -rf "$STORAGE_ROOT/.permission_test"

echo "[INFO] OK"
