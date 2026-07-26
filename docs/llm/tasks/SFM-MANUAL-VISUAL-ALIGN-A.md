# SFM-MANUAL-VISUAL-ALIGN-A

## Dependency

```text
after SFM-VIEWER-FREE-ORBIT-A
before SFM-MANUAL-VISUAL-ALIGN-B
```

## Goal

Align two models visually in one combined viewport without first selecting
small correspondence points.

## Existing behavior retained

```text
Anchor viewer
Source viewer
point-correspondence mode
Sim(3) computation from 4+ pairs
local draft persistence
manual finalize
```

Point correspondence remains available as a fallback and validation method.

## Combined viewport

Add a third persistent viewport:

```text
Anchor + Moving source
```

It is visible immediately after both clouds load.

### Anchor

```text
fixed transform
normal or neutral color
cannot be moved by TransformControls
```

### Moving source

```text
distinct tint
adjustable opacity
translation
rotation
uniform scale
```

## Interaction

```text
W = Move
E = Rotate
R = uniform Scale
Q = local/world transform space
F = fit both models
Esc = cancel current manipulation
```

Non-uniform scaling is prohibited.

## Visual aids

```text
Anchor visibility
Moving-source visibility
Moving-source opacity
Anchor point size
Moving-source point size
axes
grid
numeric transform fields
reset transform
fit both
```

Numeric fields include:

```text
translation X/Y/Z
rotation X/Y/Z or quaternion
uniform scale
```

## Transform contract

The edited transform is:

```text
Moving source → Anchor
```

Persist:

```text
matrix4
translation
rotation quaternion
uniform scale
editor mode
timestamp
anchor fingerprint
source fingerprint
```

Validation requires:

```text
finite and invertible matrix
last row = 0 0 0 1
positive uniform scale
proper rotation
no shear
no reflection
```

## Backend actions

Suggested actions:

```text
action=preview_visual_transform
action=finalize_visual_transform
```

Preview:

1. Validate matrix.
2. Transform Moving-source PLY.
3. Write temporary aligned PLY.
4. Write temporary combined PLY.
5. Write draft result JSON.
6. Do not create a trusted merge DB record.

Finalize:

1. Revalidate fingerprints and matrix.
2. Create final aligned and merged artifacts.
3. Store complete lineage.
4. Mark accepted only after user confirmation.

## Provenance

Use a distinct merge type:

```text
manual_visual_sim3_dense_ply
```

Do not pretend point correspondences were used.

## Acceptance tests

1. Both clouds appear together before any point pair exists.
2. Anchor cannot be moved.
3. Moving source can translate.
4. Moving source can rotate.
5. Moving source can uniformly scale.
6. Non-uniform scale is rejected.
7. Reset returns to identity.
8. Numeric and gizmo transforms stay synchronized.
9. Preview does not create a trusted DB merge.
10. Finalize creates visual-transform provenance.
11. Point-correspondence mode still works.
12. Accepted assembly Anchor plus remote Moving source works.
