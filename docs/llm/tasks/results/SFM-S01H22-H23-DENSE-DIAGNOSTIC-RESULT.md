# SFM-S01H.2.2 / S01H.2.3 — dense metric diagnostic closure

Status: `PASS / CLOSED (DIAGNOSTIC)`

Date: `2026-08-16`

## Accepted real pipeline

```text
pipeline_run_id:              92
EXTRACT remote job:           185780520
SPARSE remote job:            619907169
DENSE parent remote job:      103754100
sparse model:                 0
dense chunks:                 4 / 4 DONE
geometric depth maps:         334
photometric depth maps:       334
```

Persistent evidence:

```text
/home/makler/web/remote_station/output/pipeline_92/metric_evidence/
```

## S01H.2.2 accepted result

Reference strategy:

```text
geometric_footprint_p50

ToF observations:             4896
candidates:                   4461
inliers:                      2899
inlier ratio:                 0.6498542928

robust scale:                 191.014948853 mm / COLMAP unit
depth error p50:              79.511851968 mm
depth error p95:              216.369449156 mm
relative error p50:           0.0908965364
relative error p95:           0.2339038102
```

Observed raw scale-spread signals:

```text
distance:                     1.9963966344
zone row:                     1.2930628963
zone column:                  1.3928214790
zone radial:                  1.1231743924
time:                         1.0916657586
image region:                 1.2229137870
```

Accepted interpretation:

```text
distance-dependent scale instability is real
sparse-only correspondence mismatch is not sufficient to explain it
dense geometric and photometric maps show the same class of instability
one global metric scale is unsafe
```

## S01H.2.3 accepted controlled decomposition

Reference strategy:

```text
geometric_footprint_p50
direct candidates:            4461
```

Controlled results:

```text
raw distance spread:                          1.9963966344
distance spread after row/column control:     1.9222057701

zone-row spread after distance control:       1.3350201802
zone-column spread after distance control:    1.2413141594
zone-radial spread after distance control:    1.0717985469
image-region spread after distance control:   1.1320704877
time spread after distance control:           1.0601545298
```

Signals:

```text
depth geometry deformation remains after zone control:      true
zone-row effect remains after distance control:             true
zone-column effect remains after distance control:          true
zone-radial-only effect remains:                            false
image-region effect remains:                               true
time effect remains:                                       false
```

Fully normalized residual:

```text
count:                         4461
absolute ratio error p50:      0.0882021620
absolute ratio error p95:      0.4975310104
absolute ratio error max:      2.4257195226
```

The residual remains too large for geometry mutation.

## Camera2 / COLMAP optics audit correction

The first H2.3 run incorrectly treated the orientation change

```text
Camera2 prior:                1920 x 1080
COLMAP sparse camera:         1080 x 1920
```

as anisotropic resizing. The audit was corrected to use the same 90/270-degree
orientation adaptation already used by the sparse worker.

Correct orientation-aware comparison:

```text
adaptation:                   ROTATED_90_OR_270

Camera2 prior:
  f:                          1303.124942780
  cx:                         540
  cy:                         960
  k:                          0

final COLMAP:
  f:                          1314.728816674
  cx:                         540
  cy:                         960
  k:                          0.0138106267

focal delta:                  +0.8904651821 %
principal-point shift:        0 px
aspect scale mismatch:        1.0
```

Final optics signals:

```text
focal_drift_gt_2pct:                       false
principal_point_shift_gt_1pct_diagonal:    false
aspect_scale_mismatch_gt_0p5pct:           false
camera_optics_drift_signal:                false
```

Therefore a gross Camera2-vs-COLMAP focal/orientation mismatch is rejected as the
primary explanation for the measured metric instability.

## Accepted hypotheses after H2.3

Supported diagnostic hypotheses:

```text
DEPTH_GEOMETRY_DEFORMATION_REMAINS_AFTER_ZONE_CONTROL
TOF_TO_RGB_ANGULAR_OR_ZONE_CALIBRATION_RESIDUAL_REMAINS
RGB_IMAGE_REGION_GEOMETRY_RESIDUAL_REMAINS
```

Not supported by this run:

```text
gross Camera2/COLMAP optics drift
time drift as the primary cause
pure radial-zone effect as the primary cause
```

These are diagnostic correlations, not final physical root-cause proof.

## Safety / closure decision

```text
measurement_only:             true
geometry_mutation_enabled:    false
ready_for_geometry_mutation:  false
sparse_model_modified:        false
camera_poses_modified:        false
points3d_modified:            false
dense_input_modified:         false
dense_depth_modified:         false
fusion_enabled:               false
```

S01H.2.2 and S01H.2.3 are `PASS / CLOSED (DIAGNOSTIC)`.

S01H.3 remains `CLOSED / BLOCKED`.

## Next diagnostic gate — S01H.2.4

Goal:

```text
localize the remaining row/column angular bias
separately from the distance-dependent dense-depth deformation
```

Required work:

```text
1. build a distance-conditioned 8x8 per-zone residual map;
2. preserve residual sign, not only absolute error/spread;
3. join H1 observations with H2.2 candidates by image/tof_sequence/zone;
4. reconstruct projected RGB position from accepted ToF calibration;
5. compare residual against zone row/column and projected RGB x/y;
6. test whether a small ToF ray/extrinsic perturbation explains the structured
   residual better than RGB image-region/depth deformation;
7. remain measurement-only; do not alter calibration or geometry automatically.
```
