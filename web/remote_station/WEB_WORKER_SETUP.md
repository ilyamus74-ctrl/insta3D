# MaklerTour SfM remote worker setup

## Почему нельзя запускать remote_station scripts напрямую из Apache

`order.php` обрабатывается PHP под пользователем веб-сервера (`apache`/`www-data`). Этот пользователь не должен:

- иметь доступ к root SSH key для remote_station;
- запускать root-only shell scripts из HTTP request;
- держать долгие SfM/remote jobs внутри web request lifecycle;
- возвращать пользователю низкоуровневые ошибки shell/SSH.

Веб-приложение должно только валидировать запрос и создавать запись в `sfm_remote_jobs` со статусом `QUEUED`. Отдельный systemd worker под root забирает задачи из БД и безопасно запускает remote_station scripts.

## Установка systemd service

Из корня репозитория/деплоя:

```bash
cp web/remote_station/makler-sfm-worker.service.example /etc/systemd/system/makler-sfm-worker.service
systemctl daemon-reload
systemctl enable --now makler-sfm-worker.service
```

## Установка cleanup worker

Перед запуском примените обе migration в порядке: `web/migrations/20260714_sfm_remote_cleanup_runs.sql`, затем `web/migrations/20260714_sfm_remote_cleanup_runs_v2.sql`. После этого установите отдельный worker для безопасной очистки GrafikStation:

```bash
cp web/remote_station/makler-sfm-cleanup-worker.service /etc/systemd/system/makler-sfm-cleanup-worker.service
systemctl daemon-reload
systemctl enable --now makler-sfm-cleanup-worker.service
journalctl -u makler-sfm-cleanup-worker.service -f
```

## Установка очистки удалённых сборок

Веб-интерфейс удаляет запись сборки из БД и публикует файл очереди
`.delete_merge_<id>_<token>.queue.json`. Исходные root-owned каталоги не
перемещаются процессом `apache`: проверку путей и рекурсивное удаление выполняет
отдельный root-side timer. Это также работает для каталогов, которые `apache`
не может переместить между родительскими директориями.

```bash
cp web/remote_station/makler-generated-merge-cleanup.service /etc/systemd/system/
cp web/remote_station/makler-generated-merge-cleanup.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now makler-generated-merge-cleanup.timer
systemctl start makler-generated-merge-cleanup.service
journalctl -u makler-generated-merge-cleanup.service -n 100 --no-pager
```

## Установка metrics timer

Метрики собирает root-side updater в JSON cache, а PHP API только читает готовый файл.

```bash
cp web/remote_station/makler-station-metrics.service.example /etc/systemd/system/makler-station-metrics.service
cp web/remote_station/makler-station-metrics.timer.example /etc/systemd/system/makler-station-metrics.timer
systemctl daemon-reload
systemctl enable --now makler-station-metrics.timer
journalctl -u makler-station-metrics.service -f
```

## Post-deploy permissions and worker restart

После каждого deploy убедитесь, что shell scripts в `remote_station` исполняемые, проверьте PHP syntax worker-а и перезапустите service:

```bash
chmod +x /home/makler/web/remote_station/*.sh
chmod +x /home/makler/web/remote_station/scripts/*.sh
chmod +x /home/makler/web/remote_station/run_colmap_dense_job.sh
chmod +x /home/makler/web/remote_station/scripts/process_colmap_dense.sh
/home/makler/web/remote_station/get_station_metrics.sh /home/makler/web/remote_station/stations.conf
php -l /home/makler/web/tools/sfm_remote_worker.php
systemctl restart makler-sfm-worker.service
systemctl restart makler-sfm-cleanup-worker.service
journalctl -u makler-sfm-worker.service -f
journalctl -u makler-sfm-cleanup-worker.service -f
```

## Логи

```bash
journalctl -u makler-sfm-worker.service -f
```

## Проверка PHP файлов

```bash
php -l web/tools/sfm_remote_worker.php
php -l web/www/api/sfm_remote_job_status.php
```

## Runtime flow

1. Пользователь нажимает SfM action в `order.php`.
2. `order.php` валидирует входные данные и добавляет `sfm_remote_jobs.status='QUEUED'`.
3. `makler-sfm-worker.service` запускает `web/tools/sfm_remote_worker.php` под root.
4. Worker забирает `QUEUED` job, переводит в `RUNNING`, запускает нужный script из `web/remote_station` и синхронизирует статус через `get_station_status.sh`.
5. Когда remote job становится `DONE`, worker вызывает `fetch_job_result.sh` и обновляет БД.
6. Web polling endpoint `/api/sfm_remote_job_status.php` возвращает текущие `status`, `progress_percent` и `message` из БД.