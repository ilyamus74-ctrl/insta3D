#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   sudo SERVER_URL="https://example.com" DEVICE_NAME="OrangePi Printer" ./install_print_agent.sh
#   sudo ./install_print_agent.sh install SERVER_URL="https://example.com" DEVICE_NAME="OrangePi Printer"
#   sudo ./install_print_agent.sh renew "https://example.com" "OrangePi Printer"
# Optional:
#   DEVICE_ID, POLL_TIMEOUT, PRINTER_NAME, ACTION
#
# ACTION modes:
#   install  - full install (default)
#   renew    - generate a new UID and re-enroll device

SERVER_URL="${SERVER_URL:-}"
DEVICE_ID="${DEVICE_ID:-}"
DEVICE_NAME="${DEVICE_NAME:-OrangePi Print Agent}"
PRINTER_NAME="${PRINTER_NAME:-}"
POLL_TIMEOUT="${POLL_TIMEOUT:-30}"
ACTION="${ACTION:-}"

if [[ -z "$DEVICE_ID" ]]; then
  if [[ -f /etc/machine-id ]]; then
    DEVICE_ID="opi-$(cut -c1-12 /etc/machine-id)"
  else
    DEVICE_ID="opi-$(date +%s)"
  fi
fi

APP_DIR="/opt/print-agent"
CFG_DIR="/etc/print-agent"
CFG_FILE="$CFG_DIR/config.env"
UID_FILE="$CFG_DIR/device_uid"

mkdir -p "$APP_DIR" "$CFG_DIR"

parse_cli_args() {
  if [[ $# -eq 0 ]]; then
    return
  fi

  local first="${1:-}"
  case "$first" in
    install|renew)
      ACTION="$first"
      shift
      ;;
    run_renew_flow)
      ACTION="renew"
      shift
      ;;
    run_install_flow)
      ACTION="install"
      shift
      ;;
  esac

  # Positional shortcut:
  #   ./script.sh renew https://server.url "Device Name"
  if [[ $# -gt 0 && "$1" != *=* && -z "${SERVER_URL:-}" ]]; then
    SERVER_URL="$1"
    shift
  fi
  if [[ $# -gt 0 && "$1" != *=* && "${DEVICE_NAME:-OrangePi Print Agent}" == "OrangePi Print Agent" ]]; then
    DEVICE_NAME="$1"
    shift
  fi

  # KEY=VALUE style args after mode:
  #   ./script.sh install SERVER_URL=... DEVICE_NAME=...
  local kv key value
  for kv in "$@"; do
    if [[ "$kv" != *=* ]]; then
      continue
    fi
    key="${kv%%=*}"
    value="${kv#*=}"
    case "$key" in
      SERVER_URL) SERVER_URL="$value" ;;
      DEVICE_ID) DEVICE_ID="$value" ;;
      DEVICE_NAME) DEVICE_NAME="$value" ;;
      PRINTER_NAME) PRINTER_NAME="$value" ;;
      POLL_TIMEOUT) POLL_TIMEOUT="$value" ;;
      ACTION) ACTION="$value" ;;
    esac
  done
}

validate_required() {
  if [[ -z "$SERVER_URL" ]]; then
    echo "ERROR: SERVER_URL is required."
    echo "Examples:"
    echo "  SERVER_URL=https://your-domain.com ./install_print_agent.sh"
    echo "  ./install_print_agent.sh install SERVER_URL=https://your-domain.com DEVICE_NAME='OrangePi Printer'"
    echo "  ./install_print_agent.sh renew https://your-domain.com 'OrangePi Printer'"
    exit 1
  fi
}

choose_action() {
  if [[ -n "$ACTION" ]]; then
    case "$ACTION" in
      install|renew) return ;;
      *)
        echo "ERROR: ACTION must be 'install' or 'renew'"
        exit 1
        ;;
    esac
  fi

  if [[ -t 0 ]]; then
    echo "Выберите действие:"
    echo "  1) Установка (полная)"
    echo "  2) Получить новый UID и пройти регистрацию"
    read -r -p "Введите 1 или 2: " choice
    case "$choice" in
      1) ACTION="install" ;;
      2) ACTION="renew" ;;
      *)
        echo "Некорректный выбор. Ожидалось 1 или 2."
        exit 1
        ;;
    esac
  else
    ACTION="install"
    echo "[INFO] Non-interactive mode: ACTION not set, using default ACTION=install"
  fi
}

