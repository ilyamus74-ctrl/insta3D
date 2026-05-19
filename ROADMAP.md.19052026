# Roadmap

## P0 — стабилизация текущего MVP
- [x] Подключение к Insta360 X4 по Wi-Fi
- [x] OSC /osc/info и /osc/state
- [x] Photo Point capture
- [x] Background preview download
- [x] Video Scan start/stop
- [x] Переключение image -> video
- [x] Переключение video -> image
- [x] Сохранение scan video metadata
- [x] Исправление Room schema crash
- [ ] Проверить отображение video scans в Draft screen
- [ ] Проверить привязку video scan к текущей sessionId
- [ ] Добавить UI-защиту от повторного старта записи
- [ ] Добавить понятный статус записи в UI
- [ ] Добавить обработку failed scan

## P1 — локальные файлы и preview
- [ ] Единая структура storage для sessions/previews/videos
- [ ] Preview хранить offline
- [ ] Video не скачивать автоматически
- [ ] Download video только вручную или перед upload
- [ ] Статусы download для video
- [ ] Не удалять файлы с камеры автоматически

## P2 — upload/backend
- [ ] Upload queue
- [ ] Upload только по нормальному Wi-Fi
- [ ] Preview upload
- [ ] Original photo upload
- [ ] Video upload
- [ ] Retry/resume
- [ ] Server processing status

## P3 — reconstruction/backend pipeline
- [ ] Frame extraction
- [ ] Marker detection
- [ ] Scale recovery
- [ ] SfM/MVS reconstruction
- [ ] Linking photo points to reconstruction
- [ ] Tour/floorplan output

## 2026-05-10

### Done
- Video Scan capture flow works.
- camera.startCapture / camera.stopCapture return valid video file URLs.
- Video file is successfully downloaded from Insta360 to local session storage.
- Local path example:
  `/data/user/0/com.maklertour/files/sessions/<sessionId>/videos/<file>.mp4`
- Room entity stores capture/download/upload/server processing states.

### Next
- Implement upload queue for video scans.
- Add Wi-Fi-only upload policy.
- Add WorkManager background upload.
- Add backend API contract for session media upload.
- Hide debug details behind expandable UI.

## 2026-05-10 — Backend mobile upload

### Done
- Web login works on clean MaklerTour database.
- Dashboard works without legacy warehouse tables.
- Orders page works.
- Operator market works.
- Order detail page works.
- Mobile API login works.
- Mobile API orders works.
- Mobile API create_session works.
- Mobile API upload_video_scan works.
- Video files are stored under:
  `storage/orders/{order_id}/sessions/{app_session_uuid}/videos/{app_scan_uuid}_{filename}`

### Next
- Add mobile API endpoint for photo point upload.
- Store photo previews and originals separately:
  - `photos/previews/`
  - `photos/originals/`
- Show uploaded photo points in order detail page.
- Integrate Android app with backend API:
  - login
  - orders
  - create_session
  - upload video scan
  - upload photo points
- Add WorkManager upload queue later.

- TODO (public tour media derivatives): server-side preview derivatives pipeline: previews 1024x512 or 512x256, viewer_light 2048x1024, viewer_hd 4096x2048, originals untouched camera original.
