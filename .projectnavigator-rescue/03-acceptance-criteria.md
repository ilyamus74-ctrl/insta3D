# Recommended acceptance criteria

All criteria are advisory. **PASS** is used only where repository/runtime evidence closes the stated boundary; otherwise the state is PARTIAL, FAIL or NOT_RUN. IDs are stable proposal keys, not ProjectNavigator UUIDs.

## Tour MVP — `8801e3ab-2953-4113-b260-4cdb17238425`

- `ac-tour-01` **PASS:** an order and capture session persist, uploaded 360 media is associated, and an authorized private/public tour can render it. Evidence: PHP/DB paths plus recorded MVP documentation.
- `ac-tour-02` **NOT_RUN:** repeat the order → upload → publish smoke test on the current deployment without manual database repair.

## Android foundation — `4dcc4e53-cc41-4b0a-bb3a-ca2e47cca4d3`

- `ac-foundation-01` **PARTIAL:** every supported capture emits the canonical identity, mode, roles, timestamps/clock relation, calibration, media, telemetry, integrity and lifecycle fields.
- `ac-foundation-02` **PARTIAL:** artifacts survive local persistence and resumable upload without silent loss.
- `ac-foundation-03` **FAIL:** the server accepts or explicitly rejects every declared mode against a versioned contract; current dual aggregate is rejected unexpectedly.
- `ac-foundation-04` **NOT_RUN:** fixtures prove backward compatibility for current SINGLE, Auto Photo, MASTER/SLAVE, laptop-live and USB modes.

## SINGLE — `da6e553d-a4ec-40c5-a431-b03941393d29`

- `ac-single-01` **PASS:** a SINGLE capture records video, IMU and ToF sidecars and the server retains them.
- `ac-single-02` **PASS:** the sparse pipeline produces diagnostics for registration, components, points, reprojection error, trajectory, first/last frame and endpoint distance.
- `ac-single-03` **PARTIAL:** controlled long-range visual links improve connectivity without exhaustive matching; v1 supports this, isolated v2 is NOT_RUN.
- `ac-single-04` **FAIL:** IMU participates as a mapper/optimization pose or gravity prior; current use is selection/diagnostic only.
- `ac-single-05` **FAIL:** ToF participates as an active metric/geometric constraint and changes the reconstructed solution.
- `ac-single-06` **NOT_RUN:** a predeclared physical-ground-truth test demonstrates repeatable scale/trajectory improvement and acceptable metric error.

## Automatic Photo — `2498f509-0f87-41e1-9eba-fe0684511263`

- `ac-auto-01` **PARTIAL:** physical-device automatic capture preserves full-resolution images, usable timestamps and calibration and reaches server storage.
- `ac-auto-02` **PARTIAL:** the server produces a sparse/dense result and viewer entry without manual intervention.
- `ac-auto-03` **FAIL:** required IMU and ToF sidecars are uploaded and actively consumed for the intended metric result.
- `ac-auto-04` **NOT_RUN:** end-to-end runtime evidence records image count, registration/components, artifact validity and metric error.

## MASTER + SLAVE — `72378a61-7092-43c3-9671-4b365d929265`

- `ac-dual-01` **PASS:** MASTER controls SLAVE recording and persists camera roles, clock samples, per-phone video and IMU.
- `ac-dual-02` **FAIL:** aggregate dual bundle is accepted by the production upload contract.
- `ac-dual-03` **FAIL:** required ToF is captured/persisted/uploaded for both roles.
- `ac-dual-04` **NOT_RUN:** downstream processing uses both calibrated roles and clock mapping to produce an optimized global result.

## Two phones + notebook — `a94d2800-5bdf-4dea-83d9-cc05e8ac5152`

- `ac-laptop-01` **PARTIAL:** two phone streams reach one host with stable role/session identity, usable clock relation and calibration.
- `ac-laptop-02` **PARTIAL:** IMU/ToF packets reach the host with integrity/coverage diagnostics; present transport is reduced live data, not a durable canonical bundle.
- `ac-laptop-03` **FAIL:** the host or server consumes both cameras and telemetry in global alignment/fusion/optimization.
- `ac-laptop-04` **NOT_RUN:** an end-to-end run produces a valid metric cloud/mesh with stated accuracy.