load_or_create_uid() {
  if [[ -f "$UID_FILE" ]]; then
    DEVICE_UID="$(cat "$UID_FILE")"
  else
    DEVICE_UID="dev-$(cat /proc/sys/kernel/random/uuid 2>/dev/null || echo "$RANDOM-$RANDOM")"
    echo "$DEVICE_UID" > "$UID_FILE"
    chmod 600 "$UID_FILE"
  fi
}

force_new_uid() {
  DEVICE_UID="dev-$(cat /proc/sys/kernel/random/uuid 2>/dev/null || echo "$RANDOM-$RANDOM")"
  echo "$DEVICE_UID" > "$UID_FILE"
  chmod 600 "$UID_FILE"
}

MODEL="$(tr -d '\0' </proc/device-tree/model 2>/dev/null || echo "Unknown model")"
SERIAL="$(tr -d '\0' </proc/device-tree/serial-number 2>/dev/null || echo "")"
APP_VERSION="print-agent-1.0"

install_deps() {
  echo "[1/7] Installing packages..."
  apt-get update
  apt-get install -y python3 python3-pip cups cups-client curl ca-certificates
  pip3 install --break-system-packages requests >/dev/null
}

enroll_device() {
  local enroll_url="${SERVER_URL%/}/api/device_enroll.php"
  echo "[2/7] Enrolling device on server: $enroll_url"

  local payload
  payload="$(python3 - <<PY
import json
print(json.dumps({
  "mode": "enroll",
  "device_uid": "${DEVICE_UID}",
  "name": "${DEVICE_NAME}",
  "serial": "${SERIAL}",
  "model": "${MODEL}",
  "app_version": "${APP_VERSION}"
}, ensure_ascii=False))
PY
)"

  local resp
  resp="$(curl -fsS --connect-timeout 8 --max-time 20 \
    -H 'Content-Type: application/json' \
    -d "$payload" \
    "$enroll_url")"

  DEVICE_TOKEN="$(python3 - <<PY
import json,sys
obj=json.loads('''$resp''')
if obj.get('status')!='ok' or not obj.get('device_token'):
    raise SystemExit(obj.get('message','enroll failed'))
print(obj['device_token'])
PY
)"

  IS_ACTIVE="$(python3 - <<PY
import json
obj=json.loads('''$resp''')
print(int(obj.get('is_active',0)))
PY
)"

  echo "Enroll OK. is_active=${IS_ACTIVE}"
}

write_config() {
  echo "[3/7] Writing config..."
  cat > "$CFG_FILE" <<EOCFG
SERVER_URL=${SERVER_URL%/}
DEVICE_ID=${DEVICE_ID}
DEVICE_UID=${DEVICE_UID}
DEVICE_NAME=${DEVICE_NAME}
DEVICE_TOKEN=${DEVICE_TOKEN}
PRINTER_NAME=${PRINTER_NAME}
POLL_TIMEOUT=${POLL_TIMEOUT}
EOCFG
  chmod 600 "$CFG_FILE"
}

write_agent() {
  echo "[4/7] Writing agent code..."
  cat > "$APP_DIR/agent.py" <<'PYEOF'
#!/usr/bin/env python3
import os
import time
import json
import tempfile
import subprocess
import requests

SERVER_URL = os.getenv("SERVER_URL", "").rstrip("/")
DEVICE_ID = os.getenv("DEVICE_ID", "")
DEVICE_UID = os.getenv("DEVICE_UID", "")
DEVICE_NAME = os.getenv("DEVICE_NAME", "")
DEVICE_TOKEN = os.getenv("DEVICE_TOKEN", "")
PRINTER_NAME = os.getenv("PRINTER_NAME", "")
POLL_TIMEOUT = int(os.getenv("POLL_TIMEOUT", "30"))

if not SERVER_URL or not DEVICE_ID or not DEVICE_UID or not DEVICE_TOKEN:
    raise SystemExit("Missing SERVER_URL/DEVICE_ID/DEVICE_UID/DEVICE_TOKEN")

SESSION = requests.Session()
SESSION.headers.update({
    "Authorization": f"Bearer {DEVICE_TOKEN}",
    "Content-Type": "application/json",
    "Accept": "application/json",
})

def post_status(job_id, status, message=""):
    url = f"{SERVER_URL}/api/print/result"
    payload = {
        "device_id": DEVICE_ID,
        "device_uid": DEVICE_UID,
        "job_id": job_id,
        "status": status,
        "message": message[:500],
    }
    try:
        r = SESSION.post(url, data=json.dumps(payload), timeout=15)
        r.raise_for_status()
    except Exception as e:
        print(f"[WARN] post_status failed: {e}")

def print_file(path):
    cmd = ["lp"]
    if PRINTER_NAME:
        cmd += ["-d", PRINTER_NAME]
    cmd += [path]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout).strip() or "lp failed")
    return (p.stdout or "").strip()

