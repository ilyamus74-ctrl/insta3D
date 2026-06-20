#!/usr/bin/env bash
set -euo pipefail

PATCH_FILE="${1:-/tmp/new_patch.diff}"

echo "[INFO] Repo: $(pwd)"
echo "[INFO] Patch: $PATCH_FILE"

if [ ! -f "$PATCH_FILE" ]; then
  echo "[ERROR] Patch file not found: $PATCH_FILE"
  echo "Usage: ./apply_git_patch.sh /path/to/patch.diff"
  exit 2
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "[ERROR] Current directory is not a git repo"
  echo "[HINT] Run this from repo root, for example:"
  echo "       cd ~/Документы/Insta3D"
  exit 2
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

echo "[INFO] Repo root: $REPO_ROOT"
echo "[INFO] Current branch:"
git branch --show-current || true

echo "[INFO] Current git status:"
git status --short

echo "[INFO] Normalizing CRLF if needed..."
TMP_PATCH="$(mktemp /tmp/git_patch_XXXXXX.diff)"
sed 's/\r$//' "$PATCH_FILE" > "$TMP_PATCH"

echo "[INFO] Checking patch..."
if ! git apply --check "$TMP_PATCH"; then
  echo "[ERROR] Patch check failed. Nothing applied."
  echo "[INFO] Try:"
  echo "  git apply --check $TMP_PATCH"
  echo
  echo "[INFO] If paths are shifted, inspect patch headers:"
  echo "  grep -n '^+++\\|^---' $TMP_PATCH | head -40"
  exit 1
fi

echo "[INFO] Applying patch..."
git apply "$TMP_PATCH"

echo "[INFO] Patch applied successfully."

echo "[INFO] Changed files:"
git status --short

echo "[INFO] Kotlin syntax/build check if Gradle project exists..."
if [ -x "./gradlew" ]; then
  ./gradlew :app:compileDebugKotlin
else
  echo "[WARN] ./gradlew not found or not executable. Skipping Gradle check."
fi

echo "[INFO] Done."
