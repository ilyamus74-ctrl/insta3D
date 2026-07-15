Для каждой проблемы:

ID: CAM-004
Status: confirmed
Severity: high

Symptom:
Application slows down after several hours.

Confirmed observations:
- GPU utilization remains low.
- Process memory grows over time.
- Restart restores normal performance.

Hypotheses:
- frame buffers are retained;
- decoder queue grows;
- completed frames are not released.

Evidence needed:
- memory profile;
- queue size metrics;
- allocation counters.

Нужно жёстко отделять:

    подтверждённые факты;

    предположения;

    неподтверждённые выводы.
