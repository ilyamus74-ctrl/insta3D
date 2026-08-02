# APP-DUAL-PHONE-LM02.7A.2 — watchdog, manual profiles, safe overlay

Baseline: `e2eab72cce09ad7497e80b8fb25d8b64aef31669`

## Goal

Keep clock synchronization self-healing without control re-pairing and expose a
manual phone-only depth quality test before CPU laptop offload.

## Acceptance

* TCP control pairing is not restarted by clock recovery;
* incomplete UDP rounds retry every 750 ms;
* three incomplete rounds refresh the UDP socket;
* the last valid clock model provides bounded five-minute holdover;
* watchdog state is forwarded to SLAVE;
* AUTO keeps adaptive p95 downgrade;
* U960, H640, Q480 and B320 disable timing-based downgrade;
* all manual modes retain thermal floors;
* profile buttons show selected and active profiles;
* stale overlay is hidden during selection and automatic profile transitions;
* overlay dimensions must match the active profile before display;
* no queue becomes unbounded.

## Test

Record clock recovery after idle, selected/active profile, processing p50/p95,
depth FPS, thermal state and the first overlay after every profile transition.
