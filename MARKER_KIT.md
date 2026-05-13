# MaklerTour Marker Kit v1

Дата актуализации: 2026-05-13  
Комплект: `maklertour_kit_v1`

## 1) Назначение Marker Kit
MaklerTour Marker Kit v1 — это фиксированный набор печатных AprilTag-меток для использования при съемке объекта.  
Комплект нужен для последующего backend-детекта в `video scan` и `360 photo points`, чтобы восстановить масштаб и стабилизировать геометрию реконструкции.

## 2) Спецификация комплекта
```text
kit_id: maklertour_kit_v1
marker_type: APRILTAG
marker_dictionary: APRILTAG_36H11
marker_ids: 1..30
marker_size_m: 0.160
marker_size_mm: 160
```

`160 мм` — это размер **внешнего квадрата самой AprilTag-метки** (tag area).  
Это не размер листа A4 и не размер вместе с подписями/полями.

## 3) Состав
Набор содержит 30 уникальных меток:
- MT-001 → AprilTag ID 1
- MT-002 → AprilTag ID 2
- ...
- MT-030 → AprilTag ID 30

Оператор может расставлять метки в **любой последовательности**.

## 4) Правила печати
- Печатать в масштабе `100%`.
- Не использовать `fit-to-page`, если это меняет физический размер метки.
- Формат печати: `A4 portrait`.
- Каждая метка печатается на отдельной странице.
- После печати проверить линейкой, что внешний квадрат AprilTag = `160 мм`.

## 5) Правила размещения
- Размещать метки на объекте в разных зонах, чтобы они попадали в видео и/или 360-точки.
- Допускается использовать любой поднабор ID из диапазона 1..30.
- Порядок и нумерация по месту установки не важны.
- Нежелательно закрывать метки объектами, бликовать или деформировать поверхность метки.

## 6) Запрет на fake markers
Строго запрещено:
- генерировать фейковые маркеры;
- заменять AprilTag на QR-коды;
- рисовать «похожие» паттерны вручную.

Разрешены только официальные source PNG AprilTag.

## 7) Пути к исходным файлам
Основной путь source-изображений:
```text
web/storage/marker_kits/maklertour_kit_v1/source/tag36h11/
```

Ожидаемые имена файлов:
```text
tag36_11_00001.png ... tag36_11_00030.png
```
Допустимый fallback-формат имен:
```text
tag36h11_00001.png ... tag36h11_00030.png
```

Web-страница комплекта:
```text
/markers.php
/markers.php?print=all
/markers.php?print=1..30
/markers.php?img=1..30
```

## 8) Backend assumptions
Backend считает комплект стандартным и фиксированным:
- `kit_id = maklertour_kit_v1`
- `marker_type = APRILTAG`
- `marker_dictionary = APRILTAG_36H11`
- `marker_size_m = 0.160`
- `valid_marker_ids = [1..30]`

При обработке backend ищет ID 1..30 в:
- кадрах video scan;
- исходниках/производных 360 photo points.

## 9) Будущие таблицы
MaklerTour Kit v1 является стандартным ожидаемым комплектом для всех съемочных сессий.

- Оператору не нужно вручную отмечать факт использования меток.
- Backend всегда выполняет marker detection по ID 1..30.
- Если метки не найдены — это результат обработки, а не ручной выбор оператора.
- Отсутствие меток не блокирует upload, но снижает класс результата:
  - `tour_only`
  - `tour_with_graph`
  - `tour_with_metric_reconstruction`
  - `tour_with_floorplan`

Удаляется/не используется:
- `used_by_operator`
- checkbox `"[ ] Метки использовались"`
- `session_marker_usage` как обязательная таблица

Планируемые таблицы marker processing:
- `processing_jobs`
- `marker_detections`
- `reconstruction_results`

