# SfM MVP: COLMAP из видео → траектория + AprilTag scale

## 0. Цель

Добавить в существующий web-проект отдельную ветку обработки:

```text
video_scan.mp4
  ↓
extract frames
  ↓
COLMAP sparse reconstruction
  ↓
camera poses / trajectory
  ↓
AprilTag detections as rough scale ruler
  ↓
trajectory in meters
```

Текущие 3D-туры, фото-точки, viewer, previews, marker detection для фото **не ломаем**.  
SfM работает как дополнительный слой карты/траектории внутри уже существующего `order/session`.

---

## 1. Что уже есть и что используем

По текущей архитектуре уже есть:

```text
/home/makler/web
/home/makler/web/www
/home/makler/web/storage
capture_sessions
photo_points
processing_jobs
marker_detections
tour_point_links
viewer / private / public tour
worker marker jobs
raw_dualfisheye → stitch → derivatives → marker detection
```

Значит SfM добавляем не как новый проект, а как новый processing branch:

```text
processing_jobs.type = SFM_EXTRACT_FRAMES
processing_jobs.type = SFM_COLMAP_SPARSE
processing_jobs.type = SFM_APRILTAG_SCALE
processing_jobs.type = SFM_BUILD_TRAJECTORY
```

---

## 2. Важное решение по координатам углов AprilTag

Руками координаты углов метки на кадре **не вводим**.

Их должен находить детектор автоматически:

```text
frame image
  ↓
AprilTag detector
  ↓
marker_id
corner_0: x,y
corner_1: x,y
corner_2: x,y
corner_3: x,y
center_x, center_y
decision_margin / confidence
```

Потом при наличии `camera_profile` и `marker_size_m` считаем:

```text
rvec
tvec
distance_m = norm(tvec)
```

То есть пользователь вводит только:

```text
- camera profile / calibration
- marker family
- marker physical size, например 0.16 m
```

---

## 3. Camera Profile

### 3.1 Зачем

Если камера известна, COLMAP и AprilTag-scale должны работать через один профиль камеры.

Профиль нужен для:

```text
- правильной оценки фокусного расстояния
- pose estimation по AprilTag / ChArUco
- уменьшения ошибок COLMAP
- повторяемости результата
```

### 3.2 Минимальная таблица

```sql
CREATE TABLE IF NOT EXISTS camera_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(255) NOT NULL,
    camera_vendor VARCHAR(128) NULL,
    camera_model_name VARCHAR(128) NULL,

    image_width INT UNSIGNED NOT NULL,
    image_height INT UNSIGNED NOT NULL,

    colmap_camera_model VARCHAR(64) NOT NULL DEFAULT 'OPENCV',

    fx DOUBLE NULL,
    fy DOUBLE NULL,
    cx DOUBLE NULL,
    cy DOUBLE NULL,

    k1 DOUBLE NULL,
    k2 DOUBLE NULL,
    p1 DOUBLE NULL,
    p2 DOUBLE NULL,
    k3 DOUBLE NULL,

    calibration_source ENUM('manual', 'exif', 'chessboard', 'charuco', 'estimated') NOT NULL DEFAULT 'manual',
    calibration_json JSON NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_camera_profiles_active (is_active),
    KEY idx_camera_profiles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.3 Пример профиля

```sql
INSERT INTO camera_profiles (
    name,
    camera_vendor,
    camera_model_name,
    image_width,
    image_height,
    colmap_camera_model,
    fx, fy, cx, cy,
    k1, k2, p1, p2, k3,
    calibration_source
) VALUES (
    'Generic phone 1920x1080 test',
    'manual',
    'unknown',
    1920,
    1080,
    'OPENCV',
    1400.0,
    1400.0,
    960.0,
    540.0,
    0.0, 0.0, 0.0, 0.0, 0.0,
    'manual'
);
```

---

## 4. ChArUco / calibration board

### 4.1 Решение

Добавляем ChArUco как отдельную служебную сущность, но для MVP можно начать без UI.

Первый этап:

```text
camera profile создаётся руками
или импортом из JSON
```

Второй этап:

```text
upload calibration photos
  ↓
detect ChArUco
  ↓
calculate intrinsics/distortion
  ↓
