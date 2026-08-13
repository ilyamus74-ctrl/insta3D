package com.maklertour.data.tof

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.hardware.usb.UsbConstants
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbDeviceConnection
import android.hardware.usb.UsbEndpoint
import android.hardware.usb.UsbInterface
import android.hardware.usb.UsbManager
import android.os.Build
import android.os.SystemClock
import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

enum class TofUsbStatus {
    STOPPED,
    SEARCHING,
    WAITING_PERMISSION,
    CONNECTING,
    STREAMING,
    ERROR,
}

data class TofUsbState(
    val status: TofUsbStatus = TofUsbStatus.STOPPED,
    val deviceId: Int? = null,
    val vendorId: Int? = null,
    val productId: Int? = null,
    val framesOk: Long = 0,
    val crcErrors: Long = 0,
    val malformedHeaders: Long = 0,
    val sequenceDrops: Long = 0,
    val lastSequence: Long? = null,
    val lastFrameHostElapsedRealtimeNs: Long? = null,
    val lastError: String? = null,
)

class TofUsbRuntime private constructor(context: Context) {
    private val appContext = context.applicationContext
    private val usbManager = appContext.getSystemService(Context.USB_SERVICE) as UsbManager
    private val parser = TofFrameV1Parser()

    private val _state = MutableStateFlow(TofUsbState())
    val state: StateFlow<TofUsbState> = _state

    private val _latestFrame = MutableStateFlow<TofFrameV1?>(null)
    val latestFrame: StateFlow<TofFrameV1?> = _latestFrame

    private val lifecycleLock = Any()
    private var started = false
    private var scope: CoroutineScope? = null
    private var monitorJob: Job? = null
    private var readJob: Job? = null
    private var pendingPermissionDeviceId: Int? = null
    internal fun handleUsbPermissionResult(intent: Intent) {
        if (intent.action != ACTION_USB_PERMISSION) return

        val device = intent.usbDeviceExtra()
        val granted = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false)
        pendingPermissionDeviceId = null

        if (device == null) {
            setError("USB permission result without device")
            return
        }

        if (!granted) {
            _state.value = _state.value.copy(
                status = TofUsbStatus.ERROR,
                deviceId = device.deviceId,
                vendorId = device.vendorId,
                productId = device.productId,
                lastError = "USB permission denied",
            )
            Log.w(TAG, "USB permission denied deviceId=${device.deviceId}")
            return
        }

