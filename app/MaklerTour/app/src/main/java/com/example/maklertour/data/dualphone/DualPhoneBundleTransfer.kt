package com.maklertour.data.dualphone

import android.content.Context
import android.util.Log
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.DataInputStream
import java.io.DataOutputStream
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream
import java.net.InetSocketAddress
import java.net.ServerSocket
import java.net.Socket
import java.security.MessageDigest
import java.time.Instant
import java.util.Locale
import java.util.UUID
import java.util.zip.GZIPOutputStream

internal data class DualPhoneRolePackage(
    val dualCaptureId: String,
    val role: DualPhoneRole,
    val file: File,
    val sha256: String,
    val sizeBytes: Long,
)

internal data class DualPhoneTransferOffer(
    val dualCaptureId: String,
    val role: DualPhoneRole,
    val port: Int,
    val token: String,
    val fileName: String,
    val sizeBytes: Long,
    val sha256: String,
    val serverJob: Job? = null,
) {
    fun toJson(target: JSONObject): JSONObject = target
        .put("role_package_ready", true)
        .put("role_package_file_name", fileName)
        .put("role_package_size_bytes", sizeBytes)
        .put("role_package_sha256", sha256)
        .put("role_package_transfer_port", port)
        .put("role_package_transfer_token", token)

    companion object {
        fun fromJson(payload: JSONObject): DualPhoneTransferOffer? {
            if (!payload.optBoolean("role_package_ready", false)) return null
            val captureId = payload.optString("dual_capture_id").trim()
            val roleName = payload.optString("role", DualPhoneRole.SLAVE.name)
            val fileName = payload.optString("role_package_file_name").trim()
            val token = payload.optString("role_package_transfer_token").trim()
            val sha256 = payload.optString("role_package_sha256").trim().lowercase()
            val port = payload.optInt("role_package_transfer_port", 0)
            val size = payload.optLong("role_package_size_bytes", 0L)
            if (
                captureId.isBlank() || fileName.isBlank() || token.isBlank() ||
                sha256.length != 64 || port !in 1024..65535 || size <= 0L
            ) {
                return null
            }
            val role = runCatching { DualPhoneRole.valueOf(roleName) }
                .getOrDefault(DualPhoneRole.SLAVE)
            return DualPhoneTransferOffer(
                dualCaptureId = captureId,
                role = role,
                port = port,
                token = token,
                fileName = fileName,
                sizeBytes = size,
                sha256 = sha256,
            )
        }
    }
}

data class DualPhoneAggregateUploadResult(
    val queued: Boolean,
    val message: String,
)

interface DualPhoneAggregateUploadEndpoint {
    suspend fun enqueue(
        bundleFile: File,
        dualCaptureId: String,
    ): DualPhoneAggregateUploadResult
}

object DualPhoneAggregateUploadRuntime {
    @Volatile
    private var endpoint: DualPhoneAggregateUploadEndpoint? = null

    fun register(value: DualPhoneAggregateUploadEndpoint) {
        endpoint = value
    }

    fun unregister(value: DualPhoneAggregateUploadEndpoint) {
        if (endpoint === value) endpoint = null
    }

    suspend fun enqueue(
        bundleFile: File,
        dualCaptureId: String,
    ): DualPhoneAggregateUploadResult = endpoint?.enqueue(
        bundleFile = bundleFile,
        dualCaptureId = dualCaptureId,
    ) ?: DualPhoneAggregateUploadResult(
        queued = false,
        message = "Upload runtime is unavailable; aggregate bundle was retained locally",
    )
}

