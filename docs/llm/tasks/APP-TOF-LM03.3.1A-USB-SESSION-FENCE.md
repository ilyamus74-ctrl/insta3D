# LM03.3.1a — USB session lifecycle fence

## Status

```text
REPOSITORY BASELINE: b8c70f46a89cf336961adc7ebf6aa2656fbd11e1
LM03.3.1a: CLOSED
```

## Incident

The active clock model itself remained stable under camera load, but the live-stereo
transition exposed overlapping USB CDC sessions.

Observed pattern:

```text
old session streaming
new CDC streaming started
old thread continues TOF_SYNC_V1 writes
TOF_SYNC_V1 request write failed ...
crc counter +1
sequenceDrops +1
```

The root cause is lifecycle coupling:

```text
stop()
  -> cancel old readJob
  -> set readJob = null
  -> clear global scope

new Activity
  -> start()
  -> install a new global scope

old coroutine
  -> loop condition reads the new global scope
  -> can continue running
```

The global parser also made any brief overlap unsafe.

## Fix

LM03.3.1a introduces a monotonically increasing lifecycle generation.

Every USB session captures its generation when launched. It may read, publish,
write sync commands, report errors or update state only while that generation is
current.

Additional rules:

```text
TofFrameV1Parser is local to one USB session
stop() invalidates generation before coroutine cancellation
stop() closes the active UsbDeviceConnection before returning
closing the connection unblocks a pending bulkTransfer
the read loop checks currentCoroutineContext().isActive
the read loop never reads the replacement global scope
a stale session never sends "stream off"
a stale session never resets/publishes parser or StateFlow data
old finally blocks cannot clear the replacement activeConnection
```

`MainActivity` also does not stop the process-scoped ToF runtime during a normal
Android configuration recreation (`isChangingConfigurations == true`).

## Acceptance

Repeat live stereo for at least 2 minutes.

Expected log:

```text
one active CDC session generation at a time
TOF_FRAME_V1 8x8@15Hz
TOF_SYNC_V1 phase=READY
no stale-generation request-write failures
CRC counter does not increase
sequenceDrops counter does not increase
```

A pre-existing cumulative CRC/drop count is acceptable only if it does not increase
during the test.

## Closeout

Repeated CAMERA_A / MASTER runs reached live CameraX + IMU + ToF operation with a
single current USB generation, no stale-generation write pattern and clean
transport counters:

```text
crc=0
drops=0
TOF_SYNC_V1 phase=READY
```

LM03.3.1a is therefore CLOSED. LM03.3.2 may rely on the process-scoped ToF
runtime and its active clock mapping.