save camera_profile
```

### 4.2 Минимальная таблица для будущего

```sql
CREATE TABLE IF NOT EXISTS camera_calibration_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    camera_profile_id BIGINT UNSIGNED NULL,

    order_id BIGINT UNSIGNED NULL,
    session_id BIGINT UNSIGNED NULL,

    board_type ENUM('chessboard', 'charuco', 'apriltag_board') NOT NULL DEFAULT 'charuco',
    board_config_json JSON NOT NULL,

    input_path VARCHAR(1024) NOT NULL,
    output_json_path VARCHAR(1024) NULL,

    status ENUM('NEW', 'RUNNING', 'READY', 'FAILED') NOT NULL DEFAULT 'NEW',
    error_text TEXT NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_calibration_camera_profile (camera_profile_id),
    KEY idx_calibration_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 5. Video scan entity

### 5.1 Таблица

```sql
CREATE TABLE IF NOT EXISTS video_scans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    order_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    app_session_uuid CHAR(36) NULL,

    camera_profile_id BIGINT UNSIGNED NULL,

    source_video_path VARCHAR(1024) NOT NULL,
    storage_base_path VARCHAR(1024) NOT NULL,

    status ENUM(
        'UPLOADED',
        'FRAMES_READY',
        'COLMAP_RUNNING',
        'COLMAP_READY',
        'SCALE_READY',
        'TRAJECTORY_READY',
        'FAILED'
    ) NOT NULL DEFAULT 'UPLOADED',

    fps_extract DOUBLE NOT NULL DEFAULT 2.0,
    frame_width INT UNSIGNED NULL,
    frame_height INT UNSIGNED NULL,

    error_text TEXT NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_video_scans_order_session (order_id, session_id),
    KEY idx_video_scans_status (status),
    KEY idx_video_scans_camera_profile (camera_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.2 Папки

```text
/home/makler/web/storage/orders/<order_id>/sessions/<uuid>/sfm/
  video/
    scan.mp4
  frames/
    frame_000001.jpg
    frame_000002.jpg
  colmap/
    database.db
    sparse/0/
    sparse/0_txt/
  markers/
    detections.jsonl
  trajectory/
    camera_poses.json
    trajectory_scaled.json
