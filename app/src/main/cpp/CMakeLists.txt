cmake_minimum_required(VERSION 3.22.1)
project(cam1_uvc)

add_library(usb1.0 SHARED IMPORTED)
set_target_properties(usb1.0 PROPERTIES IMPORTED_LOCATION ${CMAKE_SOURCE_DIR}/../jniLibs/${ANDROID_ABI}/libusb1.0.so)

add_library(uvc SHARED IMPORTED)
set_target_properties(uvc PROPERTIES IMPORTED_LOCATION ${CMAKE_SOURCE_DIR}/../jniLibs/${ANDROID_ABI}/libuvc.so)

add_library(cam1_uvc SHARED cam1_uvc.cpp)
find_library(log-lib log)
find_library(android-lib android)
find_library(dl-lib dl)
target_link_libraries(cam1_uvc ${log-lib} ${android-lib} ${dl-lib} usb1.0 uvc)
