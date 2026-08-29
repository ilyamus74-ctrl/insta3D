#!/bin/bash

IDA="uor4xskfl79heqlj"
echo "Instal to android ID $IDA"

adb -s $IDA install ~/AndroidStudioProjects/MaklerTour/app/build/outputs/apk/debug/app-debug.apk
