cmake_minimum_required(VERSION 3.10)
project(apriltag_detector_cpp LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

find_package(OpenCV REQUIRED)
find_package(nlohmann_json REQUIRED)
find_package(PkgConfig QUIET)

pkg_check_modules(APRILTAG QUIET apriltag)

add_executable(detect_markers src/detect_markers.cpp)

target_include_directories(detect_markers PRIVATE ${OpenCV_INCLUDE_DIRS})

target_link_libraries(detect_markers PRIVATE ${OpenCV_LIBS} nlohmann_json::nlohmann_json)

if(APRILTAG_FOUND)
  target_include_directories(detect_markers PRIVATE ${APRILTAG_INCLUDE_DIRS})
  target_link_directories(detect_markers PRIVATE ${APRILTAG_LIBRARY_DIRS})
  target_link_libraries(detect_markers PRIVATE ${APRILTAG_LIBRARIES})
else()
  target_link_libraries(detect_markers PRIVATE apriltag)
endif()
