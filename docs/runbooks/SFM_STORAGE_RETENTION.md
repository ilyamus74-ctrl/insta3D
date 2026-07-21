# SfM storage retention

- **SOURCE** — keep original source video.
- **TRANSIENT REMOTE** — remove through the existing GrafikStation cleanup flow.
- **TRANSIENT LOCAL** — after successful station cleanup, remove only `job_<remote_job_id>/normalized/source_safe.mp4` for `EXTRACT_FRAMES` jobs.
- **DERIVED/FINAL** — keep pipeline results, PLY, frames needed by the established artifact contract, and logs.
- **AUTO PHOTO photos cache** — keep for now.

The local cleanup never removes order storage, an entire job directory, `result.json`, frames, sparse/dense/mesh data, PLY, logs, or archives.
