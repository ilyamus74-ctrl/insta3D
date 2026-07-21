#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'USAGE'
Usage:
  ./deploy_web_changes.sh [--dry-run] [--from REF] [--to REF] [--target USER@HOST:/path/]
  ./deploy_web_changes.sh --apply [--from REF] [--to REF] [--target USER@HOST:/path/]
  ./deploy_web_changes.sh --list-only [--from REF] [--to REF]

Defaults:
  mode:   --dry-run
  from:   HEAD^
  to:     HEAD
  target: root@IlyamusWWW:/home/makler/web/

The script deploys only changed tracked files below web/. It never uses --delete,
never deploys docs/, and excludes production configuration and runtime data.
USAGE
}

MODE="dry-run"
FROM_REF="${DEPLOY_FROM:-HEAD^}"
TO_REF="${DEPLOY_TO:-HEAD}"
TARGET="${DEPLOY_TARGET:-root@IlyamusWWW:/home/makler/web/}"
BACKUP_ROOT="${DEPLOY_BACKUP_ROOT:-/home/makler/deploy_backups}"

while (($#)); do
    case "$1" in
        --dry-run)
            MODE="dry-run"
            shift
            ;;
        --apply)
            MODE="apply"
            shift
            ;;
        --list-only)
            MODE="list-only"
            shift
            ;;
        --from)
            [[ $# -ge 2 ]] || { usage >&2; exit 2; }
            FROM_REF="$2"
            shift 2
            ;;
        --to)
            [[ $# -ge 2 ]] || { usage >&2; exit 2; }
            TO_REF="$2"
            shift 2
            ;;
        --target)
            [[ $# -ge 2 ]] || { usage >&2; exit 2; }
            TARGET="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$REPO_ROOT" ]]; then
    echo "ERROR: script must be inside the Insta3D Git repository" >&2
    exit 1
fi

SOURCE_ROOT="$REPO_ROOT/web"
[[ -d "$SOURCE_ROOT" ]] || { echo "ERROR: missing source directory: $SOURCE_ROOT" >&2; exit 1; }

git -C "$REPO_ROOT" rev-parse --verify "${FROM_REF}^{commit}" >/dev/null
git -C "$REPO_ROOT" rev-parse --verify "${TO_REF}^{commit}" >/dev/null

is_excluded() {
    local path="$1"
    case "$path" in
        configs|configs/*|\
        storage|storage/*|\
        cache|cache/*|\
        templates_c|templates_c/*|\
        MySqlDump|MySqlDump/*|\
        remote_station/output|remote_station/output/*|\
        remote_station/stations.conf|\
        www/tmp|www/tmp/*|\
        www/.well-known|www/.well-known/*|\
        .venv-*|.venv-*/*|\
        tools/colmap_src|tools/colmap_src/*|\
        */build|*/build/*|\
        *.bak|*.bak.*|*.bkp|*.bkp.*|*.before_*|\
        *.sql|*.sql.gz)
            return 0
            ;;
    esac
    return 1
}

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
ALL_LIST="$TMP_DIR/all.zlist"
DEPLOY_LIST="$TMP_DIR/deploy.zlist"
DELETE_LIST="$TMP_DIR/delete.zlist"
: > "$ALL_LIST"
: > "$DEPLOY_LIST"
: > "$DELETE_LIST"

git -C "$REPO_ROOT" diff \
    --no-renames \
    --diff-filter=ACMRTUXB \
    --name-only -z \
    "$FROM_REF" "$TO_REF" -- web/ > "$ALL_LIST"

git -C "$REPO_ROOT" diff \
    --no-renames \
    --diff-filter=D \
    --name-only -z \
    "$FROM_REF" "$TO_REF" -- web/ > "$DELETE_LIST"

if [[ -s "$DELETE_LIST" ]]; then
    echo "ERROR: deleted web files exist in ${FROM_REF}..${TO_REF}." >&2
    echo "This deploy script intentionally never deletes production files:" >&2
    while IFS= read -r -d '' path; do
        printf '  %s\n' "$path" >&2
    done < "$DELETE_LIST"
    exit 1
fi

while IFS= read -r -d '' path; do
    [[ "$path" == web/* ]] || continue
    relative="${path#web/}"
    [[ -n "$relative" ]] || continue

    if is_excluded "$relative"; then
        printf 'SKIP protected: web/%s\n' "$relative" >&2
        continue
    fi

    source_path="$SOURCE_ROOT/$relative"
    if [[ ! -e "$source_path" && ! -L "$source_path" ]]; then
        echo "ERROR: changed source is missing: web/$relative" >&2
        exit 1
    fi

    printf '%s\0' "$relative" >> "$DEPLOY_LIST"
done < "$ALL_LIST"

if [[ ! -s "$DEPLOY_LIST" ]]; then
    echo "No deployable web files in ${FROM_REF}..${TO_REF}."
    exit 0
fi

echo "Repository: $REPO_ROOT"
echo "Range:      $FROM_REF..$TO_REF"
echo "Mode:       $MODE"
if [[ "$MODE" != "list-only" ]]; then
    echo "Target:     $TARGET"
fi
echo "Files:"
while IFS= read -r -d '' relative; do
    printf '  web/%s\n' "$relative"
done < "$DEPLOY_LIST"

if [[ "$MODE" == "list-only" ]]; then
    exit 0
fi

# Validate deployable source files before transfer.
while IFS= read -r -d '' relative; do
    source_path="$SOURCE_ROOT/$relative"
    case "$relative" in
        *.php)
            php -l "$source_path" >/dev/null
            ;;
        *.sh)
            bash -n "$source_path"
            ;;
        *.py)
            python3 - "$source_path" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
compile(path.read_text(encoding="utf-8"), str(path), "exec")
PY
            ;;
    esac
done < "$DEPLOY_LIST"

echo "Local syntax validation: PASS"

RSYNC_ARGS=(
    -a
    -r
    --relative
    --protect-args
    --itemize-changes
    --human-readable
    --from0
    --files-from="$DEPLOY_LIST"
)

if [[ "$MODE" == "dry-run" ]]; then
    RSYNC_ARGS+=(--dry-run)
else
    timestamp="$(date +%Y%m%d_%H%M%S)"
    RSYNC_ARGS+=(
        --backup
        --backup-dir="${BACKUP_ROOT}/${timestamp}"
    )
fi

rsync "${RSYNC_ARGS[@]}" "$SOURCE_ROOT/" "$TARGET"

if [[ "$MODE" == "dry-run" ]]; then
    echo "Dry-run complete. Re-run with --apply after reviewing the rsync list."
else
    echo "Deploy complete. Overwritten remote files were backed up under:"
    echo "  ${BACKUP_ROOT}/${timestamp}"
    if grep -zqx 'tools/sfm_remote_worker.php' "$DEPLOY_LIST"; then
        echo "NOTICE: sfm_remote_worker.php changed. Restart makler-sfm-worker.service manually after remote lint."
    fi
fi
