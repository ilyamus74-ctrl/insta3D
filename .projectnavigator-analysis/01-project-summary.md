# Project summary

## Analysis boundary

Read-only archaeology performed against working tree at `49ec13d26511de3d7c51e8e349c4295395ca189b` (`main`). Existing dirty and untracked files were treated as evidence but not as committed baseline. Generated/build/vendor/backup files were not treated as current implementation unless needed to identify repository hygiene risk. No runtime, device, database, deployment or remote-station mutation was performed.

## A. Project goal

The project is building a real-estate capture and processing platform that takes operator capture data through Android, server storage/job orchestration and GPU reconstruction to browsable spatial deliverables. The most specific current target is a metrically scaled, globally consistent, textureable room model, while retaining the already-working 360 tour/order workflow.

```text
operator capture (Insta360 / phone / stereo / optional IMU+ToF)
  -> authenticated order/session storage and durable upload
  -> GrafikStation sparse/dense reconstruction
  -> metric alignment, component assembly and mesh/textures
  -> private/public tour, map and 3D viewer
```

Evidence:

- `docs/llm/01_REQUIREMENTS.md`: explicit three-contour system and user roles.
- `docs/llm/02_ARCHITECTURE.md`: Android -> backend -> GrafikStation -> viewers.
- `ROADMAP.md`: Matterport-like map, reconstruction, dense model and photorealistic long-term outcomes.
- `docs/CAPTURE_ARCHITECTURE_RECOVERY_PLAN.md`: stable metric room reconstruction and SINGLE-first strategy.
- `docs/llm/tasks/APP-DUAL-PHONE-STEREO-ROADMAP.md`: optimized metric geometry, mesh and original-frame textures.
- Executable Android capture, PHP upload/job, remote COLMAP/dense scripts and viewers confirm this is more than a requirements-only repository.

## Confirmed architectural decisions

1. Three execution contours: Android capture, web/backend ownership, GrafikStation processing.
2. Server owns order/session/job state and resolves storage paths; station creates artifacts but is not business-state authority.
3. `SINGLE` is the reference reconstruction path before DUAL_PHONE, USB_RIG and LAPTOP unification.
4. Canonical Auto Photo capture type is `auto_photo_session`; it reuses the existing upload queue.
5. Video SfM and calibrated stereo are separate branches. Video uses COLMAP components/assembly; stereo uses calibrated metric pair depth and visual odometry.
6. IMU and ToF are optional: RGB reconstruction must continue without them. Current ToF geometry mutation and fusion are deliberately off.
7. Production sparse matching defaults to sequential overlap 60; exhaustive is diagnostic/manual.
8. Evidence status vocabulary is PASS/PARTIAL/FAIL/NOT_RUN; source implementation is not runtime acceptance.

## Repository character

This is an evolved monorepo rather than a clean greenfield architecture: Android Kotlin/C++, legacy and current PHP, station Bash/Python/C++, firmware C, extensive task contracts, generated artifacts and historical copies coexist. Git history is available but most implementation commits use the non-descriptive message `sync local -> remote`, so file history and task/result documents are more useful than commit subjects for intent recovery.

## High-level logical chain

```text
Tour/order MVP [done]
  -> durable capture/upload contracts [done, multiple paths]
  -> server job orchestration [done/in production-like use]
  -> SINGLE sparse+dense baseline [done, quality incomplete]
  -> visual connectivity/drift diagnosis [in progress]
  -> active IMU/ToF constraints [planned]
  -> stable metric SINGLE milestone [planned]
  -> capture topology unification + stereo runtime gates [in progress]
  -> global metric geometry [planned]
  -> mesh/textures/dollhouse deliverable [backlog]
```

## Confidence

Overall goal confidence: **0.96**. Exact product acceptance and which capture topology should ship first require human confirmation.
