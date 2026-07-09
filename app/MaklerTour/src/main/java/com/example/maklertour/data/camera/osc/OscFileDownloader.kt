package com.maklertour.data.camera.osc

import android.content.Context
import android.graphics.BitmapFactory
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.util.Log
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLConnection
import java.security.MessageDigest
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

data class DownloadResult(
    val localPath: String?,
    val fileSizeBytes: Long?,
    val checksumSha256: String?,
    val contentType: String? = null,
    val width: Int? = null,
    val height: Int? = null,
    val looksLikeDualFisheye: Boolean = false,
    val error: String? = null,
)

class OscFileDownloader(
    private val context: Context,
    private val connectivityManager: ConnectivityManager? = null,
) {
    suspend fun downloadToFile(fileUrl: String, relativeTargetPath: String): DownloadResult =
        download(fileUrl, File(context.filesDir, relativeTargetPath))

    suspend fun downloadPreview(fileUrl: String, sessionId: String, pointId: String): DownloadResult =
        download(fileUrl, File(context.filesDir, "sessions/$sessionId/previews/$pointId.jpg"))

    suspend fun downloadOriginal(fileUrl: String, sessionId: String, pointId: String): DownloadResult =
        download(fileUrl, File(context.filesDir, "sessions/$sessionId/originals/$pointId.jpg"))

    private fun selectWifiNetwork(): Network? {
        val cm = connectivityManager ?: return null

        val wifiCandidates = cm.allNetworks.mapNotNull { network ->
            val caps = cm.getNetworkCapabilities(network) ?: return@mapNotNull null
            if (!caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)) {
                return@mapNotNull null
            }

            val lp = cm.getLinkProperties(network)
            val addresses = lp?.linkAddresses
                ?.mapNotNull { it.address.hostAddress }
                .orEmpty()

            val hasCameraSubnet = addresses.any { it.startsWith("192.168.42.") }

            Log.d(
                "OscFileDownloader",
                "wifi candidate network=$network addresses=$addresses hasCameraSubnet=$hasCameraSubnet routes=${lp?.routes}"
            )

            network to hasCameraSubnet
        }

        val selected = wifiCandidates.firstOrNull { it.second }?.first
            ?: wifiCandidates.firstOrNull()?.first

        Log.d("OscFileDownloader", "selected wifi network=$selected")
        return selected
    }

    private suspend fun download(fileUrl: String, target: File): DownloadResult =
        withContext(Dispatchers.IO) {
            runCatching {
                target.parentFile?.mkdirs()

                Log.d(
                    "OscFileDownloader",
                    "download(): start fileUrl=$fileUrl target=${target.absolutePath}"
                )

                val digest = MessageDigest.getInstance("SHA-256")
                val url = URL(fileUrl)
                val network = selectWifiNetwork()

                val rawConnection: URLConnection = if (network != null) {
                    Log.d("OscFileDownloader", "download(): using wifi network=$network")
                    network.openConnection(url)
                } else {
                    Log.d("OscFileDownloader", "download(): using default network")
                    url.openConnection()
                }

                val connection = rawConnection as HttpURLConnection

                try {
                    connection.connectTimeout = 15_000
                    connection.readTimeout = 60_000
                    connection.useCaches = false
                    connection.setRequestProperty("Connection", "close")

                    val code = connection.responseCode
                    Log.d("OscFileDownloader", "download(): responseCode=$code")

                    if (code !in 200..299) {
                        val errorBody = connection.errorStream
                            ?.bufferedReader()
                            ?.use { it.readText() }
                            .orEmpty()

                        error("HTTP $code $errorBody")
                    }

                    connection.inputStream.use { input ->
                        target.outputStream().use { output ->
                            val buffer = ByteArray(8 * 1024)
                            while (true) {
                                val read = input.read(buffer)
                                if (read <= 0) break
                                output.write(buffer, 0, read)
                                digest.update(buffer, 0, read)
                            }
                        }
                    }

                    val contentType = connection.contentType
                    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
                    BitmapFactory.decodeFile(target.absolutePath, bounds)
                    val width = bounds.outWidth.takeIf { it > 0 }
                    val height = bounds.outHeight.takeIf { it > 0 }
                    val looksLikeDualFisheye = looksLikeDualFisheyeJpeg(width, height, target.length())

                    val result = DownloadResult(
                        localPath = target.absolutePath,
                        fileSizeBytes = target.length(),
                        checksumSha256 = digest.digest().joinToString("") { "%02x".format(it) },
                        contentType = contentType,
                        width = width,
                        height = height,
                        looksLikeDualFisheye = looksLikeDualFisheye,
                    )

                    Log.d(
                        "OscFileDownloader",
                        "download(): success localPath=${result.localPath}, size=${result.fileSizeBytes}, contentType=$contentType, width=$width, height=$height, looksLikeDualFisheye=$looksLikeDualFisheye"
                    )

                    result
                } finally {
                    connection.disconnect()
                }
            }.getOrElse { error ->
                Log.d(
                    "OscFileDownloader",
                    "download(): failed fileUrl=$fileUrl target=${target.absolutePath}, error=${error.message}",
                    error
                )

                DownloadResult(
                    localPath = null,
                    fileSizeBytes = null,
                    checksumSha256 = null,
                    contentType = null,
                    width = null,
                    height = null,
                    looksLikeDualFisheye = false,
                    error = error.message ?: "download failed",
                )
            }
        }

    private fun looksLikeDualFisheyeJpeg(width: Int?, height: Int?, fileSizeBytes: Long): Boolean {
        if (width == null || height == null || height <= 0) return false
        val ratio = width.toDouble() / height.toDouble()
        val ratioNotEquirect = ratio < 1.8 || ratio > 2.2
        val verySmallForPano = fileSizeBytes in 1..1_500_000
        return ratioNotEquirect || verySmallForPano
    }
}