## Android + USB — `4c7e40a0-5f6a-4563-b889-27e1f3286010`

- `ac-usb-01` **PARTIAL:** phone/USB roles, calibration and synchronized pairs survive capture and package validation on a physical device.
- `ac-usb-02` **FAIL:** required ToF sidecars are persisted and reach processing.
- `ac-usb-03` **PARTIAL:** pair clouds and trajectory are generated and accumulated; complete global fusion/optimization is not accepted.
- `ac-usb-04` **NOT_RUN:** F02 device/build preflight and graphics-station runtime acceptance pass on a representative dataset.
- `ac-usb-05` **FAIL:** output meets a declared global metric and mesh/texturing gate.

## Insta360 — `19dfb29a-4d50-4fb5-8848-39af4e78eb5d`

- `ac-360-01` **PASS:** the app connects to the X4, captures intended photo/video media and associates it with the tour workflow.
- `ac-360-02` **NOT_RUN:** current hardware regression confirms capture/upload/view after recent repository changes.
- `ac-360-03` **NOT_APPLICABLE to DONE boundary:** metric SfM use requires new calibration/processing criteria if the user intends it.

## Server orchestration — `c7ba832d-1307-475d-b543-59ac5c17ea6c`

- `ac-server-01` **PASS:** authorized station can claim, heartbeat and finish supported jobs and register result artifacts.
- `ac-server-02` **PARTIAL:** supported capture packages are schema-validated, integrity-checked and durably associated with order/session/mode/roles.
- `ac-server-03` **FAIL:** every active capture branch, especially dual aggregate, is accepted under one compatible contract.
- `ac-server-04` **PARTIAL:** orchestration records whether IMU/ToF were absent, diagnostic-only or actively consumed.
- `ac-server-05` **NOT_RUN:** integration fixtures cover each capture mode and each dispatched job with valid/invalid artifact outcomes.

## GrafikStation worker — `fa24df7a-87d4-4606-b4c8-3ce87eed24a1`

- `ac-worker-01` **PASS:** sparse/dense/PLY/mesh and synchronized-dense commands emit validated artifacts rather than relying only on exit status.
- `ac-worker-02` **PARTIAL:** diagnostics quantify components, trajectory and sensor coverage.
- `ac-worker-03` **FAIL:** IMU/ToF alter optimization as active constraints and the effect is measured A/B.
- `ac-worker-04` **FAIL:** stereo pair clouds undergo accepted global alignment, fusion, optimization/loop handling and mesh/texturing.
- `ac-worker-05` **NOT_RUN:** Hybrid v2 and latest stereo path run in isolation with command/manifest/audit agreement.

## Metric textured model — `0b06374d-ccc3-4261-a376-676807e8ac12`

- `ac-metric-01` **FAIL:** all required frames/camera roles form an accepted globally consistent trajectory and component structure.
- `ac-metric-02` **FAIL:** scale and geometry are constrained by calibrated stereo, ToF, IMU/ground truth as applicable—not merely reported afterward.
- `ac-metric-03` **NOT_RUN:** preregistered measurements meet explicit absolute/relative error thresholds on representative scenes.
- `ac-metric-04` **PARTIAL:** dense/mesh tooling exists; final mesh validity, texture coverage and reproducibility are not accepted.

## Viewer — `633331e3-586d-4e7c-976f-e2c18e6c966c`

- `ac-viewer-01` **PASS:** existing 360 and basic SfM artifacts can be viewed through current routes.
- `ac-viewer-02` **FAIL:** accepted metric model renders as a navigable dollhouse with stable scale/camera framing.
- `ac-viewer-03` **FAIL:** floorplan is generated/linked and validated against the model.
- `ac-viewer-04` **FAIL:** textured photorealistic model meets declared browser performance and visual-quality gates.

## Status caveat

The PASS labels above refer only to the exact criterion. They do not automatically make the owning WorkItem DONE; each broader tile retains unresolved criteria.
