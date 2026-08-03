cmake_minimum_required(VERSION 3.20)
project(maklertour_dual_phone_host VERSION 0.1.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)

find_package(Threads REQUIRED)
find_package(nlohmann_json 3.10 REQUIRED)
find_package(OpenCV 4.7 REQUIRED COMPONENTS core imgcodecs imgproc calib3d features2d aruco)

add_executable(maklertour-dual-phone-host
    src/main.cpp
    src/protocol.cpp
    src/host_state.cpp
    src/http_dashboard.cpp
    src/operator_preview_state.cpp
    src/live_preview_runtime.cpp
    src/accumulated_map_runtime_gyro.cpp
    src/apriltag_anchor_runtime.cpp
    src/room_geometry_runtime.cpp
    src/stereo_preview.cpp
    src/stereo_preview_processing.cpp
    src/stereo_depth_runtime.cpp
)

add_executable(maklertour-dual-phone-synthetic-client
    tools/synthetic_camera_client.cpp
    src/protocol.cpp
)

target_include_directories(maklertour-dual-phone-host PRIVATE
    src
    ${OpenCV_INCLUDE_DIRS}
)
target_link_libraries(maklertour-dual-phone-host PRIVATE
    Threads::Threads
    nlohmann_json::nlohmann_json
    ${OpenCV_LIBS}
)

target_include_directories(maklertour-dual-phone-synthetic-client PRIVATE src)
target_link_libraries(maklertour-dual-phone-synthetic-client PRIVATE
    Threads::Threads
    nlohmann_json::nlohmann_json
)

foreach(target
    maklertour-dual-phone-host
    maklertour-dual-phone-synthetic-client
)
    target_compile_options(${target} PRIVATE
        -Wall -Wextra -Wpedantic -Wconversion -Wshadow
    )
endforeach()

include(GNUInstallDirs)
install(TARGETS
    maklertour-dual-phone-host
    maklertour-dual-phone-synthetic-client
    RUNTIME DESTINATION ${CMAKE_INSTALL_BINDIR}
)
install(DIRECTORY web/ DESTINATION ${CMAKE_INSTALL_DATADIR}/maklertour-dual-phone-host/web)
