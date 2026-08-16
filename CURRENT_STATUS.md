# Current Status

Дата актуализации: 2026-08-16

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
---

## S01H — метрическая валидация ToF / COLMAP

Актуальный reference run:

- `pipeline_run_id=93`
- `EXTRACT_FRAMES=476899433`
- `COLMAP_SPARSE=938990778`
- `COLMAP_RECONSTRUCTION_PREVIEW=389803539`
- sparse model: `0`
- финальная sparse-камера:
  `SIMPLE_RADIAL 1080 1920 1314.0155350160971 540 960 0.013541940739797181`

### Зафиксированный статус

| Этап | Статус |
|---|---|
| S01H.1 | PASS / CLOSED |
| S01H.2 | MEASURED |
| S01H.2.1 | PASS / CLOSED DIAGNOSTIC |
| S01H.2.2 | PASS / CLOSED DIAGNOSTIC |
| S01H.2.3 | PASS / CLOSED DIAGNOSTIC |
| S01H.2.4 | PASS / CLOSED DIAGNOSTIC |
| S01H.2.5 | PASS / CLOSED DIAGNOSTIC |
| S01H.2.6 | PARTIAL SUPPORT / CLOSED DIAGNOSTIC |
| S01H.2.7 | PASS / CLOSED DIAGNOSTIC |
| Dense-local-structure hypothesis | SUPPORTED |
| S01H.3 | CLOSED / BLOCKED |
| geometry mutation | OFF |
| fusion | OFF |

### S01H.2.7 — итоговый диагностический вывод

После удаления baseline по `zone + distance` и проверки связи внутри каждого
конкретного RGB-кадра (`exact-image within-frame Spearman`) два независимых
признака локальной нестабильности Dense сохранили статистически устойчивую
связь с остаточной метрической ошибкой:

- `geometric_local_gradient_fraction`:
  - median within-frame Spearman: `0.12167919799498747`
  - positive images: `80/112` (`71.43%`)
  - one-sided sign-test: `p=3.283169676913117e-6`
  - bootstrap 95% CI: `[0.077992277992278, 0.16751946607341495]`
  - направление положительное во всех `Q1..Q4`
  - `frame_fixed_effect_signal=true`
- `geometric_photometric_relative_difference`:
  - median within-frame Spearman: `0.14035087719298242`
  - positive images: `88/117` (`75.21%`)
  - one-sided sign-test: `p=2.1399713589953992e-8`
  - bootstrap 95% CI: `[0.09724238026124817, 0.198582995951417]`
  - направление положительное во всех `Q1..Q4`
  - `frame_fixed_effect_signal=true`
- `geometric_footprint_iqr_fraction` остается поддерживающим, но формально
  не проходит gate из-за `p=0.01303778111783177` при пороге `0.01`.

Классификация H2.7:

`DENSE_LOCAL_STRUCTURE_FRAME_FIXED_EFFECT_SUPPORTED`

Корректная интерпретация: после контроля расстояния ToF, зоны ToF и
конкретного RGB-кадра остаточная метрическая ошибка статистически связана с
локальной нестабильностью COLMAP Dense depth. Это поддерживает
`Dense-local-structure hypothesis`, но само по себе еще не доказывает
конкретный физический или алгоритмический дефект PatchMatch.

### Что предыдущими этапами не подтверждено как основная причина

- грубое несоответствие Camera2 / COLMAP optics;
- простой focal / principal-point mismatch;
- малые ошибки ToF `cx/cy/fx/fy`;
- малые ошибки ToF->RGB rotation / translation;
- nearest sparse correspondence как основная причина;
- простой temporal drift.

### H2.7 evidence

- `h27/tof_dense_h27_report.json`
  - SHA256: `e2a760fa91dbc00aa78d7152de3026b36101a773b02aeac4ff9c350a8c3e6df2`
- `h27/tof_dense_h27_image_effects.jsonl`
  - SHA256: `861bba90cb715690be1eea9c139ac211147ebe7299f9f09b7a144d5e94febbc6`

Persistent evidence root:

`remote_station/output/pipeline_93/metric_evidence/`

### Safety gate

До отдельной успешной валидации запрещено автоматически изменять финальную
геометрию по ToF:

- `geometry_mutation_enabled=false`
- `fusion_enabled=false`
- S01H.3 остается `CLOSED / BLOCKED`
- ToF остается optional и не должен блокировать RGB capture/upload/SfM/Dense/mesh
- при валидном ToF до `4 m` измерение может использоваться как метрический
  reference/anchor
- за пределами `4 m` сохраняется RGB/COLMAP/Dense geometry; размерность только
  `APPROXIMATE_ONLY`, без экстраполяции ToF
