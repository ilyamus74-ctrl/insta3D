#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_HOME="${XDG_STATE_HOME:-$HOME/.local/state}"
DEFAULT_OUTPUT="$STATE_HOME/maklertour/dual_phone_host/sessions"

exec "$ROOT/build/maklertour-dual-phone-host" \
  --bind "${MAKLER_BIND:-0.0.0.0}" \
  --ingest-port "${MAKLER_INGEST_PORT:-48640}" \
  --http-port "${MAKLER_HTTP_PORT:-48641}" \
  --output "${MAKLER_OUTPUT:-$DEFAULT_OUTPUT}" \
  --web-root "$ROOT/web" \
  --archive-every "${MAKLER_ARCHIVE_EVERY:-0}"
