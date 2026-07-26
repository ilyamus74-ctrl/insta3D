# SFM-ASSEMBLY-WORKBENCH-A

## Goal

Separate raw Video SfM component models from assembly results and provide one
predictable model-building workflow.

## Generated Models layout

```text
Исходные модели Video SfM
→ grouped by source video and pipeline Run
→ component checkboxes

Создать новую сборку
→ selected source summary
→ anchor
→ automatic aligned merge
→ manual pair alignment

Результаты сборок
→ newest first
→ ACCEPTED / ANCHOR ONLY / FAILED
→ included/excluded models
→ use accepted assembly as a source

Legacy / дополнительные инструменты
→ previous diagnostic forms and raw job table
```

## Existing assembly as a new source

An accepted assembly is not blindly concatenated with a new cloud.

For automatic merge, the assembly is expanded to its unique leaf component
DB jobs. Duplicate leaf jobs are removed before submitting the existing
`aligned_merge_generated_dense_clouds` action.

For manual merge, the accepted assembly is passed to the existing manual
alignment editor as `anchor_kind=merge` or `source_kind=merge`.

Anchor-only, failed, rejected and diagnostic assemblies cannot be selected as
trusted assembly sources.

## Limitations

Automatic aligned merge still requires all selected leaf component jobs to
belong to one sparse reconstruction. Select exactly two logical sources to use
manual alignment across different reconstructions.

## Test

```bash
php web/tests/sfm_assembly_workbench_test.php
```

Expected:

```text
OK
```
