# SINGLE Hybrid Loop Closure Audit

## Status

```text
AUDIT STATUS: BLOCKED BEFORE EXPERIMENT
DECISION: INCONCLUSIVE
HYBRID COLMAP RUN: NOT STARTED
DENSE: NOT STARTED
PRODUCTION CHANGES: NONE
SOURCE CHANGES: NONE
CAPTURE ARTIFACT CHANGES: NONE
```

## 1. Scope and intended experiment

Целью был controlled sparse-only эксперимент на том же immutable наборе 547 selected frames, который использовался в `docs/SINGLE_VISUAL_LOOP_CLOSURE_AB_AUDIT.md`:

```text
sequential temporal matching, overlap 60
  + bounded non-local visual loop candidates
  -> stock COLMAP mapper
  -> mapper internal global BA
```

Разрешённый experiment root:

```text
/tmp/insta3d_single_hybrid_loop/
```

Эксперимент должен был выполняться на GrafikStation через штатный remote-processing контур проекта. До проверки этого контура COLMAP не запускался.

## 2. Existing remote-processing mechanism

В репозитории найден штатный SSH-controlled контур:

| Layer | File / mechanism | Role |
|---|---|---|
| Station configuration | `web/remote_station/stations.conf` | Defines GrafikStation host, user, configured key, station base, Podman COLMAP image |
| Sparse runner | `web/remote_station/run_colmap_sparse_job.sh` | Connects through configured SSH transport and starts sparse processing |
| Station processor | `web/remote_station/scripts/process_colmap_sparse.sh` | Runs feature extraction, sequential/exhaustive matching and stock mapper |
| Status | `web/remote_station/get_station_status.sh` | Reads station job status |
| Health/metrics | `web/remote_station/get_station_metrics.sh` | Checks configured station through the same transport |
| Worker integration | `web/tools/sfm_remote_worker.php`, `launch_job()` | Invokes the sparse runner for production jobs |

Фактическая конфигурация в просмотренном `stations.conf`:

```text
STATION_HOST=10.0.1.8
STATION_USER=root
STATION_SSH_KEY=/root/.ssh/id_ed25519
STATION_BASE=/home/makler_storage
COLMAP_MODE=podman
COLMAP_IMAGE=docker.io/colmap/colmap:latest
COLMAP_SEQUENTIAL_OVERLAP=60
```

Этот контур предназначен для запуска COLMAP на отдельной GPU station, а не на web-server.

## 3. Stock COLMAP mechanisms found in code

Текущий runner и source checkout подтверждают наличие следующих штатных COLMAP mechanisms:

| Mechanism | Repository evidence | Current production use |
|---|---|---|
| Sequential temporal matching | `sequential_matcher`, `SequentialMatching.overlap` | Enabled, overlap 60 |
| Sequential loop detection | `SequentialMatching.loop_detection` | Supported by runner flag, disabled by baseline |
| Vocabulary-tree retrieval | `SequentialMatching.vocab_tree_path`, `vocab_tree_matcher` in COLMAP source/help contract | No configured vocab-tree path found in runner |
| Explicit candidate pairs | stock `matches_importer --match_type pairs` | Not part of production sparse runner |
| Exhaustive matching | `exhaustive_matcher` | Supported as alternate matcher, not suitable as target hybrid |
| Mapper/global BA | ordinary `mapper` | Used without source modification |

Доступность `SequentialMatching.loop_detection` и наличие compatible vocabulary tree на **installed GrafikStation COLMAP build** не удалось проверить из-за remote-access blocker. Repository source capability не принята за доказательство station runtime capability.

## 4. Isolation limitation in the existing runner

`run_colmap_sparse_job.sh` формирует пути как:

```text
$STATION_BASE/input/job_<id>/
$STATION_BASE/output/job_<id>/colmap/
$STATION_BASE/logs/job_<id>.nohup.log
$STATION_BASE/status/job_<id>.json
```

Поэтому обычный production runner нельзя использовать для этого audit без записи в production station namespace. По условиям задачи такая запись запрещена.

Корректный experiment launch должен был использовать тот же configured station transport, но со всеми input/database/model/log paths в:

```text
/tmp/insta3d_single_hybrid_loop/
```

Постоянный wrapper или remote script для этого не создавался, так как source/remote script changes запрещены.

## 5. Access check and exact blocker

Была запущена штатная read-only health command:

```bash
cd web/remote_station
./get_station_metrics.sh ./stations.conf
```

Она завершилась ошибками:

```text
Warning: Identity file /root/.ssh/id_ed25519 not accessible: Permission denied.
ssh: connect to host 10.0.1.8 port 22: Connection timed out
```

Блокер состоит из двух частей:

1. configured station key `/root/.ssh/id_ed25519` недоступен текущему execution environment;
2. configured private station address `10.0.1.8:22` не достигается из этого environment.

