#!/usr/bin/env bash
set -Eeuo pipefail

DEFAULT_PATCH="/tmp/new_patch.diff"
PATCH_FILE="$DEFAULT_PATCH"
KEEP_PATCH=0
STRICT=0
PATCH_ARG_SET=0

usage() {
  cat <<'EOF'
Usage:
  ./apply_git_patch.sh [patch.diff] [--keep] [--strict]

Options:
  --keep    Do not clear /tmp/new_patch.diff after successful application.
  --strict  Use exact git apply only; disable safe fallback modes.

Default patch:
  /tmp/new_patch.diff
EOF
}

while (($# > 0)); do
  case "$1" in
    --keep)
      KEEP_PATCH=1
      ;;
    --strict)
      STRICT=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    -*)
      echo "[ERROR] Unknown option: $1"
      usage
      exit 2
      ;;
    *)
      if ((PATCH_ARG_SET == 1)); then
        echo "[ERROR] More than one patch file was supplied."
        usage
        exit 2
      fi
      PATCH_FILE="$1"
      PATCH_ARG_SET=1
      ;;
  esac
  shift
done

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "[ERROR] Current directory is not inside a Git repository."
  exit 2
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

if [[ ! -f "$PATCH_FILE" ]]; then
  echo "[ERROR] Patch file not found: $PATCH_FILE"
  usage
  exit 2
fi

PATCH_FILE="$(readlink -f "$PATCH_FILE")"
DIAG_DIR="$(mktemp -d /tmp/git_patch_XXXXXXXX)"
NORMALIZED_PATCH="$DIAG_DIR/normalized.diff"
SUCCESS=0

cleanup() {
  local rc=$?
  if ((SUCCESS == 1)); then
    rm -rf "$DIAG_DIR"
  else
    echo "[INFO] Diagnostics preserved in: $DIAG_DIR"
  fi
  exit "$rc"
}
trap cleanup EXIT

echo "[INFO] Repo: $REPO_ROOT"
echo "[INFO] Patch: $PATCH_FILE"
echo "[INFO] HEAD: $(git rev-parse --short=12 HEAD)"
echo "[INFO] Current branch: $(git branch --show-current || true)"
echo "[INFO] Current git status:"
git status --short

echo "[INFO] Normalizing UTF-8 BOM and CRLF..."
python3 - "$PATCH_FILE" "$NORMALIZED_PATCH" <<'PY'
from pathlib import Path
import re
import sys

source = Path(sys.argv[1]).read_bytes()
if source.startswith(b"\xef\xbb\xbf"):
    source = source[3:]
source = source.replace(b"\r\n", b"\n").replace(b"\r", b"\n")

text = source.decode("utf-8")
lines = text.splitlines()
normalized = []
in_hunk = False
old_remaining = 0
new_remaining = 0
repaired_empty_context = 0

hunk_re = re.compile(
    r"^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@"
)

for line in lines:
    match = hunk_re.match(line)
    if match:
        old_remaining = int(match.group(2) or 1)
        new_remaining = int(match.group(4) or 1)
        in_hunk = old_remaining > 0 or new_remaining > 0
        normalized.append(line)
        continue

    if in_hunk and line == "":
        line = " "
        repaired_empty_context += 1

    normalized.append(line)

    if not in_hunk or line.startswith("\\ No newline"):
        continue

    prefix = line[:1]
    if prefix == " ":
        old_remaining -= 1
        new_remaining -= 1
    elif prefix == "-":
        old_remaining -= 1
    elif prefix == "+":
        new_remaining -= 1

    if old_remaining <= 0 and new_remaining <= 0:
        in_hunk = False

Path(sys.argv[2]).write_text(
    "\n".join(normalized) + "\n",
    encoding="utf-8",
)

if repaired_empty_context:
    print(
        "[INFO] Repaired empty context lines in patch: "
        f"{repaired_empty_context}"
    )
PY

if [[ ! -s "$NORMALIZED_PATCH" ]]; then
  echo "[ERROR] Patch is empty."
  exit 2
fi

echo "[INFO] Validating patch format..."
if ! git apply --numstat "$NORMALIZED_PATCH" >"$DIAG_DIR/numstat.txt" 2>"$DIAG_DIR/format-error.txt"; then
  echo "[ERROR] Git cannot parse the patch."
  cat "$DIAG_DIR/format-error.txt"
  exit 1
fi
cat "$DIAG_DIR/numstat.txt"

patch_has_zero_context() {
  awk '
    function close_hunk() {
      if (in_hunk && !has_context) {
        zero_context = 1
      }
      in_hunk = 0
      has_context = 0
    }

    /^diff --git / {
      close_hunk()
      next
    }

    /^@@ / {
      close_hunk()
      in_hunk = 1
      next
    }

    in_hunk && /^ / {
      has_context = 1
      next
    }

    END {
      close_hunk()
      exit(zero_context ? 0 : 1)
    }
  ' "$NORMALIZED_PATCH"
}

HAS_ZERO_CONTEXT=0
if patch_has_zero_context; then
  HAS_ZERO_CONTEXT=1
  echo "[WARN] Patch contains one or more zero-context hunks."
  echo "[WARN] A checked --unidiff-zero fallback may be required."
fi

