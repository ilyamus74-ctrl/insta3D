README.md

web/
└── remote_station/
    ├── stations.conf
    ├── install_station.sh
    ├── deploy_station.sh
    ├── station_worker.sh
    ├── station_status.sh
    ├── README.md
    └── templates/
        ├── worker.service
        └── worker.timer

Назначение
install_station.sh

Запускается на сервере.

Подключается по SSH к GrafikStation и:

    создает структуру каталогов

    проверяет CUDA

    проверяет COLMAP

    копирует скрипты

    создает systemd unit

    запускает worker

Пример:

./install_station.sh grafikstation01

deploy_station.sh

Обновляет станцию после изменений.

Пример:

./deploy_station.sh grafikstation01

Копирует новые версии скриптов.
station_worker.sh

Главный демон станции.

Работает бесконечно:

1. запрос задания
2. скачивание данных
3. запуск обработки
4. расчет ETA
5. отправка статуса
6. отправка результата
7. ожидание нового задания

station_status.sh

Отдельный помощник.

Отдает:

{
  "gpu":"RTX 3080",
  "vram_used_mb":5210,
  "temperature":61,
  "job":"session_35",
  "progress":42,
  "eta_sec":1830
}

Структура на GrafikStation

/home/makler_storage/
├── jobs/
├── incoming/
├── work/
├── output/
├── logs/
├── status/
└── scripts/

По архитектуре

Я бы вообще не давал серверу SSH-логиниться и запускать процессы вручную.

Лучше так:

WEB SERVER
    |
    | API
    v
GrafikStation Worker

То есть worker сам спрашивает:

есть работа?
есть работа?
есть работа?

каждые 10 секунд.

Тогда:

    не нужен входящий SSH

    не нужен NAT

    не нужен проброс портов

    можно иметь 10 графических станций

Пример:

grafikstation01
grafikstation02
grafikstation03

Все сами забирают задания.
ETA

Это обязательно.

В таблице processing_jobs я бы уже сейчас закладывал:

progress_percent
eta_seconds
worker_name
started_at
heartbeat_at

Тогда в order.php будет:

Обработка SfM

███████░░░░░░░░░░ 37%

GrafikStation01
RTX 3080

Осталось:
31 минута

Это гораздо полезнее простого статуса RUNNING.