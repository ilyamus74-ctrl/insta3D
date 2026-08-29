# IMU / ToF end-to-end matrix

Legend: **YES** is evidenced for that stage; **PARTIAL** means mode/coverage limitations; **NO** means the required behavior is absent; **NOT_VERIFIED** means code or intent exists without sufficient runtime evidence. “Consumed” is distinct from “active constraint.”

## IMU

| User tile / branch | Captured | Persisted | Uploaded | Server accepted | Processing consumed | Active geometric prior | Verified by result |
|---|---|---|---|---|---|---|---|
| SINGLE `da6e...` | YES | YES | YES | YES | YES, frame selection/diagnostics | **NO** | NO metric/trajectory improvement evidence |
| Automatic Photo `2498...` | PARTIAL snapshots/metadata | PARTIAL | PARTIAL | PARTIAL | NOT_VERIFIED | **NO** | NO |
| MASTER+SLAVE `7237...` | YES per phone | YES | aggregate path PARTIAL | **NO** for aggregate type | NO accepted aggregate run | **NO** | NO |
| 2 phones+PC `a94d...` | YES on live path | live packets, not canonical durable bundle | reaches notebook, not normal server upload | NO common server acceptance | host diagnostics/transport only | **NO** | NO |
| Android+USB `4c7e...` | phone IMU available | PARTIAL | NOT_VERIFIED | NOT_VERIFIED | stereo tooling focus, active prior not evidenced | **NO** | NO |
| Insta360 `19df...` | NOT_VERIFIED as camera telemetry | NO canonical evidence | NO | NO | NO | NO | NO; outside scoped tour DONE |

Confirmed SINGLE detail: `SINGLE_IMU_PARTICIPATION_AUDIT.md` finds that IMU affects auto-quality frame choice and post-COLMAP diagnostics, but not mapper/bundle adjustment; gravity alignment is effectively identity because the transform is not implemented. Archaeology issues `imu-not-active-prior` and `sensor-sidecar-gaps` map across the relevant APP, server and worker tiles.

## ToF

| User tile / branch | Captured | Persisted | Uploaded | Server accepted | Processing consumed | Active metric constraint | Verified by result |
|---|---|---|---|---|---|---|---|
| SINGLE `da6e...` | YES | YES | YES | YES | YES, association/metric diagnostics | **NO** | NO scale/geometry mutation evidence |
| Automatic Photo `2498...` | NO complete path | NO complete path | NO complete path | NO complete path | NO | **NO** | NO |
| MASTER+SLAVE `7237...` | **NO** required sidecars | NO | NO | NO | NO | NO | NO |
| 2 phones+PC `a94d...` | YES on live path | live packets, not canonical durable bundle | reaches notebook only | NO common server acceptance | transport/diagnostic only | **NO** | NO |
| Android+USB `4c7e...` | sensor may be available | **NO saved USB-mode sidecar evidence** | NO | NO | NO | NO | NO |
| Insta360 `19df...` | NO repository evidence | NO | NO | NO | NO | NO | NO; outside scoped tour DONE |

Confirmed SINGLE detail: `SINGLE_TOF_PARTICIPATION_AUDIT.md` finds capture, persistence, upload, acceptance, association and diagnostics, but no pose, BA, sparse/dense mutation, fusion or applied scale. Archaeology `tof-imu-measurement` is DONE only for measurement/diagnostic participation; `single-sensor-constraints` remains planned. The issue `tof-measurement-only` is not closed by successful upload.

## Server and worker accountability

- Server tile `c7ba...` must preserve telemetry, validate units/time/frame associations and record whether processing used it.
- Worker tile `fa24...` must distinguish parsing/diagnostics from active optimization and emit A/B evidence.
- Metric tile `0b06...` cannot pass on “IMU/ToF file present”; it needs geometry/scale improvement against ground truth.
