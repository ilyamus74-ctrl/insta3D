#!/usr/bin/env bash
set -euo pipefail
CONFIG="$1"; ACTION="$2"; LOGS_MODE="$3"; shift 3
[[ -f "$CONFIG" ]] || { echo '{"paths":[],"errors":[{"message":"config not found"}]}'; exit 2; }
[[ "$ACTION" == "--delete" || "$ACTION" == "--dry-run" ]] || exit 2
[[ "$LOGS_MODE" == "--include-logs" || "$LOGS_MODE" == "--no-logs" ]] || exit 2
for id in "$@"; do [[ "$id" =~ ^[1-9][0-9]*$ ]] || { echo '{"paths":[],"errors":[{"message":"bad id"}]}'; exit 2; }; done
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?}"; : "${STATION_USER:?}"; : "${STATION_SSH_KEY:?}"; : "${STATION_BASE:=/home/makler_storage}"
SSH=(ssh -i "$STATION_SSH_KEY" -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
remote_script='set -euo pipefail
base="$1"; action="$2"; logs_mode="$3"; shift 3
[[ "$base" == "/home/makler_storage" ]] || { echo "{\"paths\":[],\"errors\":[{\"message\":\"bad station base\"}]}"; exit 2; }
python3 - "$base" "$action" "$logs_mode" "$@" <<'"'"'PY'"'"'
import glob,json,os,shutil,sys
base,action,logs_mode=sys.argv[1:4]; ids=sys.argv[4:]
paths=[]; errors=[]; freed=0
def size(p):
    if not os.path.lexists(p): return 0
    if os.path.islink(p) or os.path.isfile(p):
        try: return os.lstat(p).st_size
        except OSError: return 0
    total=0
    for root, dirs, files in os.walk(p, followlinks=False):
        for n in files:
            fp=os.path.join(root,n)
            try: total += os.lstat(fp).st_size
            except OSError: pass
    return total
def safe(p,jid):
    exact={f"{base}/input/job_{jid}",f"{base}/output/job_{jid}",f"{base}/logs/job_{jid}.log",f"{base}/logs/job_{jid}.nohup.log"}
    prefixes=[f"{base}/incoming/job_{jid}_", f"{base}/status/job_{jid}"]
    return (p in exact or any(p.startswith(a) for a in prefixes)) and os.path.normpath(p)==p and not p.startswith("/home/storage/orders")
for jid in ids:
    if not jid.isdigit() or jid.startswith("0"):
        errors.append({"remote_job_id":jid,"message":"bad id"}); continue
    candidates=[f"{base}/input/job_{jid}", f"{base}/output/job_{jid}"]
    candidates += glob.glob(f"{base}/incoming/job_{jid}_*")
    candidates += [f"{base}/logs/job_{jid}.log"]
    if logs_mode == "--include-logs": candidates += [f"{base}/logs/job_{jid}.nohup.log"]
    candidates += glob.glob(f"{base}/status/job_{jid}*")
    for p in sorted(set(candidates)):
        if not safe(p,jid): errors.append({"path":p,"message":"unsafe path rejected"}); continue
        if not os.path.lexists(p): paths.append({"remote_job_id":int(jid),"path":p,"missing":True,"size_bytes":0}); continue
        s=size(p); rec={"remote_job_id":int(jid),"path":p,"missing":False,"size_bytes":s,"deleted":False}
        if action == "--delete":
            try:
                if os.path.isdir(p) and not os.path.islink(p): shutil.rmtree(p)
                else: os.unlink(p)
                rec["deleted"]=True
                freed += s
            except Exception as e: errors.append({"path":p,"message":str(e)})
        paths.append(rec)
print(json.dumps({"paths":paths,"errors":errors,"freed_bytes":freed}, separators=(",",":")))
PY'
"${SSH[@]}" bash -s -- "$STATION_BASE" "$ACTION" "$LOGS_MODE" "$@" <<< "$remote_script"