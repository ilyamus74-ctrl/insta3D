# LM02.7B.4 — laptop metric depth and CPU quality profiles

## Scope

The Fedora laptop remains the only MASTER. Two Android phones provide synchronized
JPEG/IMU uplinks. This slice ports the accepted Android live-depth policy to the
laptop host without changing the phone transport contract.

## Operator modes

```text
AUTO
FHD_1920       manual experimental CPU/load profile
ULTRA_960      manual 960x540 envelope
HIGH_640       manual 640x360 envelope
QUALITY_480    manual 480x270 envelope
BALANCED_320   manual 320x240 envelope (16:9 source fits as 320x180)
```

AUTO starts at ULTRA_960 and uses the Android warm-up, p95 thresholds and
hysteresis to move between ULTRA_960, HIGH_640, QUALITY_480, BALANCED_320 and
THROTTLED_320. AUTO never promotes itself into FHD_1920.

FHD_1920 is deliberately manual. The current phone uplink is 960x540, therefore
FHD_1920 reports `source_upscaled=true`: it is a valid CPU stress test but does not
claim additional source detail. If a later uplink sends native 1920x1080, the same
profile processes it without upscaling.

## Metric pipeline

```text
rectified pair
→ profile-sized work pair
→ grayscale + CLAHE
→ forward StereoSGBM
→ optional reverse StereoSGBM / left-right consistency
→ median disparity
→ texture gates
→ morphology
→ motion-aware bounded temporal consensus
→ metric depth Z = focal_px * baseline_mm / disparity_px / 1000
→ raw / filtered / strict depth and confidence previews
```

Metric scale is derived only from accepted calibration intrinsics/extrinsics. The
operator baseline is not used.

## Diagnostics

Status and JSONL diagnostics expose selected and active profile, work dimensions,
target FPS, processing p50/p95, utilization, upscaling state, raw/filtered/stable
coverage, confidence, median depth, jitter, motion mode, focal length and baseline.

The diagnostic archive includes:

```text
depth_raw_latest.jpg
depth_filtered_latest.jpg
depth_strict_latest.jpg
confidence_latest.jpg
```
