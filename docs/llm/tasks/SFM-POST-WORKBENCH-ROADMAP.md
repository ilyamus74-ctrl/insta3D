# SFM Post-Workbench Roadmap

## Fixed implementation order

```text
SFM-ASSEMBLY-WORKBENCH-B
→ SFM-VIEWER-FREE-ORBIT-A
→ SFM-MANUAL-VISUAL-ALIGN-A
→ SFM-MANUAL-VISUAL-ALIGN-B
→ resume LightGlue bridge POC
```

No later task may silently bypass an incomplete earlier contract.

## Current baseline

The application already has:

```text
Video SfM component models grouped by pipeline Run
run-scoped source selection
automatic merge by shared COLMAP poses
manual point-correspondence alignment
generated merge records
accepted merge as a possible Anchor
ordinary 3D viewer
```

Current limitations:

```text
disconnected COLMAP components are not automatically joined
manual alignment requires small point correspondences
the combined overlay appears only after Sim(3) computation
OrbitControls prevents comfortable camera roll through both poles
the user cannot directly move one cloud relative to another
ICP refinement is not available after a human initial alignment
```

## Stage 1 — SFM-ASSEMBLY-WORKBENCH-B

Finish explicit Anchor and Moving-source semantics, assembly lineage reuse,
source trust states, compatibility checks and actionable errors.

This is required before opening another alignment editor.

## Stage 2 — SFM-VIEWER-FREE-ORBIT-A

Add two camera-navigation modes:

```text
Horizon locked
Free orbit 360°
```

This stage changes camera navigation only. It must not alter PLY coordinates,
model transforms, floor alignment or saved assembly transforms.

## Stage 3 — SFM-MANUAL-VISUAL-ALIGN-A

Add a permanent third combined viewport:

```text
Anchor remains fixed
Moving source can be translated
Moving source can be rotated
Moving source can be uniformly scaled
```

The existing point-correspondence method remains available as a fallback.

## Stage 4 — SFM-MANUAL-VISUAL-ALIGN-B

Use the human visual transform as an initial state for local ICP refinement.

ICP must not be treated as blind global registration.

## Stage 5 — LightGlue bridge POC

After the UI and manual alignment contracts are stable, resume the bridge POC
for disconnected room/staircase components.

## Global safety rules

Every accepted alignment result preserves:

```text
order ownership
capture-session compatibility
source lineage
leaf component jobs
source PLY fingerprints
initial transform
final transform
uniform scale only
output-tree confinement
```

Not trusted as reusable sources:

```text
diagnostic concatenation
failed or rejected assembly
anchor-only assembly
missing-result assembly
assembly without valid lineage
```

## Completion gate

The sequence is complete when a user can:

1. Select a trusted assembly and a new dense component.
2. Explicitly choose Anchor and Moving source.
3. Rotate the camera freely around either cloud.
4. Visually translate, rotate and uniformly scale the Moving source.
5. Preview both clouds together before saving.
6. Optionally refine the transform with ICP.
7. Compare manual and refined states.
8. Save a new accepted assembly with complete lineage.
9. Reuse that assembly in a later operation.