        Log.i(
            TAG,
            "USB permission granted deviceId=${device.deviceId} " +
                "vid=0x${device.vendorId.toString(16)} pid=0x${device.productId.toString(16)}",
        )
        launchConnection(device)
    }

    fun start() {
        synchronized(lifecycleLock) {
            if (started) return
            started = true
            parser.reset()

            val newScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
            scope = newScope
            _state.value = _state.value.copy(
                status = TofUsbStatus.SEARCHING,
                lastError = null,
            )

            monitorJob = newScope.launch {
                while (isActive) {
                    scanAttachedDevices()
                    delay(SCAN_INTERVAL_MS)
                }
            }
        }
    }

    fun refreshAttachedDevices() {
        scope?.launch { scanAttachedDevices() }
    }

    fun stop() {
        synchronized(lifecycleLock) {
            if (!started) return
            started = false

            monitorJob?.cancel()
            readJob?.cancel()
            monitorJob = null
            readJob = null
            pendingPermissionDeviceId = null

            scope?.cancel()
            scope = null

            parser.reset()
            _latestFrame.value = null
            _state.value = _state.value.copy(status = TofUsbStatus.STOPPED)
        }
    }

    fun lastFrameAgeMs(nowElapsedRealtimeNs: Long = SystemClock.elapsedRealtimeNanos()): Long? {
        val last = _state.value.lastFrameHostElapsedRealtimeNs ?: return null
        return ((nowElapsedRealtimeNs - last).coerceAtLeast(0L)) / 1_000_000L
    }

    private fun scanAttachedDevices() {
        if (!started) return
        if (readJob?.isActive == true) return
        if (pendingPermissionDeviceId != null) return

        val candidate = usbManager.deviceList.values
            .mapNotNull { device -> findPort(device)?.let { device to it } }
            .sortedByDescending { (device, _) -> device.vendorId == RASPBERRY_PI_USB_VID }
            .firstOrNull()
            ?: run {
                if (_state.value.status != TofUsbStatus.SEARCHING) {
                    _state.value = _state.value.copy(
                        status = TofUsbStatus.SEARCHING,
                        deviceId = null,
                        vendorId = null,
                        productId = null,
                    )
                }
                return
            }

        val device = candidate.first

        if (usbManager.hasPermission(device)) {
            launchConnection(device)
        } else {
            requestPermission(device)
        }
    }

    private fun requestPermission(device: UsbDevice) {
        if (pendingPermissionDeviceId == device.deviceId) return
        pendingPermissionDeviceId = device.deviceId

        _state.value = _state.value.copy(
            status = TofUsbStatus.WAITING_PERMISSION,
            deviceId = device.deviceId,
            vendorId = device.vendorId,
            productId = device.productId,
            lastError = null,
        )

        val permissionIntent = Intent(
            appContext,
            TofUsbPermissionReceiver::class.java,
        ).setAction(ACTION_USB_PERMISSION)

        val mutabilityFlag =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0

        val pendingIntent = PendingIntent.getBroadcast(
            appContext,
            USB_PERMISSION_REQUEST_CODE,
            permissionIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or mutabilityFlag,
        )

        Log.i(
            TAG,
            "requesting USB permission deviceId=${device.deviceId} " +
                "vid=0x${device.vendorId.toString(16)} pid=0x${device.productId.toString(16)}",
        )
        usbManager.requestPermission(device, pendingIntent)
    }

    private fun launchConnection(device: UsbDevice) {
        val activeScope = scope ?: return
        if (readJob?.isActive == true) return

        readJob = activeScope.launch {
            runDeviceSession(device)
        }
    }

    private suspend fun runDeviceSession(device: UsbDevice) {
        val port = findPort(device)
        if (port == null) {
            setError("USB device has no CDC bulk IN/OUT interface")
            return
        }

        _state.value = _state.value.copy(
            status = TofUsbStatus.CONNECTING,
            deviceId = device.deviceId,
            vendorId = device.vendorId,
            productId = device.productId,
            lastError = null,
        )

        val connection = usbManager.openDevice(device)
        if (connection == null) {
            setError("UsbManager.openDevice() returned null")
            return
        }

        var communicationClaimed = false
        var dataClaimed = false

        try {
            if (port.communicationInterface != null &&
                port.communicationInterface.id != port.dataInterface.id
            ) {
                communicationClaimed = connection.claimInterface(
                    port.communicationInterface,
                    true,
                )
                if (!communicationClaimed) {
                    error("failed to claim CDC communication interface")
                }
            }

            dataClaimed = connection.claimInterface(port.dataInterface, true)
            if (!dataClaimed) {
                error("failed to claim CDC data interface")
            }

            configureCdc(connection, port.communicationInterface ?: port.dataInterface)

            delay(CDC_SETTLE_MS)

            if (!bulkWrite(connection, port.outEndpoint, "print off\n")) {
                error("failed to send 'print off'")
            }
            if (!bulkWrite(connection, port.outEndpoint, "stream 0\n")) {
                error("failed to send 'stream 0'")
            }

            parser.reset()
            TofActiveClockSync.reset()
            _state.value = _state.value.copy(
                status = TofUsbStatus.STREAMING,
                lastError = null,
            )

            Log.i(
                TAG,
                "CDC streaming started deviceId=${device.deviceId} " +
                    "dataIf=${port.dataInterface.id} " +
                    "in=0x${port.inEndpoint.address.toString(16)} " +
                    "out=0x${port.outEndpoint.address.toString(16)}",
            )

            val readBuffer = ByteArray(USB_READ_BUFFER_BYTES)
            var nextSyncNs = SystemClock.elapsedRealtimeNanos() + ACTIVE_SYNC_INITIAL_DELAY_NS

            while (started && scope?.isActive == true) {
                val read = connection.bulkTransfer(
                    port.inEndpoint,
                    readBuffer,
                    readBuffer.size,
                    USB_READ_TIMEOUT_MS,
                )

                if (read <= 0) {
                    if (!usbManager.deviceList.containsKey(device.deviceName)) {
                        throw IllegalStateException("USB device detached")
                    }
                } else {
                    val hostReceivedNs = SystemClock.elapsedRealtimeNanos()
                    val batch = parser.feed(
                        chunk = readBuffer,
                        length = read,
                        hostReceivedElapsedRealtimeNs = hostReceivedNs,
                    )

                    if (batch.crcErrors != 0 || batch.malformedHeaders != 0) {
                        _state.value = _state.value.copy(
                            crcErrors = _state.value.crcErrors + batch.crcErrors,
                            malformedHeaders = _state.value.malformedHeaders + batch.malformedHeaders,
                        )
                    }

                    for (frame in batch.frames) {
                        publishFrame(frame)
                    }

                    for (reply in batch.syncReplies) {
                        val sync = TofActiveClockSync.observe(reply) ?: continue
                        if (sync.sampleCount <= 3 || sync.sampleCount % 5 == 0) {
                            Log.i(
                                TAG,
                                "TOF_SYNC_V1 phase=${sync.phase} syncN=${sync.sampleCount} " +
                                    "lastRttUs=${sync.lastRttUs ?: "-"} " +
                                    "bestRttUs=${sync.bestRttUs ?: "-"} " +
                                    "rttP50Us=${sync.rttP50Us ?: "-"} " +
                                    "rttP95Us=${sync.rttP95Us ?: "-"} " +
                                    "driftPpm=${sync.driftPpm?.toLong() ?: "-"} " +
                                    "modelRmsUs=${sync.modelRmsUs?.toLong() ?: "-"}",
                            )
                        }
                    }
                }

                val nowNs = SystemClock.elapsedRealtimeNanos()
                if (nowNs >= nextSyncNs) {
                    val nonce = TofActiveClockSync.beginRequest(nowNs)
                    if (!bulkWrite(connection, port.outEndpoint, "sync $nonce\n")) {
                        TofActiveClockSync.cancelRequest(nonce)
                        Log.w(TAG, "TOF_SYNC_V1 request write failed nonce=$nonce")
                    }
                    nextSyncNs = nowNs + ACTIVE_SYNC_INTERVAL_NS
                }
            }
        } catch (t: Throwable) {
            if (started) {
                Log.e(TAG, "USB session failed", t)
                setError(t.message ?: t::class.java.simpleName)
            }
        } finally {
            runCatching { bulkWrite(connection, port.outEndpoint, "stream off\n") }

            if (dataClaimed) {
                runCatching { connection.releaseInterface(port.dataInterface) }
            }
            if (communicationClaimed && port.communicationInterface != null) {
                runCatching { connection.releaseInterface(port.communicationInterface) }
            }
            runCatching { connection.close() }

            parser.reset()

            if (started) {
                _state.value = _state.value.copy(status = TofUsbStatus.SEARCHING)
            }
        }
    }

    private fun publishFrame(frame: TofFrameV1) {
        val before = _state.value
        val dropped = sequenceGap(before.lastSequence, frame.sequence)
        val frameCount = before.framesOk + 1
        val clock = TofClockDiagnostics.observe(frame)

        _latestFrame.value = frame
        _state.value = before.copy(
            status = TofUsbStatus.STREAMING,
            framesOk = frameCount,
            sequenceDrops = before.sequenceDrops + dropped,
            lastSequence = frame.sequence,
            lastFrameHostElapsedRealtimeNs = frame.hostReceivedElapsedRealtimeNs,
            lastError = null,
        )

        if (frameCount == 1L || frameCount % LOG_EVERY_FRAMES == 0L) {
            Log.i(
                TAG,
                "TOF_FRAME_V1 frames=$frameCount crc=${_state.value.crcErrors} " +
                    "drops=${_state.value.sequenceDrops} seq=${frame.sequence} " +
                    "${frame.width}x${frame.height}@${frame.frequencyHz}Hz " +
                    "temp=${frame.siliconTemperatureC}C irq=${frame.irqTimestampValid} " +
                    TofClockDiagnostics.logSuffix(clock),
            )
        }
    }

    private fun sequenceGap(previous: Long?, current: Long): Long {
        if (previous == null) return 0
        val delta = (current - previous) and UINT32_MASK
        return if (delta in 2 until UINT32_HALF_RANGE) delta - 1 else 0
    }

    private fun configureCdc(
        connection: UsbDeviceConnection,
        communicationInterface: UsbInterface,
    ) {
        val lineCoding1152008N1 = byteArrayOf(
            0x00,
            0xC2.toByte(),
            0x01,
            0x00,
            0x00,
            0x00,
            0x08,
        )

        connection.controlTransfer(
            CDC_CLASS_INTERFACE_OUT,
            CDC_SET_LINE_CODING,
            0,
            communicationInterface.id,
            lineCoding1152008N1,
            lineCoding1152008N1.size,
            USB_CONTROL_TIMEOUT_MS,
        )

        val controlResult = connection.controlTransfer(
            CDC_CLASS_INTERFACE_OUT,
            CDC_SET_CONTROL_LINE_STATE,
            CDC_CONTROL_DTR or CDC_CONTROL_RTS,
            communicationInterface.id,
            null,
            0,
            USB_CONTROL_TIMEOUT_MS,
        )

        if (controlResult < 0) {
            error("failed to assert CDC DTR/RTS")
        }
    }

    private fun bulkWrite(
        connection: UsbDeviceConnection,
        endpoint: UsbEndpoint,
        text: String,
    ): Boolean {
        val bytes = text.toByteArray(Charsets.US_ASCII)
        val written = connection.bulkTransfer(
            endpoint,
            bytes,
            bytes.size,
            USB_WRITE_TIMEOUT_MS,
        )
        return written == bytes.size
    }

    private fun findPort(device: UsbDevice): UsbPort? {
        val communicationInterface =
            (0 until device.interfaceCount)
                .map { device.getInterface(it) }
                .firstOrNull { it.interfaceClass == UsbConstants.USB_CLASS_COMM }

        val candidates = mutableListOf<UsbPort>()

        for (i in 0 until device.interfaceCount) {
            val usbInterface = device.getInterface(i)
            var bulkIn: UsbEndpoint? = null
            var bulkOut: UsbEndpoint? = null

            for (e in 0 until usbInterface.endpointCount) {
                val endpoint = usbInterface.getEndpoint(e)
                if (endpoint.type != UsbConstants.USB_ENDPOINT_XFER_BULK) continue

                when (endpoint.direction) {
                    UsbConstants.USB_DIR_IN -> bulkIn = endpoint
                    UsbConstants.USB_DIR_OUT -> bulkOut = endpoint
                }
            }

            if (bulkIn != null && bulkOut != null) {
                candidates += UsbPort(
                    communicationInterface = communicationInterface,
                    dataInterface = usbInterface,
                    inEndpoint = bulkIn,
                    outEndpoint = bulkOut,
                )
            }
        }

        return candidates.firstOrNull {
            it.dataInterface.interfaceClass == UsbConstants.USB_CLASS_CDC_DATA
        } ?: candidates.firstOrNull()
    }

    private fun setError(message: String) {
        _state.value = _state.value.copy(
            status = TofUsbStatus.ERROR,
            lastError = message,
        )
    }

    @Suppress("DEPRECATION")
    private fun Intent.usbDeviceExtra(): UsbDevice? =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            getParcelableExtra(UsbManager.EXTRA_DEVICE, UsbDevice::class.java)
        } else {
            getParcelableExtra(UsbManager.EXTRA_DEVICE)
        }

    private data class UsbPort(
        val communicationInterface: UsbInterface?,
        val dataInterface: UsbInterface,
        val inEndpoint: UsbEndpoint,
        val outEndpoint: UsbEndpoint,
    )

    companion object {
        private const val TAG = "TofUsbRuntime"
        private const val ACTION_USB_PERMISSION = "com.maklertour.USB_PERMISSION_TOF"
        private const val USB_PERMISSION_REQUEST_CODE = 5308

        private const val RASPBERRY_PI_USB_VID = 0x2E8A
        private const val SCAN_INTERVAL_MS = 1000L
        private const val CDC_SETTLE_MS = 100L
        private const val USB_READ_BUFFER_BYTES = 4096
        private const val USB_READ_TIMEOUT_MS = 250
        private const val USB_WRITE_TIMEOUT_MS = 1000
        private const val USB_CONTROL_TIMEOUT_MS = 1000
        private const val LOG_EVERY_FRAMES = 30L

        private const val ACTIVE_SYNC_INITIAL_DELAY_NS = 500_000_000L
        private const val ACTIVE_SYNC_INTERVAL_NS = 1_000_000_000L

        private const val CDC_CLASS_INTERFACE_OUT = 0x21
        private const val CDC_SET_LINE_CODING = 0x20
        private const val CDC_SET_CONTROL_LINE_STATE = 0x22
        private const val CDC_CONTROL_DTR = 0x01
        private const val CDC_CONTROL_RTS = 0x02

        private const val UINT32_MASK = 0xffff_ffffL
        private const val UINT32_HALF_RANGE = 0x8000_0000L

        @Volatile
        private var instance: TofUsbRuntime? = null

        fun get(context: Context): TofUsbRuntime =
            instance ?: synchronized(this) {
                instance ?: TofUsbRuntime(context).also { instance = it }
            }
    }
}
