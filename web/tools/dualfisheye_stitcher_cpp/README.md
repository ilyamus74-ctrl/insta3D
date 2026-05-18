# dualfisheye_stitcher_cpp

C++17/OpenCV CLI stitcher for Insta360 X4 dual-fisheye JPEG (5888x2944) to equirectangular JPEG.

## Build

```bash
cmake -S . -B build -DCMAKE_BUILD_TYPE=Release
cmake --build build -j$(nproc)
```

## Run

```bash
./build/dualfisheye_stitch --input /path/raw.jpg --output /path/stitched.jpg --json
```