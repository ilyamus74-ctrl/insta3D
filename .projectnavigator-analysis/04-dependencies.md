# Dependencies

Arrow means `predecessor -> successor`. No cycles are present.

| Predecessor | Successor | Evidence | Strength | Rationale |
|---|---|---|---|---|
| `tour-platform-mvp` | `android-capture-upload` | confirmed | hard | Capture is assigned to server orders/sessions and displayed in tour/order UI. |
| `android-capture-upload` | `auto-photo-sfm` | confirmed | hard | Auto Photo reuses bundle packager/upload queue and server `capture_bundles`. |
| `android-capture-upload` | `server-processing-orchestration` | confirmed | hard | Jobs consume server-registered media/capture bundles. |
| `server-processing-orchestration` | `single-sfm-baseline` | confirmed | hard | Worker launches EXTRACT_FRAMES/COLMAP jobs and owns result publication. |
| `single-sfm-baseline` | `single-connectivity-drift` | confirmed | hard | Experiments reuse the immutable selected frames, intrinsics and stock mapper baseline. |
| `single-sfm-baseline` | `tof-imu-measurement` | confirmed | hard | Metric diagnostics compare sensor evidence against completed sparse/dense geometry. |
| `single-connectivity-drift` | `single-sensor-constraints` | inferred | soft | Current decision sequence allows one bounded visual run before physical priors; a failed visual gate strengthens the IMU-prior path. |
| `tof-imu-measurement` | `single-sensor-constraints` | confirmed | hard | Active constraints require validated timing, calibration and observation provenance. |
| `single-sensor-constraints` | `capture-topology-unification` | inferred | soft | Recovery plan declares SINGLE reference stabilization first; some contract unification can proceed in parallel. |
| `android-capture-upload` | `dual-phone-capture` | confirmed | hard | Dual roles reuse phone recorder, IMU writer, bundle transfer and upload queue. |
| `capture-topology-unification` | `dual-phone-capture` | inferred | hard | Shared identity/package/server contract must be reconciled for an end-to-end accepted dual capture. |
| `capture-topology-unification` | `usb-stereo-capture` | inferred | hard | USB rig needs the common descriptor/profile/timeline contract specified by the recovery plan. |
| `usb-stereo-capture` | `stereo-global-fusion` | confirmed | hard | `MAKLERTOUR_SYNCED_DENSE` requires calibrated synchronized pairs and stereo extrinsics. |
| `dual-phone-capture` | `stereo-global-fusion` | inferred | hard | Dual-phone output cannot feed stereo processing until server acceptance/materialization exists. |
| `single-sfm-baseline` | `sfm-component-assembly` | confirmed | hard | Assembly operates on sparse/dense components created by SfM. |
| `single-sensor-constraints` | `metric-textured-model` | inferred | soft | A stable metric SINGLE reference is the declared prerequisite for promoting physical constraints; stereo may reach the target through its parallel branch. |
| `stereo-global-fusion` | `metric-textured-model` | confirmed | hard | Pair clouds and metric camera trajectory are direct geometry inputs; current fusion is explicitly incomplete. |
| `sfm-component-assembly` | `metric-textured-model` | inferred | soft | Video-SfM path needs global component placement before a single publishable mesh; not required for a fully connected stereo path. |
| `metric-textured-model` | `photorealistic-viewer` | inferred | soft | Dollhouse/floorplan measurement features require accepted spatial geometry; splat/NeRF can be a parallel research branch. |

## Parallel branches

After capture/upload and orchestration, three branches can run concurrently:

```text
SINGLE visual/sensor stabilization
Auto Photo SfM productization
Dual/USB capture -> calibrated stereo runtime acceptance
```

They converge at accepted global metric geometry rather than by inventing cross-links between every experimental document.
