# SINGLE Visual Loop Closure Sparse-Only A/B Audit

## Status

```text
BASELINE COMMIT: 4c3181e
EXPERIMENT: COMPLETE
SCOPE: SPARSE ONLY
DENSE: NOT RUN
PRODUCTION PIPELINE CHANGES: NONE
CAPTURE / SELECTED FRAMES CHANGES: NONE
IMU BA / TOF GEOMETRY MUTATION: NOT USED
LOOP_CLOSURE_RESULT: PARTIAL_SUPPORT
```

## Executive conclusion

Stock COLMAP non-local matching measurably improves visual connectivity for the immutable SINGLE capture `job_180237696`:

- A, production-equivalent sequential matching, produced 6 disconnected sparse components;
- a direct first/last-window experiment tested 900 pairs but produced 0 geometrically verified endpoint edges and therefore did not change the reconstruction;
- B, bounded exhaustive matching on the same 547 images/features/intrinsics, produced 11,744 verified pairs, including 479 with temporal frame gap greater than 300;
- B produced one component with 509 registered images, containing both `frame_000001.jpg` and `frame_000547.jpg`;
- the B start/end camera-center gap is 1.305 COLMAP units, or 2.09% of its 62.498-unit trajectory length;
- A has no comparable global start/end gap because frame 1 and frame 547 belong to different components.

This supports the hypothesis that missing non-local visual connectivity is a material cause of fragmentation and open-loop drift. It does **not** yet prove that spiral/self-intersection is fully cured: B mean sparse reprojection error increased from approximately 0.85 px in A's two largest components to 0.94 px, maximum adjacent trajectory step increased, and the PCA segment-crossing proxy did not improve.

Exhaustive matching took 19.3 minutes on CPU and is a diagnostic upper bound, not a production-ready policy.

## Experiment isolation and immutable input

Evidence root:

```text
/tmp/insta3d_single_loop_ab/
```

Input frames are referenced read-only from:

```text
/mnt/storage/makler_pipeline/output/job_180237696/frames/
```

Input contract:

| Property | Value |
|---|---|
| Selected images | 547 |
| Frame-set SHA-256 | `cca48791af8d0516c7139a9286b11b444ca7053cb1353fcf166e896b938de585` |
| Actual JPEG geometry | 1080 x 1920 |
| Shared camera | Yes |
| Camera model | `SIMPLE_RADIAL` |
| Adapted camera params | `1303.124942779541,540.0,960.0,0.0` |
| Feature extraction | One common database, CPU SIFT |
| Mapper | Ordinary stock `colmap mapper`, default mapper/BA options |

`camera_metadata.json` stores the prior for source orientation as `f,cx,cy,k = 1303.124942779541,960,540,0`. The actual selected JPEGs are portrait-oriented. The experiment applies the same 90/270-degree principal-point adaptation as `process_colmap_sparse.sh`, yielding `1303.124942779541,540,960,0`.

No existing sparse model or pipeline evidence was opened for writing. The source frame directory was linked, not copied or modified.

## Matcher capability audit

| Option | Availability | Decision |
|---|---|---|
| `SequentialMatching.loop_detection` | Available; current pipeline setting defaults false | Not used for final B because no existing vocabulary tree was found |
| `SequentialMatching.vocab_tree_path` | Supported by installed COLMAP | No vocabulary-tree file found in inspected server/storage roots |
| Explicit match pairs | Supported through `colmap matches_importer --match_type pairs` | Tested as intermediate B-explicit attempt |
| Exhaustive matcher | Available | Used as final bounded B to discover any usable non-local edges |
| Stock mapper/global BA | Available | Same ordinary `mapper` command for A and B |

The installed binary rejects `colmap --version`; supported commands/options were verified through command help. The experiment did not assume that the repository COLMAP checkout equals the deployed binary.

## Variants

### A — sequential baseline

```text
common feature database
  -> sequential_matcher overlap=60 loop_detection=0
  -> ordinary mapper
```

This matches the effective production sparse policy apart from CPU rather than station GPU execution. Both comparison variants use the same CPU-extracted SIFT features, so the A/B delta remains matcher pairing only.

### B-explicit — endpoint diagnostic

```text
A matched database copy
  -> 30 first frames x 30 last frames = 900 explicit pairs
  -> ordinary mapper
```

Result: 0 endpoint-window pairs survived geometric verification. B-explicit therefore remained effectively identical to A and was retained as negative evidence, not used as the final B conclusion.

### B — exhaustive non-local upper bound

```text
common feature database copy
  -> exhaustive_matcher
  -> ordinary mapper/global BA
```

This is the smallest available stock-COLMAP diagnostic that can discover non-local overlap without an existing vocabulary tree. It changes pairing only; images, features, camera and mapper settings remain fixed.

## Results

### Main A vs B table

