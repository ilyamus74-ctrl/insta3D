cmake_minimum_required(VERSION 3.14)
project(dualfisheye_stitcher_cpp LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)

if(NOT CMAKE_BUILD_TYPE)
  set(CMAKE_BUILD_TYPE Release CACHE STRING "Build type" FORCE)
endif()

find_package(OpenCV REQUIRED COMPONENTS core imgproc imgcodecs)

add_executable(dualfisheye_stitch src/main.cpp)
target_link_libraries(dualfisheye_stitch PRIVATE ${OpenCV_LIBS})
