# Cross-cutting capture/processing contract

This is a project-level artifact, not a new permanent product WorkItem. Contract-unification work remains active and should be owned jointly by:

- Android foundation `4dcc4e53-cc41-4b0a-bb3a-ca2e47cca4d3` for emission/persistence/upload;
- Server orchestration `c7ba832d-1307-475d-b543-59ac5c17ea6c` for validation/storage/routing;
- GrafikStation worker `fa24df7a-87d4-4606-b4c8-3ce87eed24a1` for declared consumption/results.

Archaeology `capture-topology-unification` therefore maps to these existing tiles and to this document, not to a new top-level tile.

## Canonical envelope

| Area | Required fields/behavior | Current evidence | Gap |
|---|---|---|---|
| Identity | contract version, order, capture/session ID, device/source IDs | IDs exist across modes | laptop-live creates independent per-phone sessions; envelope differs |
| Mode | canonical capture mode and compatible server routing | multiple `capture_type` values | dual aggregate value is rejected by PHP |
| Camera roles | MASTER/SLAVE, PHONE/USB, panorama role; stable stream IDs | role concepts exist in dual/USB | not uniform across every manifest and processing job |
| Time | capture timestamps, clock domain, offset/drift samples, pairing tolerance | BLE clock samples and stereo timestamps exist | no common semantics/quality gate |
| Calibration | intrinsics, distortion, extrinsics, version/source | USB/stereo calibration artifacts exist | transport/consumption not uniform; other branches incomplete |
| Media | images/videos, frame index, camera/role link, encoding/dimensions | mode-specific media manifests | no single schema/fixture suite |
| IMU | samples, axes/units/frame, monotonic time, coverage | SINGLE/dual/live paths capture variants | downstream use is diagnostic, not an active prior |
| ToF | depth/distance, units, confidence/validity, ray/frame association | SINGLE/live variants carry measurements | dual/USB/Auto Photo sidecars incomplete; no active constraint |
| Integrity | size, checksum, required/optional classification | upload/storage checks exist selectively | canonical per-artifact checks and rejection reasons incomplete |
| Upload lifecycle | queued/uploading/retry/accepted/rejected, idempotency | services and endpoints exist | behavior varies by mode; dual acceptance breaks |
| Server acceptance | schema version, supported mode, artifact validation, durable association | ordinary and Auto Photo endpoints work | inconsistent whitelist/contracts |
| Processing expectations | requested products, required inputs, telemetry policy, algorithm version | job types/runners exist | package does not uniformly declare “ignored/diagnostic/active” telemetry |
| Result | artifact manifest, validation, metrics, provenance, consumption report | job finish/result records and audits exist | global metric acceptance schema incomplete |

## Closure criteria

1. Versioned JSON fixtures cover one valid and representative invalid package for every active capture mode.
2. Android emitters and PHP validators agree on mode names, required artifacts and optional telemetry.
3. The server stores role/time/calibration/telemetry without flattening or silent loss.
4. Each processing result declares for IMU and ToF: absent, rejected, diagnostic-only or active constraint.
5. Contract compatibility tests run without requiring a camera or graphics station.
6. Runtime audits then prove each physical branch separately; schema success is not substituted for physical acceptance.

Existing `web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md` is relevant evidence, but the architecture audit proves that documentation is not yet a uniformly enforced contract.
