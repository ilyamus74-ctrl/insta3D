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
        val preflight = validateSyncedDepthPreflight(
            captureDir = captureDir,
            calibrationSessionDir = calibrationSessionDir,
            activeRigProfileJson = activeRigProfileJson,
        )
        packageCaptureBundle(
            captureType = "synced_depth_frames",
            captureDir = captureDir,
            calibrationSessionDir = calibrationSessionDir,
            activeRigProfileJson = activeRigProfileJson,
            outputRoot = outputRoot,
            preflight = preflight,
        )
    }

    suspend fun packageLegacyStereoVideoCapture(
        videoSessionDir: File,
        calibrationSessionDir: File?,
        activeRigProfileJson: JSONObject?,
        outputRoot: File,
    ): File = withContext(Dispatchers.IO) {
        packageCaptureBundle(
            captureType = "stereo_video_legacy",
            captureDir = videoSessionDir,
            calibrationSessionDir = calibrationSessionDir,
            activeRigProfileJson = activeRigProfileJson,
            outputRoot = outputRoot,
            preflight = null,
        )
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
        preflight: StereoCaptureBundlePreflightResult?,
    ): File {
        require(captureDir.exists() && captureDir.isDirectory) { "capture dir not found: ${captureDir.absolutePath}" }
        outputRoot.mkdirs() // files/upload_packages
        require(outputRoot.isDirectory) { "output directory was not created: ${outputRoot.absolutePath}" }
        val timestamp = System.currentTimeMillis()
        val out = File(outputRoot, "maklertour_capture_bundle_${captureType}_$timestamp.tgz")
        Log.i(TAG, "packaging started captureType=$captureType captureDir=${captureDir.absolutePath} output=${out.absolutePath}")

        val captureManifest = readJson(File(captureDir, "synced_depth_manifest.json")) ?: JSONObject()
        val pairs = captureManifest.optJSONArray("pairs") ?: JSONArray()
        val hasCalibration = calibrationSessionDir?.isDirectory == true
        val hasExtrinsics = hasCalibration && File(calibrationSessionDir, "stereo_extrinsics.json").isFile
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

        if (preflight != null) {
            bundleManifest
                .put("preflight_schema_version", preflight.schemaVersion)
                .put("preflight_status", "passed")
                .put("validated_pairs_count", preflight.pairsCount)
                .put("validated_calibration_status", preflight.calibrationStatus)
                .put("validated_baseline_magnitude", preflight.baselineMagnitude)
        } else if (!hasCalibration) {
            bundleManifest.put("warning", "calibration session not found")
        }

        try {
            TarGzWriter(out).use { tar ->
                tar.addBytes("bundle_manifest.json", bundleManifest.toString(2).toByteArray(StandardCharsets.UTF_8))
                tar.addDirectoryContents(captureDir, "capture")
                calibrationSessionDir?.takeIf { it.isDirectory }?.let { tar.addDirectoryContents(it, "calibration") }
                activeRigProfileJson?.let { tar.addBytes("rig/active_rig_profile.json", it.toString(2).toByteArray(StandardCharsets.UTF_8)) }
            }
        } catch (t: Throwable) {
            out.delete()
            throw t
        }

        require(out.isFile && out.length() > 0L) { "capture bundle archive was not created" }
        Log.i(
            TAG,
            "packaging complete path=${out.absolutePath} size=${out.length()} preflight=${preflight != null}",
        )
        return out
    }

    private fun validateSyncedDepthPreflight(
        captureDir: File,
        calibrationSessionDir: File?,
        activeRigProfileJson: JSONObject?,
    ): StereoCaptureBundlePreflightResult {
        val captureManifestFile = File(captureDir, "synced_depth_manifest.json")
        val captureManifest = readRequiredJson(
            captureManifestFile,
            "synced depth manifest",
        )
        val pairsJson = captureManifest.optJSONArray("pairs")
            ?: throw CaptureBundlePreflightException(
                "synced_depth_manifest.json has no pairs array",
            )
        val pairs = buildList {
            for (index in 0 until pairsJson.length()) {
                val pair = pairsJson.optJSONObject(index)
                    ?: throw CaptureBundlePreflightException(
                        "pair entry $index is not an object",
                    )
                add(
                    StereoCapturePairInput(
                        pairIndex = pair.optInt("pair_index", index),
                        cam0File = pair.optString("cam0_file", ""),
                        cam1File = pair.optString("cam1_file", ""),
                    ),
                )
            }
        }

        val calibrationDir = calibrationSessionDir
            ?: throw CaptureBundlePreflightException(
                "calibration session is not selected",
            )
        val extrinsicsFile = File(calibrationDir, "stereo_extrinsics.json")
        val extrinsics = readRequiredJson(
            extrinsicsFile,
            "stereo extrinsics",
        )

        val calibration = StereoCalibrationInput(
            status = extrinsics.optString("status", ""),
            cam0CameraMatrix = jsonNumberList(
                extrinsics,
                "cam0_camera_matrix",
                "camera_matrix_0",
                "K0",
            ),
            cam0DistCoeffs = jsonNumberList(
                extrinsics,
                "cam0_dist_coeffs",
                "dist_coeffs_0",
                "D0",
            ),
            cam1CameraMatrix = jsonNumberList(
                extrinsics,
                "cam1_camera_matrix",
                "camera_matrix_1",
                "K1",
            ),
            cam1DistCoeffs = jsonNumberList(
                extrinsics,
                "cam1_dist_coeffs",
                "dist_coeffs_1",
                "D1",
            ),
            stereoRotation = jsonNumberList(
                extrinsics,
                "stereo_R",
                "R",
                "rotation_matrix",
            ),
            stereoTranslation = jsonNumberList(
                extrinsics,
                "stereo_T",
                "T",
                "translation_vector",
            ),
            cam0ImageWidth = jsonPositiveInt(
                extrinsics,
                "cam0_image_width",
                "image_width_0",
            ),
            cam0ImageHeight = jsonPositiveInt(
                extrinsics,
                "cam0_image_height",
                "image_height_0",
            ),
            cam1ImageWidth = jsonPositiveInt(
                extrinsics,
                "cam1_image_width",
                "image_width_1",
            ),
            cam1ImageHeight = jsonPositiveInt(
                extrinsics,
                "cam1_image_height",
                "image_height_1",
            ),
            rigId = jsonString(
                extrinsics,
                "rig_id",
                "rigId",
            ),
        )

        return StereoCaptureBundlePreflight.validate(
            StereoCaptureBundlePreflightInput(
                captureDir = captureDir,
                captureType = captureManifest.optString(
                    "capture_type",
                    "synced_depth_frames",
                ),
                pairs = pairs,
                captureRigId = jsonString(
                    captureManifest,
                    "rig_id",
                    "rigId",
                ),
                activeRigProfileId = jsonString(
                    activeRigProfileJson,
                    "rigId",
                    "rig_id",
                ),
                rawWidth = captureManifest.optInt("raw_width", 0),
                rawHeight = captureManifest.optInt("raw_height", 0),
                calibrationSessionDir = calibrationDir,
                extrinsicsFile = extrinsicsFile,
                calibration = calibration,
            ),
        )
    }

    private fun readRequiredJson(file: File, label: String): JSONObject {
        if (!file.isFile || file.length() <= 0L) {
            throw CaptureBundlePreflightException(
                "$label file is missing or empty: ${file.absolutePath}",
            )
        }
        return runCatching { JSONObject(file.readText()) }
            .getOrElse {
                throw CaptureBundlePreflightException(
                    "$label JSON is invalid: ${it.message ?: it.javaClass.simpleName}",
                )
            }
    }

    private fun jsonNumberList(
        json: JSONObject,
        vararg keys: String,
    ): List<Double> {
        for (key in keys) {
            if (!json.has(key) || json.isNull(key)) continue
            val values = mutableListOf<Double>()
            flattenJsonNumbers(json.get(key), key, values)
            return values
        }
        return emptyList()
    }

    private fun flattenJsonNumbers(
        value: Any?,
        path: String,
        output: MutableList<Double>,
    ) {
        when (value) {
            is Number -> output += value.toDouble()
            is JSONArray -> {
                for (index in 0 until value.length()) {
                    flattenJsonNumbers(
                        value.get(index),
                        "$path[$index]",
                        output,
                    )
                }
            }
            else -> throw CaptureBundlePreflightException(
                "$path must contain only numeric values",
            )
        }
    }

    private fun jsonPositiveInt(
        json: JSONObject,
        vararg keys: String,
    ): Int? {
        for (key in keys) {
            if (!json.has(key) || json.isNull(key)) continue
            return json.optInt(key, 0).takeIf { it > 0 }
        }
        return null
    }

    private fun jsonString(
        json: JSONObject?,
        vararg keys: String,
    ): String? {
        if (json == null) return null
        for (key in keys) {
            val value = json.optString(key, "").trim()
            if (value.isNotBlank()) return value
        }
        return null
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