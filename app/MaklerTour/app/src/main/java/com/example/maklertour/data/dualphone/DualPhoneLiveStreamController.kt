package com.example.maklertour.data.dualphone

data class DualPhoneLiveStreamOwner(
    val sessionUuid: String,
    val dualCaptureId: String,
    val localRole: String,
    val peerIdentity: String,
    val cameraIdentity: String,
    val recordingModeIdentity: String,
    val calibrationIdentity: String,
    val rigMountRevision: String,
    val captureMode: DualPhoneLiveStreamMode,
    val streamId: String,
) {
    init {
        require(sessionUuid.isNotBlank()) { "sessionUuid is required" }
        require(dualCaptureId.isNotBlank()) { "dualCaptureId is required" }
        require(localRole.isNotBlank()) { "localRole is required" }
        require(peerIdentity.isNotBlank()) { "peerIdentity is required" }
        require(cameraIdentity.isNotBlank()) { "cameraIdentity is required" }
        require(recordingModeIdentity.isNotBlank()) { "recordingModeIdentity is required" }
        require(calibrationIdentity.isNotBlank()) { "calibrationIdentity is required" }
        require(rigMountRevision.isNotBlank()) { "rigMountRevision is required" }
        require(streamId.isNotBlank()) { "streamId is required" }
    }
}

data class DualPhoneLiveStreamSnapshot(
    val state: DualPhoneLiveStreamState = DualPhoneLiveStreamState.DISABLED,
    val owner: DualPhoneLiveStreamOwner? = null,
    val stats: DualPhoneLiveStreamStats = DualPhoneLiveStreamStats(),
)

/**
 * LM01A-1 session-owned stream lifecycle.
 *
 * This controller contains no socket, image, encoder or CameraX work. It protects the
 * ownership boundary that later transport slices must obey.
 */
class DualPhoneLiveStreamController {
    private var currentSnapshot = DualPhoneLiveStreamSnapshot()

    val snapshot: DualPhoneLiveStreamSnapshot
        @Synchronized get() = currentSnapshot

    /**
     * Selects the complete stream owner and enters PREPARING.
     *
     * SYNC_VIDEO explicitly keeps live streaming disabled and clears any old owner.
     */
    @Synchronized
    fun prepare(owner: DualPhoneLiveStreamOwner): Boolean {
        if (!owner.captureMode.streamEnabled) {
            releaseInternal()
            return false
        }

        if (
            currentSnapshot.owner == owner &&
            currentSnapshot.state in ACTIVE_OR_PREPARING_STATES
        ) {
            return true
        }

        currentSnapshot = DualPhoneLiveStreamSnapshot(
            state = DualPhoneLiveStreamState.PREPARING,
            owner = owner,
            stats = DualPhoneLiveStreamStats(),
        )
        return true
    }

    @Synchronized
    fun markReady(streamId: String): Boolean = transition(
        streamId = streamId,
        allowedFrom = setOf(
            DualPhoneLiveStreamState.PREPARING,
            DualPhoneLiveStreamState.RECONNECTING,
        ),
        target = DualPhoneLiveStreamState.READY,
    )

    @Synchronized
    fun start(streamId: String): Boolean = transition(
        streamId = streamId,
        allowedFrom = setOf(DualPhoneLiveStreamState.READY),
        target = DualPhoneLiveStreamState.STREAMING,
    )

    @Synchronized
    fun beginStop(streamId: String): Boolean = transition(
        streamId = streamId,
        allowedFrom = setOf(
            DualPhoneLiveStreamState.PREPARING,
            DualPhoneLiveStreamState.READY,
            DualPhoneLiveStreamState.STREAMING,
            DualPhoneLiveStreamState.DEGRADED,
            DualPhoneLiveStreamState.RECONNECTING,
            DualPhoneLiveStreamState.FAILED,
        ),
        target = DualPhoneLiveStreamState.STOPPING,
    )

    @Synchronized
    fun completeStop(streamId: String): Boolean = transition(
        streamId = streamId,
        allowedFrom = setOf(DualPhoneLiveStreamState.STOPPING),
        target = DualPhoneLiveStreamState.STOPPED,
    )

    @Synchronized
    fun markDegraded(streamId: String, reason: String): Boolean {
        if (reason.isBlank()) return false
        if (!transition(
                streamId = streamId,
                allowedFrom = setOf(
                    DualPhoneLiveStreamState.READY,
                    DualPhoneLiveStreamState.STREAMING,
                ),
                target = DualPhoneLiveStreamState.DEGRADED,
            )
        ) {
            return false
        }
        currentSnapshot = currentSnapshot.copy(
            stats = currentSnapshot.stats.copy(lastError = reason),
        )
        return true
    }

    @Synchronized
    fun markFailed(streamId: String, reason: String): Boolean {
        if (reason.isBlank() || !matchesOwner(streamId)) return false
        if (currentSnapshot.state in setOf(
                DualPhoneLiveStreamState.DISABLED,
                DualPhoneLiveStreamState.STOPPED,
            )
        ) {
            return false
        }
        currentSnapshot = currentSnapshot.copy(
            state = DualPhoneLiveStreamState.FAILED,
            stats = currentSnapshot.stats.copy(lastError = reason),
        )
        return true
    }

    @Synchronized
    fun markReconnecting(streamId: String): Boolean {
        if (!transition(
                streamId = streamId,
                allowedFrom = setOf(
                    DualPhoneLiveStreamState.DEGRADED,
                    DualPhoneLiveStreamState.FAILED,
                ),
                target = DualPhoneLiveStreamState.RECONNECTING,
            )
        ) {
            return false
        }
        currentSnapshot = currentSnapshot.copy(
            stats = currentSnapshot.stats.copy(
                connectionRestarts = currentSnapshot.stats.connectionRestarts + 1L,
            ),
        )
        return true
    }

    /**
     * Releases a stream when the selected session boundary no longer matches.
     * No new stream is started implicitly.
     */
    @Synchronized
    fun reconcileOwner(expectedOwner: DualPhoneLiveStreamOwner?): Boolean {
        val currentOwner = currentSnapshot.owner ?: return false
        if (currentOwner == expectedOwner) return false
        releaseInternal()
        return true
    }

    @Synchronized
    fun isOwnedBy(
        sessionUuid: String,
        dualCaptureId: String,
        streamId: String,
    ): Boolean {
        val owner = currentSnapshot.owner ?: return false
        return owner.sessionUuid == sessionUuid &&
            owner.dualCaptureId == dualCaptureId &&
            owner.streamId == streamId
    }

    @Synchronized
    fun release() {
        releaseInternal()
    }

    private fun transition(
        streamId: String,
        allowedFrom: Set<DualPhoneLiveStreamState>,
        target: DualPhoneLiveStreamState,
    ): Boolean {
        if (!matchesOwner(streamId) || currentSnapshot.state !in allowedFrom) return false
        currentSnapshot = currentSnapshot.copy(state = target)
        return true
    }

    private fun matchesOwner(streamId: String): Boolean =
        streamId.isNotBlank() && currentSnapshot.owner?.streamId == streamId

    private fun releaseInternal() {
        currentSnapshot = DualPhoneLiveStreamSnapshot()
    }

    private companion object {
        val ACTIVE_OR_PREPARING_STATES: Set<DualPhoneLiveStreamState> = setOf(
            DualPhoneLiveStreamState.PREPARING,
            DualPhoneLiveStreamState.READY,
            DualPhoneLiveStreamState.STREAMING,
            DualPhoneLiveStreamState.DEGRADED,
            DualPhoneLiveStreamState.RECONNECTING,
        )
    }
}
