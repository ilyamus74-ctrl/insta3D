package com.maklertour.data.camera.osc


import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.BufferedReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLConnection

class OscHttpClient(
    private val baseUrl: String,
    private val connectivityManager: ConnectivityManager? = null,
) {

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
                "OscHttpClient",
                "wifi candidate network=$network addresses=$addresses hasCameraSubnet=$hasCameraSubnet routes=${lp?.routes}"
            )

            network to hasCameraSubnet
        }

        val selected = wifiCandidates.firstOrNull { it.second }?.first
            ?: wifiCandidates.firstOrNull()?.first

        Log.d("OscHttpClient", "selected wifi network=$selected")
        return selected
    }
    suspend fun get(path: String): JSONObject = withContext(Dispatchers.IO) {
        request("GET", path, null)
    }

    suspend fun post(path: String, body: JSONObject): JSONObject = withContext(Dispatchers.IO) {
        request("POST", path, body)
    }

    private fun request(method: String, path: String, body: JSONObject?): JSONObject {
        val fullUrl = "$baseUrl$path"
        val url = URL(fullUrl)
        Log.d("OscHttpClient", "$method $fullUrl opening")

        val network = selectWifiNetwork()
        System.setProperty("http.keepAlive", "false")
        val rawConnection: URLConnection = if (network != null) {
            Log.d("OscHttpClient", "$method $fullUrl using wifi network=$network")
            network.openConnection(url)
        } else {
            Log.d("OscHttpClient", "$method $fullUrl using default network")
            url.openConnection()
        }

        val connection = (rawConnection as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 5000
            readTimeout = 10000
            useCaches = false
            setRequestProperty("Content-Type", "application/json;charset=utf-8")
            setRequestProperty("Connection", "close")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("X-XSRF-Protected", "1")
            setRequestProperty("Cache-Control", "no-cache")
            doInput = true
            if (method == "POST") {
                doOutput = true
            }
        }

        return try {
            Log.d("OscHttpClient", "$method $fullUrl body=${body?.toString()}")
            if (body != null) {
                OutputStreamWriter(connection.outputStream).use { writer ->
                    writer.write(body.toString())
                    writer.flush()
                }
            }

            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val raw = stream?.bufferedReader()?.use(BufferedReader::readText)?.ifBlank { "{}" } ?: "{}"
            Log.d("OscHttpClient", "$method $fullUrl -> $code, raw=$raw")
            Thread.sleep(80)
            JSONObject(raw)
        } finally {
            connection.disconnect()
        }
    }
}