После этой ошибки новый SSH workflow, proxy through another server или local/web-server COLMAP substitute не использовались.

## 6. Commands that should have been run

После восстановления штатного station access первыми должны выполняться read-only capability checks через тот же configured transport:

```bash
podman run --rm \
  --device nvidia.com/gpu=all \
  --security-opt=label=disable \
  -v /tmp/insta3d_single_hybrid_loop:/tmp/insta3d_single_hybrid_loop \
  docker.io/colmap/colmap:latest \
  colmap sequential_matcher -h
```

На station нужно проверить:

```text
SequentialMatching.loop_detection
SequentialMatching.vocab_tree_path
vocab_tree_matcher
matches_importer
```

Если compatible vocab tree уже доступен, intended hybrid matching command должен сохранять baseline:

```bash
colmap sequential_matcher \
  --database_path /tmp/insta3d_single_hybrid_loop/hybrid/database.db \
  --FeatureMatching.use_gpu 1 \
  --SequentialMatching.overlap 60 \
  --SequentialMatching.loop_detection 1 \
  --SequentialMatching.vocab_tree_path <existing-compatible-vocab-tree>
```

Затем — неизменённый mapper:

```bash
colmap mapper \
  --database_path /tmp/insta3d_single_hybrid_loop/hybrid/database.db \
  --image_path /tmp/insta3d_single_hybrid_loop/frames \
  --output_path /tmp/insta3d_single_hybrid_loop/hybrid/sparse
```

Если station build не может использовать loop detection без отсутствующего vocab tree, минимальный experimental fallback — создать bounded candidate list и передать его stock:

```bash
colmap matches_importer \
  --database_path /tmp/insta3d_single_hybrid_loop/hybrid/database.db \
  --match_list_path /tmp/insta3d_single_hybrid_loop/hybrid/non_local_pairs.txt \
  --match_type pairs \
  --FeatureMatching.use_gpu 1
```

Алгоритм формирования bounded list не выбирался, потому что штатные retrieval capabilities installed build не удалось проверить. Это соответствует требованию не придумывать новый algorithm до проверки stock mechanisms.

## 7. Sequential / Exhaustive / Hybrid comparison

Hybrid results не подменены предположениями:

| Metric | Sequential | Exhaustive | Hybrid |
|---|---:|---:|---:|
| Components | 6 | 1 | Not run |
| Largest model images | 204 | 509 | Not run |
| Total registered images | 526 | 509 | Not run |
| Sparse points | 72,896 | 82,570 | Not run |
| Verified pairs | 2,145 | 11,744 | Not run |
| Long-range pairs, gap >300 | 0 | 479 | Not run |
| Endpoint coverage | No | Frames 1–547 | Not run |
| Normalized endpoint gap | Undefined | 2.09% | Not run |
| Mean reprojection error | ~0.85 px in largest model | 0.94 px | Not run |
| Trajectory-step outlier | Present across fragmented result | Present | Not run |
| Crossing/self-intersection proxy | 7 in A largest fragment | 20 over full B path | Not run |

Sequential and exhaustive values are carried from the completed previous controlled audit. No new baseline or exhaustive reconstruction was run.

## 8. Findings

### Loop-closure improvement

Нового доказательства улучшения loop closure нет, поскольку Hybrid не был запущен.

### Spiral/self-intersection

Нового доказательства уменьшения spiral/self-intersection нет. Никакая geometry не была создана или изменена в рамках этого audit.

### Side effects

Побочных эффектов reconstruction нет, так как processing не запускался. В production storage, database, capture artifacts и existing models записи не было.

## 9. Decision

```text
DECISION: INCONCLUSIVE
```

Это infrastructure-blocked result, а не отрицательный результат hybrid loop closure.

Hybrid не может быть классифицирован как promising или rejected без фактического station run и измерений.

## 10. Recommended next step

1. Восстановить доступ execution environment к configured key `/root/.ssh/id_ed25519` и private station address `10.0.1.8`.
2. Повторить `web/remote_station/get_station_metrics.sh web/remote_station/stations.conf`.
3. Через тот же штатный transport проверить installed Podman COLMAP loop-detection/vocab-tree options и наличие compatible vocabulary tree.
4. Запустить isolated experiment только под `/tmp/insta3d_single_hybrid_loop/`.
5. Не изменять production runner и не продвигать hybrid policy до получения connectivity **и** geometry-stability acceptance.

## 11. Experiment artifacts

```text
/tmp/insta3d_single_hybrid_loop/ was not created
No Hybrid database
No Hybrid sparse model
No Hybrid trajectory
No Hybrid PLY
No Dense artifacts
```

## Repository impact

В репозитории создан только этот Markdown-отчёт. Existing documentation, source code, Android, backend/PHP, remote scripts и production pipeline не изменялись.
