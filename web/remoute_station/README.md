# remote_station SSH control scripts

`web/remote_station` contains the web-server-side scripts used to control a GPU station over SSH through WireGuard.

The station does **not** poll the API. The web server copies input files to the station, starts processing over SSH, reads `status.json`, and fetches output files back.

## Files

```text
web/remote_station/
├── README.md
├── stations.conf.example
├── install_station.sh
├── deploy_station.sh
├── run_extract_frames_job.sh
├── get_station_status.sh
├── fetch_job_result.sh
└── scripts/
    └── process_extract_frames.sh
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

The installer checks SSH access, creates this remote directory layout, uploads `scripts/process_extract_frames.sh`, marks it executable, and checks `ffmpeg`, `ffprobe`, `nvidia-smi`, plus optional `colmap`:

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

## Run a test extract-frames job

```bash
./run_extract_frames_job.sh ./stations.conf test001 /path/to/video.mp4
```

The runner copies the video to:

```text
$STATION_BASE/incoming/job_test001_video.mp4
```

Then it starts the remote job with `nohup`:

```text
$STATION_BASE/scripts/process_extract_frames.sh test001 <remote_input> $STATION_BASE/output/job_test001/frames
```

Frames are written as `frame_000001.jpg`, `frame_000002.jpg`, etc.

## Check status

```bash
./get_station_status.sh ./stations.conf test001
```

The command prints `$STATION_BASE/status/job_test001.json`. If it is not present yet, it returns:

```json
{"status":"UNKNOWN"}
```

Statuses include `RUNNING`, `DONE`, and `ERROR`, with `progress_percent` and `eta_sec` while the job is running.

## Fetch result

```bash
./fetch_job_result.sh ./stations.conf test001 ./results
```

This creates `./results/job_test001/` and fetches:

- `$STATION_BASE/output/job_test001/`
- `$STATION_BASE/status/job_test001.json`
- `$STATION_BASE/logs/job_test001.log`
- `$STATION_BASE/logs/job_test001.nohup.log`

## Notes

No systemd worker, timer, or API polling worker is installed at this stage. Job orchestration is intentionally SSH-controlled by the web server.
