#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo '{"ok":false,"message":"Usage: get_station_metrics.sh ./stations.conf"}'
  exit 1
fi
CONFIG="$1"
if [[ ! -f "$CONFIG" ]]; then
  echo '{"ok":false,"message":"config not found"}'
  exit 1
fi
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
SSH=(ssh -i "$STATION_SSH_KEY" -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")

remote_script='set -euo pipefail
json_escape(){ python3 -c '\''import json,sys; print(json.dumps(sys.stdin.read().rstrip("\n")))'\''; }
hostname_v=$(hostname 2>/dev/null || printf unknown)
uptime_v=$(uptime -p 2>/dev/null || uptime 2>/dev/null || true)
load_avg=$(awk "{print \$1\" \"\$2\" \"\$3}" /proc/loadavg 2>/dev/null || printf "")
cpu_usage=$(awk '\''/cpu /{u=$2+$4; t=$2+$3+$4+$5+$6+$7+$8; if(t>0) printf "%d", (u*100/t); else printf "0"}'\'' /proc/stat 2>/dev/null || printf "0")
read mem_total mem_avail < <(awk '\''/MemTotal:/{t=int($2/1024)} /MemAvailable:/{a=int($2/1024)} END{print t+0, a+0}'\'' /proc/meminfo)
mem_used=$((mem_total-mem_avail))
updated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf "{\"ok\":true,\"hostname\":%s,\"uptime\":%s,\"cpu\":{\"load_avg\":%s,\"usage_percent\":%s},\"memory\":{\"used_mb\":%s,\"total_mb\":%s}," "$(printf "%s" "$hostname_v"|json_escape)" "$(printf "%s" "$uptime_v"|json_escape)" "$(printf "%s" "$load_avg"|json_escape)" "$cpu_usage" "$mem_used" "$mem_total"
if command -v nvidia-smi >/dev/null 2>&1; then
  gpu=$(nvidia-smi --query-gpu=name,utilization.gpu,temperature.gpu,power.draw,power.limit,memory.used,memory.total --format=csv,noheader,nounits 2>/dev/null | head -n1 || true)
  if [[ -n "$gpu" ]]; then
    IFS="," read -r name util temp pdraw plimit memu memt <<< "$gpu"
    trim(){ sed "s/^ *//;s/ *$//"; }
    name=$(printf "%s" "$name"|trim); util=$(printf "%s" "$util"|trim); temp=$(printf "%s" "$temp"|trim); pdraw=$(printf "%s" "$pdraw"|trim); plimit=$(printf "%s" "$plimit"|trim); memu=$(printf "%s" "$memu"|trim); memt=$(printf "%s" "$memt"|trim)
    printf "\"gpu\":{\"available\":true,\"name\":%s,\"utilization_percent\":%s,\"temperature_c\":%s,\"power_draw_w\":%s,\"power_limit_w\":%s,\"memory_used_mb\":%s,\"memory_total_mb\":%s}," "$(printf "%s" "$name"|json_escape)" "${util:-0}" "${temp:-0}" "${pdraw:-0}" "${plimit:-0}" "${memu:-0}" "${memt:-0}"
  else
    printf "\"gpu\":{\"available\":false},"
  fi
else
  printf "\"gpu\":{\"available\":false},"
fi
printf "\"updated_at\":%s}\n" "$(printf "%s" "$updated_at"|json_escape)"'

out=$("${SSH[@]}" "$remote_script")
METRICS_JSON="$out" python3 - "$STATION_HOST" <<'PY'
import json, os, sys
host=sys.argv[1]
data=json.loads(os.environ.get("METRICS_JSON", "{}"))
data["station_host"]=host
print(json.dumps(data, ensure_ascii=False, separators=(",",":")))
PY