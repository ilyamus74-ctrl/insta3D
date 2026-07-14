package com.example.maklertour.data.capture

import android.content.Context
import android.util.Log
import com.maklertour.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedOutputStream
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream
import java.nio.charset.StandardCharsets
import java.time.Instant
import java.util.Locale
import java.util.zip.GZIPOutputStream

class CaptureBundlePackager(private val context: Context) {
    suspend fun packageSyncedDepthCapture(
        captureDir: File,
        calibrationSessionDir: File?,
        activeRigProfileJson: JSONObject?,
        outputRoot: File,
    ): File = withContext(Dispatchers.IO) {
        packageCaptureBundle("synced_depth_frames", captureDir, calibrationSessionDir, activeRigProfileJson, outputRoot)
    }

    suspend fun packageLegacyStereoVideoCapture(
        videoSessionDir: File,
        calibrationSessionDir: File?,
        activeRigProfileJson: JSONObject?,
        outputRoot: File,
    ): File = withContext(Dispatchers.IO) {
        packageCaptureBundle("stereo_video_legacy", videoSessionDir, calibrationSessionDir, activeRigProfileJson, outputRoot)
    }


    suspend fun packageAutomaticPhotoSession(
        captureDir: File,
        outputRoot: File,
    ): File = withContext(Dispatchers.IO) {
        packageAutomaticPhotoBundle(captureDir, outputRoot)
    }

    private fun packageAutomaticPhotoBundle(captureDir: File, outputRoot: File): File {
        require(captureDir.exists() && captureDir.isDirectory) { "auto photo capture dir not found: ${captureDir.absolutePath}" }
        val manifestFile = File(captureDir, "manifest.json")
        require(manifestFile.exists()) { "auto photo manifest not found: ${manifestFile.absolutePath}" }
        val manifest = JSONObject(manifestFile.readText())
        require(manifest.optString("capture_type") == "auto_photo_session") { "unexpected capture_type=${manifest.optString("capture_type")}" }
        val photosDir = File(captureDir, "photos")
        val photos = photosDir.listFiles { file -> file.isFile && file.extension.equals("jpg", ignoreCase = true) }
            ?.sortedBy { it.name }
            ?: emptyList()
        require(photos.size == manifest.optInt("photos_count")) { "manifest photos_count=${manifest.optInt("photos_count")} actual=${photos.size}" }
        photos.forEach { photo -> require(photo.length() > 0L) { "empty JPEG: ${photo.absolutePath}" } }
        outputRoot.mkdirs()
        val captureUuid = manifest.optString("capture_uuid", System.currentTimeMillis().toString())
        val out = File(outputRoot, "maklertour_capture_bundle_auto_photo_session_$captureUuid.tgz")
        val bundleManifest = JSONObject()
            .put("bundle_schema_version", 1)
            .put("bundle_type", "maklertour_capture_bundle")
            .put("capture_type", "auto_photo_session")
            .put("app_bundle_uuid", captureUuid)
            .put("created_at_utc", Instant.now().toString())
            .put("app_package", context.packageName)
            .put("app_version", BuildConfig.VERSION_NAME)
            .put("photos_count", photos.size)
            .put("source_capture_dir", captureDir.absolutePath)
        TarGzWriter(out).use { tar ->
            tar.addBytes("bundle_manifest.json", bundleManifest.toString(2).toByteArray(StandardCharsets.UTF_8))
            tar.addDirectoryContents(captureDir, "capture")
        }
        require(out.exists() && out.length() > 0L) { "archive was not created" }
        Log.i(TAG, "auto photo packaging complete path=${out.absolutePath} size=${out.length()} photos=${photos.size}")
        return out
    }

