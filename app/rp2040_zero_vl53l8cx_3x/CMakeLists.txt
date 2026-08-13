cmake_minimum_required(VERSION 3.13)

if(NOT DEFINED ENV{PICO_SDK_PATH})
  message(FATAL_ERROR "PICO_SDK_PATH is not set. Run ./build_uf2.sh")
endif()

include($ENV{PICO_SDK_PATH}/external/pico_sdk_import.cmake)

project(tof_rig C CXX ASM)
set(CMAKE_C_STANDARD 11)
set(CMAKE_CXX_STANDARD 17)

pico_sdk_init()

set(VL53_DIR ${CMAKE_CURRENT_LIST_DIR}/vendor/VL53L8CX/src)

add_executable(tof_rig
  src/main.c
  src/tof_frame_v1.c
  src/tof_sync_v1.c
  src/vl53l8cx_pico_platform.c
  ${VL53_DIR}/vl53l8cx_api.c
  ${VL53_DIR}/vl53l8cx_platform.c
)

target_include_directories(tof_rig PRIVATE
  ${CMAKE_CURRENT_LIST_DIR}/src
  ${VL53_DIR}
)

target_link_libraries(tof_rig
  pico_stdlib
  hardware_i2c
)

pico_enable_stdio_usb(tof_rig 1)
pico_enable_stdio_uart(tof_rig 0)
pico_add_extra_outputs(tof_rig)

add_executable(tof_scan
  src/i2c_scan.c
)

target_link_libraries(tof_scan
  pico_stdlib
  hardware_i2c
)

pico_enable_stdio_usb(tof_scan 1)
pico_enable_stdio_uart(tof_scan 0)
pico_add_extra_outputs(tof_scan)
