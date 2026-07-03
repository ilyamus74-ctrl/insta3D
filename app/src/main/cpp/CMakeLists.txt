cmake_minimum_required(VERSION 3.22.1)
project(cam1_uvc)

add_library(cam1_uvc SHARED cam1_uvc.cpp)
find_library(log-lib log)
find_library(android-lib android)
target_link_libraries(cam1_uvc ${log-lib} ${android-lib})