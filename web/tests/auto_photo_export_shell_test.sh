#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/web/remote_station/export_sparse_ply.sh"
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
BIN="$TMP/bin"; mkdir -p "$BIN"
LOG="$TMP/calls.log"; export LOG SCP_MODE=success
cat >"$BIN/ssh" <<'EOF'
#!/usr/bin/env bash
printf 'ssh|%s\n' "${!#}" >>"$LOG"
exit "${SSH_EXIT:-0}"
EOF
cat >"$BIN/scp" <<'EOF'
#!/usr/bin/env bash
printf 'scp|%s\n' "$*" >>"$LOG"
dest="${!#}"
case "${SCP_MODE:-success}" in success) printf ply >"$dest";; empty) : >"$dest";; failure) exit 23;; esac
EOF
cat >"$BIN/rsync" <<'EOF'
#!/usr/bin/env bash
printf 'rsync|%s\n' "$*" >>"$LOG"
printf legacy >"${!#}"
EOF
chmod +x "$BIN"/*
export PATH="$BIN:$PATH"
CONFIG="$TMP/station.conf"
cat >"$CONFIG" <<EOF
STATION_HOST=stub
STATION_USER=test
STATION_SSH_KEY=$TMP/key
STATION_BASE=/remote/base
EOF
touch "$TMP/key"
fail(){ echo "FAIL: $1" >&2; exit 1; }
ok(){ "$@" >/dev/null || fail "$1"; }
reject(){ : >"$LOG"; if "$@" >/dev/null 2>&1; then fail "expected rejection: $*"; fi; [[ ! -s "$LOG" ]] || fail "validation contacted remote"; }
run_photo(){ : >"$LOG"; SCP_MODE="${1:-success}" "$SCRIPT" "$CONFIG" 12 0 "$TMP/out" "$TMP/out/job_34/sparse_0.ply" 34 >/dev/null; }

bash -n "$SCRIPT" || fail syntax
ok "$SCRIPT" "$CONFIG" legacy 0 "$TMP/legacy"
[[ -f "$TMP/legacy/job_legacy/colmap/sparse/0/model.ply" ]] || fail legacy_destination
! grep -q photo_export_tmp "$LOG" || fail legacy_photo_temp
reject "$SCRIPT" "$CONFIG" 1 0 /tmp x
reject "$SCRIPT" "$CONFIG" 1 0 /tmp x 2 extra
for args in "x 0 $TMP/out $TMP/out/job_34/sparse_0.ply 34" "12 01 $TMP/out $TMP/out/job_34/sparse_01.ply 34" "12 0 $TMP/out $TMP/out/job_x/sparse_0.ply x" "12 0 relative relative/job_34/sparse_0.ply 34" "12 0 $TMP/a/../out $TMP/a/../out/job_34/sparse_0.ply 34" "12 0 $TMP/out $TMP/nope 34" "12 0 $TMP/out $TMP/out/job_35/sparse_0.ply 34" "12 0 $TMP/out $TMP/out/job_34/sparse_1.ply 34" "12 0 $TMP/out $TMP/out/job_12/sparse_0.ply 12"; do
  read -r a b c d e <<<"$args"; reject "$SCRIPT" "$CONFIG" "$a" "$b" "$c" "$d" "$e"
done
run_photo success || fail valid_photo
FINAL="$TMP/out/job_34/sparse_0.ply"
[[ -s "$FINAL" ]] || fail final_artifact
! compgen -G "$(dirname "$FINAL")/.sparse_0.ply.tmp.*" >/dev/null || fail local_temp
grep -F -- '--input_path /remote/base/output/job_12/colmap/sparse/0' "$LOG" >/dev/null || fail source_input
grep -F -- '--output_path /remote/base/output/job_34/photo_export_tmp/sparse_0.ply' "$LOG" >/dev/null || fail photo_output
grep -F -- 'rm -rf -- /remote/base/output/job_34/photo_export_tmp' "$LOG" >/dev/null || fail success_cleanup
! grep -F -- 'rm -rf -- /remote/base/output/job_12' "$LOG" >/dev/null || fail source_cleanup
[[ -s "$FINAL" ]] || fail cleanup_final
: >"$LOG"; if run_photo failure; then fail scp_failure_status; fi
grep -F -- 'rm -rf -- /remote/base/output/job_34/photo_export_tmp' "$LOG" >/dev/null || fail scp_cleanup
: >"$LOG"; if run_photo empty; then fail empty_status; fi
grep -F -- 'rm -rf -- /remote/base/output/job_34/photo_export_tmp' "$LOG" >/dev/null || fail empty_cleanup
echo OK