internal class DualPhoneBundleCoordinator(
    context: Context,
    private val scope: CoroutineScope,
) {
    private val appContext = context.applicationContext

    suspend fun packageRole(
        stopResult: DualPhoneCaptureStopResult,
        dualCaptureId: String,
        role: DualPhoneRole,
    ): DualPhoneRolePackage = withContext(Dispatchers.IO) {
        require(stopResult.captured) { "$role capture was not finalized" }
        val manifest = File(stopResult.manifestPath)
        val roleDir = manifest.parentFile
            ?: throw IllegalStateException("Role capture directory is unavailable")
        require(roleDir.isDirectory) {
            "Role capture directory is missing: ${roleDir.absolutePath}"
        }
        val requiredFiles = REQUIRED_ROLE_FILES.map { File(roleDir, it) }
        requiredFiles.forEach { file ->
            require(file.isFile && file.length() > 0L) {
                "Required DP04.2 artifact is missing or empty: ${file.absolutePath}"
            }
        }

        val outputDir = File(
            appContext.filesDir,
            "dual_phone_transfer/$dualCaptureId",
        ).apply { mkdirs() }
        val roleName = role.name.lowercase()
        val output = File(outputDir, "dual_phone_${dualCaptureId}_${roleName}.tgz")
        val fileEntries = JSONArray()
        requiredFiles.forEach { file ->
            fileEntries.put(
                JSONObject()
                    .put("name", file.name)
                    .put("size_bytes", file.length())
                    .put("sha256", sha256(file)),
            )
        }
        val packageManifest = JSONObject()
            .put("schema_version", 1)
            .put("package_type", "dual_phone_role_capture")
            .put("dual_capture_id", dualCaptureId)
            .put("role", role.name)
            .put("created_at_utc", Instant.now().toString())
            .put("files", fileEntries)

        output.delete()
        try {
            TarGzWriter(output).use { tar ->
                tar.addBytes(
                    "role_package_manifest.json",
                    (packageManifest.toString(2) + "\n").toByteArray(Charsets.UTF_8),
                )
                requiredFiles.forEach { file ->
                    tar.addFile(file, "capture/$roleName/${file.name}")
                }
            }
        } catch (error: Throwable) {
            output.delete()
            throw error
        }
        require(output.isFile && output.length() > 0L) {
            "Role package was not created"
        }
        val result = DualPhoneRolePackage(
            dualCaptureId = dualCaptureId,
            role = role,
            file = output,
            sha256 = sha256(output),
            sizeBytes = output.length(),
        )
        Log.i(
            TAG,
            "role package ready capture=$dualCaptureId role=${role.name} " +
                "path=${output.absolutePath} size=${result.sizeBytes}",
        )
        result
    }

    suspend fun serveOnce(
        rolePackage: DualPhoneRolePackage,
        port: Int,
    ): DualPhoneTransferOffer {
        val token = UUID.randomUUID().toString()
        val ready = CompletableDeferred<Unit>()
        val job = scope.launch(Dispatchers.IO) {
            val server = ServerSocket()
            try {
                server.reuseAddress = true
                server.soTimeout = SERVER_ACCEPT_TIMEOUT_MS
                server.bind(InetSocketAddress(port))
                ready.complete(Unit)
                var sent = false
                var lastClientError: Throwable? = null
                repeat(DOWNLOAD_ATTEMPTS) {
                    if (sent) return@repeat
                    try {
                        server.accept().use { client ->
                            client.soTimeout = SOCKET_TIMEOUT_MS
                            DataInputStream(BufferedInputStream(client.getInputStream())).use { input ->
                                DataOutputStream(BufferedOutputStream(client.getOutputStream())).use { output ->
                                    val magic = input.readUTF()
                                    val captureId = input.readUTF()
                                    val requestedRole = input.readUTF()
                                    val suppliedToken = input.readUTF()
                                    require(magic == TRANSFER_MAGIC) { "Invalid transfer protocol" }
                                    require(captureId == rolePackage.dualCaptureId) {
                                        "dual_capture_id mismatch"
                                    }
                                    require(requestedRole == rolePackage.role.name) {
                                        "role mismatch"
                                    }
                                    require(suppliedToken == token) { "transfer token mismatch" }
                                    output.writeLong(rolePackage.sizeBytes)
                                    output.writeUTF(rolePackage.sha256)
                                    FileInputStream(rolePackage.file).use { fileInput ->
                                        fileInput.copyTo(output, COPY_BUFFER_BYTES)
                                    }
                                    output.flush()
                                    sent = true
                                    Log.i(
                                        TAG,
                                        "role package sent capture=$captureId role=$requestedRole",
                                    )
                                }
                            }
                        }
                    } catch (error: Throwable) {
                        lastClientError = error
                        Log.w(TAG, "role package client attempt failed", error)
                    }
                }
                if (!sent) throw lastClientError
                    ?: IllegalStateException("Role package was not transferred")
            } catch (error: Throwable) {
                if (!ready.isCompleted) ready.completeExceptionally(error)
                Log.e(TAG, "role package server failed", error)
                throw error
            } finally {
                runCatching { server.close() }
            }
        }
        ready.await()
        return DualPhoneTransferOffer(
            dualCaptureId = rolePackage.dualCaptureId,
            role = rolePackage.role,
            port = port,
            token = token,
            fileName = rolePackage.file.name,
            sizeBytes = rolePackage.sizeBytes,
            sha256 = rolePackage.sha256,
            serverJob = job,
        )
    }

    suspend fun download(
        peerHost: String,
        offer: DualPhoneTransferOffer,
    ): DualPhoneRolePackage = withContext(Dispatchers.IO) {
        val outputDir = File(
            appContext.filesDir,
            "dual_phone_transfer/${offer.dualCaptureId}/received",
        ).apply { mkdirs() }
        val finalFile = File(outputDir, safeFileName(offer.fileName))
        val partFile = File(outputDir, finalFile.name + ".part")
        var lastError: Throwable? = null
        repeat(DOWNLOAD_ATTEMPTS) { attempt ->
            try {
                partFile.delete()
                Socket().use { client ->
                    client.connect(
                        InetSocketAddress(peerHost, offer.port),
                        CONNECT_TIMEOUT_MS,
                    )
                    client.soTimeout = SOCKET_TIMEOUT_MS
                    DataOutputStream(BufferedOutputStream(client.getOutputStream())).use { output ->
                        DataInputStream(BufferedInputStream(client.getInputStream())).use { input ->
                            output.writeUTF(TRANSFER_MAGIC)
                            output.writeUTF(offer.dualCaptureId)
                            output.writeUTF(offer.role.name)
                            output.writeUTF(offer.token)
                            output.flush()
                            val declaredSize = input.readLong()
                            val declaredHash = input.readUTF().lowercase()
                            require(declaredSize == offer.sizeBytes) {
                                "Slave package size changed: $declaredSize != ${offer.sizeBytes}"
                            }
                            require(declaredHash == offer.sha256) {
                                "Slave package hash changed"
                            }
                            FileOutputStream(partFile).use { fileOutput ->
                                var remaining = declaredSize
                                val buffer = ByteArray(COPY_BUFFER_BYTES)
                                while (remaining > 0L) {
                                    val read = input.read(
                                        buffer,
                                        0,
                                        minOf(buffer.size.toLong(), remaining).toInt(),
                                    )
                                    if (read < 0) throw IllegalStateException(
                                        "Slave closed transfer with $remaining bytes remaining",
                                    )
                                    fileOutput.write(buffer, 0, read)
                                    remaining -= read
                                }
                                fileOutput.fd.sync()
                            }
                        }
                    }
                }
                require(partFile.length() == offer.sizeBytes) {
                    "Downloaded package size mismatch"
                }
                require(sha256(partFile) == offer.sha256) {
                    "Downloaded package SHA-256 mismatch"
                }
                finalFile.delete()
                require(partFile.renameTo(finalFile)) {
                    "Could not finalize downloaded package"
                }
                return@withContext DualPhoneRolePackage(
                    dualCaptureId = offer.dualCaptureId,
                    role = offer.role,
                    file = finalFile,
                    sha256 = offer.sha256,
                    sizeBytes = offer.sizeBytes,
                )
            } catch (error: Throwable) {
                lastError = error
                Log.w(
                    TAG,
                    "download attempt ${attempt + 1}/$DOWNLOAD_ATTEMPTS failed: " +
                        (error.message ?: error.javaClass.simpleName),
                )
                if (attempt + 1 < DOWNLOAD_ATTEMPTS) Thread.sleep(RETRY_DELAY_MS)
            }
        }
        partFile.delete()
        throw lastError ?: IllegalStateException("Slave role package download failed")
    }

    suspend fun packageAggregate(
        masterPackage: DualPhoneRolePackage,
        slavePackage: DualPhoneRolePackage,
    ): File = withContext(Dispatchers.IO) {
        require(masterPackage.dualCaptureId == slavePackage.dualCaptureId) {
            "Role package dual_capture_id mismatch"
        }
        require(masterPackage.role == DualPhoneRole.MASTER) {
            "Master role package is invalid"
        }
        require(slavePackage.role == DualPhoneRole.SLAVE) {
            "Slave role package is invalid"
        }
        val captureId = masterPackage.dualCaptureId
        val outputRoot = File(appContext.filesDir, "upload_packages").apply { mkdirs() }
        val output = File(
            outputRoot,
            "maklertour_capture_bundle_dual_phone_stereo_video_$captureId.tgz",
        )
        val manifest = JSONObject()
            .put("bundle_schema_version", 1)
            .put("bundle_type", "maklertour_capture_bundle")
            .put("capture_type", "dual_phone_stereo_video")
            .put("app_bundle_uuid", captureId)
            .put("dual_capture_id", captureId)
            .put("created_at_utc", Instant.now().toString())
            .put("roles", JSONArray().apply {
                put(roleJson(masterPackage, "roles/master.tgz"))
                put(roleJson(slavePackage, "roles/slave.tgz"))
            })
            .put("aggregate_complete", true)
        output.delete()
        try {
            TarGzWriter(output).use { tar ->
                tar.addBytes(
                    "bundle_manifest.json",
                    (manifest.toString(2) + "\n").toByteArray(Charsets.UTF_8),
                )
                tar.addFile(masterPackage.file, "roles/master.tgz")
                tar.addFile(slavePackage.file, "roles/slave.tgz")
            }
        } catch (error: Throwable) {
            output.delete()
            throw error
        }
        require(output.isFile && output.length() > 0L) {
            "Aggregate dual-phone bundle was not created"
        }
        Log.i(
            TAG,
            "aggregate bundle ready capture=$captureId path=${output.absolutePath} " +
                "size=${output.length()}",
        )
        output
    }

    private fun roleJson(
        rolePackage: DualPhoneRolePackage,
        archivePath: String,
    ): JSONObject = JSONObject()
        .put("role", rolePackage.role.name)
        .put("archive_path", archivePath)
        .put("size_bytes", rolePackage.sizeBytes)
        .put("sha256", rolePackage.sha256)

    private fun safeFileName(value: String): String {
        val safe = value.substringAfterLast('/').substringAfterLast('\\')
        require(safe.matches(Regex("[A-Za-z0-9._-]{8,160}"))) {
            "Unsafe role package file name"
        }
        return safe
    }

    private class TarGzWriter(file: File) : AutoCloseable {
        private val out = BufferedOutputStream(GZIPOutputStream(FileOutputStream(file)))

        fun addFile(file: File, name: String) {
            FileInputStream(file).use { input -> addEntry(name, file.length(), input::read) }
        }

        fun addBytes(name: String, bytes: ByteArray) {
            var position = 0
            addEntry(name, bytes.size.toLong()) { buffer ->
                val count = minOf(buffer.size, bytes.size - position)
                if (count <= 0) {
                    -1
                } else {
                    System.arraycopy(bytes, position, buffer, 0, count)
                    position += count
                    count
                }
            }
        }

        private fun addEntry(
            name: String,
            size: Long,
            read: (ByteArray) -> Int,
        ) {
            out.write(header(name, size))
            val buffer = ByteArray(8192)
            var written = 0L
            while (true) {
                val count = read(buffer)
                if (count <= 0) break
                out.write(buffer, 0, count)
                written += count
            }
            repeat(((512 - (written % 512)) % 512).toInt()) { out.write(0) }
        }

        private fun header(name: String, size: Long): ByteArray {
            val header = ByteArray(512)
            fun put(value: String, offset: Int, length: Int) {
                val bytes = value.toByteArray()
                System.arraycopy(bytes, 0, header, offset, minOf(bytes.size, length))
            }
            put(name, 0, 100)
            put("0000644\u0000", 100, 8)
            put("0000000\u0000", 108, 8)
            put("0000000\u0000", 116, 8)
            put(String.format(Locale.US, "%011o\u0000", size), 124, 12)
            put(
                String.format(Locale.US, "%011o\u0000", System.currentTimeMillis() / 1000),
                136,
                12,
            )
            for (index in 148 until 156) header[index] = 32
            header[156] = '0'.code.toByte()
            put("ustar\u0000", 257, 6)
            put("00", 263, 2)
            val checksum = header.sumOf { it.toUByte().toInt() }
            put(String.format(Locale.US, "%06o\u0000 ", checksum), 148, 8)
            return header
        }

        override fun close() {
            out.write(ByteArray(1024))
            out.close()
        }
    }

    companion object {
        private const val TAG = "DualPhoneBundle"
        private const val TRANSFER_MAGIC = "MAKLERTOUR_DP043_V1"
        private const val SERVER_ACCEPT_TIMEOUT_MS = 120_000
        private const val SOCKET_TIMEOUT_MS = 120_000
        private const val CONNECT_TIMEOUT_MS = 10_000
        private const val DOWNLOAD_ATTEMPTS = 3
        private const val RETRY_DELAY_MS = 1_000L
        private const val COPY_BUFFER_BYTES = 256 * 1024
        private val REQUIRED_ROLE_FILES = listOf(
            "video.mp4",
            "dual_capture_manifest.json",
            "frames.jsonl",
            "encoder_pts.jsonl",
            "frame_encoder_map.jsonl",
            "local_timeline_report.json",
            "imu.jsonl",
            "camera_info.json",
            "clock_sync.json",
            "capture_events.jsonl",
            "clock_sync_history.jsonl",
        )
    }
}

internal fun sha256(file: File): String {
    val digest = MessageDigest.getInstance("SHA-256")
    FileInputStream(file).use { input ->
        val buffer = ByteArray(256 * 1024)
        while (true) {
            val read = input.read(buffer)
            if (read <= 0) break
            digest.update(buffer, 0, read)
        }
    }
    return digest.digest().joinToString("") { "%02x".format(it.toInt() and 0xff) }
}
