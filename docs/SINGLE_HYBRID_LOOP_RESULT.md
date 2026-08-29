# SINGLE Hybrid Loop Closure v1 — Result

## 1. Dataset

Reference dataset: `job_180237696`, последовательность кадров SINGLE capture. В Hybrid v1 использовались COLMAP feature extraction, `sequential_matcher` с включённым loop detection и stock mapper. Dense reconstruction в рамках сравнения не рассматривается.

## 2. Comparison

| Metric | Sequential baseline | Exhaustive baseline | Hybrid v1 |
|---|---:|---:|---:|
| Registered images | 204 | 509 | 440 |
| Components | 6 | 1 | 3 |
| Largest model | 204 images | 509 images | 440 images (`model/0`) |
| Frame coverage of largest model | Not recorded here | Not recorded here | `frame_000079.jpg` … `frame_000547.jpg` |
| Matching policy | Sequential | Full exhaustive | Sequential + loop detection |

Значение `registered images` для fragmented baseline трактуется как размер largest model, согласно переданным результатам эксперимента, а не как сумма регистраций по потенциально пересекающимся моделям.

## 3. Что доказал эксперимент

Hybrid loop detection существенно улучшил visual connectivity относительно sequential baseline: largest model вырос с 204 до 440 кадров, а число components уменьшилось с 6 до 3. Следовательно, ограниченные non-local visual associations способны соединить значительную часть траектории без полного exhaustive graph.

## 4. Что эксперимент не доказал

Результат не доказывает физический loop closure. Камера или объект не обязаны возвращаться в исходную пространственную точку; близость либо связь визуальных дескрипторов сама по себе не является метрическим или физическим ограничением. Эксперимент также не доказывает отсутствие trajectory drift, корректный масштаб, отсутствие self-intersection или геометрическую точность. Остаточная проблема классифицируется как trajectory drift / отсутствие физических priors.

## 5. Следующие шаги

1. Hybrid v2: controlled long-range pairs — проверить ограниченный детерминированный набор дальних визуальных связей без exhaustive matcher.
2. IMU pose prior experiment — проверить, стабилизирует ли orientation/pose prior траекторию там, где одной визуальной связности недостаточно.
3. ToF metric constraint experiment — проверить метрическое ограничение масштаба и геометрии независимым depth/range сигналом.
