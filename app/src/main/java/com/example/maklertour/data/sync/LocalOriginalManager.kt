package com.maklertour.data.sync

import com.maklertour.data.camera.osc.OscFileDownloader
import com.maklertour.data.local.dao.CapturePointDao
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.DeleteState
import com.maklertour.domain.FileLocalState
import com.maklertour.domain.ServerUploadState
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import android.util.Log

class LocalOriginalManager(
    private val capturePointDao: CapturePointDao,
    private val oscFileDownloader: OscFileDownloader,
) {
    suspend fun downloadOriginalForPoint(sessionId: String, pointId: String) {
        val point = capturePointDao.getById(pointId) ?: return
        val fileUrl = point.cameraFileUrl ?: return
        capturePointDao.upsert(point.copy(localOriginalState = FileLocalState.DOWNLOADING.name))
        val result = oscFileDownloader.downloadOriginal(fileUrl, sessionId, pointId)
        capturePointDao.upsert(
            point.copy(
                localOriginalPath = result.localPath,
                fileSizeBytes = result.fileSizeBytes,
                checksumSha256 = result.checksumSha256,
                localOriginalState = if (result.error == null) FileLocalState.DOWNLOADED.name else FileLocalState.DOWNLOAD_ERROR.name,
                updatedAtEpochMs = System.currentTimeMillis(),
            )
        )
    }



    suspend fun downloadPreviewForPoint(sessionId: String, point: CapturePoint): CapturePoint {
        val fileUrl = point.cameraFileUrl ?: return point

        Log.d(
            "LocalOriginalManager",
            "downloadPreviewForPoint(): start sessionId=$sessionId, pointId=${point.id}, fileUrl=$fileUrl"
        )

        val result = oscFileDownloader.downloadPreview(fileUrl, sessionId, point.id)
        val localPreviewPath = result.localPath

        Log.d(
            "LocalOriginalManager",
            "downloadPreviewForPoint(): result pointId=${point.id}, localPath=${result.localPath}, error=${result.error}"
        )

        val updatedPoint = point.copy(
            previewUri = localPreviewPath ?: point.cameraFileUrl,
            localPreviewPath = localPreviewPath,
        )

        capturePointDao.updatePreview(
            pointId = point.id,
            previewUri = updatedPoint.previewUri,
            localPreviewPath = updatedPoint.localPreviewPath,
            updatedAtEpochMs = System.currentTimeMillis(),
        )

        Log.d(
            "LocalOriginalManager",
            "downloadPreviewForPoint(): DB updated pointId=${point.id}, previewUri=${updatedPoint.previewUri}, localPreviewPath=${updatedPoint.localPreviewPath}"
        )

        return updatedPoint
    }
    suspend fun downloadOriginalsForSession(sessionId: String) {
        capturePointDao.getBySessionId(sessionId).forEach { downloadOriginalForPoint(sessionId, it.id) }
    }

    suspend fun deleteLocalOriginalsAfterServerConfirmed(sessionId: String) = withContext(Dispatchers.IO) {
        capturePointDao.getBySessionId(sessionId).forEach { point ->
            if (point.serverUploadState == ServerUploadState.CONFIRMED.name && !point.localOriginalPath.isNullOrBlank()) {
                val deleted = runCatching { File(point.localOriginalPath).delete() }.getOrDefault(false)
                capturePointDao.upsert(
                    point.copy(
                        localDeleteState = if (deleted) DeleteState.DELETED.name else DeleteState.DELETE_ERROR.name,
                        localOriginalPath = if (deleted) null else point.localOriginalPath,
                        updatedAtEpochMs = System.currentTimeMillis(),
                    )
                )
            }
        }
    }
}
