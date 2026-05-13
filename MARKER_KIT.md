MaklerTour Marker Kit v1
AprilTag 36h11
IDs 1-30
marker size 160 mm
print rules
operator placement rules
backend processing assumptions
Print: A4 / PDF / PNG
Usage: operator places any subset of markers in any order

Порядок расстановки не важен. Обработчик сам увидит:

marker_id = 7
marker_id = 14
marker_id = 22


Backend DB

Таблицы:

marker_kits
marker_kit_items
session_marker_usage
marker_detections


С полями:

session_id
marker_kit_id
marker_type
marker_dictionary
marker_size_m
marker_ids_json
used_by_operator
notes

 APP

В сессии/черновике:

Метки:
[ ] Метки использовались
Комплект: MaklerTour Kit v1
Размер: 160 мм
ID: 1-30
Заметка оператора

Главное: все метки одного физического размера. Например:

160 mm = размер кодовой области AprilTag

web/www/markers.php
web/storage/marker_kits/maklertour_kit_v1/
 ├── README.md
 ├── maklertour_marker_001.pdf
 ├── maklertour_marker_002.pdf
 ├── ...
 ├── maklertour_marker_030.pdf
 ├── maklertour_marker_001.png
 ├── ...
 ├── maklertour_marker_030.png
 └── maklertour_marker_kit_v1_all.pdf
 
 MaklerTour Marker Kit v1
[Скачать весь комплект PDF]
[Marker 001 PDF] [PNG]
[Marker 002 PDF] [PNG]
...
[Marker 030 PDF] [PNG]



```markdown # MaklerTour Marker Kit v1 Дата актуализации: 2026-05-13 Комплект: `maklertour_kit_v1` --- ## 1. Назначение MaklerTour Marker Kit v1 — фиксированный комплект печатных маркеров для восстановления масштаба, геометрии и расстояний при серверной обработке 360 photo points и video scan. Маркеры используются backend-обработчиком для: - обнаружения известных контрольных объектов на фото/видео; - восстановления метрического масштаба; - улучшения геометрии реконструкции; - последующего расчета расстояний и размеров помещений. Android-приложение не выполняет обязательное распознавание маркеров. Оно только фиксирует, что при съемке использовался стандартный комплект. --- ## 2. Спецификация комплекта ```text kit_id: maklertour_kit_v1 marker_type: APRILTAG marker_dictionary: APRILTAG_36H11 marker_ids: 1..30 marker_size_m: 0.160 marker_size_mm: 160 ``` `marker_size_m = 0.160` означает физический размер внешнего квадрата самой AprilTag-метки. В этот размер НЕ входят: - лист A4; - подписи; - белые поля; - рамка для вырезания; - текстовая маркировка MT-001, MT-002 и т.д. --- ## 3. Состав комплекта Комплект содержит 30 уникальных меток: ```text MT-001 -> AprilTag ID 1 MT-002 -> AprilTag ID 2 MT-003 -> AprilTag ID 3 ... MT-030 -> AprilTag ID 30 ``` Все метки имеют одинаковый физический размер: ```text 160 mm ``` Разница между метками — только в ID/рисунке AprilTag. --- ## 4. Использование оператором Оператор может использовать любое количество меток из комплекта. Рекомендуемый минимум: ```text 5 меток ``` Для больших объектов желательно использовать больше: ```text 8–15 меток ``` Для сложных объектов можно использовать до 30 меток. Порядок расстановки не важен. Backend сам определит найденные ID: ```text marker_id = 7 marker_id = 14 marker_id = 22 ``` --- ## 5. Правила размещения Рекомендуется: - размещать метки на плоских поверхностях; - ставить метки на уровне примерно 1–1.5 м; - делать так, чтобы метки попадали в video scan; - по возможности, чтобы часть меток была видна на 360 photo points; - размещать метки в разных зонах объекта; - ставить метки у переходов между комнатами/коридорами. Не рекомендуется: - клеить метки на глянцевые поверхности; - ставить на сильно изогнутые поверхности; - класть на пол под острым углом; - закрывать мебелью; - использовать несколько копий одной и той же метки в одном объекте; - менять масштаб при печати. --- ## 6. Печать Формат печати: ```text A4 portrait 1 marker per page print scale: 100% ``` Критично: ```text Не использовать fit-to-page, если он меняет размер метки. ``` Проверка после печати: - измерить линейкой размер внешнего квадрата AprilTag; - он должен быть 160 мм; - допустимое отклонение для MVP: ±1 мм. Рекомендуется: - матовая бумага; - контрастная печать; - без бликов; - не ламинировать глянцевой пленкой. --- ## 7. Web-шаблоны Печатные шаблоны доступны через backend web: ```text /markers.php /markers.php?print=all /markers.php?print=1 ``` Файлы исходных AprilTag находятся в: ```text web/storage/marker_kits/maklertour_kit_v1/source/tag36h11/ ``` Ожидаемые имена: ```text tag36_11_00001.png tag36_11_00002.png ... tag36_11_00030.png ``` --- ## 8. Backend assumptions Backend должен считать этот комплект стандартным: ```text marker_kit_id = maklertour_kit_v1 marker_type = APRILTAG marker_dictionary = APRILTAG_36H11 marker_size_m = 0.160 valid_marker_ids = [1..30] ``` При обработке backend ищет AprilTag ID 1–30 в: - video scan frames; - 360 photo originals; - preview images, если требуется быстрая диагностика. --- ## 9. Future DB tables Для следующих этапов обработки планируются таблицы: ```text session_marker_usage marker_detections processing_jobs reconstruction_results ``` ### session_marker_usage ```text id session_id marker_kit_id marker_type marker_dictionary marker_size_m marker_ids_json used_by_operator notes created_at updated_at ``` ### marker_detections ```text id session_id source_type source_id frame_index timestamp_ms marker_id corners_json confidence created_at ``` --- ## 10. Важное ограничение В репозиторий нельзя добавлять фейковые маркеры. Разрешено: - использовать настоящие official AprilTag source images; - собирать из них печатные страницы; - отдавать их через PHP. Запрещено: - рисовать псевдо-маркеры вручную; - использовать QR-коды вместо AprilTag; - менять размер отдельных меток; - использовать один и тот же ID несколько раз в одном объекте. ```
