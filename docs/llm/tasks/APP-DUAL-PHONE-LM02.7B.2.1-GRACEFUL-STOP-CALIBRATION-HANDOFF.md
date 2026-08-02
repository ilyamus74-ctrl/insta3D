# APP-DUAL-PHONE-LM02.7B.2.1

## Goal

Add a dashboard-controlled graceful shutdown, automatic JSON diagnostic
packaging, and transfer of the accepted dual-phone calibration from CAMERA_A to
the laptop host.

## Acceptance

1. Dashboard button and F8/Alt+S request graceful stop.
2. Host exits without an operator Ctrl+C.
3. `run.sh` packages the exact session after host exit.
4. Archive remains outside Git and is JSON-only by default.
5. CAMERA_A sends the full accepted calibration profile when available.
6. Host persists `stereo_calibration.json`.
7. Calibration and hello files are included in the diagnostic archive.
8. Existing nearest-unused-frame pairing and IMU transport remain unchanged.