| Metric | A sequential | B exhaustive | Interpretation |
|---|---:|---:|---|
| Component count | 6 | 1 | Strong connectivity improvement |
| Registered images, union/sum | 526 | 509 | B loses 17 registrations but puts all retained images in one model |
| Largest component images | 204 | 509 | `+305`, 2.50x larger |
| Largest/full-model frame span | 308–511 | 1–547 | B contains the complete temporal endpoints |
| Sparse points, all components/full model | 72,896 | 82,570 | `+9,674`, +13.3% |
| Mean reprojection error | 0.848 px in A largest | 0.941 px | B is worse by about 0.093 px |
| Verified image pairs | 2,145 | 11,744 | 5.48x more |
| Verified inliers | 818,160 | 1,647,779 | 2.01x more |
| Verified pairs with gap >60 | 70 | 2,480 | Non-local connectivity added |
| Verified pairs with gap >100 | 25 | 1,452 | Non-local connectivity added |
| Verified pairs with gap >300 | 0 | 479 | Long-range edges exist in B |
| Literal first-30/last-30 verified pairs | 0 | 0 | Closure is mediated through other long-range views, not literal endpoints |
| Global start/end gap | Not defined: endpoints disconnected | 1.305 units | B closes the full temporal sequence into one model |
| Trajectory path length | Not defined globally | 62.498 units | B full registered trajectory |
| Normalized start/end gap | Not defined globally | 0.0209 / 2.09% | Positive loop-gap result, no A numeric denominator |
| Adjacent step median | 0.157 in A largest | 0.108 | Better typical continuity |
| Adjacent step p95 | 0.294 in A largest | 0.242 | Better p95 continuity |
| Adjacent step maximum | 0.500 in A largest | 1.668 | A large B outlier remains |
| PCA projected segment crossings | 7 in A largest | 20 in B full path | Not improved; different temporal coverage limits direct comparability |

### A component structure

| Model | Frames | Frame span | Points | Mean reprojection error |
|---:|---:|---:|---:|---:|
| 1 | 204 | 308–511 | 35,885 | 0.848 px |
| 0 | 153 | 79–231 | 20,524 | 0.850 px |
| 2 | 56 | 235–290 | 2,709 | 0.952 px |
| 4 | 56 | 492–547 | 6,263 | 0.890 px |
| 3 | 43 | 1–43 | 7,113 | 0.774 px |
| 5 | 14 | 294–307 | 402 | 1.055 px |

A is not an open trajectory in one model; it is a fragmented sequence with gaps around frames 44–78, 232–234, 291–293, and overlaps/alternative placement near 492–511.

### B-explicit result

| Metric | A | B-explicit |
|---|---:|---:|
| Requested endpoint pairs | 0 | 900 |
| Verified endpoint pairs | 0 | 0 |
| Component count | 6 | 6 |
| Registered images | 526 | 526 |
| Points | 72,896 | 72,900 |
| Largest normalized local gap | 0.301887 | 0.301999 |

The tiny numeric changes are mapper nondeterminism/BA variation, not loop closure. A direct first-last policy cannot work for this capture because those windows have no verified visual correspondence.

## Runtime

| Stage | Runtime |
|---|---:|
| Common feature extraction | 3:48.11 |
| A sequential matcher | 1:26.81 |
| A mapper | 5:39.71 |
| B-explicit additional 900-pair matching | 0:13.02 |
| B-explicit mapper | 5:04.75 |
| B exhaustive matcher | 19:18.58 |
| B exhaustive mapper | 9:27.59 |

Runtime was recorded by `/usr/bin/time -v`. Dense was never started.

## Exact COLMAP commands

Environment variables shown below are expanded for readability:

```bash
EXP=/tmp/insta3d_single_loop_ab
FRAMES=/mnt/storage/makler_pipeline/output/job_180237696/frames
```

Common feature extraction:

```bash
colmap feature_extractor \
  --database_path "$EXP/features.db" \
  --image_path "$FRAMES" \
  --ImageReader.camera_model SIMPLE_RADIAL \
  --ImageReader.single_camera 1 \
  --ImageReader.camera_params 1303.124942779541,540.0,960.0,0.0 \
  --FeatureExtraction.use_gpu 0
```

A matching and mapping:

```bash
cp "$EXP/features.db" "$EXP/A/database.db"

colmap sequential_matcher \
  --database_path "$EXP/A/database.db" \
  --FeatureMatching.use_gpu 0 \
  --SequentialMatching.overlap 60 \
  --SequentialMatching.loop_detection 0

colmap mapper \
  --database_path "$EXP/A/database.db" \
  --image_path "$FRAMES" \
  --output_path "$EXP/A/sparse"
```

B-explicit matching and mapping:

