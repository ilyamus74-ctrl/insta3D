# SFM-MANUAL-VISUAL-ALIGN-A1 — Ready Sync Hotfix

## Status

```text
IMPLEMENTED
WEB DEPLOYMENT AND RUNTIME ACCEPTANCE PENDING
```

## Problem

The original A1 implementation used two independent inline ES modules.

The first module loaded Anchor and Source with top-level `await` and assigned
`window.sfmManualClouds` only after both PLY files finished loading.

The second module read `window.sfmManualClouds` immediately. It could therefore
run while the first module was suspended on PLY loading.

Observed result:

```text
Anchor viewer loaded
Source viewer loaded
third visual-alignment viewport not created
```

## Fix

The first module creates `window.sfmManualCloudsReady` before starting the two
PLY requests. It resolves the promise only after both geometries are ready.

The second module waits for that promise before creating the visual editor.

## Failure visibility

When loading fails or valid geometries remain unavailable, the page renders
`#visualAlignmentError` instead of failing only in the browser console.

## Scope

This hotfix does not modify PLY files, point-correspondence alignment, database
records, assembly finalization or visual-transform behavior.
