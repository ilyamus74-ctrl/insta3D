#!/usr/bin/env bash
set -euo pipefail
root=$(mktemp -d); trap 'rm -rf "$root"' EXIT
mkdir -p "$root/bin" "$root/photos" "$root/base/status"; printf x > "$root/photos/frame_000001.jpg"; printf '{"frames":[],"sidecars":[]}' > "$root/manifest.json"; printf old > "$root/base/status/job_7.json"
cat > "$root/stations.conf" <<EOF
STATION_HOST=x
STATION_USER=x
STATION_SSH_KEY=x
STATION_BASE="$root/base"
EOF
cat > "$root/bin/rsync" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
src=${@: -2:1}; dst=${@: -1}; path=${dst#*:}; mkdir -p "$(dirname "$path")"; cp "$src" "$path"
EOF
cat > "$root/bin/ssh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
cmd=${@: -1}; printf '%s\n' "$cmd" >> "$SSH_LOG"; sh -c "$cmd"
EOF
cat > "$root/bin/python3" <<'EOF'
#!/usr/bin/env bash
exit 127
EOF
chmod +x "$root/bin/ssh" "$root/bin/rsync" "$root/bin/python3"
export PATH="$root/bin:$PATH" SSH_LOG="$root/ssh.log"
bash "$(dirname "$0")/../remote_station/run_auto_photo_prepare_job.sh" "$root/stations.conf" 7 "$root/photos" "$root/manifest.json"
for _ in {1..30}; do [[ -f "$root/base/status/job_7.json" ]] && break; sleep .1; done
grep -q '"status":"ERROR"' "$root/base/status/job_7.json"
grep -q 'auto_photo_prepare_processor_start_failed' "$root/base/status/job_7.json"
grep -q '\$1' "$root/ssh.log"; grep -q '\$5' "$root/ssh.log"
[[ ! -e "$root/base/incoming/auto_photo_prepare_7" ]]
echo PASS
