# APP-DUAL-PHONE-LM02.5 — dense preview and strict geometry coverage

Baseline:

```text
ff741e2654f1a45a7ebf3930c96f59a6f17d65ca
```

## Goal

Recover useful visual coverage on low-texture walls without making the
measurement-grade mask less trustworthy.

## Products

```text
RAW
    valid SGBM range only

DENSE
    relaxed 3.0 px left-right consistency
    lower texture threshold
    morphology close without destructive open
    current-frame spatial output

STRICT
    1.5 px left-right consistency
    original texture threshold
    morphology open/close
    STATIC/MOVING/RESET temporal consensus

CONF
    LOW    raw-only
    MEDIUM dense spatial
    HIGH   strict temporal and strong texture
```

## Projected texture

The pipeline may benefit from an external pseudo-random dot projector because
active stereo uses the added pattern as ordinary texture. Pattern geometry is not
decoded and does not need projector calibration.

The projector is optional. Visible patterns must be switched off before
texture-video capture. Only an appropriately certified eye-safe product may be
mounted on the rig.

## Acceptance

* DENSE coverage is separately visible from STRICT/stable coverage.
* Future geometry consumes STRICT/HIGH, not DENSE.
* Filter-funnel percentages identify the dominant loss stage.
* Existing bounded queues, cadence and thermal guard remain unchanged.
