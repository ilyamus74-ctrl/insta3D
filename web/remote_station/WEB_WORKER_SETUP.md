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