```bash
cp "$EXP/A/database.db" "$EXP/B/database.db"

colmap matches_importer \
  --database_path "$EXP/B/database.db" \
  --match_list_path "$EXP/B/loop_pairs.txt" \
  --match_type pairs \
  --FeatureMatching.use_gpu 0

colmap mapper \
  --database_path "$EXP/B/database.db" \
  --image_path "$FRAMES" \
  --output_path "$EXP/B/sparse"
```

Final B exhaustive matching and mapping:

```bash
cp "$EXP/features.db" "$EXP/B_exhaustive/database.db"

colmap exhaustive_matcher \
  --database_path "$EXP/B_exhaustive/database.db" \
  --FeatureMatching.use_gpu 0

colmap mapper \
  --database_path "$EXP/B_exhaustive/database.db" \
  --image_path "$FRAMES" \
  --output_path "$EXP/B_exhaustive/sparse"
```

Evidence exports, repeated for each model/variant:

```bash
colmap model_converter --input_path MODEL --output_path EXPORT/txt --output_type TXT
colmap model_converter --input_path MODEL --output_path EXPORT/sparse.ply --output_type PLY
colmap model_analyzer --path MODEL
```

## Artifact paths

```text
/tmp/insta3d_single_loop_ab/frames_set_sha256.txt
/tmp/insta3d_single_loop_ab/features.db
/tmp/insta3d_single_loop_ab/summary.json
/tmp/insta3d_single_loop_ab/B_exhaustive_summary.json

/tmp/insta3d_single_loop_ab/A/database.db
/tmp/insta3d_single_loop_ab/A/sparse/
/tmp/insta3d_single_loop_ab/A/export/<model_id>/txt/
/tmp/insta3d_single_loop_ab/A/export/<model_id>/sparse.ply
/tmp/insta3d_single_loop_ab/A/export/<model_id>/camera_trajectory.json
/tmp/insta3d_single_loop_ab/A/export/<model_id>/sparse_diagnostics.json

/tmp/insta3d_single_loop_ab/B/loop_pairs.txt
/tmp/insta3d_single_loop_ab/B/database.db
/tmp/insta3d_single_loop_ab/B/sparse/
/tmp/insta3d_single_loop_ab/B/export/

/tmp/insta3d_single_loop_ab/B_exhaustive/database.db
/tmp/insta3d_single_loop_ab/B_exhaustive/sparse/0/
/tmp/insta3d_single_loop_ab/B_exhaustive/export/0/txt/
/tmp/insta3d_single_loop_ab/B_exhaustive/export/0/sparse.ply
/tmp/insta3d_single_loop_ab/B_exhaustive/export/0/camera_trajectory.json
/tmp/insta3d_single_loop_ab/B_exhaustive/export/0/sparse_diagnostics.json

/tmp/insta3d_single_loop_ab/logs/
```

The artifacts are temporary server-local evidence. They are isolated from `/mnt/storage/makler_pipeline/output` and `/home/makler/web/remote_station/output`.

## Spiral / self-intersection assessment

The experiment establishes three positive facts:

1. A's temporal fragments can be connected by stock visual matches.
2. B contains the full sequence in one optimized model.
3. B's endpoint gap is small relative to its full path length.

It does not establish that geometry is fully stable:

- literal first/last views do not match, so closure is indirect;
- reprojection error is higher;
- the maximum adjacent camera step is larger;
- PCA-projected camera-trajectory segment crossings increased, although this proxy is not directly comparable between a 204-frame fragment and a 509-frame full loop;
- no human visual inspection or ground-truth object geometry measurement was performed in this audit.

Therefore the result supports loop closure as part of the remedy, but not exhaustive matching as the final fix and not a claim that self-intersection is solved.

## Classification

```text
LOOP_CLOSURE_RESULT: PARTIAL_SUPPORT
```

Rationale:

- not `SUPPORTED`: geometry-quality and self-intersection acceptance are not met;
- not `NO_IMPROVEMENT`: component count, temporal coverage, non-local edges and normalized endpoint gap improve materially;
- not `INCONCLUSIVE`: a real set of verified non-local edges was produced and its connectivity effect is measurable;
- `PARTIAL_SUPPORT`: connectivity/closure improves, while geometry quality remains mixed.

## Recommended next step

```text
RECOMMENDED_NEXT_STEP: investigate another visual failure mode
```

Specifically, do not promote exhaustive matching. Derive a narrow non-local pair policy from B evidence (for example retrieval or selected long-gap pairs connecting A component boundaries), rerun sparse-only, and require all of:

- one component with both temporal endpoints;
- normalized endpoint gap no worse than B;
- no reprojection regression versus A;
- bounded maximum trajectory step;
- visual review of exported trajectory/PLY for spiral/self-intersection.

Only after that targeted visual experiment should the project decide whether visual closure is sufficient or proceed to the IMU orientation/gravity experiment.

## Repository impact

Only this Markdown report was added to the repository. No Android, PHP/backend, processing, capture, database, or existing documentation file was modified. No patch was applied to production COLMAP scripts.
