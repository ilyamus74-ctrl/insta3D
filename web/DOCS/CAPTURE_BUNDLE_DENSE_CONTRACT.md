# Capture bundle synced dense contract

MaklerTour Android uploads capture bundle `.tgz` archives with `mobile.php?action=upload_capture_bundle`. The web server stores them under `storage/orders/<order_id>/sessions/<session_uuid>/capture_bundles/` and records metadata in `capture_bundles`.

## Dense job

* Job type: `MAKLERTOUR_SYNCED_DENSE` in the existing `sfm_remote_jobs` table.
* Processing runs on GrafikStation through `web/remote_station/run_maklertour_synced_dense_job.sh` and `scripts/process_maklertour_synced_dense.sh`.
* Android and the web server do not run dense computation locally; the web server only queues and transfers the bundle.

## Expected `.tgz` structure

The unpacked package must contain:

```text
bundle_manifest.json
capture/synced_depth_manifest.json
capture/pairs/
calibration/stereo_extrinsics.json
```

Only `capture_type=synced_depth_frames` can start synced dense processing. `stereo_video_legacy` bundles may be displayed for audit/download but dense processing is disabled.

## Parameters

`parameters_json` in `sfm_remote_jobs` includes:

```json
{
  "capture_bundle_id": 123,
  "capture_type": "synced_depth_frames",
  "max_pairs": 40,
  "num_disparities": 128,
  "block_size": 7
}
```

## Output artifacts

GrafikStation writes artifacts under `$STATION_BASE/output/job_<remote_job_id>/dense/` and the worker fetches them to `/home/makler/web/remote_station/output/job_<remote_job_id>/`:

* `dense/contact_dense_depth.jpg` — browser preview.
* `dense/dense_depth_debug.json` — debug metadata.
* `dense/dense_depth_summary.csv` — pair/summary table.
* `result.json` — job result contract.