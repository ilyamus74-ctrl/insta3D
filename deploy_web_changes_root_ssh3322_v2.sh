
#!/usr/bin/env bash
set -Eeuo pipefail

LOCAL_WEB="/home/ilyamus/Документы/Insta3D/web/"
REMOTE="root@makler.cargocells.com"
REMOTE_PORT="3322"
REMOTE_WEB="/home/makler/web/"
BACKUP_ROOT="/home/makler/deploy_backups"

MODE="${1:---dry-run}"
RESTART_WORKER="${2:-}"

case "$MODE" in
    --dry-run|--apply)
        ;;
    *)
        echo "Использование:"
        echo "  $0 --dry-run"
        echo "  $0 --apply"
        echo "  $0 --apply --restart-worker"
        exit 2
        ;;
esac

SSH_COMMAND="ssh -p ${REMOTE_PORT} -o BatchMode=yes -o StrictHostKeyChecking=accept-new"

EXCLUDES=(
    "--exclude=configs/"
    "--exclude=storage/"
    "--exclude=cache/"
    "--exclude=templates_c/"
    "--exclude=MySqlDump/"
    "--exclude=remote_station/output/"
    "--exclude=remote_station/stations.conf"
    "--exclude=www/tmp/"
    "--exclude=www/.well-known/"
    "--exclude=tools/colmap_src/"
    "--exclude=.venv-*/"
    "--exclude=*/build/"
    "--exclude=*.bak"
    "--exclude=*.bak.*=tools/colmap_src/"
    "--exclude=.venv-*/"
    "--"
    "--exclude=*.bkp"
    "--exclude=*.bkp.*"
    "--exclude=*.before_*"
    "--exclude=*.sql"
    "--exclude=*.sql.gz"
)

echo "Local:  $LOCAL_WEB"
echo "Remote: ${REMOTE}:${REMOTE_WEB}"
echo "Mode:   $MODE"

[[ -d "$LOCAL_WEB" ]] || {
    echo "ERROR: каталог не найден: $LOCAL_WEB" >&2
    exit 1
}

echo
echo "==> Проверка локального PHP и shell"

while IFS= read -r -d '' FILE; do
    case "$FILE" in
        *.php)
            php -l "$FILE" >/dev/null
            ;;
        *.sh)
            bash -n "$FILE"
            ;;
    esac
done < <(
    find "$LOCAL_WEB" \
        -type f \
        \( -name '*.php' -o -name '*.sh' \) \
        -print0
)

echo "Local syntax validation: PASS"

RSYNC_ARGS=(
    -a
    -c
    --no-owner
    --no-group
    --omit-dir-times
    --protect-args
    --itemize-changes
    --human-readable
    --no-perms
)

RSYNC_ARGS+=("${EXCLUDES[@]}")

if [[ "$MODE" == "--dry-run" ]]; then
    RSYNC_ARGS+=(--dry-run)
else
    TIMESTAMP="$(date +%Y%m%d_%H%M%S)"

    RSYNC_ARGS+=(
        --backup
        "--backup-dir=${BACKUP_ROOT}/${TIMESTAMP}"
    )
fi

echo
echo "==> Rsync всего web/"

RSYNC_RSH="$SSH_COMMAND" \
rsync \
    "${RSYNC_ARGS[@]}" \
    "$LOCAL_WEB" \
    "${REMOTE}:${REMOTE_WEB}"

if [[ "$MODE" == "--dry-run" ]]; then
    echo
    echo "Dry-run complete."
    echo "Для применения:"
    echo "  $0 --apply"
    exit 0
fi

echo
echo "Backup:"
echo "  ${BACKUP_ROOT}/${TIMESTAMP}"

echo
echo "==> Проверка ключевых файлов на сервере"

$SSH_COMMAND "$REMOTE" '
set -Eeuo pipefail

cd /home/makler/web

REQUIRED=(
    libs/auto_photo_sparse_lib.php
    libs/auto_photo_sparse_web_lib.php
    libs/auto_photo_sparse_ui_lib.php
    libs/auto_photo_export_worker_lib.php

    tools/sfm_remote_worker.php
    remote_station/export_sparse_ply.sh

    tests/auto_photo_sparse_ui_test.php
    tests/auto_photo_export_worker_test.php
    tests/auto_photo_export_shell_test.sh
    tests/auto_photo_sparse_review_test.php
    tests/auto_photo_sparse_web_test.php

    www/order.php
)

for FILE in "${REQUIRED[@]}"; do
    [[ -f "$FILE" ]] || {
        echo "ERROR: отсутствует серверный файл: $FILE" >&2
        exit 1
    }
done

chmod 755 \
    remote_station/export_sparse_ply.sh \
    tests/auto_photo_export_shell_test.sh

php -l libs/auto_photo_sparse_lib.php
php -l libs/auto_photo_sparse_web_lib.php
php -l libs/auto_photo_sparse_ui_lib.php
php -l libs/auto_photo_export_worker_lib.php
php -l tools/sfm_remote_worker.php
php -l www/order.php

bash -n remote_station/export_sparse_ply.sh
bash -n tests/auto_photo_export_shell_test.sh

php tests/auto_photo_sparse_ui_test.php
php tests/auto_photo_export_worker_test.php
bash tests/auto_photo_export_shell_test.sh
php tests/auto_photo_sparse_review_test.php
php tests/auto_photo_sparse_web_test.php

echo "Remote validation: PASS"
'

if [[ "$RESTART_WORKER" == "--restart-worker" ]]; then
    echo
    echo "==> Перезапуск worker"

    $SSH_COMMAND "$REMOTE" '
set -Eeuo pipefail

systemctl restart makler-sfm-worker.service
systemctl status makler-sfm-worker.service --no-pager -l
'
else
    echo
    echo "Worker не перезапущен."
fi

echo
echo "Deploy complete."
