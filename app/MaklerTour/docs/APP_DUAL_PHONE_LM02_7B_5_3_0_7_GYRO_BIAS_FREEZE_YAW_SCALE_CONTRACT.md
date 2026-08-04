# LM02.7B.5.3.0.7 — Gyro bias freeze and yaw-scale diagnostics

## Problem

The previous runtime continuously updated `gyro_bias_rad_s` whenever the selected
rate was below `0.10 rad/s`. A slow tripod pan is commonly below that threshold,
so real angular motion was learned as sensor bias and removed from integrated yaw.

In session `2026-08-04T08-29-13.931Z`, replaying CAMERA_A IMU gives approximately:

- uncorrected gyro yaw range: `53.39 deg`;
- legacy adaptive-bias yaw range: `22.84 deg`;
- reconstructed trajectory maximum yaw: `25.16 deg`.

## Runtime contract

1. Gyro bias is calibrated only from the first 80 low-rate, low-acceleration
   samples of an IMU session.
2. After calibration, bias is frozen for that IMU session. Slow intentional
   rotation must never be absorbed into bias.
3. No hard yaw multiplier is applied.
4. Both uncalibrated and bias-corrected yaw are integrated and exposed for audit.
5. Pose diagnostics include total yaw, per-reference yaw, removed bias, bias value,
   calibration state and calibration sample count.
6. Gyro remains unavailable to pose fusion until initial bias calibration finishes.
7. On an IMU session-id change, bias calibration restarts, while the cumulative
   yaw origin is preserved for map continuity.

## Expected capture procedure

Hold the tripod still for at least 2–3 seconds after session start, then rotate
slowly. The status must show `gyro_bias_ready: true` before gyro-assisted tracking.
