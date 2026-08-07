# LM02.7B.5.5.2 — Native-resolution stereo contract

Base: `b064091cfd8fca92b9e1d1e0d7de31827f322a02`

## Goal

Remove the Android-side geometry sandwich from dual-phone/laptop stereo.

For metric stereo, the selected phone video resolution is authoritative up to
1920x1080. Calibration analysis and laptop/phone reduced-frame uplink use that
same pixel geometry. Android must not silently center-crop or resize the frame.

## Contract

- selected 1920x1080 -> calibration analysis requests 1920x1080;
- selected 1920x1080 -> reduced-frame producer requests 1920x1080;
- the producer rejects an actual CameraX size different from the selected size;
- no center crop is used in the uplink encoder;
- no Android downscale is used in the uplink encoder;
- JPEG payload keeps raw sensor/ImageAnalysis orientation;
- JPEG quality is 85;
- stereo transport supports 1920x1080 and up to 2 MiB;
- host protocol already accepts 2 MiB payloads;
- the CPU host performs rectification at the received native size;
- HIGH_640 / ULTRA_960 / FHD_1920 are host-side processing profiles;
- host resizing therefore happens only after rectification.

## Metric camera geometry

Stereo capture forces:
- zoom ratio 1.0x;
- EIS/video stabilization OFF when supported;
- OIS OFF when supported.

Calibration uses the same 1.0x geometry and records the resulting effective
zoom state.

## Failure policy

Metric stereo must fail visibly instead of silently changing geometry.

Examples:
- selected 1920x1080 but CameraX returns 1280x720 -> reject frame;
- selected mode above 1920x1080 -> reject metric stereo mode;
- calibration requested at 1920x1080 but ImageAnalysis returns another size ->
  reject calibration frame and expose `actual_resolution_mismatch`.

## Required retest

Existing calibration profiles created under the old 1280/cap/960 pipeline are
not authoritative for this test.

After installing LM02.7B.5.5.2:
1. select 1920x1080 @ 30 FPS on both phones;
2. keep the same physical camera on both phones;
3. run a new complete MASTER/SLAVE calibration;
4. verify saved calibration intrinsics report 1920x1080;
5. switch both phones to laptop mode;
6. verify Raw phone uplink reports 1920x1080 for CAMERA_A and CAMERA_B;
7. verify host calibration becomes READY;
8. measure a textured/AprilTag plane at 1.0 m, 2.0 m and 3.0 m;
9. compare HIGH_640 and ULTRA_960;
10. pack diagnostics.

Do not introduce an empirical distance multiplier or hard-coded disparity
offset. The purpose of this patch is to make calibration and runtime geometry
identical before evaluating remaining SGBM error.
