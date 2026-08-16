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
| S01H.2.4 | INSUFFICIENT_SUPPORT / CLOSED DIAGNOSTIC |
| S01H.2.5 | PASS / CLOSED DIAGNOSTIC |
| S01H.2.6 | PARTIAL SUPPORT / CLOSED DIAGNOSTIC |
| S01H.2.7 | PASS / CLOSED DIAGNOSTIC |
| S01H.2.8 | PARTIAL SUPPORT / CLOSED DIAGNOSTIC |
| S01H.2.9 | PASS / CLOSED DIAGNOSTIC |
| Dense-local-structure hypothesis | SUPPORTED |
| Strong systematic distance/scale deformation | NOT SUPPORTED POST-CW90 |
| Independent ToF range-linearity sweep | OPTIONAL / NOT REQUIRED NEXT |
| S01H.2.10 | NEXT — POST-CW90 METRIC READINESS / REPRODUCIBILITY |
| S01H.3 | CLOSED / BLOCKED |
| geometry mutation | OFF |
| fusion | OFF |

### CW90 orientation incident — CLOSED

Исправление CAMERA_A landscape -> COLMAP portrait зафиксировано commit:

`8f649737c56b4dd7c27f7dc668218f47511e8c2a`

Физическое направление преобразования подтверждено независимо от residual:

`CW_90_PHYSICALLY_CONFIRMED`

Coordinate contract:

```text
X_colmap = -Y_camera_a
Y_colmap =  X_camera_a
Z_colmap =  Z_camera_a
```

Pipeline 93 повторно измерен с неизменными H1 observations, sparse model и
Dense maps. POST-CW90 evidence root:

`remote_station/output/pipeline_93/metric_evidence_post_cw90_8f649737c56b4dd7/`

H2.2 `geometric_footprint_p50`:

| Metric | PRE-CW90 | POST-CW90 |
|---|---:|---:|
| coverage | 91.24% | 98.12% |
| valid correspondences | 4467 | 4804 |
| robust inliers | 2899 | 4223 |
| robust scale, mm/unit | 190.067 | 190.605 |
| residual p50, mm | 79.89 | 26.46 |
| residual p95, mm | 217.41 | 122.33 |
| relative error p50 | 9.04% | 3.01% |
| relative error p95 | 23.50% | 12.09% |
| distance spread | 1.993 | 1.211 |
| row spread | 1.289 | 1.046 |
| column spread | 1.393 | 1.048 |
| image-region spread | 1.221 | 1.009 |

POST-CW90 classifications:

- H2.4: `INSUFFICIENT_SUPPORT`;
- H2.9: `QUALITY_GATING_COLLAPSES_CONTROLLED_DEFORMATION`;
- H2.9 CLEAN zone-normalized distance spread: `1.0010`;
- прежняя strong systematic distance deformation больше не поддерживается.

Независимый ToF range-linearity sweep больше не является обязательным
следующим шагом. Он остается допустимым дополнительным sensor audit, но не
блокирует POST-CW90 readiness/reproducibility review.

Старый evidence root
`remote_station/output/pipeline_93/metric_evidence/` и приведенные ниже
результаты H2.2-H2.9 сохраняются как исторические **PRE-CW90** результаты,
полученные до исправления orientation bug. Они не являются актуальной оценкой
POST-CW90 metric deformation.

### S01H.2.7 — итоговый диагностический вывод

> Historical PRE-CW90 result: этот раздел сохраняется без удаления для
> трассируемости результатов до исправления orientation bug.

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

### S01H.2.8 — Dense quality gating

> Historical PRE-CW90 result: этот раздел сохраняется без удаления для
> трассируемости результатов до исправления orientation bug.

H2.8 заморозил `CLEAN_DENSE` / `UNSTABLE_DENSE` только по Dense-quality
признакам, без использования ToF residual/error при выборе.

Классификация:

`DENSE_QUALITY_GATE_PARTIAL_SUPPORT`

Ключевые результаты:

