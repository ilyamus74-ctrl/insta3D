# APP-DUAL-PHONE-LM02.7B.2.1 — graceful stop and calibration handoff

Baseline: `63275333fa41985b0211e3b293cced1ae839b732`.

## Operator shutdown

The laptop dashboard exposes `Stop + pack JSON` and keyboard shortcuts `F8`
and `Alt+S`. The browser requests a graceful host shutdown instead of requiring
`Ctrl+C`.

After the C++ host exits, `scripts/run.sh` packages the exact current session
with `scripts/pack_session.sh`. Packaging is JSON-only by default and writes the
`.tar.zst` plus SHA-256 file outside the Git checkout.

Set `MAKLER_PACK_ON_EXIT=0` only when automatic packaging is intentionally
disabled.

## Calibration authority

Both Android capture clients remain `SLAVE` devices. `CAMERA_A` is the
authoritative carrier of the accepted dual-phone stereo calibration that was
previously stored on the phone acting as MASTER.

Its hello message includes the active calibration profile ID, rig revision and
the complete accepted calibration JSON. The host stores:

- `camera_a_hello.json`;
- `camera_b_hello.json`;
- `stereo_calibration.json` when CAMERA_A supplied a valid profile.

The diagnostic package includes these files. The laptop StereoSGBM stage must
use `stereo_calibration.json`; it must not infer metric geometry from the heat
map or from operator baseline alone.
