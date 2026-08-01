# APP dual-phone LM01A-4.2 — MASTER navigation ownership

## Baseline

```text
81f9928a6adb5f16f0ae878959914f7707404eb5
```

## Goal

Keep SLAVE visibly subordinate to MASTER across the whole working application,
not only while the Camera tab is open.

## Required behavior

```text
MASTER Sessions  → SLAVE managed work screen
MASTER Orders    → SLAVE managed work screen
MASTER Camera    → SLAVE managed work screen
MASTER Draft     → SLAVE managed work screen
MASTER Queue     → SLAVE managed work screen
MASTER Settings  → SLAVE Settings
```

## Invariants

- Root navigation owns the managed/unmanaged transition.
- Camera Compose content must not independently claim or release SLAVE.
- Moving between non-Settings tabs must not tear down LIVE/HYBRID transport.
- `EXIT_WORK_MODE` is sent only when MASTER opens Settings or explicitly stops
  ownership.
- The Camera-card `Выкл. LIVE` action returns to passive `WORK_APP` without
  releasing SLAVE.
- SLAVE always displays its role and the fact that MASTER owns the application.
- Emergency disconnect remains available on SLAVE.

## Verification

```bash
php web/tests/dual_phone_lm01a_navigation_ownership_test.php
cd app/MaklerTour
./gradlew :app:compileDebugKotlin
```
