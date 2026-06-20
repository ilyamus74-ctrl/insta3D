#!/usr/bin/env bash
set -u

METRICS_SCRIPT="/home/makler/web/remote_station/get_station_metrics.sh"
CONFIG_FILE="/home/makler/web/remote_station/stations.conf"
OUTPUT_DIR="/home/makler/web/remote_station/output"
OUTPUT_FILE="${OUTPUT_DIR}/station_metrics.json"
TMP_FILE=""

updated_at() {
  date -u +%Y-%m-%dT%H:%M:%SZ
}

cleanup() {
  if [[ -n "${TMP_FILE}" && -f "${TMP_FILE}" ]]; then
    rm -f "${TMP_FILE}"
  fi
}
trap cleanup EXIT

if [[ ${EUID} -ne 0 ]]; then
  echo "update_station_metrics.sh must be run as root" >&2
  exit 1
fi

mkdir -p "${OUTPUT_DIR}"
TMP_FILE="$(mktemp "${OUTPUT_DIR}/station_metrics.json.tmp.XXXXXX")"

if "${METRICS_SCRIPT}" "${CONFIG_FILE}" > "${TMP_FILE}"; then
  :
else
  cat > "${TMP_FILE}" <<JSON
{ "ok": false, "message": "failed to collect station metrics", "updated_at": "$(updated_at)" }
JSON
fi

chmod 664 "${TMP_FILE}"
if getent group apache >/dev/null 2>&1; then
  chown root:apache "${TMP_FILE}" 2>/dev/null || true
fi

mv -f "${TMP_FILE}" "${OUTPUT_FILE}"
TMP_FILE=""
