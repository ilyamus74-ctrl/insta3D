# SINGLE Hybrid v2 Audit

## Status

```text
EXPERIMENT IMPLEMENTATION: PASS
LOCAL STATIC VERIFICATION: PASS
HYBRID V2 RUNTIME: NOT_RUN
DENSE: NOT_RUN
PRODUCTION CHANGES: NONE
```

## Scope

Подготовлен отдельный sparse-only experiment для immutable frames `job_180237696`. Production pipeline, PHP/backend, deployment scripts, Android/capture и существующие документы не изменялись. Artifact root по умолчанию: `/tmp/insta3d_single_hybrid_v2/`.

## Experimental policy

Pipeline:

1. COLMAP feature extraction с зафиксированными baseline intrinsics.
2. Sequential matcher: overlap 60, loop detection enabled, explicit compatible vocabulary tree required.
3. `matches_importer` для bounded controlled graph: endpoint windows и четыре распределённые long-range hypotheses; пары с temporal gap ≤60 исключаются.
4. Stock COLMAP mapper.
5. TXT model export, `model_analyzer` output и JSON trajectory diagnostics.

Полный exhaustive graph не создаётся. При окне ±5 policy имеет верхнюю границу 520 candidate pairs до дедупликации; при фиксированном числе anchors граф не растёт квадратично с числом кадров.

## Requested diagnostics

`diagnostics.json` экспортирует число components, сумму registrations и sparse points, а для каждого model — registered images, sparse points, mean point reprojection error, first/last registered frame, endpoint distance, trajectory path length, normalized endpoint distance, median/p95/max adjacent step. SfM distances остаются в arbitrary units.

## Runtime result

Фактический Hybrid v2 reconstruction из этой среды не выполнен:

- `colmap` отсутствует в `PATH`;
- `/mnt/storage/makler_pipeline/output/job_180237696/frames` не смонтирован;
- ранее задокументированный configured GrafikStation access недоступен текущей execution environment.

Поэтому численные Hybrid v2 результаты отсутствуют и не подменены данными Hybrid v1 или exhaustive baseline.

| Metric | Hybrid v2 |
|---|---:|
| Registered images | NOT_RUN |
| Components | NOT_RUN |
| Sparse points | NOT_RUN |
| Reprojection error | NOT_RUN |
| First/last registered frame | NOT_RUN |
| Endpoint distance | NOT_RUN |
| Trajectory diagnostics | NOT_RUN |

## Recommendation

```text
DECISION: INCONCLUSIVE UNTIL RUNTIME
CURRENT RECOMMENDATION: CONTINUE VISUAL DIRECTION FOR ONE BOUNDED RUN
```

Hybrid v1 уже показал сильное улучшение connectivity, поэтому один controlled Hybrid v2 run оправдан. Продолжать visual direction следует только если v2 воспроизводит улучшение connectivity без ухудшения reprojection/trajectory outliers и сокращает components при небольшом verified long-range graph. Если bounded pairs не дают устойчивого улучшения trajectory diagnostics либо connectivity улучшается, но drift сохраняется, следующий эксперимент должен перейти к IMU pose prior. ToF следует оценивать после этого как отдельное metric constraint, а не как замену проверки pose prior.

## Reproduction

На host с dataset, COLMAP и compatible vocabulary tree:

```bash
experiments/single_hybrid_v2/run.sh \
  /mnt/storage/makler_pipeline/output/job_180237696/frames \
  /path/to/compatible/vocab_tree.bin
```

Runner не перезаписывает существующий artifact root и не запускает dense reconstruction.
