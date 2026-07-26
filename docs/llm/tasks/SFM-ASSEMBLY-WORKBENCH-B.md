# SFM-ASSEMBLY-WORKBENCH-B

## Status

```text
NEXT
```

## Goal

Finish the source-selection and pair-direction contract introduced by
`SFM-ASSEMBLY-WORKBENCH-A`.

## Logical sources

A logical source is one of:

```text
remote dense component
accepted generated assembly
```

The UI must distinguish a logical source from its leaf component jobs.

## Explicit direction

The user selects:

```text
Anchor
Moving source
```

Direction must not depend on checkbox order or DOM order.

### Supported by the current backend

```text
remote Anchor + remote Moving source
accepted assembly Anchor + remote Moving source
```

The current manual API contract is:

```text
anchor_kind = remote | merge
source_kind = remote
```

### Deferred until the backend is extended

```text
remote Anchor + assembly Moving source
assembly Anchor + assembly Moving source
```

When one selected source is an assembly and the other is a remote component,
the assembly must become Anchor.

## Automatic assembly reuse

An accepted assembly selected for automatic reconstruction is expanded to its
unique leaf component DB jobs.

Example:

```text
Assembly #17 = Model 1 + Model 3 + Model 5

Assembly #17 + Model 8
→ Model 1 + Model 3 + Model 5 + Model 8
```

The merged PLY itself is not concatenated again.

Duplicate leaf jobs are removed before submission.

Automatic aligned merge remains available only when all unique leaves belong
to one sparse parent.

## Trust states

Selectable:

```text
accepted automatic assembly
accepted manual assembly
accepted manual incremental assembly
```

Not selectable:

```text
anchor-only
diagnostic
failed
rejected
missing PLY
invalid lineage
```

## Required result fields

```text
merge ID
creation timestamp
merge type
state
point count
leaf source jobs
leaf model IDs
included models
excluded models
message
Open
Download PLY
Result JSON
Use as source
```

Newest results are shown first.

## Actionable errors

```text
select exactly two logical sources
Moving source must be a remote component
assembly cannot be used as Moving source
sources belong to different capture sessions
automatic merge requires one sparse parent
assembly is not trusted
source is already included in the selected assembly
```

## Acceptance tests

1. Pair direction is independent of checkbox order.
2. Assembly plus remote opens with `anchor_kind=merge`.
3. Remote plus remote supports either explicit direction.
4. Assembly plus assembly is blocked with a clear reason.
5. Automatic reuse expands assembly lineage.
6. Duplicate leaf jobs are removed.
7. Anchor-only results cannot be selected.
8. Accepted assemblies remain reusable.
9. Legacy tools remain in a closed diagnostic section.