    private fun packageCaptureBundle(
        captureType: String,
        captureDir: File,
        calibrationSessionDir: File?,
        activeRigProfileJson: JSONObject?,
        outputRoot: File,
    ): File {
        require(captureDir.exists() && captureDir.isDirectory) { "capture dir not found: ${captureDir.absolutePath}" }
        outputRoot.mkdirs() // files/upload_packages
        val timestamp = System.currentTimeMillis()
        val out = File(outputRoot, "maklertour_capture_bundle_${captureType}_$timestamp.tgz")
        Log.i(TAG, "packaging started captureType=$captureType captureDir=${captureDir.absolutePath} output=${out.absolutePath}")

        val captureManifest = readJson(File(captureDir, "synced_depth_manifest.json")) ?: JSONObject()
        val pairs = captureManifest.optJSONArray("pairs") ?: JSONArray()
        val hasCalibration = calibrationSessionDir?.isDirectory == true
        val hasExtrinsics = hasCalibration && File(calibrationSessionDir, "stereo_extrinsics.json").exists()
        val bundleManifest = JSONObject()
            .put("bundle_schema_version", 1)
            .put("bundle_type", "maklertour_capture_bundle")
            .put("capture_type", captureType)
            .put("created_at_utc", Instant.now().toString())
            .put("app_package", context.packageName)
            .put("app_version", BuildConfig.VERSION_NAME)
            .put("rig_id", captureManifest.optString("rig_id", activeRigProfileJson?.optString("rigId", "") ?: ""))
            .put("active_rig_profile_name", jsonStringOrNull(activeRigProfileJson, "name"))
            .put("active_rig_profile_id", jsonStringOrNull(activeRigProfileJson, "rigId"))
            .put("source_capture_dir", captureDir.absolutePath)
            .put("source_calibration_session_dir", calibrationSessionDir?.absolutePath ?: JSONObject.NULL)
            .put("has_stereo_extrinsics", hasExtrinsics)
            .put("pairs_count", pairs.length())
            .put("physical_orientation_counts", captureManifest.optJSONObject("physical_orientation_counts") ?: JSONObject())
            .put("orientation_transition_count", captureManifest.optInt("orientation_transition_count", 0))
            .put("raw_width", captureManifest.optInt("raw_width", 0))
            .put("raw_height", captureManifest.optInt("raw_height", 0))
            .put("cam0_rotation_degrees_applied", 0)
            .put("cam1_rotation_degrees_applied", 0)
        if (!hasCalibration) bundleManifest.put("warning", "calibration session not found")

        TarGzWriter(out).use { tar ->
            tar.addBytes("bundle_manifest.json", bundleManifest.toString(2).toByteArray(StandardCharsets.UTF_8))
            tar.addDirectoryContents(captureDir, "capture")
            calibrationSessionDir?.takeIf { it.isDirectory }?.let { tar.addDirectoryContents(it, "calibration") }
            activeRigProfileJson?.let { tar.addBytes("rig/active_rig_profile.json", it.toString(2).toByteArray(StandardCharsets.UTF_8)) }
        }
        Log.i(TAG, "packaging complete path=${out.absolutePath} size=${out.length()}")
        return out
    }

    private fun readJson(file: File): JSONObject? = runCatching { if (file.exists()) JSONObject(file.readText()) else null }.getOrNull()

    private fun jsonStringOrNull(json: JSONObject?, key: String): Any {
        val value = json?.optString(key)?.takeIf { it.isNotBlank() }
        return value ?: JSONObject.NULL
    }

    private class TarGzWriter(file: File) : AutoCloseable {
        private val out = BufferedOutputStream(GZIPOutputStream(FileOutputStream(file)))
        fun addDirectoryContents(root: File, prefix: String) { root.walkTopDown().filter { it.isFile }.forEach { addFile(it, "$prefix/${it.relativeTo(root).invariantSeparatorsPath}") } }
        fun addFile(file: File, name: String) { FileInputStream(file).use { input -> addEntry(name, file.length(), input::read) } }
        fun addBytes(name: String, bytes: ByteArray) { var pos = 0; addEntry(name, bytes.size.toLong()) { b -> val n = minOf(b.size, bytes.size - pos); if (n <= 0) -1 else { System.arraycopy(bytes, pos, b, 0, n); pos += n; n } } }
        private fun addEntry(name: String, size: Long, read: (ByteArray) -> Int) {
            out.write(header(name, size)); val buf = ByteArray(8192); var written = 0L; while (true) { val n = read(buf); if (n <= 0) break; out.write(buf, 0, n); written += n }; repeat(((512 - (written % 512)) % 512).toInt()) { out.write(0) }
        }
        private fun header(name: String, size: Long): ByteArray { val h = ByteArray(512); fun put(s: String, off: Int, len: Int) { val b=s.toByteArray(); System.arraycopy(b,0,h,off,minOf(b.size,len)) }; put(name,0,100); put("0000644\u0000",100,8); put("0000000\u0000",108,8); put("0000000\u0000",116,8); put(String.format(Locale.US, "%011o\u0000", size),124,12); put(String.format(Locale.US, "%011o\u0000", System.currentTimeMillis()/1000),136,12); for(i in 148 until 156) h[i]=32; h[156]='0'.code.toByte(); put("ustar\u0000",257,6); put("00",263,2); val sum=h.sumOf{ it.toUByte().toInt() }; put(String.format(Locale.US, "%06o\u0000 ", sum),148,8); return h }
        override fun close() { out.write(ByteArray(1024)); out.close() }
    }
    companion object { private const val TAG = "CaptureBundle" }
}