```

---

## 6. Frames

```sql
CREATE TABLE IF NOT EXISTS sfm_frames (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    video_scan_id BIGINT UNSIGNED NOT NULL,

    frame_index INT UNSIGNED NOT NULL,
    timestamp_ms BIGINT UNSIGNED NULL,

    image_path VARCHAR(1024) NOT NULL,
    image_name VARCHAR(255) NOT NULL,

    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,

    has_marker TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_sfm_frame (video_scan_id, frame_index),
    KEY idx_sfm_frames_video_scan (video_scan_id),
    KEY idx_sfm_frames_image_name (image_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 7. COLMAP sparse model

```sql
CREATE TABLE IF NOT EXISTS sfm_models (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    video_scan_id BIGINT UNSIGNED NOT NULL,

    status ENUM('NEW', 'RUNNING', 'READY', 'FAILED') NOT NULL DEFAULT 'NEW',

    colmap_project_path VARCHAR(1024) NOT NULL,
    database_path VARCHAR(1024) NULL,
    sparse_model_path VARCHAR(1024) NULL,
    sparse_txt_path VARCHAR(1024) NULL,

    registered_frames_count INT UNSIGNED NOT NULL DEFAULT 0,
    sparse_points_count INT UNSIGNED NOT NULL DEFAULT 0,

    scale_status ENUM('NONE', 'ROUGH_READY', 'FAILED') NOT NULL DEFAULT 'NONE',
    scale_factor DOUBLE NULL,
    scale_method VARCHAR(64) NULL,

    error_text TEXT NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_sfm_models_video_scan (video_scan_id),
    KEY idx_sfm_models_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 8. Camera poses / trajectory

```sql
CREATE TABLE IF NOT EXISTS sfm_camera_poses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    sfm_model_id BIGINT UNSIGNED NOT NULL,
    frame_id BIGINT UNSIGNED NULL,

    image_name VARCHAR(255) NOT NULL,

    qw DOUBLE NOT NULL,
    qx DOUBLE NOT NULL,
    qy DOUBLE NOT NULL,
    qz DOUBLE NOT NULL,

    tx DOUBLE NOT NULL,
    ty DOUBLE NOT NULL,
    tz DOUBLE NOT NULL,

    x DOUBLE NULL,
    y DOUBLE NULL,
    z DOUBLE NULL,

    x_scaled DOUBLE NULL,
    y_scaled DOUBLE NULL,
    z_scaled DOUBLE NULL,

    is_registered TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_sfm_pose_image (sfm_model_id, image_name),
    KEY idx_sfm_pose_model (sfm_model_id),
    KEY idx_sfm_pose_frame (frame_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 8.1 Важно по COLMAP pose

COLMAP в `images.txt` хранит world-to-camera:

```text
qvec, tvec
```

Позиция камеры в world coordinates:

```text
C = -R^T * t
```

Именно `C` сохраняем как:

```text
x, y, z
```

---

## 9. AprilTag rough scale

### 9.1 Наблюдения меток

```sql
CREATE TABLE IF NOT EXISTS sfm_marker_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    sfm_model_id BIGINT UNSIGNED NOT NULL,
    frame_id BIGINT UNSIGNED NOT NULL,

    marker_family VARCHAR(64) NOT NULL DEFAULT 'tag36h11',
    marker_id INT NOT NULL,
    marker_size_m DOUBLE NOT NULL,

    corner_0_x DOUBLE NOT NULL,
    corner_0_y DOUBLE NOT NULL,
    corner_1_x DOUBLE NOT NULL,
    corner_1_y DOUBLE NOT NULL,
    corner_2_x DOUBLE NOT NULL,
    corner_2_y DOUBLE NOT NULL,
    corner_3_x DOUBLE NOT NULL,
    corner_3_y DOUBLE NOT NULL,

    center_x DOUBLE NULL,
    center_y DOUBLE NULL,

    rvec_json JSON NULL,
    tvec_json JSON NULL,

    distance_m DOUBLE NULL,
    confidence DOUBLE NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_sfm_marker_model (sfm_model_id),
    KEY idx_sfm_marker_frame (frame_id),
    KEY idx_sfm_marker_id (marker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 9.2 Scale через грубую линейку

Для MVP не ищем точное положение метки в COLMAP.

Используем метки как метрическую линейку:

```text
1. В кадре есть AprilTag.
2. По camera_profile + marker_size_m получаем distance_m.
3. Берём относительное движение камеры между кадрами, где видна та же метка.
4. Сравниваем изменение расстояния в метрах с изменением позиции COLMAP.
5. Получаем rough scale.
6. scale_final = median(all valid scale samples).
```

Упрощённая формула для пары кадров:

```text
d_marker_m = abs(distance_m(frame_B) - distance_m(frame_A))

d_colmap = norm(C_colmap_B - C_colmap_A)

scale = d_marker_m / d_colmap
```

Это грубо, но для первого MVP достаточно.

### 9.3 Фильтры качества

Отбрасываем sample, если:

```text
d_colmap < 0.05
d_marker_m < 0.05
distance_m <= 0
confidence слишком низкий
marker_id разный
угол слишком косой / tvec нестабильный
```

---

## 10. Processing jobs

### 10.1 Новые типы

```text
SFM_EXTRACT_FRAMES
SFM_COLMAP_SPARSE
SFM_DETECT_APRILTAG_FRAMES
SFM_APRILTAG_SCALE
SFM_BUILD_TRAJECTORY
```

### 10.2 Порядок

```text
video_scans.status = UPLOADED
  ↓ create job SFM_EXTRACT_FRAMES

SFM_EXTRACT_FRAMES done
  ↓ video_scans.status = FRAMES_READY
  ↓ create job SFM_COLMAP_SPARSE

SFM_COLMAP_SPARSE done
  ↓ sfm_models.status = READY
  ↓ video_scans.status = COLMAP_READY
  ↓ create job SFM_DETECT_APRILTAG_FRAMES

SFM_DETECT_APRILTAG_FRAMES done
  ↓ create job SFM_APRILTAG_SCALE

SFM_APRILTAG_SCALE done
  ↓ sfm_models.scale_status = ROUGH_READY
  ↓ video_scans.status = SCALE_READY
  ↓ create job SFM_BUILD_TRAJECTORY

SFM_BUILD_TRAJECTORY done
  ↓ video_scans.status = TRAJECTORY_READY
```

---

## 11. COLMAP wrapper

### 11.1 Файл

```text
/home/makler/web/tools/sfm/run_colmap_sparse.sh
```

### 11.2 Содержимое

```bash
#!/usr/bin/env bash
set -euo pipefail

PROJECT_PATH="$1"
IMAGE_PATH="$PROJECT_PATH/frames"
DB_PATH="$PROJECT_PATH/colmap/database.db"
SPARSE_PATH="$PROJECT_PATH/colmap/sparse"
TXT_PATH="$PROJECT_PATH/colmap/sparse/0_txt"

mkdir -p "$PROJECT_PATH/colmap"
mkdir -p "$SPARSE_PATH"

rm -f "$DB_PATH"

colmap feature_extractor \
  --database_path "$DB_PATH" \
  --image_path "$IMAGE_PATH" \
  --ImageReader.single_camera 1 \
  --SiftExtraction.use_gpu 0

colmap sequential_matcher \
  --database_path "$DB_PATH" \
  --SiftMatching.use_gpu 0

colmap mapper \
  --database_path "$DB_PATH" \
  --image_path "$IMAGE_PATH" \
  --output_path "$SPARSE_PATH"

rm -rf "$TXT_PATH"
mkdir -p "$TXT_PATH"

colmap model_converter \
  --input_path "$SPARSE_PATH/0" \
  --output_path "$TXT_PATH" \
  --output_type TXT

echo "COLMAP sparse ready: $TXT_PATH"
```

Для сервера без NVIDIA стартуем с CPU:

```text
--SiftExtraction.use_gpu 0
--SiftMatching.use_gpu 0
```

Потом можно включить GPU, если появится нормальная NVIDIA.

---

## 12. Frame extraction wrapper

### 12.1 Файл

```text
/home/makler/web/tools/sfm/extract_frames.sh
```

### 12.2 Содержимое

```bash
#!/usr/bin/env bash
set -euo pipefail

VIDEO_PATH="$1"
FRAMES_PATH="$2"
FPS="${3:-2}"
WIDTH="${4:-1920}"

mkdir -p "$FRAMES_PATH"
rm -f "$FRAMES_PATH"/frame_*.jpg

ffmpeg -y \
  -i "$VIDEO_PATH" \
  -vf "fps=${FPS},scale=${WIDTH}:-1" \
  -q:v 2 \
  "$FRAMES_PATH/frame_%06d.jpg"

COUNT=$(find "$FRAMES_PATH" -maxdepth 1 -type f -name 'frame_*.jpg' | wc -l)

echo "frames_created=$COUNT"
```

---

## 13. Parse COLMAP images.txt

### 13.1 Файл

```text
/home/makler/web/tools/sfm/parse_colmap_images.py
```

### 13.2 Содержимое

```python
#!/usr/bin/env python3
import json
import math
import sys
from pathlib import Path

import numpy as np


def qvec_to_rotmat(qvec):
    qw, qx, qy, qz = qvec
    return np.array([
        [1 - 2 * qy * qy - 2 * qz * qz,     2 * qx * qy - 2 * qw * qz,     2 * qz * qx + 2 * qw * qy],
        [2 * qx * qy + 2 * qw * qz,         1 - 2 * qx * qx - 2 * qz * qz, 2 * qy * qz - 2 * qw * qx],
        [2 * qz * qx - 2 * qw * qy,         2 * qy * qz + 2 * qw * qx,     1 - 2 * qx * qx - 2 * qy * qy],
    ], dtype=float)


def parse_images_txt(path: Path):
    poses = []

    with path.open("r", encoding="utf-8") as f:
        lines = [line.strip() for line in f if line.strip() and not line.startswith("#")]

    i = 0
    while i < len(lines):
        parts = lines[i].split()
        if len(parts) >= 10:
            image_id = int(parts[0])
            qw, qx, qy, qz = map(float, parts[1:5])
            tx, ty, tz = map(float, parts[5:8])
            camera_id = int(parts[8])
            image_name = parts[9]

            qvec = np.array([qw, qx, qy, qz], dtype=float)
            tvec = np.array([tx, ty, tz], dtype=float)

            rot = qvec_to_rotmat(qvec)
            center = -rot.T @ tvec

            poses.append({
                "image_id": image_id,
                "camera_id": camera_id,
                "image_name": image_name,
                "qvec": [qw, qx, qy, qz],
                "tvec": [tx, ty, tz],
                "center": center.tolist(),
            })

        i += 2  # next line contains 2D points

    return poses


def main():
    if len(sys.argv) != 3:
        print("Usage: parse_colmap_images.py <images.txt> <out.json>", file=sys.stderr)
        sys.exit(2)

    input_path = Path(sys.argv[1])
    output_path = Path(sys.argv[2])

    poses = parse_images_txt(input_path)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps({
        "ok": True,
        "count": len(poses),
        "poses": poses,
    }, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"poses={len(poses)}")


if __name__ == "__main__":
    main()
```

---

## 14. Build rough scale

### 14.1 Файл

```text
/home/makler/web/tools/sfm/rough_scale_from_markers.py
```

### 14.2 Логика

Вход:

```text
camera_poses.json
marker_observations.json
```

Выход:

```text
scale_factor
trajectory_scaled.json
```

Смысл:

```text
- сгруппировать наблюдения по marker_id
- взять пары соседних кадров с одним marker_id
- посчитать изменение distance_m
- сравнить с изменением COLMAP center
- взять median(scale)
```

### 14.3 Содержимое

```python
#!/usr/bin/env python3
import json
import math
import statistics
import sys
from pathlib import Path


def norm3(a, b):
    return math.sqrt(
        (a[0] - b[0]) ** 2 +
        (a[1] - b[1]) ** 2 +
        (a[2] - b[2]) ** 2
    )


def main():
    if len(sys.argv) != 4:
        print("Usage: rough_scale_from_markers.py <poses.json> <markers.json> <out.json>", file=sys.stderr)
        sys.exit(2)

    poses_path = Path(sys.argv[1])
    markers_path = Path(sys.argv[2])
    out_path = Path(sys.argv[3])

    poses_data = json.loads(poses_path.read_text(encoding="utf-8"))
    markers_data = json.loads(markers_path.read_text(encoding="utf-8"))

    poses_by_image = {
        p["image_name"]: p
        for p in poses_data.get("poses", [])
    }

    by_marker = {}
    for obs in markers_data.get("observations", []):
        image_name = obs.get("image_name")
        marker_id = obs.get("marker_id")
        distance_m = obs.get("distance_m")

        if image_name not in poses_by_image:
            continue
        if marker_id is None or distance_m is None or distance_m <= 0:
            continue

        by_marker.setdefault(marker_id, []).append(obs)

    scale_samples = []

    for marker_id, items in by_marker.items():
        items = sorted(items, key=lambda x: x.get("frame_index", 0))

        for a, b in zip(items, items[1:]):
            pa = poses_by_image.get(a["image_name"])
            pb = poses_by_image.get(b["image_name"])
            if not pa or not pb:
                continue

            ca = pa["center"]
            cb = pb["center"]

            d_colmap = norm3(ca, cb)
            d_marker = abs(float(b["distance_m"]) - float(a["distance_m"]))

            if d_colmap < 0.05:
                continue
            if d_marker < 0.05:
                continue

            scale = d_marker / d_colmap

            if 0.001 <= scale <= 1000:
                scale_samples.append({
                    "marker_id": marker_id,
                    "image_a": a["image_name"],
                    "image_b": b["image_name"],
                    "d_colmap": d_colmap,
                    "d_marker_m": d_marker,
                    "scale": scale,
                })

    if not scale_samples:
        result = {
            "ok": False,
            "error": "No valid scale samples",
            "scale_factor": None,
            "samples_count": 0,
            "trajectory_scaled": [],
        }
        out_path.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
        print("scale_failed=no_valid_samples")
        sys.exit(1)

    scale_factor = statistics.median([s["scale"] for s in scale_samples])

    trajectory_scaled = []
    for p in poses_data.get("poses", []):
        c = p["center"]
        trajectory_scaled.append({
            "image_name": p["image_name"],
            "x": c[0],
            "y": c[1],
            "z": c[2],
            "x_scaled": c[0] * scale_factor,
            "y_scaled": c[1] * scale_factor,
            "z_scaled": c[2] * scale_factor,
        })

    result = {
        "ok": True,
        "scale_factor": scale_factor,
        "samples_count": len(scale_samples),
        "samples": scale_samples,
        "trajectory_scaled": trajectory_scaled,
    }

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"scale_factor={scale_factor}")
    print(f"samples={len(scale_samples)}")


if __name__ == "__main__":
    main()
```

---

## 15. MVP без точного marker position в COLMAP

На первом этапе намеренно не делаем:

```text
- точную 3D-позицию AprilTag в COLMAP world
- absolute world coordinate system
- bundle adjustment с constraints
- dense MVS
- mesh
- texturing
```

Делаем только:

```text
COLMAP camera centers
+
rough metric scale
```

Это даст достаточно для:

```text
- черновой траектории
- привязки 360 photo_points к проходу
- проверки качества скана
- дальнейшей 2D/3D карты
```

---

## 16. UI минимум

В существующий worker UI добавить блок:

```text
SfM / Video reconstruction
```

Поля:

```text
Video scan:
- id
- status
- source video
- camera profile
- frames count
- registered frames count
- sparse points count
- scale status
- scale factor
- marker observations count
```

Кнопки:

```text
Run extract frames
Run COLMAP sparse
Run AprilTag scale
Rebuild trajectory
```

Отображение:

```text
- список кадров
- зарегистрированные кадры
- найденные marker_id
- простой SVG/Canvas polyline по x_scaled/z_scaled
```

---

## 17. Первый CLI-тест на сервере

```bash
SESSION_UUID="4713d186-d294-4f56-9593-87e364dc442d"
ORDER_ID="6"

BASE="/home/makler/web/storage/orders/${ORDER_ID}/sessions/${SESSION_UUID}/sfm"

mkdir -p "$BASE/video" "$BASE/frames" "$BASE/colmap" "$BASE/trajectory"

# scan.mp4 положить сюда:
# $BASE/video/scan.mp4

/home/makler/web/tools/sfm/extract_frames.sh \
  "$BASE/video/scan.mp4" \
  "$BASE/frames" \
  2 \
  1920

/home/makler/web/tools/sfm/run_colmap_sparse.sh \
  "$BASE"

/home/makler/web/tools/sfm/parse_colmap_images.py \
  "$BASE/colmap/sparse/0_txt/images.txt" \
  "$BASE/trajectory/camera_poses.json"
```

После этого должен появиться файл:

```text
$BASE/trajectory/camera_poses.json
```

Если он есть и `count > 0`, первый этап живой.

---

## 18. Очередность разработки

### Этап 1 — schema + wrappers

```text
1. Добавить SQL-таблицы:
   - camera_profiles
   - camera_calibration_runs
   - video_scans
   - sfm_frames
   - sfm_models
   - sfm_camera_poses
   - sfm_marker_observations

2. Добавить tools/sfm:
   - extract_frames.sh
   - run_colmap_sparse.sh
   - sfm_tool parse-colmap-images
   - sfm_tool rough-scale
   - sfm_tool detect-apriltag-frames
```

### Этап 2 — ручной CLI прогон

```text
1. Положить тестовое видео в sfm/video/scan.mp4
2. Нарезать кадры
3. Запустить COLMAP
4. Распарсить images.txt
5. Проверить camera_poses.json
```

### Этап 3 — интеграция в processing_jobs

```text
1. Добавить job type SFM_EXTRACT_FRAMES
2. Добавить job type SFM_COLMAP_SPARSE
3. После успеха создавать следующий job
4. Логи писать в processing_jobs log/error
```

### Этап 4 — AprilTag rough scale

```text
1. Прогнать существующий/новый detector по sfm_frames
2. Сохранить углы меток в sfm_marker_observations
3. Посчитать rough scale
4. Записать scale_factor в sfm_models
5. Обновить x_scaled/y_scaled/z_scaled в sfm_camera_poses
```

### Этап 5 — UI

```text
1. Добавить вкладку/секцию SfM
2. Показать statuses/jobs
3. Показать trajectory polyline
4. Показать marker observations
```

---

## 19. Что проверить перед стартом

```bash
which ffmpeg
which colmap
python3 --version
php -v
mysql --version
```

Проверка COLMAP:

```bash
colmap -h | head
```

Если COLMAP нет:

```bash
dnf search colmap
```

или собирать отдельно под сервер.

---

## 20. Риски

### 20.1 COLMAP не соберёт модель

Причины:

```text
- мало текстуры
- размытое видео
- слишком быстрый проход
- слишком мало overlap
- кадры из 360/equirectangular без perspective conversion
```

Решение:

```text
- fps=2 или fps=3
- scale=1920
- медленный проход
- избегать белых стен
- добавить AprilTag/ChArUco boards в сцену
```

### 20.2 Scale будет шумный

Нормально для MVP.

Уменьшение шума:

```text
- много AprilTag
- несколько marker boards
- median scale
- отбрасывать плохие углы/низкий confidence
```

### 20.3 360-видео

Если источник — equirectangular 360, напрямую в COLMAP не кормить.

Лучше:

```text
360 video
  ↓
perspective frames / cubemap faces
  ↓
COLMAP
```

Для MVP проще начать с обычного perspective video.

---

## 21. Готовность к следующему шагу

Первым делом писать:

```text
/home/makler/web/tools/sfm/extract_frames.sh
/home/makler/web/tools/sfm/run_colmap_sparse.sh
/home/makler/web/tools/sfm/parse_colmap_images.py
/home/makler/web/tools/sfm/rough_scale_from_markers.py
/home/makler/web/sql/2026_05_19_sfm_mvp.sql
```

Потом подключать это к существующему worker-у `processing_jobs`.
