#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   ./git_push.sh
#   ./git_push.sh "my commit message"
#   ./git_push.sh "my commit message" origin main
#
# Defaults:
#   message = "sync local -> remote"
#   remote  = origin
#   branch  = current branch (or main if detached)

COMMIT_MESSAGE="${1:-sync local -> remote}"
REMOTE="${2:-origin}"
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$CURRENT_BRANCH" == "HEAD" ]]; then
  CURRENT_BRANCH="main"
fi
BRANCH="${3:-$CURRENT_BRANCH}"

echo "==> Remote: ${REMOTE}"
echo "==> Branch: ${BRANCH}"

if ! git remote get-url "${REMOTE}" >/dev/null 2>&1; then
  echo "ERROR: remote '${REMOTE}' is not configured."
  echo "Hint: git remote add ${REMOTE} <repo-url>"
  exit 1
fi

echo "==> Fetch latest ${REMOTE}/${BRANCH}"
git fetch "${REMOTE}" "${BRANCH}" --prune

REMOTE_HEAD_BEFORE="$(git rev-parse "${REMOTE}/${BRANCH}")"

echo "==> Stage all changes"
git add -A

if git diff --cached --quiet; then
  echo "==> No staged changes to commit."
  DID_COMMIT="0"
else
  echo "==> Commit: ${COMMIT_MESSAGE}"
  git commit -m "${COMMIT_MESSAGE}"
  DID_COMMIT="1"
fi

LOCAL_HEAD="$(git rev-parse HEAD)"
REMOTE_HEAD="${REMOTE_HEAD_BEFORE}"

echo "==> Push with lease"
echo "    local : ${LOCAL_HEAD}"
echo "    remote: ${REMOTE_HEAD}"
git push -u "${REMOTE}" "HEAD:${BRANCH}" --force-with-lease="${BRANCH}:${REMOTE_HEAD}"

REMOTE_HEAD_AFTER="$(git rev-parse "${REMOTE}/${BRANCH}")"
LAST_COMMIT_SUBJECT="$(git log -1 --pretty=%s)"
LAST_COMMIT_AUTHOR="$(git log -1 --pretty='%an <%ae>')"
LAST_COMMIT_DATE="$(git log -1 --date=iso-strict --pretty=%cd)"
LAST_COMMIT_FILES="$(git show --name-status --pretty='' -1)"
WORKTREE_STATUS="$(git status --short)"
if [[ -z "${WORKTREE_STATUS}" ]]; then
  WORKTREE_STATUS="(clean)"
fi

echo "==> Done."
echo
echo "===== COPY FOR CODEX ====="
echo "repo_sync_report:"
echo "  remote: ${REMOTE}"
echo "  branch: ${BRANCH}"
echo "  remote_head_before: ${REMOTE_HEAD_BEFORE}"
echo "  local_head_pushed: ${LOCAL_HEAD}"
echo "  remote_head_after: ${REMOTE_HEAD_AFTER}"
echo "  committed_in_this_run: ${DID_COMMIT}"
echo "  last_commit:"
echo "    hash: ${LOCAL_HEAD}"
echo "    subject: ${LAST_COMMIT_SUBJECT}"
echo "    author: ${LAST_COMMIT_AUTHOR}"
echo "    date: ${LAST_COMMIT_DATE}"
echo "  changed_files: |"
echo "${LAST_COMMIT_FILES}" | sed 's/^/    /'
echo "  working_tree_status: |"
echo "${WORKTREE_STATUS}" | sed 's/^/    /'
echo "===== END COPY FOR CODEX ====="