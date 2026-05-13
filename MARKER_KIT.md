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
