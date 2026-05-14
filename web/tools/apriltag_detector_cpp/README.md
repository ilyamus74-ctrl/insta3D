# AprilTag detector CLI (C++)

## Dependencies (Fedora)

```bash
sudo dnf install -y cmake gcc-c++ make opencv-devel apriltag-devel nlohmann-json-devel ffmpeg
```

If `apriltag-devel` is unavailable in your Fedora repos, build AprilTag from source:

```bash
git clone https://github.com/AprilRobotics/apriltag.git
cd apriltag
cmake -B build -S .
cmake --build build -j$(nproc)
sudo cmake --install build
sudo ldconfig
```

## Build

```bash
cd tools/apriltag_detector_cpp
mkdir -p build
cd build
cmake ..
make -j$(nproc)
```

## Run

```bash
./detect_markers \
  --input-list input_media.json \
  --output detections.json \
  --tag-family tag36h11 \
  --valid-ids 1-30 \
  --marker-size-m 0.160
```