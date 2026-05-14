MaklerTour / insta3D
Интеграция AprilTag detector через C/C++ CLI

Идём C/C++ путём.

ffmpeg нужен для извлечения кадров из видео.
OpenCV можно использовать для чтения и предобработки изображений.
AprilTag C library используется непосредственно для детекта маркеров.

Текущий PHP worker уже готов как управляющий слой:

process_marker_jobs.php

Он уже:

берёт QUEUED jobs
проверяет media
пишет processing log
пока временно завершает job как PROCESSED / NO_MARKERS

Теперь этот временный блок нужно заменить на реальный вызов detector CLI.

Общая архитектура

PHP worker process_marker_jobs.php
        ↓
собирает список фото и видео
        ↓
ffmpeg режет видео на кадры
        ↓
PHP формирует input_media.json
        ↓
PHP вызывает C/C++ detector CLI
        ↓
detector возвращает detections.json
        ↓
PHP пишет найденные метки в marker_detections
        ↓
PHP обновляет processing_jobs

Почему C/C++ — правильный путь

Для production это лучше, потому что:

быстрее Python
меньше runtime-зависимостей
удобно запускать из cron
проще завернуть в systemd worker
лучше подходит для локальной обработки media

OpenCV используем не как основной detector, а как вспомогательный слой:

imread
grayscale
resize later
undistort later
crop later

Сама логика распознавания маркеров должна идти через:

AprilTag C library

Этап 1 — C/C++ detector CLI

Создать отдельный проект:

tools/apriltag_detector_cpp/
├── CMakeLists.txt
├── src/
│   └── detect_markers.cpp
├── README.md
└── build/

CLI interface

Detector должен запускаться так:

./detect_markers \
  --input-list /home/makler/web/storage/processing/sessions/<uuid>/input_media.json \
  --output /home/makler/web/storage/processing/sessions/<uuid>/detections/detections.json \
  --tag-family tag36h11 \
  --valid-ids 1-30 \
  --marker-size-m 0.160

Input format: input_media.json

{
  "session_id": 31,
  "items": [
    {
      "source_type": "PHOTO_POINT",
      "source_id": 12,
      "source_path": "orders/3/sessions/.../photos/originals/a.jpg",
      "absolute_path": "/home/makler/web/storage/orders/3/sessions/.../photos/originals/a.jpg"
    },
    {
      "source_type": "VIDEO_FRAME",
      "source_id": 8,
      "source_path": "orders/3/sessions/.../videos/a.mp4",
      "absolute_path": "/home/makler/web/storage/processing/sessions/<uuid>/frames/video_8/frame_000001.jpg",
      "frame_index": 1,
      "timestamp_ms": 1000
    }
  ]
}

Output format: detections.json

{
  "ok": true,
  "detector": "apriltag-cpp",
  "tag_family": "tag36h11",
  "marker_size_m": 0.16,
  "detections": [
    {
      "source_type": "PHOTO_POINT",
      "source_id": 12,
      "source_path": "orders/3/sessions/.../photos/originals/a.jpg",
      "frame_index": null,
      "timestamp_ms": null,
      "marker_id": 7,
      "corners": [
        [100.0, 120.0],
        [230.0, 121.0],
        [232.0, 250.0],
        [98.0, 248.0]
      ],
      "center_x": 165.0,
      "center_y": 185.0,
      "confidence": 0.91
    }
  ]
}

Если произошла фатальная ошибка:

{
  "ok": false,
  "error": "..."
}

Этап 2 — ffmpeg frame extraction

Для видео не нужно извлекать все кадры.
Для MVP достаточно делать выборку:

fps=1

Команда:

ffmpeg -hide_banner -loglevel error -y \
  -i input.mp4 \
  -vf fps=1 \
  -q:v 2 \
  /home/makler/web/storage/processing/sessions/<uuid>/frames/video_<id>/frame_%06d.jpg

Позже можно улучшить стратегию:

fps=2 для коротких видео
fps=0.5 для длинных видео
extract around camera movement

Но для MVP достаточно:

fps=1

Этап 3 — PHP worker integration

В файле:

web/bin/process_marker_jobs.php

нужно заменить временный блок:

Marker detector is not connected yet

на полноценный pipeline:

1. prepare processing dirs
2. extract frames from video
3. write input_media.json
4. call detect_markers
5. read detections.json
6. insert marker_detections
7. update processing_jobs metric_status

Логика статусов

0 unique markers:
  status = PROCESSED
  metric_status = NO_MARKERS

1–2 unique markers:
  status = PROCESSED
  metric_status = PARTIAL_MARKERS

>=3 unique markers:
  status = PROCESSED
  metric_status = METRIC_READY

Позже можно ужесточить правило до:

>=5 unique markers = METRIC_READY

Этап 4 — Web UI

В order.php добавить вывод найденных маркеров по сессии:

Найденные метки: MT-001, MT-007, MT-014

Источники:
Фото: 2 detections
Видео: 18 detections

Metric status:
METRIC_READY / PARTIAL_MARKERS / NO_MARKERS

Также в шаблоне желательно показать компактную таблицу:

marker ID
source type
source id
frame
confidence

Ограничить вывод первыми 30 detections, чтобы не раздувать страницу.
Важный нюанс по 360-фото Insta360

Фото Insta360 — это equirectangular 360 JPG.

Прямой AprilTag detector может находить метки, но на краях и около полюсов будут искажения.

Для MVP:

детектим прямо original equirectangular JPG

Следующий улучшенный этап:

360 JPG -> cubemap faces -> detect tags on cube faces

Это сильно повысит точность, но сначала нужен простой и стабильный прямой detector.

Для видео всё проще:

обычный mp4 frame
обычный AprilTag detection
