package com.example.maklertour.auth

import android.content.Context
import android.util.Log
import com.example.maklertour.network.ApiConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject

data class MobileOrder(
    val id: Long,
    val brokerId: Long?,
    val operatorId: Long?,
    val isPublished: Boolean,
    val title: String,
    val address: String,
    val areaM2: String?,
    val customerName: String?,
    val customerPhone: String?,
    val customerEmail: String?,
    val status: String,
    val operatorClosedAt: String?,
    val brokerClosedAt: String?,
    val operatorClosedBy: Long?,
    val brokerClosedBy: Long?,
    val createdAt: String?,
    val updatedAt: String?,
)

class MobileOrdersApi(context: Context) {
    private val client = OkHttpClient.Builder().build()

    suspend fun getOrders(token: String, scope: String = "active"): OrdersResponse = withContext(Dispatchers.IO) {
        val url = "${ApiConfig.mobileApiUrl}?action=orders&scope=$scope"
        Log.d("MobileOrdersApi", "getOrders url=$url tokenPresent=${token.isNotBlank()}")

        val request = Request.Builder()
            .url(url)
            .header("Authorization", "Bearer $token")
            .get()
            .build()

        try {
            client.newCall(request).execute().use { response ->
                Log.d("MobileOrdersApi", "getOrders http=${response.code}")

                val body = response.body?.string().orEmpty()
                Log.d("MobileOrdersApi", "getOrders body=$body")

                if (response.code == 401) {
                    return@withContext OrdersResponse.Unauthorized
                }

                if (!response.isSuccessful) {
                    return@withContext OrdersResponse.Error("http ${response.code}: $body")
                }

                val json = JSONObject(body)

                if (!json.optBoolean("ok", false)) {
                    return@withContext OrdersResponse.Error(json.optString("error", "api error"))
                }

                val arr = json.optJSONArray("orders")
                    ?: return@withContext OrdersResponse.Success(emptyList())

                val orders = buildList {
                    for (i in 0 until arr.length()) {
                        val item = arr.optJSONObject(i) ?: continue

                        add(
                            MobileOrder(
                                id = item.optLong("id", 0L),
                                brokerId = item.optNullableLong("broker_id"),
                                operatorId = item.optNullableLong("operator_id"),
                                isPublished = item.optInt("is_published", 0) == 1,
                                title = item.optString("title", ""),
                                address = item.optString("address", ""),
                                areaM2 = item.optNullableString("area_m2"),
                                customerName = item.optNullableString("customer_name"),
                                customerPhone = item.optNullableString("customer_phone"),
                                customerEmail = item.optNullableString("customer_email"),
                                status = item.optString("status", "UNKNOWN"),
                                operatorClosedAt = item.optNullableString("operator_closed_at"),
                                brokerClosedAt = item.optNullableString("broker_closed_at"),
                                operatorClosedBy = item.optNullableLong("operator_closed_by"),
                                brokerClosedBy = item.optNullableLong("broker_closed_by"),
                                createdAt = item.optNullableString("created_at"),
                                updatedAt = item.optNullableString("updated_at"),
                            )
                        )
                    }
                }

                Log.d("MobileOrdersApi", "getOrders parsed count=${orders.size}")
                OrdersResponse.Success(orders)
            }
        } catch (e: Exception) {
            Log.e("MobileOrdersApi", "getOrders failed", e)
            OrdersResponse.Error(e.message ?: "network error")
        }
    }

    suspend fun takeOrder(token: String, orderId: Long): TakeOrderResponse = withContext(Dispatchers.IO) {
        val url = "${ApiConfig.mobileApiUrl}?action=take_order"
        Log.d("MobileOrdersApi", "takeOrder url=$url orderId=$orderId tokenPresent=${token.isNotBlank()}")

        val formBody = FormBody.Builder()
            .add("order_id", orderId.toString())
            .build()

        val request = Request.Builder()
            .url(url)
            .header("Authorization", "Bearer $token")
            .post(formBody)
            .build()

        try {
            client.newCall(request).execute().use { response ->
                val body = response.body?.string().orEmpty()
                Log.d("MobileOrdersApi", "takeOrder http=${response.code} body=$body")

                if (response.code == 401) {
                    return@withContext TakeOrderResponse.Unauthorized
                }

                if (response.code == 409) {
                    return@withContext TakeOrderResponse.Conflict("order already taken or unavailable")
                }

                if (!response.isSuccessful) {
                    return@withContext TakeOrderResponse.Error("http ${response.code}: $body")
                }

                val json = JSONObject(body)

                if (!json.optBoolean("ok", false)) {
                    return@withContext TakeOrderResponse.Error(json.optString("error", "api error"))
                }

                TakeOrderResponse.Success
            }
        } catch (e: Exception) {
            Log.e("MobileOrdersApi", "takeOrder failed", e)
            TakeOrderResponse.Error(e.message ?: "network error")
        }
    }

    suspend fun operatorCloseOrder(token: String, orderId: Long): OperatorCloseOrderResponse = withContext(Dispatchers.IO) {
        val url = "${ApiConfig.mobileApiUrl}?action=operator_close_order"
        val formBody = FormBody.Builder().add("order_id", orderId.toString()).build()
        val request = Request.Builder().url(url).header("Authorization", "Bearer $token").post(formBody).build()
        try {
            client.newCall(request).execute().use { response ->
                val body = response.body?.string().orEmpty()
                if (response.code == 401) return@withContext OperatorCloseOrderResponse.Unauthorized
                if (response.code == 403) return@withContext OperatorCloseOrderResponse.Forbidden
                if (!response.isSuccessful) return@withContext OperatorCloseOrderResponse.Error("http ${response.code}: $body")
                val json = JSONObject(body)
                if (!json.optBoolean("ok", false)) return@withContext OperatorCloseOrderResponse.Error(json.optString("error", "api error"))
                OperatorCloseOrderResponse.Success(
                    status = json.optString("status", "UNKNOWN"),
                    operatorClosedAt = json.optNullableString("operator_closed_at"),
                )
            }
        } catch (e: Exception) {
            OperatorCloseOrderResponse.Error(e.message ?: "network error")
        }
    }

}

private fun JSONObject.optNullableLong(name: String): Long? {
    if (!has(name) || isNull(name)) {
        return null
    }

    return runCatching {
        getLong(name)
    }.getOrNull()
}

private fun JSONObject.optNullableString(name: String): String? {
    if (!has(name) || isNull(name)) {
        return null
    }

    val value = optString(name, "")
        .trim()
        .takeIf { it.isNotBlank() && it.lowercase() != "null" }

    return value
}
sealed interface OrdersResponse {
    data class Success(val orders: List<MobileOrder>) : OrdersResponse
    data object Unauthorized : OrdersResponse
    data class Error(val message: String) : OrdersResponse
}

sealed interface TakeOrderResponse {
    data object Success : TakeOrderResponse
    data object Unauthorized : TakeOrderResponse
    data class Conflict(val message: String) : TakeOrderResponse
    data class Error(val message: String) : TakeOrderResponse
}

sealed interface OperatorCloseOrderResponse {
    data class Success(val status: String, val operatorClosedAt: String?) : OperatorCloseOrderResponse
    data object Unauthorized : OperatorCloseOrderResponse
    data object Forbidden : OperatorCloseOrderResponse
    data class Error(val message: String) : OperatorCloseOrderResponse
}