#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_HOME="${XDG_STATE_HOME:-$HOME/.local/state}"
DEFAULT_OUTPUT="$STATE_HOME/maklertour/dual_phone_host/sessions"
SESSION_PATH_FILE="$(mktemp)"

cleanup() {
  rm -f "$SESSION_PATH_FILE"
}
trap cleanup EXIT

set +e
"$ROOT/build/maklertour-dual-phone-host" \
  --bind "${MAKLER_BIND:-0.0.0.0}" \
  --http-bind "${MAKLER_HTTP_BIND:-127.0.0.1}" \
  --ingest-port "${MAKLER_INGEST_PORT:-48640}" \
  --http-port "${MAKLER_HTTP_PORT:-48641}" \
  --output "${MAKLER_OUTPUT:-$DEFAULT_OUTPUT}" \
  --web-root "$ROOT/web" \
  --session-path-file "$SESSION_PATH_FILE" \
  --archive-every "${MAKLER_ARCHIVE_EVERY:-0}"
HOST_STATUS=$?
set -e

if [[ "${MAKLER_PACK_ON_EXIT:-1}" == "1" && -s "$SESSION_PATH_FILE" ]]; then
  SESSION_DIR="$(<"$SESSION_PATH_FILE")"
  if [[ -d "$SESSION_DIR" ]]; then
    echo "[PACK] Creating JSON diagnostics archive for: $SESSION_DIR"
    if ! "$ROOT/scripts/pack_session.sh" "$SESSION_DIR"; then
      echo "[WARN] Diagnostic packaging failed; raw JSON remains in $SESSION_DIR" >&2
    fi
  fi
fi

exit "$HOST_STATUS"