def handle_job(job):
    job_id = str(job.get("job_id", "")).strip()
    if not job_id:
        print("[WARN] bad job: no job_id")
        return

    post_status(job_id, "received", "job accepted by device")
    label_url = job.get("label_url")
    label_base64 = job.get("label_base64")
    file_name = job.get("file_name") or f"label_{job_id}.pdf"

    try:
        if label_url:
            r = SESSION.get(label_url, timeout=30)
            r.raise_for_status()
            data = r.content
        elif label_base64:
            import base64
            data = base64.b64decode(label_base64)
        else:
            raise RuntimeError("No label_url or label_base64 in job")

        suffix = ".pdf"
        low = file_name.lower()
        if low.endswith(".zpl"):
            suffix = ".zpl"
        elif low.endswith(".png"):
            suffix = ".png"

        with tempfile.NamedTemporaryFile(prefix="print_", suffix=suffix, delete=False) as f:
            f.write(data)
            tmp_path = f.name

        out = print_file(tmp_path)
        post_status(job_id, "printed", out)
    except Exception as e:
        post_status(job_id, "failed", str(e))
        print(f"[ERROR] job {job_id} failed: {e}")

def poll_once():
    url = f"{SERVER_URL}/api/print/next?device_id={DEVICE_ID}&device_uid={DEVICE_UID}&timeout={POLL_TIMEOUT}"
    r = SESSION.get(url, timeout=POLL_TIMEOUT + 10)
    if r.status_code == 204:
        return None
    r.raise_for_status()
    data = r.json()
    return data.get("job")

def main():
    print(f"[INFO] print-agent started for {DEVICE_NAME} ({DEVICE_UID})")
    backoff = 2
    while True:
        try:
            job = poll_once()
            if job:
                handle_job(job)
            backoff = 2
        except Exception as e:
            print(f"[WARN] poll error: {e}; retry in {backoff}s")
            time.sleep(backoff)
            backoff = min(backoff * 2, 30)

if __name__ == "__main__":
    main()
PYEOF
  chmod +x "$APP_DIR/agent.py"
}

setup_services() {
  echo "[5/7] Enabling CUPS..."
  systemctl enable cups >/dev/null
  systemctl restart cups

  echo "[6/7] Writing systemd service..."
  cat > /etc/systemd/system/print-agent.service <<'EOSVC'
[Unit]
Description=Orange Pi Print Agent (Long Poll)
After=network-online.target cups.service
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/print-agent/config.env
ExecStart=/usr/bin/env python3 /opt/print-agent/agent.py
Restart=always
RestartSec=3
User=root

[Install]
WantedBy=multi-user.target
EOSVC

  systemctl daemon-reload
  systemctl enable print-agent >/dev/null
  systemctl restart print-agent
}

restart_agent_service() {
  echo "[4/4] Restarting print-agent service..."
  systemctl daemon-reload
  systemctl restart print-agent
}

finish_info() {
  echo "[7/7] Done."
  echo ""
  echo "Device enrolled:"
  echo "  DEVICE_UID=$DEVICE_UID"
  echo "  DEVICE_ID=$DEVICE_ID"
  echo "  TOKEN=${DEVICE_TOKEN:0:8}..."
  echo ""
  echo "Check status: systemctl status print-agent --no-pager"
  echo "Watch logs:   journalctl -u print-agent -f"
  echo "Printers:     lpstat -p -d"
}

finish_renew_info() {
  echo "[DONE] UID renewed and device re-enrolled."
  echo ""
  echo "New identity:"
  echo "  DEVICE_UID=$DEVICE_UID"
  echo "  DEVICE_ID=$DEVICE_ID"
  echo "  TOKEN=${DEVICE_TOKEN:0:8}..."
  echo ""
  echo "Check status: systemctl status print-agent --no-pager"
  echo "Watch logs:   journalctl -u print-agent -f"
}

run_install_flow() {
  load_or_create_uid
  install_deps
  enroll_device
  write_config
  write_agent
  setup_services
  finish_info
}

run_renew_flow() {
  echo "[1/4] Generating new DEVICE_UID..."
  force_new_uid
  echo "[2/4] Re-enrolling with new UID..."
  enroll_device
  echo "[3/4] Updating config..."
  write_config
  restart_agent_service
  finish_renew_info
}

parse_cli_args "$@"
validate_required
choose_action
case "$ACTION" in
  install)
    run_install_flow
    ;;
  renew)
    run_renew_flow
    ;;
esac
