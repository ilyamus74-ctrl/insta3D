cmake_minimum_required(VERSION 3.20)
project(maklertour_dual_phone_host VERSION 0.1.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)

find_package(Threads REQUIRED)
find_package(nlohmann_json 3.10 REQUIRED)

add_executable(maklertour-dual-phone-host
    src/main.cpp
    src/protocol.cpp
    src/host_state.cpp
    src/http_dashboard.cpp
)

add_executable(maklertour-dual-phone-synthetic-client
    tools/synthetic_camera_client.cpp
    src/protocol.cpp
)

foreach(target
    maklertour-dual-phone-host
    maklertour-dual-phone-synthetic-client
)
    target_include_directories(${target} PRIVATE src)
    target_link_libraries(${target} PRIVATE
        Threads::Threads
        nlohmann_json::nlohmann_json
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
