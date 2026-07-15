# AUTO-000R-RUNTIME-BUNDLE-RESULT

Дата: 2026-07-15. Режим: production read-only inspection. Source code, database, storage, services and processing state were not modified. Created only this result file.

## Environment
Repository path: `/workspace/insta3D`  
Branch: `work`  
Commit: `cc419caca7025b6f41ff0962d685c811dc23be55`  
Storage root: `/workspace/insta3D/web/storage` from current checkout `APP_STORAGE_DIR`; expected deployment path from task (`/home/makler/web`) was not present as the active cwd in this container.

## Database row
Source: repository SQL full dump `web/MySqlDump/maklertour_full_20260715_163926.sql.gz`, because live MySQL was unreachable from this environment. The requested live SELECT could not be executed successfully.

Bundle ID: `7`  
Order ID: `30`  
Capture session ID: `63`  
App bundle UUID: `b8b55de2-87ec-4665-912b-b1ee906e9569`  
Capture type: `auto_photo_session`  
Status: `UPLOADED`  
Filename: `b8b55de2-87ec-4665-912b-b1ee906e9569_maklertour_capture_bundle_auto_photo_session_b8b55de2-87ec-4665-912b-b1ee906e9569.tgz`  
Storage path: `orders/30/sessions/031321af-f41d-46c1-842f-db5f0c0b27e0_30/capture_bundles/b8b55de2-87ec-4665-912b-b1ee906e9569_maklertour_capture_bundle_auto_photo_session_b8b55de2-87ec-4665-912b-b1ee906e9569.tgz`  
DB size: `572818552`  
Created: `2026-07-14 10:25:24.828251`  
Updated: `2026-07-14 10:25:24.828251`

## Filesystem
Resolved path: `/workspace/insta3D/web/storage/orders/30/sessions/031321af-f41d-46c1-842f-db5f0c0b27e0_30/capture_bundles/b8b55de2-87ec-4665-912b-b1ee906e9569_maklertour_capture_bundle_auto_photo_session_b8b55de2-87ec-4665-912b-b1ee906e9569.tgz`  
Regular file: `UNKNOWN` / not found in this checkout storage  
Symlink: `UNKNOWN` / not found  
Filesystem size: `UNKNOWN` / not found  
SHA-256: `UNKNOWN` / not found  
Android reported size: `572818552`

Warnings:

- Live DB connection failed in this container (`localhost` socket: no such file; `127.0.0.1:3306`: connection refused).
- The DB row was recovered from the repository full dump, not a live production SELECT.
- The filesystem path was resolved only from that recovered DB row and current checkout storage root.
- The TGZ file was not present under current checkout storage, so archive and JPEG inspection could not be completed.

## Archive safety
Member count: `UNKNOWN`  
Regular files: `UNKNOWN`  
Directories: `UNKNOWN`  
Links: `UNKNOWN`  
Devices: `UNKNOWN`  
Traversal entries: `UNKNOWN`  
Declared unpacked bytes: `UNKNOWN`  
Largest member: `UNKNOWN`  
Unexpected entries: `UNKNOWN`

## Bundle manifests
Bundle manifest: `UNKNOWN`  
Capture manifest: `UNKNOWN`  
Camera info: `UNKNOWN`  
Metadata: `UNKNOWN`  
IMU: `UNKNOWN`  
Quality: `UNKNOWN`  
Events: `UNKNOWN`

## Counts
Bundle manifest photos: `UNKNOWN`  
Capture manifest photos: `UNKNOWN`  
Manifest photo objects: `UNKNOWN`  
Actual JPEG: `UNKNOWN`  
Metadata records: `UNKNOWN`  
IMU records: `UNKNOWN`  
Total JPEG bytes: `UNKNOWN`

## JPEG properties
First sequence: `UNKNOWN`  
Last sequence: `UNKNOWN`  
Sequence gaps: `UNKNOWN`  
Duplicate sequences: `UNKNOWN`  
Duplicate filenames: `UNKNOWN`  
Manifest file missing in TGZ: `UNKNOWN`  
TGZ JPEG missing in manifest: `UNKNOWN`  
Zero-size JPEG: `UNKNOWN`  
Dimensions: `UNKNOWN`  
Valid JPEG headers: `UNKNOWN`

## Metadata and IMU
Metadata record count: `UNKNOWN`  
Metadata JSON parse errors: `UNKNOWN`  
Duplicate photo UUID: `UNKNOWN`  
Duplicate metadata sequence: `UNKNOWN`  
Missing filename: `UNKNOWN`  
Filename without JPEG: `UNKNOWN`  
Physical orientation distribution: `UNKNOWN`  
Missing orientation count: `UNKNOWN`  
Sharpness availability: `UNKNOWN`  
Angular velocity availability: `UNKNOWN`  
IMU line count: `UNKNOWN`  
IMU JSON parse errors: `UNKNOWN`  
IMU first timestamp: `UNKNOWN`  
IMU last timestamp: `UNKNOWN`  
IMU sensor fields: `UNKNOWN`

## Preliminary validation status
Status: `INVALID_FOR_RUNTIME_INSPECTION` / task result `PARTIAL`.

Reason: the DB identity and expected row values were recovered from a SQL dump and match the expected `capture_type=auto_photo_session` and `status=UPLOADED`, but live DB verification and filesystem/archive verification could not be performed in this environment. Since the TGZ was not available at the DB-derived path, JPEG count, metadata count, IMU count, sequence, dimensions, SHA-256, archive safety, and manifest consistency remain unverified.

## Commands and exit codes

| Command | Exit code | Notes |
|---|---:|---|
| `pwd` | 0 | Returned `/workspace/insta3D`. |
| `git branch --show-current` | 0 | Returned `work`. |
| `git rev-parse HEAD` | 0 | Returned `cc419caca7025b6f41ff0962d685c811dc23be55`. |
| `git status --short` | 0 | Pre-existing untracked `app/MaklerTour/.gradle/`; this result file added later. |
| `php /tmp/auto000r_inspect.php` | 255 | Live DB via configured `localhost` failed: socket unavailable. |
| `php /tmp/auto000r_inspect.php` | 255 | Live DB via `127.0.0.1:3306` failed: connection refused. |
| `zgrep -n "b8b55de2-87ec-4665-912b-b1ee906e9569" web/MySqlDump/maklertour_full_20260715_163926.sql.gz \| head -20` | 0 | Found audit-log entry and `capture_bundles` row in dump. |
| `stat -c '%n\|%F\|%s\|%N' <DB-derived path>` | not run after existence guard | Guard reported file missing before stat. |
| `sha256sum <DB-derived path>` | not run after existence guard | Guard reported file missing before hash. |
| `realpath web/storage/orders` | 0 | Returned `/workspace/insta3D/web/storage/orders`. |

## Changed files

- `docs/llm/tasks/results/AUTO-000R-RUNTIME-BUNDLE-RESULT.md`

## Final outcome

Bundle ID: `7`  
Actual JPEG count: `UNKNOWN`  
Validation status: `INVALID_FOR_RUNTIME_INSPECTION`  
Result file: `docs/llm/tasks/results/AUTO-000R-RUNTIME-BUNDLE-RESULT.md`  
Outcome: `PARTIAL`
