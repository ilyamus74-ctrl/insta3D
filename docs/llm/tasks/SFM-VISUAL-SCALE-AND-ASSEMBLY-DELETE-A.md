# SFM Visual Scale and Assembly Delete A

## Shared camera scale

Anchor and Source open with one shared camera radius. Each cloud remains
centered independently, but the apparent world scale is comparable.

Button:

```text
Одинаковый масштаб двух окон
```

The old individual Fit buttons automatically reapply shared scale.

## Match Moving scale

The combined visual editor adds:

```text
Match Moving scale to Anchor
```

It sets a positive uniform scale using the ratio of local bounding-box
diagonals. This is an initial estimate and still requires visual inspection.

## Assembly deletion

Every result in `Результаты сборок` receives:

```text
Удалить сборку
```

Deletion requires typing the merge ID.

It removes the database row and physical merge artifacts. Source Video SfM
component jobs and their PLY files are not deleted.

Deletion is blocked when another assembly references the selected assembly.

Artifacts are moved into an output-root quarantine before the database delete.
If the database operation fails, the paths are restored.
