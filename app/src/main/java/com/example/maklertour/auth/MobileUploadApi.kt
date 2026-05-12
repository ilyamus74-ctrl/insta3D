package com.example.maklertour.auth

import com.example.maklertour.network.ApiConfig
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.ScanVideo
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.FormBody
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okio.ForwardingSink
import okio.BufferedSink
import okio.buffer
import org.json.JSONObject
import android.util.Log
import java.io.File

class MobileUploadApi(
    private val authStorage: AuthStorage,
    private val client: OkHttpClient = OkHttpClient(),
) {
    data class UploadProgress(
        val bytesUploaded: Long,
        val bytesTotal: Long,
    )

    suspend fun createSession(orderId: Long, appSessionUuid: String): Long = withContext(Dispatchers.IO) {
        val token = authStorage.getToken().orEmpty()
        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=create_session")
            .header("Authorization", "Bearer $token")
            .post(
                FormBody.Builder()
                    .add("order_id", orderId.toString())
                    .add("app_session_uuid", appSessionUuid)
                    .build()
            )
            .build()

        client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            Log.d("MobileUploadApi", "create_session response http=${response.code} body=$text")
            if (!response.isSuccessful) return@withContext 0L

            runCatching { JSONObject(text) }
                .onFailure { Log.e("MobileUploadApi", "create_session parse failed", it) }
                .getOrNull()
                ?.let { json ->
                    json.optLong("capture_session_id", 0L).takeIf { it > 0L }
                        ?: json.optLong("session_id", 0L).takeIf { it > 0L }
                        ?: 0L
                }
                ?: 0L
        }
    }

    suspend fun uploadVideoScan(orderId: Long, captureSessionId: Long, scan: ScanVideo, onProgress: ((UploadProgress) -> Unit)? = null): Boolean = withContext(Dispatchers.IO) {
        val token = authStorage.getToken().orEmpty()
        val videoFile = scan.localVideoPath?.let(::File)
        val videoExists = videoFile?.exists() == true
        val videoSize = if (videoExists) videoFile?.length() else null

        Log.d(
            "MobileUploadApi",
            "upload_video_scan request orderId=$orderId captureSessionId=$captureSessionId app_scan_uuid=${scan.id} durationSec=${scan.durationSec} localCameraUrl=${scan.cameraFileUrl} videoPath=${scan.localVideoPath} exists=$videoExists size=$videoSize"
        )

        val bodyBuilder = MultipartBody.Builder().setType(MultipartBody.FORM)
            .addFormDataPart("order_id", orderId.toString())
            .addFormDataPart("capture_session_id", captureSessionId.toString())
            .addFormDataPart("app_scan_uuid", scan.id)
            .addFormDataPart("duration_sec", (scan.durationSec ?: 0L).toString())
            .addFormDataPart("local_camera_url", scan.cameraFileUrl ?: "")

        if (videoFile != null && videoFile.exists()) {
            bodyBuilder.addFormDataPart(
                "video",
                videoFile.name,
                ProgressRequestBody(videoFile.asRequestBody("video/mp4".toMediaType())) { uploaded, total ->
                    onProgress?.invoke(UploadProgress(uploaded, total))
                }
            )
        }

        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=upload_video_scan")
            .header("Authorization", "Bearer $token")
            .post(bodyBuilder.build())
            .build()

        client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            Log.d("MobileUploadApi", "upload_video_scan response http=${response.code} body=$text")
            response.isSuccessful && text.contains("\"ok\":true")
        }
    }

    suspend fun uploadPhotoPoint(orderId: Long, captureSessionId: Long, point: CapturePoint, onProgress: ((UploadProgress) -> Unit)? = null): Boolean = withContext(Dispatchers.IO) {
        val token = authStorage.getToken().orEmpty()
        val previewFile = point.localPreviewPath?.let(::File)
        val originalFile = point.localOriginalPath?.let(::File)
        val previewExists = previewFile?.exists() == true
        val originalExists = originalFile?.exists() == true
        val previewSize = if (previewExists) previewFile?.length() else null
        val originalSize = if (originalExists) originalFile?.length() else null

        Log.d(
            "MobileUploadApi",
            "upload_photo_point request orderId=$orderId captureSessionId=$captureSessionId app_point_uuid=${point.id} pointName=${point.name} previewPath=${point.localPreviewPath} previewExists=$previewExists previewSize=$previewSize originalPath=${point.localOriginalPath} originalExists=$originalExists originalSize=$originalSize"
        )

        val bodyBuilder = MultipartBody.Builder().setType(MultipartBody.FORM)
            .addFormDataPart("order_id", orderId.toString())
            .addFormDataPart("capture_session_id", captureSessionId.toString())
            .addFormDataPart("app_point_uuid", point.id)
            .addFormDataPart("point_name", point.name)
            .addFormDataPart("camera_file_url", point.cameraFileUrl ?: "")
            .addFormDataPart("camera_local_path", point.cameraLocalPath ?: "")

        if (previewFile != null && previewFile.exists()) {
            bodyBuilder.addFormDataPart(
                "preview",
                previewFile.name,
                ProgressRequestBody(previewFile.asRequestBody("image/jpeg".toMediaType())) { uploaded, total ->
                    onProgress?.invoke(UploadProgress(uploaded, total))
                }
            )
        }

        if (originalFile != null && originalFile.exists()) {
            bodyBuilder.addFormDataPart(
                "original",
                originalFile.name,
                ProgressRequestBody(originalFile.asRequestBody("image/jpeg".toMediaType())) { uploaded, total ->
                    onProgress?.invoke(UploadProgress(uploaded, total))
                }
            )
        }

        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=upload_photo_point")
            .header("Authorization", "Bearer $token")
            .post(bodyBuilder.build())
            .build()

        client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            Log.d("MobileUploadApi", "upload_photo_point response http=${response.code} body=$text")
            response.isSuccessful && text.contains("\"ok\":true")
        }
    }
}


private class ProgressRequestBody(
    private val delegate: RequestBody,
    private val onProgress: (bytesUploaded: Long, bytesTotal: Long) -> Unit,
) : RequestBody() {
    override fun contentType() = delegate.contentType()
    override fun contentLength() = delegate.contentLength()

    override fun writeTo(sink: BufferedSink) {
        val countingSink = object : ForwardingSink(sink) {
            var uploaded = 0L
            override fun write(source: okio.Buffer, byteCount: Long) {
                super.write(source, byteCount)
                uploaded += byteCount
                onProgress(uploaded, contentLength())
            }
        }.buffer()
        delegate.writeTo(countingSink)
        countingSink.flush()
    }
}