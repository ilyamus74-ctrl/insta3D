# Unknowns / needs human confirmation

1. Which outcome is the first shippable product: panorama tour, Auto Photo model, SINGLE video mesh, USB stereo model or dual-phone model?
2. What quantitative thresholds define “stable metric 3D reconstruction” (scale error, drift, completeness, reprojection error, mesh quality)?
3. Which repository working-tree files are authoritative? Many important audits/experiments and source backups are untracked; two tracked files and the COLMAP submodule are dirty.
4. Which commit/version is actually deployed on Android, web server and GrafikStation? Task documents cite several older baselines.
5. What is the current production/test DB schema? Repository PHP creates/alters some tables dynamically; no runtime schema audit was authorized.
6. Are the recorded `/mnt/storage` and `remote_station/output` evidence roots still available and immutable?
7. Which Hybrid v2 definition is intended: sequential loop detection with vocabulary tree plus controlled pairs, or loop detection disabled plus controlled pairs only?
8. Did Hybrid v1 (`440` images, `3` components) run from a script/artifact set not present or not tracked in the repository? Exact logs/database are not identified in its result document.
9. What canonical server capture type should represent dual-phone aggregate video, and should PHP unpack nested role TGZs or store them for a new job type?
10. Should ToF be recorded in dual-phone and USB bundles, and which role/device owns the authoritative ToF calibration identity?
11. Is actual 30/60 fps asymmetry accepted, or must capture refuse mismatched modes before recording?
12. Which optimization technology is approved for active IMU priors and ToF constraints? Stock COLMAP mapper currently has no such integration in this project.
13. Are AprilTag alignment and manual component assembly product requirements, recovery tools or temporary diagnostics?
14. Is the SSH-controlled GrafikStation architecture intentional long-term, and are mutable container tags acceptable?
15. Which tests are mandatory CI gates? A large test corpus exists, but no CI workflow was identified in the inspected top-level inventory.
16. Are generated templates, build trees, IDE directories, debug captures and backup files intentionally retained in the worktree?
17. Do current users rely on the legacy PHP/Smarty areas outside MaklerTour, or may ProjectNavigator scope focus only on MaklerTour/Insta3D modules?
18. Are Gaussian Splatting/NeRF active roadmap commitments or merely option notes?
