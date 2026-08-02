# APP-DUAL-PHONE-LM02.7B.1 CPU laptop receiver

Implement the Linux C++ network MASTER foundation before adding stereo depth.
The host must accept two outbound camera clients, keep bounded latest frames,
show a browser dashboard, archive frames and emit machine-readable JSONL logs.

Baseline: `fd952471e0af8d7717a08729a0d2befab0e46fba`.

Acceptance gates:

1. Fedora 41 build uses CMake/Ninja and system packages only.
2. Two C++ synthetic clients connect simultaneously as CAMERA_A and CAMERA_B.
3. Dashboard shows both JPEG streams and pairing delta.
4. Session output contains JSONL diagnostics and per-camera frame archives.
5. Protocol has explicit sizes, CRC32 and bounded payloads.
6. Existing Android/on-device files are not changed in this slice.
7. `web/tools/colmap_src` remains untouched.
