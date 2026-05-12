# Insta360 X4 OSC Reference

## Общие HTTP headers

Для всех OSC-запросов использовать:

```http
Content-Type: application/json;charset=utf-8
Accept: application/json
X-XSRF-Protected: 1
```

Базовый endpoint: `http://192.168.42.1/osc/commands/execute`

## Проверка режима

```json
{
  "name": "camera.getOptions",
  "parameters": {
    "optionNames": ["captureMode", "_videoType", "_videoTypeSupport"]
  }
}
```
Проверяем в ответе:
- `captureMode`;
- `_videoType`;
- `_videoTypeSupport`.

## Переключение в Video Mode

```json
{
  "name": "camera.setOptions",
  "parameters": {
    "options": {
      "captureMode": "video",
      "_videoType": "normal"
    }
  }
}
```

После `setOptions` обязательно повторно вызвать `camera.getOptions` и подтвердить:
- `captureMode == video`;
- `_videoType == normal`.

## Старт записи видео

```json
{
  "name": "camera.startCapture"
}
```

## Стоп записи видео

```json
{
  "name": "camera.stopCapture"
}
```

При успешном `stopCapture` ответ содержит `.mp4` в `fileUrls` и `_localFileUrls`.

## Переключение в Photo Mode

```json
{
  "name": "camera.setOptions",
  "parameters": {
    "options": {
      "captureMode": "image"
    }
  }
}
```

После `setOptions` обязательно повторно вызвать `camera.getOptions` и подтвердить:
- `captureMode == image`.

## Съемка фото

```json
{
  "name": "camera.takePicture"
}
```

## Polling через /osc/commands/status

Если `camera.takePicture` возвращает `inProgress`, нужно poll-ить статус через `/osc/commands/status` до `state=done`.

## Нерабочие/нежелательные варианты

Не использовать как основной способ переключения режима:

```json
{"name":"camera.setOptions","parameters":{"options":{"_captureMode":"video"}}}
```

```json
{"name":"camera.setOptions","parameters":{"options":{"_videoMode":"video"}}}
```

```json
{"name":"camera.setOptions","parameters":{"options":{"_captureMode":"image"}}}
```

```json
{"name":"camera.setOptions","parameters":{"options":{"_videoMode":"image"}}}
```
Даже при `state=done` эти варианты могут не менять фактический режим камеры.

## Особенности X4

- Камера иногда возвращает stale responses через `/osc/state`.
- Для принятия решений ориентироваться на реальные `command/status` responses и `camera.getOptions`.
- Для видеофиксировать итог по успешному `camera.stopCapture` с `fileUrls/_localFileUrls`.
- Подтвержденный рабочий переход в video mode: `captureMode=video` + `_videoType=normal`.
- Один только `captureMode=video` может работать нестабильно.

