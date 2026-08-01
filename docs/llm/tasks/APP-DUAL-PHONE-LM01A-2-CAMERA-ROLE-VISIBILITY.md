# APP dual-phone LM01A-2 — Camera menu role visibility

## Status

```text
READY FOR IMPLEMENTATION
BASELINE REPOSITORY: 8ca2f01258a386f64251dc2320cf5b78db93f34e
```

## Fixed UI rule

The Camera destination exposes one capture family at a time:

```text
STANDALONE
→ keep the existing photo-point and video-scan controls
→ hide LIVE and HYBRID controls

MASTER or SLAVE
→ hide the standalone photo-point and video-scan controls
→ show the LM01A LIVE and HYBRID card
```

The selected role is reloaded when the Camera destination resumes. Returning from
Settings therefore updates the visible capture family without restarting the app.

This slice changes menu visibility only. It does not open the reduced-frame data
channel, change CameraX binding, change raw orientation, or alter recording and
calibration calculations.
