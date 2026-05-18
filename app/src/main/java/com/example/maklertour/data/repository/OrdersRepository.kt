package com.maklertour.data.repository

import android.util.Log
import com.example.maklertour.auth.AuthStorage
import com.example.maklertour.auth.MobileOrder
import com.example.maklertour.auth.MobileOrdersApi
import com.example.maklertour.auth.OperatorCloseOrderResponse
import com.example.maklertour.auth.OrdersResponse
import com.example.maklertour.auth.TakeOrderResponse

sealed interface OrdersRepoResult {
    data class Success(val orders: List<MobileOrder>) : OrdersRepoResult
    data object Unauthorized : OrdersRepoResult
    data class Error(val message: String) : OrdersRepoResult
}

sealed interface TakeOrderRepoResult {
    data class Success(val orders: List<MobileOrder>) : TakeOrderRepoResult
    data object Unauthorized : TakeOrderRepoResult
    data class Conflict(val message: String) : TakeOrderRepoResult
    data class Error(val message: String) : TakeOrderRepoResult
}
sealed interface OperatorCloseRepoResult {
    data class Success(val status: String, val operatorClosedAt: String?) : OperatorCloseRepoResult
    data object Unauthorized : OperatorCloseRepoResult
    data object Forbidden : OperatorCloseRepoResult
    data class Error(val message: String) : OperatorCloseRepoResult
}

class OrdersRepository(
    private val authStorage: AuthStorage,
    private val ordersApi: MobileOrdersApi,
) {
    suspend fun refreshOrders(scope: String = "active"): OrdersRepoResult {
        Log.d("OrdersRepository", "refresh start")
        val token = authStorage.getToken()
        if (token.isNullOrBlank()) {
            Log.e("OrdersRepository", "token missing")
            return OrdersRepoResult.Unauthorized
        }
        return when (val result = ordersApi.getOrders(token, scope)) {
            is OrdersResponse.Success -> OrdersRepoResult.Success(result.orders)
            is OrdersResponse.Unauthorized -> {
                authStorage.clear()
                OrdersRepoResult.Unauthorized
            }
            is OrdersResponse.Error -> OrdersRepoResult.Error(result.message)
        }
    }


    suspend fun takeOrder(orderId: Long): TakeOrderRepoResult {
        val token = authStorage.getToken()
        if (token.isNullOrBlank()) return TakeOrderRepoResult.Unauthorized

        return when (val result = ordersApi.takeOrder(token, orderId)) {
            is TakeOrderResponse.Success -> {
                when (val refresh = refreshOrders()) {
                    is OrdersRepoResult.Success -> TakeOrderRepoResult.Success(refresh.orders)
                    is OrdersRepoResult.Unauthorized -> TakeOrderRepoResult.Unauthorized
                    is OrdersRepoResult.Error -> TakeOrderRepoResult.Error(refresh.message)
                }
            }
            is TakeOrderResponse.Unauthorized -> {
                authStorage.clear()
                TakeOrderRepoResult.Unauthorized
            }
            is TakeOrderResponse.Conflict -> TakeOrderRepoResult.Conflict(result.message)
            is TakeOrderResponse.Error -> TakeOrderRepoResult.Error(result.message)
        }
    }

    suspend fun operatorCloseOrder(orderId: Long): OperatorCloseRepoResult {
        val token = authStorage.getToken()
        if (token.isNullOrBlank()) return OperatorCloseRepoResult.Unauthorized
        return when (val result = ordersApi.operatorCloseOrder(token, orderId)) {
            is OperatorCloseOrderResponse.Success -> OperatorCloseRepoResult.Success(result.status, result.operatorClosedAt)
            is OperatorCloseOrderResponse.Unauthorized -> {
                authStorage.clear()
                OperatorCloseRepoResult.Unauthorized
            }
            is OperatorCloseOrderResponse.Forbidden -> OperatorCloseRepoResult.Forbidden
            is OperatorCloseOrderResponse.Error -> OperatorCloseRepoResult.Error(result.message)
        }
    }
}
