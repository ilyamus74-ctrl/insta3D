# Marker processing worker cron

Пример cron-запуска worker-а:

```cron
* * * * * php /home/makler/web/bin/process_marker_jobs.php --limit=5 >> /home/makler/web/storage/logs/marker_worker.log 2>&1
```
