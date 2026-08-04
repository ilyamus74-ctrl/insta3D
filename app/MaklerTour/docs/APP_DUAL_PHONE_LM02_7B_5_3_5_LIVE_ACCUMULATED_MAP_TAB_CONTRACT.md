# APP-DUAL-PHONE-LM02.7B.5.3.5 — Live accumulated map tab

## Purpose

Expose the point cloud that the laptop runtime is already accumulating while a
scan is in progress, and render it in a separate dashboard tab without running
a second reconstruction pipeline.

## Runtime behaviour

1. The existing Cameras + depth view remains the default dashboard tab.
2. A Live 3D model tab renders the current session-owned accumulated PLY with a
   self-contained WebGL point-cloud viewer. No external JavaScript library or
   internet connection is required.
3. RAW, MULTIVIEW, TEMPORAL STRICT raw and TEMPORAL STRICT multiview clouds are
   selectable. RAW is the default because it exposes maximum live coverage.
4. Camera trajectory JSON may be overlaid on the point cloud.
5. The browser reloads the selected cloud when accumulated-map counters change.
   It does not request PLY at camera-frame rate and does not reset the operator's
   orbit, pan or zoom after an automatic cloud refresh.
6. Point size, manual refresh and fit-to-scene controls are available. Mouse or
   pointer input supports orbit, pan and zoom.
7. HTTP endpoints expose only fixed map artifacts inside the current session
   directory. Arbitrary filesystem paths are not accepted.
8. The viewer is diagnostic and does not modify tracking, provisional geometry,
   voxel fusion, keyframe selection, scale or stored PLY files.

## Endpoints

- `/api/map/raw.ply`
- `/api/map/multiview.ply`
- `/api/map/strict.ply`
- `/api/map/strict-multiview.ply`
- `/api/map/trajectory.json`
