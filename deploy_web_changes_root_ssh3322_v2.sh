#!/usr/bin/env bash
set -Eeuo pipefail

LOCAL_WEB="/home/ilyamus/Документы/Insta3D/web"

REMOTE_USER="root"
REMOTE_HOST="makler.cargocells.com"
REMOTE_PORT="3322"
REMOTE_WEB="/home/makler/web"
REMOTE_BACKUPS="/home/makler/deploy_backups"

MODE="${1:---dry-run}"
RESTART_WORKER="${2:-}"

case "$MODE" in
    --dry-run|--apply)
        ;;
    *)
        echo "Usage:"
        echo "  $0 --dry-run"
        echo "  $0 --apply"
        echo "  $0 --apply --restart-worker"
        exit 2
        ;;
esac

FILES=(
    "libs/auto_photo_sparse_lib.php"
    "libs/auto_photo_sparse_web_lib.php"
    "libs/auto_photo_export_worker_lib.php"

    "tools/sfm_remote_worker.php"

    "remote_station/export_sparse_ply.sh"

    "tests/auto_photo_export_worker_test.php"
    "tests/auto_photo_export_shell_test.sh"
    "tests/auto_photo_sparse_review_test.php"
    "tests/auto_photo_sparse_web_test.php"

    "www/order.php"
)

SSH=(
    ssh
    -p "$REMOTE_PORT"
    -o BatchMode=yes
    -o StrictHostKeyChecking=accept-new
    "${REMOTE_USER}@${REMOTE_HOST}"
)

echo "Local:  $LOCAL_WEB"
echo "Remote: ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_WEB}/"
echo "Mode:   $MODE"
echo
echo "Files:"
printf '  web/%s\n' "${FILES[@]}"

#
# Проверка локальных файлов
#

echo
echo "==> Local validation"

for FILE in "${FILES[@]}"; do
    PATH_LOCAL="${LOCAL_WEB}/${FILE}"

    if [[ ! -f "$PATH_LOCAL" ]]; then
        echo "ERROR: missing local file:"
        echo "  $PATH_LOCAL"
        exit 1
    fi

    case "$FILE" in
        *.php)
            php -l "$PATH_LOCAL" >/dev/null
            ;;
        *.sh)
            bash -n "$PATH_LOCAL"
            ;;
    esac
done

php "$LOCAL_WEB/tests/auto_photo_export_worker_test.php"
bash "$LOCAL_WEB/tests/auto_photo_export_shell_test.sh"
php "$LOCAL_WEB/tests/auto_photo_sparse_review_test.php"
if php -r 'exit(class_exists("mysqli_result") ? 0 : 1);'; then
    php "$LOCAL_WEB/tests/auto_photo_sparse_web_test.php"
else
    echo "WARN: local PHP has no mysqli extension; sparse web test skipped locally"
fi

echo "Local validation: PASS"

#
# Резервная копия сервера
#

if [[ "$MODE" == "--apply" ]]; then
    echo
    echo "==> Remote backup"

    "${SSH[@]}" bash -s -- \
        "$REMOTE_WEB" \
        "$REMOTE_BACKUPS" \
        "${FILES[@]}" <<'REMOTE_BACKUP'
set -Eeuo pipefail

REMOTE_WEB="$1"
REMOTE_BACKUPS="$2"
shift 2

BACKUP="${REMOTE_BACKUPS}/manual_$(date +%Y%m%d_%H%M%S)"

mkdir -p "$BACKUP"

for FILE in "$@"; do
    SOURCE="${REMOTE_WEB}/${FILE}"

    if [[ -e "$SOURCE" || -L "$SOURCE" ]]; then
        DESTINATION="${BACKUP}/${FILE}"

        mkdir -p "$(dirname "$DESTINATION")"
        cp -a -- "$SOURCE" "$DESTINATION"
    fi
done

echo "Backup: $BACKUP"
REMOTE_BACKUP
fi

#
# Синхронизация
#

echo
echo "==> Rsync"

RSYNC_ARGS=(
    -a
    -c
    --relative
    --no-owner
    --no-group
    --omit-dir-times
    --protect-args
    --itemize-changes
    --human-readable
)

if [[ "$MODE" == "--dry-run" ]]; then
    RSYNC_ARGS+=(--dry-run)
fi

(
    cd "$LOCAL_WEB"

    RSYNC_RSH="ssh -p ${REMOTE_PORT} -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
        rsync \
        "${RSYNC_ARGS[@]}" \
        "${FILES[@]/#/./}" \
        "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_WEB}/"
)

if [[ "$MODE" == "--dry-run" ]]; then
    echo
    echo "Dry-run complete."
    echo "Для применения:"
    echo "  $0 --apply"
    exit 0
fi

#
# Проверка сервера
#

echo
echo "==> Remote validation"

"${SSH[@]}" bash -s -- "$REMOTE_WEB" <<'REMOTE_VERIFY'
set -Eeuo pipefail

REMOTE_WEB="$1"

cd "$REMOTE_WEB"

REQUIRED=(
    "libs/auto_photo_sparse_lib.php"
    "libs/auto_photo_sparse_web_lib.php"
    "libs/auto_photo_export_worker_lib.php"

    "tools/sfm_remote_worker.php"

    "remote_station/export_sparse_ply.sh"

    "tests/auto_photo_export_worker_test.php"
    "tests/auto_photo_export_shell_test.sh"
    "tests/auto_photo_sparse_review_test.php"
    "tests/auto_photo_sparse_web_test.php"

    "www/order.php"
)

for FILE in "${REQUIRED[@]}"; do
    if [[ ! -f "$FILE" ]]; then
        echo "ERROR: remote file missing:"
        echo "  ${REMOTE_WEB}/${FILE}"
        exit 1
    fi
done

chmod 755 \
    remote_station/export_sparse_ply.sh \
    tests/auto_photo_export_shell_test.sh

php -l libs/auto_photo_sparse_lib.php
php -l libs/auto_photo_sparse_web_lib.php
php -l libs/auto_photo_export_worker_lib.php
php -l tools/sfm_remote_worker.php
php -l www/order.php

bash -n remote_station/export_sparse_ply.sh
bash -n tests/auto_photo_export_shell_test.sh

php tests/auto_photo_export_worker_test.php
bash tests/auto_photo_export_shell_test.sh
php tests/auto_photo_sparse_review_test.php
php tests/auto_photo_sparse_web_test.php

if grep -n \
    "photo_export_shell_not_ready" \
    tools/sfm_remote_worker.php
then
    echo "ERROR: temporary photo export guard is still present"
    exit 1
fi

echo "Remote validation: PASS"
REMOTE_VERIFY

#
# Необязательный перезапуск worker
#

if [[ "$RESTART_WORKER" == "--restart-worker" ]]; then
    echo
    echo "==> Restart worker"

    "${SSH[@]}" '
set -Eeuo pipefail

systemctl restart makler-sfm-worker.service
systemctl status makler-sfm-worker.service --no-pager
journalctl -u makler-sfm-worker.service -n 50 --no-pager
'
else
    echo
    echo "Worker не перезапущен."
    echo "После проверки запусти:"
    echo "  $0 --apply --restart-worker"
fi

echo
echo "Deploy complete."

