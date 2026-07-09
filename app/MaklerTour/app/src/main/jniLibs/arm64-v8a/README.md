Place the real Android arm64-v8a libusb/libuvc shared libraries here:

- libusb1.0.so
- libuvc.so

The Gradle/CMake configuration intentionally imports these libraries so debug APKs
must package real native dependencies for the cam1 UVC backend. Placeholder or
empty libraries are not accepted.