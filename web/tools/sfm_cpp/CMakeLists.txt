cmake_minimum_required(VERSION 3.16)
project(sfm_tool LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

find_package(PkgConfig REQUIRED)
pkg_check_modules(OPENCV4 REQUIRED opencv4)
pkg_check_modules(APRILTAG REQUIRED apriltag)
find_package(nlohmann_json REQUIRED)

add_executable(sfm_tool
  src/main.cpp
  src/colmap_parser.cpp
  src/trajectory_scale.cpp
  src/apriltag_frames.cpp
  src/camera_profile.cpp
)

target_include_directories(sfm_tool PRIVATE src ${OPENCV4_INCLUDE_DIRS} ${APRILTAG_INCLUDE_DIRS})
target_link_directories(sfm_tool PRIVATE ${OPENCV4_LIBRARY_DIRS} ${APRILTAG_LIBRARY_DIRS})
target_link_libraries(sfm_tool PRIVATE ${OPENCV4_LIBRARIES} ${APRILTAG_LIBRARIES} nlohmann_json::nlohmann_json)
target_compile_options(sfm_tool PRIVATE ${OPENCV4_CFLAGS_OTHER} ${APRILTAG_CFLAGS_OTHER})
