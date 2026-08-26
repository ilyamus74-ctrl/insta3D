# MaklerTour Capture Architecture Recovery Plan

## Objective

Получить стабильную метрическую 3D реконструкцию помещений.

Главный принцип:

Сначала подтвердить и стабилизировать SINGLE pipeline.
После этого переносить проверенный контракт в DUAL_PHONE, USB_RIG и LAPTOP.

---

## Current priorities

Order:

1. SINGLE + IMU + ToF audit and stabilization
2. DUAL_PHONE architecture unification
3. USB_RIG architecture unification
4. LAPTOP live reconstruction

---

# Phase 1 — SINGLE

SINGLE является reference implementation.

Expected workflow:

```
Camera configuration
        |
Charuco calibration
        |
Camera profile creation
        |
Video capture
        |
IMU recording
        |
Optional ToF recording
        |
Upload
        |
COLMAP / reconstruction
        |
Metric validation
```

Audit requirements:

- проверить создание camera profile;
- проверить использование profile во время capture;
- проверить наличие IMU данных;
- проверить наличие ToF данных;
- проверить передачу IMU/ToF на сервер;
- проверить использование IMU/ToF при reconstruction;
- проверить источник scale;
- проверить причину накопления drift при круговом обходе объекта.

---

# Phase 2 — DUAL_PHONE

Target model:

```
SINGLE MASTER
        +
SINGLE SLAVE
        +
Stereo contract
        +
IMU
        +
ToF
```

Requirements:

- common CaptureSessionDescriptor;
- master/slave identity;
- unified camera profiles;
- stereo profile;
- synchronized timeline;
- ToF availability;
- backend processing support.

---

# Phase 3 — USB_RIG

Target:

```
Phone camera
+
USB camera
+
IMU
+
optional ToF
```

Requirements:

- common camera descriptor;
- stereo calibration;
- timestamp normalization;
- profile management.

---

# Phase 4 — LAPTOP

Target:

```
Dual phones
