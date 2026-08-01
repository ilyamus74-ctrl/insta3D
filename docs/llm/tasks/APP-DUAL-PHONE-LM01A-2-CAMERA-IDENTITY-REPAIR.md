# APP dual-phone LM01A-2 — calibration camera identity repair

## Status

```text
READY FOR IMPLEMENTATION
BASELINE REPOSITORY: d507285753c1052845238dbabc000f9ee13d799a
```

## Scope

Repair the already accepted calibration profile that contains missing
`master_camera_id` or `slave_camera_id`.

The repair uses:

```text
local selected Camera2 camera ID
connected peer capability camera ID
```

Only blank IDs are filled. Existing non-blank IDs are never overwritten; a
different current ID is reported as a conflict.

The numerical calibration result is unchanged:

```text
K/D unchanged
R/T unchanged
baseline unchanged
image dimensions unchanged
raw orientation unchanged
```

This patch intentionally does not modify the fullscreen calibration solver. Future
profile creation will be fixed in a separate exact-context patch after the current
profile is repaired and LM01A session preparation is unblocked.
