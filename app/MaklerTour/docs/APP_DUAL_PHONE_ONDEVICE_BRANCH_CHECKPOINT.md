# Dual-phone on-device checkpoint

Frozen checkpoint: `fd952471e0af8d7717a08729a0d2befab0e46fba`.
Recommended archive branch:

```bash
git branch archive/dual-phone-ondevice-lm02-7a2 fd952471e0af8d7717a08729a0d2befab0e46fba
git push origin archive/dual-phone-ondevice-lm02-7a2
```

Preserved capabilities:

- phone-to-phone synchronized JPEG transport;
- clock watchdog and holdover;
- AUTO/U960/H640/Q480/B320 profiles;
- on-device rectification and StereoSGBM;
- confidence filtering and raw-camera overlay;
- diagnostic OUTLINE/ASSIST/HEAT views.

Known limitations to retain for future work:

- manual ULTRA is detailed but approximately 1–2 depth FPS;
- metric distance is underestimated in the observed 3–5 m range;
- saturated green diagnostic coverage is not semantic segmentation;
- depth only exists inside stereo overlap;
- weakly textured walls remain difficult;
- on-device result exposes preview maps rather than a durable raw 3D product.

The laptop branch does not delete or rewrite this implementation.
