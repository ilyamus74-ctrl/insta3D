# APP-DUAL-PHONE-LM02.7B.5.3.3 — Full stereo SE(3) provisional bridge

## Purpose

Replace the yaw-only live stereo translation fallback with a full metric
six-degree-of-freedom stereo visual odometry step, and prevent long gaps from
forming when the last globally trusted keyframe is no longer visually close to
the current frame.

## Runtime behaviour

1. ORB correspondences are accepted only after reciprocal ratio matching and a
   fundamental-matrix RANSAC geometry filter.
2. Matched stereo depth points are fitted with a robust Kabsch/SVD SE(3)
   estimator inside RANSAC. Rotation, including pitch and roll, and metric
   translation are estimated together.
3. Gyroscope yaw is a consistency check only. It is not inserted as a hard
   rotation prior and is never multiplied by an empirical scale.
4. PnP cannot independently publish a walking pose. It is recorded only as a
   confirmation when it agrees with the stereo SE(3) pose.
5. A translation-uncertain frame may become an untrusted provisional tracking
   reference. It is still excluded from accumulated geometry, but the next
   frame is matched against a recent image instead of an increasingly stale
   keyframe.
6. Two consecutive accepted stereo SE(3) steps may promote the provisional
   chain back to a trusted walking pose. This uses measured image geometry only;
   no synthetic displacement is introduced.
7. Pose-candidate scoring selects a reference for the current frame. It does not
   split the session into reconstruction chunks and does not select a single
   best point-cloud chunk.

## Diagnostics

The status and pose-validation streams expose mutual/geometric match counts,
full SE(3) rotation and yaw, gyro-yaw disagreement, PnP confirmation,
provisional chain length, provisional tracking frames and promotions.
