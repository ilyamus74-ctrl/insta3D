# SFM-MANUAL-VISUAL-ALIGN-B

## Dependency

```text
after SFM-MANUAL-VISUAL-ALIGN-A
```

## Goal

Refine a human-created visual transform with local ICP and allow comparison,
acceptance or rejection.

ICP is a local optimizer, not blind global registration.

## Input

```text
Anchor PLY
Moving-source PLY
human initial Moving-source → Anchor transform
```

Optional preprocessing:

```text
voxel downsample
outlier filtering
normal estimation
point-count cap
```

## Modes

Preferred order:

```text
point-to-plane ICP when normals are usable
point-to-point ICP fallback
```

Record the selected mode.

The first version keeps the human uniform scale fixed and refines only rigid
rotation and translation.

## Persisted parameters

```text
voxel size
maximum correspondence distance
iteration limit
convergence tolerance
normal radius
sample counts
```

Defaults derive from model scale or voxel size.

## Outputs

```text
initial_visual_matrix4
icp_delta_matrix4
refined_matrix4
fitness
inlier RMSE
correspondence count
iterations
converged
mode
warnings
```

## UI

```text
Manual initial
ICP refined
Before/after
Apply ICP result
Reject ICP result
Return to manual transform
```

The refined overlay must be inspectable before finalization.

## Rejection gates

Do not recommend or auto-accept when:

```text
fitness is too low
RMSE is non-finite
correspondence count is too small
transform jump exceeds safety limits
rotation jump is implausible
translation jump is implausible
bounding box becomes invalid
```

Rejected ICP must not overwrite the human transform.

## Execution

A dedicated bounded Python/Open3D helper may perform ICP.

The web layer must:

1. Validate access and source ownership.
2. Write immutable request JSON.
3. Execute with bounded resources.
4. Validate result JSON and PLY.
5. Keep initial and refined artifacts separate.
6. Finalize only after explicit confirmation.

## Provenance

Use a distinct merge type:

```text
manual_visual_icp_dense_ply
```

The result points back to the initial visual draft.

## Acceptance tests

1. ICP starts only from a valid visual transform.
2. Initial transform remains available.
3. Scale stays fixed.
4. Before/after switching does not reload both PLY files.
5. Bad ICP can be rejected.
6. Rejection does not alter the draft.
7. Valid refinement records fitness and inlier RMSE.
8. Accepted result stores initial and refined matrices.
9. Generated Models shows ICP provenance.
10. Accepted result can be reused as Anchor.
