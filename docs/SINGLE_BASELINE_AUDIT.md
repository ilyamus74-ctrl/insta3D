текущую цель SINGLE;
подтверждённые файлы;
job_180237696 как reference capture;
что capture часть работает;
что остаётся проверить server consumption;
дальнейший план SINGLE → DUAL → USB → LAPTOP.

# SINGLE Baseline Audit

Status:
IN PROGRESS

Reference capture:
job_180237696

Purpose:
Verify complete PHONE_CAMERA pipeline:
capture -> upload -> reconstruction -> metric scale

Confirmed:

- video captured
- camera metadata captured
- IMU captured
- ToF captured
- timestamps captured
- server output generated

Not yet confirmed:

- IMU influence on reconstruction
- ToF influence on reconstruction scale
- metric correction path
- COLMAP constraint integration