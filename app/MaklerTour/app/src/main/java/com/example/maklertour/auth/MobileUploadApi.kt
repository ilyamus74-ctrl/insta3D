package com.example.maklertour.auth

import com.example.maklertour.network.ApiConfig
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.ScanSource
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
import java.io.FileInputStream
import kotlin.math.ceil
import kotlin.math.min

class MobileUploadApi(
    private val authStorage: AuthStorage,
    private val client: OkHttpClient = OkHttpClient(),
) {
    companion object {
        private const val CHUNK_UPLOAD_THRESHOLD_BYTES = 200L * 1024L * 1024L
        private const val CHUNK_SIZE_BYTES = 8L * 1024L * 1024L
        private const val MAX_CHUNK_RETRIES = 3
    }
    data class UploadProgress(
        val bytesUploaded: Long,
        val bytesTotal: Long,
    )

    private data class PhoneScanMetadataFiles(
        val cameraInfo: File,
        val manifest: File,
        val imu: File,
        val frames: File,
    ) {
        val requiredReady: Boolean
            get() = cameraInfo.isFile && cameraInfo.length() > 0L &&
                manifest.isFile && manifest.length() > 0L
    }

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

    suspend fun uploadVideoScan(
        orderId: Long,
        captureSessionId: Long,
        scan: ScanVideo,
        onProgress: ((UploadProgress) -> Unit)? = null,
    ): Boolean = withContext(Dispatchers.IO) {
        val file = scan.localVideoPath?.let(::File)
        if (file?.isFile != true || file.length() <= 0L) {
            Log.e(
                "MobileUploadApi",
                "video missing scanId=${scan.id} path=${scan.localVideoPath}",
            )
            return@withContext false
        }

        if (scan.source == ScanSource.PHONE_CAMERA) {
            val metadata = phoneScanMetadataFiles(file)
            if (!metadata.requiredReady) {
                Log.e(
                    "MobileUploadApi",
                    "PHONE_CAMERA upload rejected before network transfer: required metadata missing scanId=${scan.id} cameraInfo=${metadata.cameraInfo.absolutePath}:${metadata.cameraInfo.length()} manifest=${metadata.manifest.absolutePath}:${metadata.manifest.length()}",
                )
                return@withContext false
            }
        }

        if (file.length() > CHUNK_UPLOAD_THRESHOLD_BYTES) {
            uploadVideoScanChunked(
                orderId,
                captureSessionId,
                scan,
                file,
                onProgress,
            )
        } else {
            uploadVideoScanSingle(
                orderId,
                captureSessionId,
                scan,
                file,
                onProgress,
            )
        }
    }

    private fun phoneScanMetadataFiles(videoFile: File): PhoneScanMetadataFiles {
        val dir = videoFile.parentFile
            ?: throw IllegalStateException("Video parent directory is unavailable")
        return PhoneScanMetadataFiles(
            cameraInfo = File(dir, "camera_info.json"),
            manifest = File(dir, "manifest.json"),
            imu = File(dir, "imu.jsonl"),
            frames = File(dir, "frames.jsonl"),
        )
    }

    private fun uploadResponseAccepted(
        responseText: String,
        requirePhoneMetadata: Boolean,
    ): Boolean {
        val json = runCatching { JSONObject(responseText) }
            .onFailure {
                Log.e(
                    "MobileUploadApi",
                    "upload response is not valid JSON: $responseText",
                    it,
                )
            }
            .getOrNull() ?: return false
        if (!json.optBoolean("ok", false)) return false

        val warnings = json.optJSONArray("metadata_warnings")
        if (warnings != null && warnings.length() > 0) {
            Log.w("MobileUploadApi", "server metadata warnings=$warnings")
        }
        if (!requirePhoneMetadata) return true

        val metadata = json.optJSONObject("metadata") ?: return false
        val cameraInfoStored = !metadata.isNull("camera_info") &&
            metadata.optString("camera_info").isNotBlank()
        val manifestStored = !metadata.isNull("manifest") &&
            metadata.optString("manifest").isNotBlank()
        if (!cameraInfoStored || !manifestStored) {
            Log.e(
                "MobileUploadApi",
                "PHONE_CAMERA server acknowledgement missing required metadata camera_info=$cameraInfoStored manifest=$manifestStored response=$responseText",
            )
        }
        return cameraInfoStored && manifestStored
    }

    private fun uploadVideoScanSingle(orderId: Long, captureSessionId: Long, scan: ScanVideo, videoFile: File, onProgress: ((UploadProgress) -> Unit)?): Boolean {
        val token = authStorage.getToken().orEmpty()
        val videoSize = videoFile.length()

        Log.d(
            "MobileUploadApi",
            "upload_video_scan request orderId=$orderId captureSessionId=$captureSessionId app_scan_uuid=${scan.id} scanId=${scan.id} source=${scan.source} durationSec=${scan.durationSec} localCameraUrl=${scan.cameraFileUrl} localVideoPath=${scan.localVideoPath} file.exists=${videoFile.exists()} file.length=$videoSize"
        )

        val bodyBuilder = MultipartBody.Builder().setType(MultipartBody.FORM)
            .addFormDataPart("order_id", orderId.toString())
            .addFormDataPart("capture_session_id", captureSessionId.toString())
            .addFormDataPart("app_scan_uuid", scan.id)
            .addFormDataPart("duration_sec", (scan.durationSec ?: 0L).toString())
            .addFormDataPart("local_camera_url", scan.cameraFileUrl ?: "")
            .addFormDataPart("source", scan.source.name)
            .addFormDataPart("scan_role", scan.role?.name ?: "")
            .addFormDataPart("role", scan.role?.name ?: "")
            .addFormDataPart("camera_local_file_url", scan.cameraLocalFileUrl ?: "")
            .addFormDataPart("marker_expected", scan.markerExpected.toString())
            .addFormDataPart("marker_detected", scan.markerDetected.toString())

        addPhoneScanMetadataPartsIfAvailable(bodyBuilder, scan, videoFile)

        bodyBuilder.addFormDataPart(
            "video",
            videoFile.name,
            ProgressRequestBody(videoFile.asRequestBody("video/mp4".toMediaType())) { uploaded, total ->
                onProgress?.invoke(UploadProgress(uploaded, total))
            }
        )

        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=upload_video_scan")
            .header("Authorization", "Bearer $token")
            .post(bodyBuilder.build())
            .build()

        return client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            Log.d("MobileUploadApi", "upload_video_scan response http=${response.code} body=$text")
            response.isSuccessful && uploadResponseAccepted(
                responseText = text,
                requirePhoneMetadata = scan.source == ScanSource.PHONE_CAMERA,
            )
        }
    }

    private fun addPhoneScanMetadataPartsIfAvailable(bodyBuilder: MultipartBody.Builder, scan: ScanVideo, videoFile: File) {
        val metadata = phoneScanMetadataFiles(videoFile)
        addFilePartIfAvailable(bodyBuilder, scan, metadata.cameraInfo, "camera_info", "application/json")
        addFilePartIfAvailable(bodyBuilder, scan, metadata.manifest, "manifest", "application/json")
        addFilePartIfAvailable(bodyBuilder, scan, metadata.imu, "imu", "application/x-ndjson")
        addFilePartIfAvailable(bodyBuilder, scan, metadata.frames, "frames", "application/x-ndjson")
    }

    private fun addFilePartIfAvailable(bodyBuilder: MultipartBody.Builder, scan: ScanVideo, file: File, partName: String, mediaType: String) {
        if (!file.exists() || file.length() <= 0L) {
            Log.d("MobileUploadApi", "$partName not found scanId=${scan.id} path=${file.absolutePath}")
            return
        }
        Log.d("MobileUploadApi", "attaching $partName scanId=${scan.id} path=${file.absolutePath} size=${file.length()}")
        bodyBuilder.addFormDataPart(
            partName,
            file.name,
            file.asRequestBody(mediaType.toMediaType()),
        )
    }

    private fun uploadVideoScanChunked(orderId: Long, captureSessionId: Long, scan: ScanVideo, videoFile: File, onProgress: ((UploadProgress) -> Unit)?): Boolean {
        val token = authStorage.getToken().orEmpty()
        val uploadId = scan.id
        val totalSize = videoFile.length()
        val totalChunks = ceil(totalSize.toDouble() / CHUNK_SIZE_BYTES.toDouble()).toInt()
        var uploadedBefore = 0L
        Log.d(
            "UploadChunk",
            "upload_video_scan request scanId=${scan.id} source=${scan.source} localVideoPath=${scan.localVideoPath} file.exists=${videoFile.exists()} file.length=$totalSize durationSec=${scan.durationSec} upload_id=$uploadId chunked=true chunkSize=$CHUNK_SIZE_BYTES totalChunks=$totalChunks"
        )
        for (chunkIndex in 0 until totalChunks) {
            val offset = chunkIndex * CHUNK_SIZE_BYTES
            val chunkSize = min(CHUNK_SIZE_BYTES, totalSize - offset)
            var success = false
            var attempt = 0

            while (attempt < MAX_CHUNK_RETRIES && !success) {
                val bodyBuilder = MultipartBody.Builder().setType(MultipartBody.FORM)
                    .addFormDataPart("order_id", orderId.toString())
                    .addFormDataPart("capture_session_id", captureSessionId.toString())
                    .addFormDataPart("app_scan_uuid", scan.id)
                    .addFormDataPart("duration_sec", (scan.durationSec ?: 0L).toString())
                    .addFormDataPart("local_camera_url", scan.cameraFileUrl ?: "")
                    .addFormDataPart("source", scan.source.name)
                    .addFormDataPart("scan_role", scan.role?.name ?: "")
                    .addFormDataPart("role", scan.role?.name ?: "")
                    .addFormDataPart("camera_local_file_url", scan.cameraLocalFileUrl ?: "")
                    .addFormDataPart("marker_expected", scan.markerExpected.toString())
                    .addFormDataPart("marker_detected", scan.markerDetected.toString())
                    .apply { if (chunkIndex == totalChunks - 1) addPhoneScanMetadataPartsIfAvailable(this, scan, videoFile) }
                    .addFormDataPart("upload_id", uploadId)
                    .addFormDataPart("chunk_index", chunkIndex.toString())
                    .addFormDataPart("total_chunks", totalChunks.toString())
                    .addFormDataPart("chunk_size", chunkSize.toString())
                    .addFormDataPart("total_size", totalSize.toString())
                    .addFormDataPart(
                        "video",
                        videoFile.name,
                        FileChunkRequestBody(videoFile, offset, chunkSize, "video/mp4".toMediaType()) { inChunk ->
                            onProgress?.invoke(UploadProgress(uploadedBefore + inChunk, totalSize))
                        }
                    )
                val request = Request.Builder()
                    .url("${ApiConfig.mobileApiUrl}?action=upload_video_scan")
                    .header("Authorization", "Bearer $token")
                    .post(bodyBuilder.build())
                    .build()
                runCatching {
                    client.newCall(request).execute().use { response ->
                        val text = response.body?.string().orEmpty()
                        Log.d("UploadChunk", "upload_id=$uploadId chunk=$chunkIndex/$totalChunks attempt=${attempt + 1} http=${response.code} body=$text")
                        val responseJson = runCatching { JSONObject(text) }.getOrNull()
                        val ok = responseJson?.optBoolean("ok", false) == true
                        val complete = responseJson?.optBoolean("upload_complete", false) == true
                        success = if (chunkIndex == totalChunks - 1) {
                            response.isSuccessful &&
                                complete &&
                                uploadResponseAccepted(
                                    responseText = text,
                                    requirePhoneMetadata =
                                        scan.source == ScanSource.PHONE_CAMERA,
                                )
                        } else {
                            response.isSuccessful && ok
                        }
                    }
                }.onFailure { Log.e("UploadChunk", "chunk failed upload_id=$uploadId chunk=$chunkIndex attempt=${attempt + 1}", it) }
                attempt += 1
            }
            if (!success) return false
            uploadedBefore += chunkSize
        }
        Log.d("UploadChunk", "complete upload_id=$uploadId finalUploaded=$uploadedBefore total=$totalSize")
        return true
    }

    suspend fun uploadCaptureBundle(
        orderId: Long,
        captureSessionId: Long,
        bundleFile: File,
        captureType: String,
        appBundleUuid: String,
        onProgress: ((UploadProgress) -> Unit)? = null,
    ): Boolean = withContext(Dispatchers.IO) {
        if (!bundleFile.exists() || bundleFile.length() <= 0L) return@withContext false
        val token = authStorage.getToken().orEmpty()
        Log.i("UploadQueue", "upload capture bundle request orderId=$orderId captureSessionId=$captureSessionId path=${bundleFile.absolutePath} size=${bundleFile.length()} captureType=$captureType")
        val body = MultipartBody.Builder().setType(MultipartBody.FORM)
            .addFormDataPart("order_id", orderId.toString())
            .addFormDataPart("capture_session_id", captureSessionId.toString())
            .addFormDataPart("upload_type", "CAPTURE_BUNDLE")
            .addFormDataPart("capture_type", captureType)
            .addFormDataPart("app_bundle_uuid", appBundleUuid)
            .addFormDataPart(
                "capture_bundle",
                bundleFile.name,
                ProgressRequestBody(bundleFile.asRequestBody("application/gzip".toMediaType())) { uploaded, total ->
                    onProgress?.invoke(UploadProgress(uploaded, total))
                }
            )
            .build()
        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=upload_capture_bundle")
            .header("Authorization", "Bearer $token")
            .post(body)
            .build()
        client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            Log.i("UploadQueue", "upload capture bundle response http=${response.code} body=$text")
            response.isSuccessful && runCatching { JSONObject(text).optBoolean("ok", false) }.getOrDefault(text.contains("\"ok\":true"))
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

private class FileChunkRequestBody(
    private val file: File,
    private val offset: Long,
    private val byteCount: Long,
    private val contentType: okhttp3.MediaType,
    private val onProgress: (Long) -> Unit,
) : RequestBody() {
    override fun contentType() = contentType
    override fun contentLength() = byteCount
    override fun writeTo(sink: BufferedSink) {
        FileInputStream(file).use { input ->
            input.skip(offset)
            val buffer = ByteArray(64 * 1024)
            var remaining = byteCount
            var written = 0L
            while (remaining > 0) {
                val read = input.read(buffer, 0, min(buffer.size.toLong(), remaining).toInt())
                if (read <= 0) break
                sink.write(buffer, 0, read)
                remaining -= read
                written += read
                onProgress(written)
            }
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