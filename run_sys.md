6. Лучше systemd, но cron пока норм

Для MVP cron с flock достаточно.

Позже лучше сделать systemd timer:

maklertour-marker-worker.service
maklertour-marker-worker.timer

Плюсы systemd:

логи через journalctl
контроль пользователя
ограничение ресурсов
удобный restart
нет параллельных запусков

Но сейчас не обязательно. Главное — не запускать worker через веб-запрос.
7. Важный баг, который надо поправить позже

Сейчас при Fatal Error job может остаться в:

PROCESSING

Как было с job 4 после ошибки confidence.

Надо будет добавить в process_marker_jobs.php:

try/catch вокруг process_one_job()
при exception:
  status = FAILED
  metric_status = FAILED
  error_text = exception message

И лучше вставку detections делать в transaction:

START TRANSACTION
DELETE old detections
INSERT detections
UPDATE processing_jobs
COMMIT

Если ошибка:

ROLLBACK
status = FAILED

Но это следующий фикс. Сейчас detector pipeline уже доказан рабочим.
