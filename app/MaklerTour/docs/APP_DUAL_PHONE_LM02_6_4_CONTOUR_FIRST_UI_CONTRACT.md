# APP dual-phone LM02.6.4 contour-first UI contract

Baseline:

```text
adc4df024ca15eab2c7be4d1b9d19aac9af997d0
```

## Operator modes

```text
OUTLINE  natural paired MASTER frame + STRICT boundaries, no DENSE fill
ASSIST   OUTLINE + weak DENSE metric fill
HEATMAP  full registered DENSE map + STRICT + metric legend + RECT DEPTH inset
```

OUTLINE is the default operator mode. Room corners, door frames and object
silhouettes remain visually dominant. DENSE colour is diagnostic assistance and
must not obscure the natural image by default.

All registered layers use the exact paired MASTER frame, identical rotation and
one shared aspect-preserving center-crop transform. This patch does not change
stereo rectification, K/D/R/T, depth values, pairing, thermal policy or the
registered raw-camera projection introduced by LM02.6.3.

## SLAVE preview

The sharp SLAVE preview uses FIT_CENTER and therefore displays the complete
transported frame with zero crop and no non-uniform scaling. A dim center-crop
copy may fill unused screen area behind it, but it is decorative and must never
replace or geometrically transform the sharp foreground.

The SLAVE information panel reports source dimensions and `FIT_CENTER · crop 0%`.