- `FULL=4017`, `CLEAN_DENSE=475`, `UNSTABLE_DENSE=1394`;
- raw depth error p50: `129.18 -> 110.47 mm`;
- raw depth error p95: `868.10 -> 801.76 mm`;
- zone+distance-conditioned p50 ratio vs FULL: `0.746644`;
- zone+distance-conditioned p95 ratio vs FULL: `0.293956`;
- distance spread: `1.989424 -> 1.984098`;
- zone-row spread: `1.317443 -> 1.497874`;
- zone-column spread: `2.068726 -> 1.202768`.

Интерпретация: quality gating подтверждает локальный Dense-компонент ошибки,
но не устраняет remaining systematic distance/scale deformation.

H2.8 evidence:

- `h28/tof_dense_h28_report.json`
  - SHA256: `cff04bd340ffa4be3b3c2b7dc38bbab56f1af141f1e22099949472f2d840fbde`
- `h28/tof_dense_h28_quality_gate.jsonl`
  - SHA256: `c3f2747fc3195eb832c757c508bed5aea1c24b114b726fe8bc1ee556e736621a`

### S01H.2.9 — quality-controlled decomposition

> Historical PRE-CW90 result: этот раздел сохраняется без удаления для
> трассируемости результатов до исправления orientation bug.

H2.9 повторил controlled decomposition на `CLEAN_DENSE`.

Классификация:

`MIXED_LOCAL_DENSE_INSTABILITY_AND_SYSTEMATIC_DEPTH_SCALE_DEFORMATION_SUPPORTED`

Ключевые результаты:

- CLEAN observations: `475`;
- raw distance scale spread: `1.984097735007529`;
- distance-normalized zone-row spread: `1.273973401926296`;
- distance-normalized zone-column spread: `1.1062106872306543`;
- zone-normalized distance scale spread: `1.9282663266674782`;
- fully normalized residual p50: `0.07124459064611713`;
- fully normalized residual p95: `0.22425715337674382`.

Bootstrap по `109` RGB images подтверждает remaining distance term:

- raw distance spread p2.5: `1.9075881739084382`;
- zone-normalized distance spread p2.5: `1.8106659592481953`;
- zone-normalized distance spread median: `1.9154533013959054`.

Зафиксированное разделение:

1. local Dense instability — `SUPPORTED`;
2. systematic distance/scale deformation — `SUPPORTED`;
3. source attribution (`ToF range bias` vs `COLMAP Dense depth nonlinearity`) — `OPEN`.

H2.9 evidence:

- `h29/tof_dense_h29_report.json`
  - SHA256: `e935a57e1de76915f9e1ac8e6c2ee8df144a04aa57bd871cfb1d1663ea6ce11f`
- `h29/tof_dense_h29_controlled_rows.jsonl`
  - SHA256: `428e6a22bd9884c83de9744fde6b740681de86c2c3e93b9811dc3e7f5f2d8f0d`

Persistent evidence root:

`remote_station/output/pipeline_93/metric_evidence/`

### S01H.2.10 — POST-CW90 METRIC READINESS / REPRODUCIBILITY

Следующий обязательный gate должен проверить воспроизводимость исправленного
coordinate-space contract, а не заранее предполагать ToF range nonlinearity.

Минимальный scope H2.10:

1. повторить measurement-only H2.2-H2.9 на независимом capture/pipeline с тем
   же явным CAMERA_A -> COLMAP CW90 contract;
2. проверить стабильность coverage, correspondence count, robust scale,
   p50/p95 residual и distance/row/column/image-region spreads;
3. подтвердить, что H2.4 image-region pattern и H2.9 strong clean systematic
   distance deformation не возвращаются;
4. зафиксировать artifact provenance, immutable H1 input и точные версии
   diagnostic scripts;
5. выдать отдельный readiness decision перед любым открытием S01H.3.

H2.10 остается measurement-only. Он не меняет ToF calibration, R/t, sparse или
Dense geometry. До отдельного readiness decision обязательны:

- `geometry_mutation_enabled=false`;
- `fusion_enabled=false`;
- S01H.3 `CLOSED / BLOCKED`.

Независимый ToF range-linearity sweep может быть выполнен как дополнительный
контроль сенсора, если новые capture evidence снова покажут устойчивую
distance-dependent деформацию, но больше не является обязательным следующим
шагом S01H.

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