check_already_applied() {
  if git apply --reverse --check --whitespace=nowarn "$NORMALIZED_PATCH" \
      >"$DIAG_DIR/reverse-exact.log" 2>&1; then
    return 0
  fi

  if git apply --reverse --check --recount --whitespace=nowarn \
      "$NORMALIZED_PATCH" >"$DIAG_DIR/reverse-recount.log" 2>&1; then
    return 0
  fi

  if ((HAS_ZERO_CONTEXT == 1)) \
      && git apply --reverse --check --recount --unidiff-zero \
        --whitespace=nowarn "$NORMALIZED_PATCH" \
        >"$DIAG_DIR/reverse-zero-context.log" 2>&1; then
    return 0
  fi

  return 1
}

if check_already_applied; then
  echo "[INFO] Patch appears to be already applied. Nothing changed."
  SUCCESS=1
  if ((KEEP_PATCH == 0)) && [[ "$PATCH_FILE" == "$DEFAULT_PATCH" ]]; then
    : > "$PATCH_FILE"
  fi
  exit 0
fi

STRATEGY_NAME=""
APPLY_ARGS=()

try_strategy() {
  local name="$1"
  shift
  local log="$DIAG_DIR/check-${name}.log"

  echo "[INFO] Checking strategy: $name"
  if git apply --check --whitespace=nowarn "$@" "$NORMALIZED_PATCH" \
      >"$log" 2>&1; then
    STRATEGY_NAME="$name"
    APPLY_ARGS=("$@")
    return 0
  fi
  return 1
}

if try_strategy exact; then
  :
elif ((STRICT == 0)) && try_strategy recount --recount; then
  :
elif ((STRICT == 0 && HAS_ZERO_CONTEXT == 1)) \
    && try_strategy zero-context --recount --unidiff-zero; then
  :
elif ((STRICT == 0)) \
    && try_strategy whitespace --recount \
      --ignore-space-change --ignore-whitespace; then
  :
elif ((STRICT == 0 && HAS_ZERO_CONTEXT == 1)) \
    && try_strategy zero-context-whitespace --recount --unidiff-zero \
      --ignore-space-change --ignore-whitespace; then
  :
else
  echo "[ERROR] Patch does not apply safely to the current working tree."
  echo
  echo "[INFO] This normally means one of the following:"
  echo "  1. the patch was generated against another commit;"
  echo "  2. part of the patch is already present;"
  echo "  3. the target code was edited after the patch was generated;"
  echo "  4. a handcrafted hunk has insufficient or incorrect context."
  echo
  echo "[INFO] Patch target files:"
  sed -n 's#^+++ b/##p' "$NORMALIZED_PATCH" | sort -u
  echo
  echo "[INFO] Detailed check logs:"
  for log in "$DIAG_DIR"/check-*.log; do
    [[ -e "$log" ]] || continue
    echo "===== $(basename "$log") ====="
    sed -n '1,120p' "$log"
  done
  echo
  echo "[INFO] No partial application was performed."
  exit 1
fi

echo "[INFO] Applying with strategy: $STRATEGY_NAME"
git apply --whitespace=nowarn "${APPLY_ARGS[@]}" "$NORMALIZED_PATCH"

echo "[INFO] Checking resulting diff..."
if ! git diff --check >"$DIAG_DIR/diff-check.log" 2>&1; then
  echo "[ERROR] Patch applied, but git diff --check found whitespace errors."
  cat "$DIAG_DIR/diff-check.log"
  echo "[INFO] Changes remain applied for inspection."
  exit 1
fi

mapfile -t CHANGED_FILES < <(
  {
    git diff --name-only --diff-filter=ACMRT
    git ls-files --others --exclude-standard
  } | awk 'NF' | sort -u
)

VALIDATION_FAILED=0

echo "[INFO] Syntax checking changed PHP files..."
PHP_FOUND=0
for file in "${CHANGED_FILES[@]}"; do
  [[ "$file" == *.php ]] || continue
  [[ -f "$file" ]] || continue
  PHP_FOUND=1
  echo "[PHP-LINT] $file"
  if ! php -l "$file"; then
    VALIDATION_FAILED=1
  fi
done
if ((PHP_FOUND == 0)); then
  echo "[INFO] No changed PHP files."
fi

echo "[INFO] Syntax checking changed shell scripts..."
SH_FOUND=0
for file in "${CHANGED_FILES[@]}"; do
  [[ "$file" == *.sh ]] || continue
  [[ -f "$file" ]] || continue
  SH_FOUND=1
  echo "[BASH-LINT] $file"
  if ! bash -n "$file"; then
    VALIDATION_FAILED=1
  fi
done
if ((SH_FOUND == 0)); then
  echo "[INFO] No changed shell scripts."
fi

echo "[INFO] Changed files:"
git status --short

if ((VALIDATION_FAILED == 1)); then
  echo "[ERROR] Patch was applied, but syntax validation failed."
  echo "[INFO] Changes remain applied for inspection and correction."
  exit 1
fi

echo "[INFO] Patch applied successfully using: $STRATEGY_NAME"

SUCCESS=1
if ((KEEP_PATCH == 0)) && [[ "$PATCH_FILE" == "$DEFAULT_PATCH" ]]; then
  : > "$PATCH_FILE"
  echo "[INFO] Cleared $DEFAULT_PATCH"
fi
