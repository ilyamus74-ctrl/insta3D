# SFM-MANUAL-VISUAL-ALIGN-A2 — Save Assembly

## Result

The third visual editor can persist its exact Moving-source Sim(3) transform.

Button:

```text
Сохранить сборку
```

The server validates the 4×4 matrix, positive uniform scale, orthonormal
rotation and determinant close to +1.

It then creates:

```text
source_visual_aligned_to_anchor.ply
manual_visual_merged_dense_cloud.ply
visual_transform.json
merge_result.json
```

The accepted result is inserted into `sfm_generated_model_merges`.

## Incremental workflow

A visual assembly contains:

```text
leaf_source_jobs
leaf_transforms
parent_inputs
parent_merge_id
```

It is accepted by `sfm_manual_resolve_merge_anchor()` and can therefore be
selected as Anchor for another remote component.

Workflow:

```text
two component models
→ visual assembly #N
→ select assembly #N plus a new component
→ assign assembly #N as Anchor
→ save visual incremental assembly #N+1
```

## Scale

Disconnected monocular SfM components can have independent gauge:

```text
translation
rotation
scale
```

Point count does not determine physical scale. The browser's bounding-box
scale match remains only a coarse starting estimate.

## Deletion

Accepted visual assemblies use the existing generated-assembly deletion
endpoint and dedicated `accepted_manual_alignments/order_<id>/merge_<id>`
directory contract.
