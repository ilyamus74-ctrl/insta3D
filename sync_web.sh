#!/usr/bin/env bash
set -e

MODE="${1:---dry-run}"

SOURCE="/home/ilyamus/Документы/Insta3D/web/"
DESTINATION="root@makler.cargocells.com:/home/makler/web/"

COMMON_OPTIONS="
--recursive
--links
--checksum
--verbose
--itemize-changes
--human-readable
--no-owner
--no-group
--no-perms
--omit-dir-times
--exclude=/configs/
--exclude=/storage/
--exclude=/cache/
--exclude=/templates_c/
--exclude=/MySqlDump/
--exclude=/remote_station/output/
--exclude=/remote_station/stations.conf
--exclude=/tools/colmap_src/
--exclude=/www/NiceAdmin
--exclude=/www/assets/
--exclude=/www/img/
--exclude=/www/vendor/
--exclude=/www/t/
--exclude=/www/extension/
--exclude=*.bak
--exclude=*.bak.*
--exclude=*.bkp
--exclude=*.bkp.*
--exclude=*.sql
--exclude=*.sql.gz
--exclude=/git_pull.sh
--exclude=/git_pull.sh.bkp
--exclude=/git_push.sh
--exclude=/git_push.sh.bkp
--exclude=/init_git.sh
--exclude=/dumpDB.sh
"

case "$MODE" in
    --dry-run)
        echo "DRY RUN"

        rsync \
            $COMMON_OPTIONS \
            --dry-run \
            -e "ssh -p 3322 -o StrictHostKeyChecking=accept-new" \
            "$SOURCE" \
            "$DESTINATION"
        ;;

    --apply)
        BACKUP="/home/makler/deploy_backups/rsync_$(date +%Y%m%d_%H%M%S)"

        ssh -p 3322 root@makler.cargocells.com \
            "mkdir -p '$BACKUP'"

        echo "APPLY"
        echo "Backup: $BACKUP"

        rsync \
            $COMMON_OPTIONS \
            --backup \
            --backup-dir="$BACKUP" \
            -e "ssh -p 3322 -o StrictHostKeyChecking=accept-new" \
            "$SOURCE" \
            "$DESTINATION"
        ;;

    *)
        echo "Usage:"
        echo "  $0 --dry-run"
        echo "  $0 --apply"
        exit 1
        ;;
esac

