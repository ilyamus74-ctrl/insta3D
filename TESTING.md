
# Testing

## Build

```bash
./gradlew :app:assembleDebug
```

## Install

```bash
adb install -r app/build/outputs/apk/debug/app-debug.apk
```
## Launch

```bash
adb shell monkey -p com.maklertour 1
```

## Crash logs

```bash
adb logcat -c
adb shell monkey -p com.maklertour 1
adb logcat -d | grep -i -A 80 -B 20 "FATAL EXCEPTION\|AndroidRuntime\|Room\|SQLite\|IllegalStateException"
```

## App logs

```bash
adb logcat | grep -i -E "AppStateViewModel|RoomSessionRepository|Insta360OscProvider|OscHttpClient"
```

## Room schema crash diagnostics

```bash
adb logcat -c
adb shell monkey -p com.maklertour 1
adb logcat -d | grep -i -A 120 -B 40 "Room\|Schema\|IllegalStateException\|Migration\|SQLite"
```

## Insta360 OSC: check mode

```bash
curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.getOptions",
    "parameters": {
      "optionNames": ["captureMode", "_videoType", "_videoTypeSupport"]
    }
  }'
```

## Insta360 OSC: switch to video

```bash
curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.setOptions",
    "parameters": {
      "options": {
        "captureMode": "video",
        "_videoType": "normal"
      }
    }
  }'

curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.getOptions",
    "parameters": {
      "optionNames": ["captureMode", "_videoType"]
    }
  }'
```

## Insta360 OSC: record 5 sec video

```bash
curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{"name":"camera.startCapture"}'

sleep 5

curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{"name":"camera.stopCapture"}'
```

## Insta360 OSC: switch to photo

```bash
curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.setOptions",
    "parameters": {
      "options": {
        "captureMode": "image"
      }
    }
  }'

curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.getOptions",
    "parameters": {
      "optionNames": ["captureMode"]
    }
  }'
```

## Insta360 OSC: take photo

```bash
# 1) Запуск съемки фото
curl -s -X POST "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{"name":"camera.takePicture"}'

# 2) Если в ответе state=inProgress, использовать id команды и poll:
curl -s -X POST "http://192.168.42.1/osc/commands/status" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{"id":"<COMMAND_ID_FROM_takePicture_RESPONSE>"}'
```

## Insta360 OSC через adb shell (реальные команды)


```bash
# getOptions: captureMode/_videoType/_videoTypeSupport
adb shell "cmd connectivity airplane-mode disable >/dev/null 2>&1; \
printf 'POST /osc/commands/execute HTTP/1.1\r\nHost: 192.168.42.1\r\nContent-Type: application/json;charset=utf-8\r\nAccept: application/json\r\nX-XSRF-Protected: 1\r\nContent-Length: 113\r\n\r\n{\"name\":\"camera.getOptions\",\"parameters\":{\"optionNames\":[\"captureMode\",\"_videoType\",\"_videoTypeSupport\"]}}' | toybox nc 192.168.42.1 80"

# set video: captureMode=video + _videoType=normal
adb shell "printf 'POST /osc/commands/execute HTTP/1.1\r\nHost: 192.168.42.1\r\nContent-Type: application/json;charset=utf-8\r\nAccept: application/json\r\nX-XSRF-Protected: 1\r\nContent-Length: 97\r\n\r\n{\"name\":\"camera.setOptions\",\"parameters\":{\"options\":{\"captureMode\":\"video\",\"_videoType\":\"normal\"}}}' | toybox nc 192.168.42.1 80"

# startCapture
adb shell "printf 'POST /osc/commands/execute HTTP/1.1\r\nHost: 192.168.42.1\r\nContent-Type: application/json;charset=utf-8\r\nAccept: application/json\r\nX-XSRF-Protected: 1\r\nContent-Length: 30\r\n\r\n{\"name\":\"camera.startCapture\"}' | toybox nc 192.168.42.1 80"

# stopCapture
adb shell "printf 'POST /osc/commands/execute HTTP/1.1\r\nHost: 192.168.42.1\r\nContent-Type: application/json;charset=utf-8\r\nAccept: application/json\r\nX-XSRF-Protected: 1\r\nContent-Length: 29\r\n\r\n{\"name\":\"camera.stopCapture\"}' | toybox nc 192.168.42.1 80"

# set photo: captureMode=image
adb shell "printf 'POST /osc/commands/execute HTTP/1.1\r\nHost: 192.168.42.1\r\nContent-Type: application/json;charset=utf-8\r\nAccept: application/json\r\nX-XSRF-Protected: 1\r\nContent-Length: 78\r\n\r\n{\"name\":\"camera.setOptions\",\"parameters\":{\"options\":{\"captureMode\":\"image\"}}}' | toybox nc 192.168.42.1 80"

# takePicture + status polling
adb shell "printf 'POST /osc/commands/execute HTTP/1.1\r\nHost: 192.168.42.1\r\nContent-Type: application/json;charset=utf-8\r\nAccept: application/json\r\nX-XSRF-Protected: 1\r\nContent-Length: 28\r\n\r\n{\"name\":\"camera.takePicture\"}' | toybox nc 192.168.42.1 80"

adb shell "printf 'POST /osc/commands/status HTTP/1.1\r\nHost: 192.168.42.1\r\nContent-Type: application/json;charset=utf-8\r\nAccept: application/json\r\nX-XSRF-Protected: 1\r\nContent-Length: 20\r\n\r\n{\"id\":\"<COMMAND_ID>\"}' | toybox nc 192.168.42.1 80"
```

