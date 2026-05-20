# MaklerTour systemd workers

## Marker processing worker

Этот документ фиксирует backend worker, который автоматически обрабатывает MaklerTour processing jobs после upload.

## Назначение

`maklertour-marker-worker` периодически запускает PHP worker и обрабатывает задания из `processing_jobs`.

Pipeline новой uploaded session:

```text
Android upload
↓
processing_jobs.status = QUEUED
↓
systemd timer запускает maklertour-marker-worker.service
↓
process_marker_jobs.php
↓
создание viewer_light / viewer_hd panorama derivatives
↓
C++ AprilTag detector по originals/video frames
↓
запись marker_detections
↓
обновление processing_jobs metric_status
↓
Auto map v1
↓
Auto links / navigation arrows
↓
tour готов к просмотру

systemd service

Файл:

/etc/systemd/system/maklertour-marker-worker.service

Текущий service:

[Unit]
Description=MaklerTour marker processing worker
After=network.target mariadb.service php-fpm.service

[Service]
Type=oneshot
User=apache
Group=apache
WorkingDirectory=/home/makler/web

ExecStart=/bin/bash -lc 'flock -n /tmp/maklertour-marker-worker.lock /usr/bin/php -d max_execution_time=0 /home/makler/web/bin/process_marker_jobs.php --limit=3 || exit 0'

Nice=5
IOSchedulingClass=best-effort
IOSchedulingPriority=6

[Install]
WantedBy=multi-user.target

Примечания:

flock — не даёт запустить два worker параллельно
--limit=3 — обрабатывает до 3 queued jobs за один запуск
max_execution_time=0 — отключает PHP timeout для больших media jobs
User=apache — чтобы новые файлы создавались с правами web-пользователя

systemd timer

Файл:

/etc/systemd/system/maklertour-marker-worker.timer

Текущий timer:

[Unit]
Description=Run MaklerTour marker processing worker every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=10s
Unit=maklertour-marker-worker.service

[Install]
WantedBy=timers.target

Enable / reload

systemctl daemon-reload
systemctl enable --now maklertour-marker-worker.timer

Проверка статуса

systemctl status maklertour-marker-worker.timer
systemctl list-timers | grep maklertour

Ручной запуск service:

systemctl start maklertour-marker-worker.service
systemctl status maklertour-marker-worker.service --no-pager
journalctl -u maklertour-marker-worker.service -n 100 --no-pager

Ожидаемый idle output:

No queued jobs

Ожидаемый successful output:

Job #<id> processed: METRIC_READY, detections=<count>

Проверка очереди в БД

SELECT
  id,
  session_id,
  order_id,
  status,
  metric_status,
  markers_detected_count,
  LEFT(COALESCE(warning_text, ''), 160) AS warning_text,
  LEFT(COALESCE(error_text, ''), 160) AS error_text,
  created_at,
  updated_at
FROM processing_jobs
ORDER BY id DESC
LIMIT 20;

Связанный код

/home/makler/web/bin/process_marker_jobs.php
/home/makler/web/libs/tour_media_derivatives_lib.php
/home/makler/web/libs/tour_auto_map_lib.php
/home/makler/web/libs/tour_auto_links_lib.php
/home/makler/web/tools/apriltag_detector_cpp/build/detect_markers

Media derivatives

Worker создаёт browser-ready panorama derivatives:

photos/viewer_light/  2048x1024, mobile/light view
photos/viewer_hd/     4096x2048, HD view
photos/originals/     originals for detector/archive/future reconstruction

Важно:

photos/originals используются для detector и будущей реконструкции.
viewer_light/viewer_hd используются только для web tour viewer.


EOF
## SfM video worker

Новый CLI pipeline для video-only SfM через `processing_jobs`.

Enqueue job:

```bash
php /home/makler/web/tools/enqueue_sfm_video_job.php \
  --order-id=18 \
  --session-id=42 \
  --video-path=/home/makler/web/storage/orders/18/sessions/a4295f07-6aed-466f-8169-06bb0e6ed587_18/videos/89dcaa37-c6b2-4652-9d6c-5fc039497e69_VID_20260519_171531_00_164.mp4
```

Process queue:

```bash
php /home/makler/web/tools/process_sfm_video_jobs.php --limit=1
```

Optional single-job mode:

```bash
php /home/makler/web/tools/process_sfm_video_jobs.php --job-id=123
```

`process_sfm_video_jobs.php` берет `job_type=SFM_VIDEO_PIPELINE`, запускает ffmpeg + sfm_tool + colmap + `sfm_finalize_run.php` + `sfm_materialize_keyframes.php` и пишет per-job лог:

`/home/makler/web/storage/orders/<order_id>/sessions/<session_dir>/sfm/logs/sfm_pipeline_job_<job_id>_<timestamp>.log`
