#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT"

git rm -r --cached --ignore-unmatch \
  web/remote_station/dual_phone_host/build \
  web/remote_station/dual_phone_host/sessions \
  web/remote_station/dual_phone_host/archives

echo "Runtime artifacts were removed from the Git index only."
echo "Local files remain on disk and are protected by .gitignore."
