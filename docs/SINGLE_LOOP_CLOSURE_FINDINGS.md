# SINGLE Loop Closure Findings

## Статус документа

Этот документ фиксирует текущее состояние исследования SINGLE visual loop closure по результатам завершённого controlled sparse-only A/B эксперимента, описанного в `docs/SINGLE_VISUAL_LOOP_CLOSURE_AB_AUDIT.md`.

Это фиксация findings, а не готовое решение проблемы spiral/self-intersection.

## 1. Current sequential matcher

Текущий visual sparse baseline с sequential matching дал:

| Metric | Result |
|---|---:|
| Sparse components | 6 |
| Largest model | 204 images |
| Sparse points | 72,896 |
| Verified image pairs | 2,145 |
| Long-range verified pairs, frame gap >300 | 0 |
| Full endpoint coverage | No |

Начало и конец capture sequence не попали в один sparse component, поэтому global start/end trajectory gap для baseline не определён.

## 2. Exhaustive matcher

На том же immutable наборе selected frames, с теми же features, intrinsics и stock COLMAP mapper, exhaustive matching дал:

| Metric | Result |
|---|---:|
| Sparse components | 1 |
| Largest model | 509 images |
| Sparse points | 82,570 |
| Verified image pairs | 11,744 |
| Long-range verified pairs, frame gap >300 | 479 |
| Endpoint coverage | Frames 1–547 |
| Normalized endpoint gap | 2.09% |
| Mean reprojection error | 0.94 px |

Exhaustive matching объединил temporal regions, которые sequential baseline разнёс по разным sparse components, и создал полную endpoint coverage в одной модели.

## 3. Findings

Завершённый A/B эксперимент подтвердил:

- visual connectivity и loop closure являются реальной проблемой текущего SINGLE sparse pipeline;
- exhaustive matching существенно улучшает connectivity и объединяет разорванную trajectory;
- exhaustive matching не доказал, что self-intersection или spiral deformation устранены;
- mean reprojection error немного ухудшился относительно largest components sequential baseline;
- крупный trajectory-step outlier сохранился;
- exhaustive matcher нельзя внедрять в production на основании текущих results.

Улучшение component connectivity не равно доказанной геометрической стабильности. Перед production change нужен более узкий и контролируемый visual loop policy.

## 4. Next research step

Следующий исследовательский шаг:

```text
controlled hybrid / loop-detection experiment

sequential matching
  + visual loop candidates
  -> stock COLMAP mapper
  -> global bundle adjustment
```

Цель следующего эксперимента — сохранить нужные non-local visual edges без стоимости и побочных эффектов полного exhaustive matching.

До завершения controlled hybrid experiment production pipeline менять нельзя.

## 5. Research boundary

Текущий результат не является acceptance для SINGLE reconstruction и не разрешает production migration.

Документ фиксирует только текущее состояние исследования и не утверждает, что проблема spiral/self-intersection решена.
