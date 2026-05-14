cmake_minimum_required(VERSION 3.16)

project(apriltag_detector_cpp LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

find_package(OpenCV REQUIRED COMPONENTS core imgproc imgcodecs)
find_package(nlohmann_json REQUIRED)
find_package(PkgConfig REQUIRED)

pkg_check_modules(APRILTAG REQUIRED apriltag)

add_executable(detect_markers
    src/detect_markers.cpp
)

target_include_directories(detect_markers PRIVATE
    ${OpenCV_INCLUDE_DIRS}
    ${APRILTAG_INCLUDE_DIRS}
)

target_link_directories(detect_markers PRIVATE
    ${APRILTAG_LIBRARY_DIRS}
)

target_compile_options(detect_markers PRIVATE
    ${APRILTAG_CFLAGS_OTHER}
)

target_link_libraries(detect_markers PRIVATE
    ${OpenCV_LIBS}
    nlohmann_json::nlohmann_json
    ${APRILTAG_LIBRARIES}
)

install(TARGETS detect_markers RUNTIME DESTINATION bin)
