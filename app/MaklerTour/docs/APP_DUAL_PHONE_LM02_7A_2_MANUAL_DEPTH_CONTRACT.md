# APP dual-phone LM02.7A.2 — clock watchdog and manual depth profiles

Baseline:

```text
e2eab72cce09ad7497e80b8fb25d8b64aef31669
```

## Clock continuity

The established TCP control pairing remains active. Clock recovery must never
require the operator to pair the phones again.

The MASTER keeps the last valid clock model as a bounded holdover for up to five
minutes while it performs fast UDP recovery probes. Three consecutive incomplete
rounds refresh the connected UDP socket. Recovery state is also sent to SLAVE
through the existing control channel.

After the holdover expires, depth pairing is blocked until a new valid UDP model
is accepted. The watchdog continues retrying without restarting control pairing.

## Depth profile selection

The operator can select:

```text
AUTO
MANUAL ULTRA_960
MANUAL HIGH_640
MANUAL QUALITY_480
MANUAL BALANCED_320
```

AUTO retains p95-based adaptive downgrade and upgrade. Manual modes ignore
timing-based downgrade so a high-quality low-FPS slideshow can be evaluated.
Android thermal floors remain authoritative in every mode; HOT may select the
throttled profile and CRITICAL may pause depth.

## Overlay transition safety

A profile transition temporarily suppresses registered DENSE and STRICT layers.
The layers become visible only when the published work dimensions match the
active profile. This prevents an old low-resolution rectangle from remaining
over the natural MASTER frame while a new map is being calculated.

The depth layer is not stretched to invent coverage outside the calibrated
stereo overlap. LM02.7B will move high-resolution processing to a CPU laptop
after phone-only ULTRA testing is complete.
