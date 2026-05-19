#!/usr/bin/env bash

export ORDER_ID="18"
export SESSION_DIR="a4295f07-6aed-466f-8169-06bb0e6ed587_18"

export WEB_ROOT="/home/makler/web"
export SESSION_PATH="${WEB_ROOT}/storage/orders/${ORDER_ID}/sessions/${SESSION_DIR}"

export VIDEO_FILE="89dcaa37-c6b2-4652-9d6c-5fc039497e69_VID_20260519_171531_00_164.mp4"
export VIDEO_PATH="${SESSION_PATH}/videos/${VIDEO_FILE}"

export SFM_BASE="${SESSION_PATH}/sfm"
export SFM_VIDEO_DIR="${SFM_BASE}/video"
export SFM_FRAMES_DIR="${SFM_BASE}/frames"
export SFM_KEYFRAMES_DIR="${SFM_BASE}/keyframes"
export SFM_COLMAP_DIR="${SFM_BASE}/colmap"
export SFM_MARKERS_DIR="${SFM_BASE}/markers"
export SFM_TRAJECTORY_DIR="${SFM_BASE}/trajectory"
export SFM_LOG_DIR="${SFM_BASE}/logs"

export SFM_TOOL="/home/makler/web/tools/sfm_cpp/build/bin/sfm_tool"
export COLMAP_BIN="/usr/local/bin/colmap"

export SFM_FPS="3"
export KEYFRAME_FPS="0.33"
export FRAME_WIDTH="1920"

export MARKER_SIZE_M="0.16"
export MARKER_FAMILY="tag36h11"
