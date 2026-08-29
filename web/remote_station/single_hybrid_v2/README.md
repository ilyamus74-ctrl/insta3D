# SINGLE Hybrid v2

Изолированный sparse-only эксперимент для `job_180237696`. Production pipeline, backend и deployment scripts не используются и не изменяются.

Pipeline: feature extraction → sequential matching (`overlap=60`, loop detection enabled) → bounded controlled long-range pairs → mapper → TXT exports and trajectory diagnostics. Dense reconstruction отсутствует.

```bash
experiments/single_hybrid_v2/run.sh \
  /mnt/storage/makler_pipeline/output/job_180237696/frames \
  /path/to/compatible/vocab_tree.bin
```

По умолчанию новый artifact root — `/tmp/insta3d_single_hybrid_v2`. Runner намеренно завершается с ошибкой, если этот путь уже существует: результаты предыдущего запуска не перезаписываются.

Controlled graph содержит пять гипотез: начало↔конец и четыре распределённые связи (10%↔60%, 20%↔70%, 30%↔80%, 40%↔90%). Для каждой гипотезы используются окна ±5 кадров. Это не exhaustive graph: для последовательности из 547 кадров верхняя граница — 520 candidate pairs, до удаления дублей и пар с gap ≤60.
