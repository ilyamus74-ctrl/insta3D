# APP dual-phone LM02.6.2 native-aspect and stable-camera contract

Baseline:

```text
7b0d0b0390aa022488129955c04330a9a49cd392
```

## Native processing aspect

The post-rotation rectified frame is fitted inside the active performance
profile envelope with one uniform scale:

```text
scale = min(1, maxWidth/sourceWidth, maxHeight/sourceHeight)
```

It is never resized independently on X and Y. A 270×360 rectified frame remains
270×360 inside a 270×480 envelope instead of being stretched to 270×480.

The effective focal scale continues to use:

```text
workMaster.cols / depthMaster.cols
```

## Stable operator view

The full-screen human-readable background is always the natural MASTER camera
frame. It must not switch to `rectifiedMasterJpeg` when the first depth result
arrives.

Rectified MASTER, DENSE and STRICT remain pixel-registered and are displayed in
the dedicated `RECT DEPTH` diagnostic inset. They are not projected onto the
natural camera frame until an explicit inverse-rectification mapping exists.

This separation prevents:

* visible field-of-view jumps at first depth;
* misleading depth placement over raw camera pixels;
* distorted door frames and room corners;
* accidental use of UI stretching as geometry.

## Invariants

* stereo K/D/R/T and `stereoRectify` stay unchanged;
* the vertical-baseline processing rotation stays unchanged;
* no post-rotation non-uniform scaling is allowed;
* RAW, DENSE, STRICT and CONF retain identical native processing dimensions;
* the natural camera remains readable during WAIT CLOCK and WAIT FRAMES;
* queues, pairing, freshness, thermal control and metric colours are unchanged.
