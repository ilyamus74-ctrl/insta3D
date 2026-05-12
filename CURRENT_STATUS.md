# Current Status

Дата актуализации: 2026-05-10

## Подтвержденный статус

- Android-приложение собирается и запускается.
- Room schema crash исправлен.
- Подключение к Insta360 X4 по Wi‑Fi работает.
- Фото-точки работают (включая сохранение и preview download в фоне).
- Video Scan работает (start/stop и сохранение metadata).

## Подтвержденные payload-варианты режимов

- Рабочий payload для video mode:
  - `captureMode=video`
  - `_videoType=normal`
- Рабочий payload для photo mode:
  - `captureMode=image`

## Важное ограничение
Старые варианты `_captureMode` / `_videoMode` не использовать как основной способ переключения режимов, даже если отдельные ответы возвращают `state=done`.