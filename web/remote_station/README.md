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
INSTALL_PACKAGES="1"
COLMAP_MODE="podman"
COLMAP_BIN="colmap"
COLMAP_IMAGE="docker.io/colmap/colmap:latest"
COLMAP_MATCHER="sequential"
COLMAP_SEQUENTIAL_OVERLAP="60"
REQUIRE_COLMAP="1"
```

`STATION_HOST` should be the WireGuard/private address or DNS name reachable from the web server.

## Install station

Run once from the web server:

```bash
./install_station.sh ./stations.conf
```

The installer keeps the current SSH-control architecture: it connects from the web server to the station over SSH, checks `hostname && whoami`, detects the remote OS from `/etc/os-release`, optionally installs supported packages, creates the remote directory layout, uploads all `scripts/*.sh`, marks them executable, and runs a final health check. It does not install a systemd service, API polling worker, or anything on Android.

```text
/home/makler_storage/
├── incoming/
├── work/
├── output/
├── logs/
├── status/
└── scripts/
```

## Install dependencies

`install_station.sh` supports Fedora, Debian, and Ubuntu stations. Configure dependency behavior in `stations.conf`:

```bash
INSTALL_PACKAGES="1"
REQUIRE_COLMAP="1"
```

`INSTALL_PACKAGES` controls whether the installer attempts package installation on the remote station:

- `INSTALL_PACKAGES="1"` runs the platform package manager. Fedora uses `dnf`; Debian and Ubuntu use `apt-get update` followed by `apt-get install`.
- `INSTALL_PACKAGES="0"` skips package installation and only checks already installed tools.

`COLMAP_MODE` controls how sparse reconstruction runs COLMAP:

- `COLMAP_MODE="podman"` runs photogrammetry COLMAP from `COLMAP_IMAGE` with GPU access through Podman.
- `COLMAP_MODE="native"` runs the local binary configured by `COLMAP_BIN`.

`COLMAP_MATCHER` controls the feature matching strategy for sparse reconstruction:

- `COLMAP_MATCHER="sequential"` (default for MaklerTour video scans) uses COLMAP `sequential_matcher`, which only compares nearby frames instead of every possible pair. Tune the neighborhood with `COLMAP_SEQUENTIAL_OVERLAP="60"`.
- `COLMAP_MATCHER="exhaustive"` uses COLMAP `exhaustive_matcher`. Keep it as a manual mode for small, unordered photo sets only; it is usually too slow for video scans because it compares all image pairs.

`REQUIRE_COLMAP` controls whether COLMAP is mandatory:

- `REQUIRE_COLMAP="1"` fails `install_station.sh` if the configured COLMAP mode is unavailable after installation/checks.
- `REQUIRE_COLMAP="0"` prints a warning if the configured COLMAP mode is unavailable and continues.

Package behavior by OS:

- Fedora: installs/checks `ffmpeg` (including `ffprobe`), `rsync`, `python3`, and `colmap` via `dnf`. If `colmap` is not available in enabled repositories, the installer prints: `COLMAP package not found via dnf. Install COLMAP manually or enable required repository.`
- Debian/Ubuntu: runs `apt-get update` and installs `ffmpeg` (including `ffprobe`), `colmap`, `rsync`, and `python3`.

NVIDIA driver/CUDA are **not** installed automatically by this script. The NVIDIA driver must be installed on the station ahead of time and is checked with `nvidia-smi`. If `nvidia-smi` is missing, installation fails with an error.

Typical setup/deploy sequence:

```bash
./install_station.sh ./stations.conf
./deploy_station.sh ./stations.conf
chmod +x /home/makler/web/remote_station/*.sh
chmod +x /home/makler/web/remote_station/scripts/*.sh
chmod +x /home/makler/web/remote_station/run_colmap_dense_job.sh
chmod +x /home/makler/web/remote_station/scripts/process_colmap_dense.sh
/home/makler/web/remote_station/get_station_metrics.sh /home/makler/web/remote_station/stations.conf
```

The `chmod` commands are required after every deploy on the web host so GrafikStation metrics and worker endpoints can execute newly added scripts.


## COLMAP via Podman

Use Podman on GrafikStation when the host OS does not provide photogrammetry COLMAP as `/usr/bin/colmap`. The verified image is:

```text
docker.io/colmap/colmap:latest
```

Recommended `stations.conf` settings:

```bash
COLMAP_MODE="podman"
COLMAP_BIN="colmap"
COLMAP_IMAGE="docker.io/colmap/colmap:latest"
COLMAP_MATCHER="sequential"
COLMAP_SEQUENTIAL_OVERLAP="60"
REQUIRE_COLMAP="1"
```

Manual verification commands on the station:

```bash
podman pull docker.io/colmap/colmap:latest

podman run --rm \
  --device nvidia.com/gpu=all \
  --security-opt=label=disable \
  -v /home/makler_storage:/home/makler_storage \
  docker.io/colmap/colmap:latest \
  colmap help
```

On Fedora, `/usr/bin/colmap` can belong to the `geomorph` package. That is not photogrammetry COLMAP. Check it with:

```bash
rpm -qf /usr/bin/colmap
```

If it shows `geomorph`, remove it before using native COLMAP:

```bash
dnf remove -y geomorph
```

## Deploy script updates

After changing local scripts, deploy them to the station:

```bash
./deploy_station.sh ./stations.conf
```

This copies `scripts/*.sh` into `$STATION_BASE/scripts/` and runs `chmod +x` remotely. After repository/web deploys, also refresh executable bits on the web host and verify station metrics:

```bash
chmod +x /home/makler/web/remote_station/*.sh
chmod +x /home/makler/web/remote_station/scripts/*.sh
/home/makler/web/remote_station/get_station_metrics.sh /home/makler/web/remote_station/stations.conf
```

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

By default, video scan frames are matched with `sequential_matcher` and `COLMAP_SEQUENTIAL_OVERLAP="60"`. Use `COLMAP_MATCHER="exhaustive"` only for small, unordered photo sets where comparing every image pair is acceptable.

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

## COLMAP sparse vs dense pipeline

The remote station supports two COLMAP reconstruction stages:

- **COLMAP_SPARSE** builds the sparse SfM model from extracted frames. It produces the camera model, sparse points, and per-model folders under `output/job_<id>/colmap/sparse/<model_id>`.
- **COLMAP_DENSE** uses an existing sparse model and the original frames to produce a denser fused point cloud at `output/job_<dense_id>/dense/fused.ply`.

Dense reconstruction is intentionally heavier than sparse reconstruction. It runs:

1. `image_undistorter`
2. `patch_match_stereo`
3. `stereo_fusion`

The dense launcher is:

```bash
./run_colmap_dense_job.sh ./stations.conf <dense_job_id> <sparse_job_id> <model_id>
```

The station script reads `frames_dir` from the sparse job's `colmap/result.json`, validates the sparse model contains `cameras.bin`, `images.bin`, and `points3D.bin`, then writes logs to:

- `dense/logs/image_undistorter.log`
- `dense/logs/patch_match_stereo.log`
- `dense/logs/stereo_fusion.log`

### Hardware and runtime guidance

Dense COLMAP is GPU/RAM intensive. Recommended baseline:

- NVIDIA GPU with CUDA support and at least 8 GB VRAM for small/medium captures.
- 16-32 GB system RAM minimum; 64 GB is safer for larger frame sets.
- Fast local SSD storage, because PatchMatch and fusion create large intermediate workspaces.

Expected runtime depends heavily on frame count, image resolution, overlap, and GPU speed. Small captures may finish in tens of minutes; larger room/property scans can take hours. Keep dense reconstruction manual by default until station capacity and quality settings are tuned.
