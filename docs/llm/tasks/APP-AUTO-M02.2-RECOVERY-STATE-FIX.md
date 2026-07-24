# APP-AUTO-M02.2 — Recovery State Fix

## Status

```text
IMPLEMENTED
RUNTIME ACCEPTANCE REQUIRED
```

`movement_features_low` and unmeasured `tracking_failed` now use
`SEEK_TEXTURE`, do not show the ghost, and do not save a photo. `RECOVER` is
reserved for `status=ok` with measured flow outside the overlap window. The
last reference ghost is stored internally and exposed only in measured
`RECOVER`. `CAPTURED` confirmation is held for 1200 ms.

## Checks

```text
cd app/MaklerTour
./gradlew :app:testDebugUnitTest
./gradlew :app:assembleDebug
python3 tools/stereo_contract_audit.py
```
