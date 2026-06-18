# remote_station SSH control scripts

`web/remote_station` contains the web-server-side scripts used to control a GPU station over SSH through WireGuard.

The station does **not** poll the API. The web server copies or references input data on the station, starts processing over SSH, reads `status.json`, and fetches output files back.

## Files

```text
web/remote_station/
├── README.md
├── stations.conf.example
├── install_station.sh
├── deploy_station.sh
├── run_extract_frames_job.sh
├── run_colmap_sparse_job.sh
├── get_station_status.sh
├── fetch_job_result.sh
└── scripts/
    ├── process_extract_frames.sh
    └── process_colmap_sparse.sh
```

## Station config

Create a local config from the example:

```bash
cd web/remote_station
cp stations.conf.example stations.conf
```

Edit `stations.conf`:

```bash
STATION_NAME="grafikstation01"
STATION_HOST="10.77.0.2"
STATION_USER="root"
STATION_SSH_KEY="/root/.ssh/makler_grafikstation_ed25519"
STATION_BASE="/home/makler_storage"
```

`STATION_HOST` should be the WireGuard/private address or DNS name reachable from the web server.

## Install station

Run once from the web server:

```bash
./install_station.sh ./stations.conf
```

The installer checks SSH access, creates this remote directory layout, uploads all `scripts/*.sh`, marks them executable, and checks `ffmpeg`, `ffprobe`, `nvidia-smi`, plus optional `colmap`. Missing `colmap` prints a warning but does not fail installation.

```text
/home/makler_storage/
├── incoming/
├── work/
├── output/
├── logs/
├── status/
└── scripts/
```

## Deploy script updates

After changing local scripts, deploy them to the station:

```bash
./deploy_station.sh ./stations.conf
```

This copies `scripts/*.sh` into `$STATION_BASE/scripts/` and runs `chmod +x` remotely.

## Extract frames test

Start an extract-frames job from a local video file:

```bash
./run_extract_frames_job.sh ./stations.conf 1 /path/to/video.mp4
watch -n 2 './get_station_status.sh ./stations.conf 1'
./fetch_job_result.sh ./stations.conf 1 ./output
```

The runner copies the video to `$STATION_BASE/incoming/`, starts `$STATION_BASE/scripts/process_extract_frames.sh` with `nohup`, and writes frames to `$STATION_BASE/output/job_1/frames` as `frame_000001.jpg`, `frame_000002.jpg`, etc.

## COLMAP sparse reconstruction test

After frames are extracted, start sparse reconstruction against the remote frames directory:

```bash
./run_colmap_sparse_job.sh ./stations.conf 2 /home/makler_storage/output/job_1/frames
watch -n 2 './get_station_status.sh ./stations.conf 2'
./fetch_job_result.sh ./stations.conf 2 ./output
```

The runner verifies the remote frames directory exists, then starts `$STATION_BASE/scripts/process_colmap_sparse.sh` with `nohup`. COLMAP output is written to `$STATION_BASE/output/job_2/colmap`:

```text
/home/makler_storage/output/job_2/colmap/
├── database.db
├── sparse/
├── logs/
└── result.json
```

During COLMAP feature extraction and matching, check GPU activity on the station with:

```bash
watch -n 1 nvidia-smi
```

## Check status

```bash
./get_station_status.sh ./stations.conf 1
```

The command prints `$STATION_BASE/status/job_<job_id>.json`. If it is not present yet, it returns:

```json
{"status":"UNKNOWN"}
```

Statuses include `RUNNING`, `DONE`, and `ERROR`, with `progress_percent` and `eta_sec` while the job is running. COLMAP stages currently use `eta_sec: -1` because exact ETA is not estimated yet.

## Fetch result

```bash
./fetch_job_result.sh ./stations.conf 1 ./output
```

This creates `./output/job_<job_id>/` and fetches:

- `$STATION_BASE/output/job_<job_id>/`
- `$STATION_BASE/status/job_<job_id>.json`
- `$STATION_BASE/logs/job_<job_id>.log`
- `$STATION_BASE/logs/job_<job_id>.nohup.log`

## Notes

No systemd worker, timer, or API polling worker is installed at this stage. Job orchestration is intentionally SSH-controlled by the web server.
