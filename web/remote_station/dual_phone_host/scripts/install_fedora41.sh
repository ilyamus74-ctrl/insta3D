#!/usr/bin/env bash
set -euo pipefail
sudo dnf install -y gcc-c++ cmake ninja-build json-devel opencv-devel firewalld
sudo firewall-cmd --permanent --add-port=48640/tcp
sudo firewall-cmd --permanent --add-port=48641/tcp
sudo firewall-cmd --reload
