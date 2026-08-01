# APP-DUAL-PHONE-LM02.6.4 — contour-first operator UI and aspect-safe SLAVE

Baseline:

```text
adc4df024ca15eab2c7be4d1b9d19aac9af997d0
```

## Defects

1. LM02.6.3 correctly registered depth to the natural MASTER frame, but the DENSE
   fill remained visually dominant on a small phone display.
2. The SLAVE full-screen preview used CENTER_CROP, so a 16:9 analysis frame was
   enlarged and cropped on a portrait display and looked like digital zoom.

## Correction

* make OUTLINE contour-only and keep it as the default;
* add ASSIST with a weak DENSE layer;
* add HEATMAP for full metric coverage diagnostics;
* hide the metric legend and RECT DEPTH inset in OUTLINE;
* use the same paired frame and transform for all MASTER registered layers;
* render the sharp SLAVE frame with FIT_CENTER and zero crop;
* use only a dim center-crop copy as decorative background.

## Acceptance

* OUTLINE keeps doors, corners and object silhouettes recognizable;
* ASSIST adds subtle colour without covering the scene;
* HEATMAP retains the complete depth diagnostic view;
* SLAVE shows the same full analysis-frame field of view without stretching;
* media FPS, depth FPS, registration, pairing and calibration are unchanged.
