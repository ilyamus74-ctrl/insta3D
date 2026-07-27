# SFM AprilTag partial dense recovery

Status: IMPLEMENTED

## Problem

A Video SfM run can successfully complete AprilTag metric alignment and still
lose one dense component because COLMAP PatchMatch crashes in a large chunk.
The observed reference case is pipeline run `71`:

- AprilTag detections: `348`;
- usable observations: `317`;
- metric status: `METRIC_PARTIALLY_ALIGNED`;
- Sim3 applied: `true`;
- sparse models: `9 -> 2`;
- large model: `464` registered images;
- small model: `119` registered images;
- large-model dense chunk failed with exit `139` after the first 75% retry;
- small-model dense and mesh completed.

The remote cleanup worker may already have removed the GrafikStation sparse
directory, while the web worker still has the fetched sparse cache under:

```text
web/remote_station/output/job_<sparse_remote_job_id>/colmap/sparse/
```

Repeating video extraction and sparse reconstruction is unnecessary.

## Recovery command

```bash
php web/tools/sfm_recover_partial_dense.php \
  --pipeline-run-id=71 \
  --model-id=0
```

Default recovery profile:

```text
target_images_per_chunk = 12
max_images_per_chunk    = 16
overlap_images          = 4
num_src_images          = 4
ram_reserve_mb          = 6000
max_image_size          = at most 640
PatchMatch cache        = 1
fusion cache            = 1
```

The command:

1. Locates the sparse remote job from `auto_components` or the job table.
2. Validates the locally cached BIN or TXT model.
3. Preserves `apriltag_assist.json` under `pipeline_<id>`.
4. Resolves `frames_dir` from the sparse result and restores the cached source
   frame job to GrafikStation.
5. Restores the cached sparse job to GrafikStation using rsync.
6. Creates a new dense parent job for only the requested model.
7. Reopens the pipeline as `RUNNING / DENSE_PLAN`.
8. Removes the old cleanup schedule so restored data is not immediately
   deleted.
9. Keeps the already completed component and its artifacts intact.

Use `--dry-run` before changing the database:

```bash
php web/tools/sfm_recover_partial_dense.php \
  --pipeline-run-id=71 \
  --model-id=0 \
  --dry-run
```

## Expected continuation

```text
restore sparse job
-> queue safe dense parent for model 0
-> plan smaller chunks
-> PatchMatch/fusion for model 0
-> dense merge for model 0
-> automatic two-component aligned merge
-> mesh for recovered model
-> pipeline DONE
```

The existing successful model-1 dense and mesh are not rerun.

## Persistent AprilTag report

Sparse integration now copies:

```text
job_<sparse_job>/colmap/apriltag_assist.json
```

to:

```text
pipeline_<pipeline_run_id>/apriltag_assist.json
```

The compact summary is also stored in `sfm_pipeline_runs.parameters_json`.
This keeps alignment groups and failures available after remote cleanup.

## Boundaries

This recovery does not change the AprilTag transform already applied to the
sparse models. It only resumes dense processing from the fetched metric sparse
cache. A missing local sparse cache cannot be reconstructed by this command;
that case requires a new sparse run from source frames or source video.
