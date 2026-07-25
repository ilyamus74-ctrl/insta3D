# APP-STEREO-RUNTIME-A — Station Deploy Gate

## Status

```text
IMPLEMENTED
RUNTIME DEPLOYMENT PENDING
```

## Goal

Prevent a successful-looking GrafikStation deploy from omitting or breaking
F01 stereo runtime scripts.

## Checks

Deployment requires the dense-depth, visual-odometry, global-fusion, and
synced-dense orchestration scripts. The station venv compiles all Python stereo
stages, and the shell pipeline must reference odometry and global fusion.

## Test

```bash
php web/tests/stereo_station_deploy_gate_test.php
```

## Runtime sequence

```bash
cd /home/makler/web/remote_station
./deploy_station.sh ./stations.conf
```

Then submit a new `MAKLERTOUR_SYNCED_DENSE` bundle.
