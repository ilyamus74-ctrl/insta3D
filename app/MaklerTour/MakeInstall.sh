#!/bin/bash

/usr/bin/python3 tools/stereo_contract_audit.py
./gradlew :app:assembleDebug
SER=192.168.2.217:5555
PKG=com.maklertour
adb connect $SER
adb -s "$SER" install -r app/build/outputs/apk/debug/app-debug.apk
adb -s "$SER" shell am force-stop "$PKG"
adb -s "$SER" logcat -c