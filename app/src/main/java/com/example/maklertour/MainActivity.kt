package com.maklertour

import android.content.Intent
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Bundle
import android.widget.Toast
import android.util.Log
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.ui.layout.ContentScale
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.Switch
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.zIndex
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.maklertour.BuildConfig
import com.maklertour.data.camera.Insta360OscProvider
import com.maklertour.data.camera.MockCameraProvider
import com.maklertour.data.camera.osc.OscHttpClient
import com.maklertour.data.camera.osc.OscFileDownloader
import com.maklertour.data.local.RoomDatabaseProvider
import com.maklertour.data.network.ConnectivityState
import com.maklertour.data.repository.RoomSessionRepository
import com.maklertour.data.repository.RoomUploadQueueRepository
import com.maklertour.data.sync.LocalOriginalManager
import com.maklertour.data.sync.MockSyncApi
import com.maklertour.data.sync.SyncRepository
import com.maklertour.state.AppStateViewModel
import com.maklertour.domain.VideoScanUiState
import com.maklertour.state.EnqueueUploadResult
import com.maklertour.i18n.AppLanguage
import com.maklertour.i18n.LanguagePreferences
import com.maklertour.i18n.DebugPreferences
import com.maklertour.i18n.withAppLanguage
import com.maklertour.ui.components.AppSectionCard
import com.maklertour.ui.components.AppStorageStatusRow
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.DisposableEffect
import com.example.maklertour.auth.AuthStorage
import com.example.maklertour.auth.LoginResult
import com.example.maklertour.auth.MobileAuthApi
import com.example.maklertour.auth.MobileOrder
import com.example.maklertour.auth.MobileOrdersApi
import com.example.maklertour.auth.MobileUploadApi
import com.example.maklertour.network.ApiConfig
import com.maklertour.data.repository.OrdersRepoResult
import com.maklertour.data.repository.TakeOrderRepoResult
import com.maklertour.data.repository.OrdersRepository
import kotlinx.coroutines.launch
import kotlinx.coroutines.delay
import coil.compose.AsyncImage
import java.io.File

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        RoomDatabaseProvider.get(this)
        setContent {
            MaklerTourApp()
        }
    }
}

private enum class AppTab(val route: String, val titleRes: Int) {
    Sessions("sessions", R.string.tab_sessions),
    Orders("orders", R.string.tab_orders),
    Camera("camera", R.string.tab_camera),
    Draft("draft", R.string.tab_draft),
    Queue("queue", R.string.tab_queue),
    Settings("settings", R.string.tab_settings)
}

@Composable
private fun MaklerTourApp() {
    val navController = rememberNavController()
    val baseContext = LocalContext.current
    val authStorage = remember { AuthStorage(baseContext.applicationContext) }
    val authApi = remember { MobileAuthApi(baseContext.applicationContext) }
    val ordersRepository = remember { OrdersRepository(authStorage, MobileOrdersApi(baseContext.applicationContext)) }
    val coroutineScope = rememberCoroutineScope()
    var isLoggedIn by remember { mutableStateOf(authStorage.isLoggedIn()) }
    var showLogin by remember { mutableStateOf(false) }
    var isBootChecking by remember { mutableStateOf(false) }
    var isLoginLoading by remember { mutableStateOf(false) }
    var loginError by remember { mutableStateOf<String?>(null) }
    var appLanguage by remember { mutableStateOf(LanguagePreferences.get(baseContext)) }
    var debugMode by remember { mutableStateOf(DebugPreferences.get(baseContext)) }
    val localizedContext: android.content.Context = remember(appLanguage, baseContext) {
        baseContext.withAppLanguage(appLanguage)
    }
    val database = remember { RoomDatabaseProvider.get(baseContext.applicationContext) }
    val viewModel = remember {
        val appConnectivityManager = baseContext.applicationContext
            .getSystemService(android.net.ConnectivityManager::class.java)

        val downloader = OscFileDownloader(
            context = baseContext.applicationContext,
            connectivityManager = appConnectivityManager,
        )
        AppStateViewModel(
            cameraProvider = if (BuildConfig.CAMERA_PROVIDER == "osc") {
                Insta360OscProvider(
                    OscHttpClient(
                        baseUrl = BuildConfig.INSTA360_OSC_BASE_URL,
                        connectivityManager = appConnectivityManager,
                    )
                )
            } else {
                MockCameraProvider()
            },
            sessionRepository = RoomSessionRepository(
                captureSessionDao = database.captureSessionDao(),
                capturePointDao = database.capturePointDao(),
                roomDao = database.roomDao(),
                tourDraftConnectionDao = database.tourDraftConnectionDao(),
                scanVideoDao = database.scanVideoDao(),
            ),
            uploadQueueRepository = RoomUploadQueueRepository(
                uploadItemDao = database.uploadItemDao(),
            ),
            localOriginalManager = LocalOriginalManager(database.capturePointDao(), downloader),
            syncRepository = SyncRepository(MockSyncApi()),
            mobileUploadApi = MobileUploadApi(authStorage),
            oscFileDownloader = downloader,
        )
    }
    val state by viewModel.uiState.collectAsState()
    val context = LocalContext.current
    var pendingSessionName by remember { mutableStateOf<String?>(null) }
    var orders by remember { mutableStateOf<List<MobileOrder>>(emptyList()) }
    var ordersError by remember { mutableStateOf<String?>(null) }
    var ordersLoading by remember { mutableStateOf(false) }
    var selectedOrder by remember { mutableStateOf<MobileOrder?>(null) }

    LaunchedEffect(Unit) {
        val existingToken = authStorage.getToken()
        if (!existingToken.isNullOrBlank()) {
            isBootChecking = true
            val pingOk = authApi.ping(existingToken)
            if (pingOk) {
                isLoggedIn = true
            } else {
                authStorage.clear()
                isLoggedIn = false
            }
            isBootChecking = false
        }
    }

    DisposableEffect(Unit) {
        val manager = context.applicationContext.getSystemService(ConnectivityManager::class.java)
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onCapabilitiesChanged(network: Network, networkCapabilities: NetworkCapabilities) {
                if (networkCapabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)) {
                    viewModel.processQueuedUploadsOnWifi()
                }
            }
        }
        manager?.registerDefaultNetworkCallback(callback)
        onDispose {
            runCatching { manager?.unregisterNetworkCallback(callback) }
        }
    }

    if (isBootChecking) {
        Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }

    if (!isLoggedIn) {
        if (showLogin) {
            LoginScreen(
                onLogin = { loginValue, password ->
                    if (isLoginLoading) return@LoginScreen
                    loginError = null
                    isLoginLoading = true
                    coroutineScope.launch {
                        when (val result = authApi.login(loginValue, password)) {
                            is LoginResult.Success -> {
                                authStorage.clear()
                                authStorage.saveToken(result.token)
                                authStorage.saveUserId(result.user.id.toLong())
                                isLoggedIn = true
                                showLogin = false
                                when (val refresh = ordersRepository.refreshOrders()) {
                                    is OrdersRepoResult.Success -> {
                                        orders = refresh.orders
                                        ordersError = null
                                        Log.d("OrdersScreen", "login refresh success count=${refresh.orders.size}")
                                    }
                                    is OrdersRepoResult.Unauthorized -> {
                                        Log.e("OrdersScreen", "login refresh unauthorized")
                                        isLoggedIn = false
                                        authStorage.clear()
                                    }
                                    is OrdersRepoResult.Error -> {
                                        ordersError = refresh.message
                                        Log.e("OrdersScreen", "login refresh error=${refresh.message}")
                                    }
                                }
                            }
                            is LoginResult.Error -> {
                                loginError = "Неверный логин или пароль"
                            }
                        }
                        isLoginLoading = false
                    }
                },
                onBack = { showLogin = false },
                isLoading = isLoginLoading,
                errorMessage = loginError,
            )
        } else {
            WelcomeScreen(
                onLoginClick = { showLogin = true },
                onRegisterClick = {
                    baseContext.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiConfig.registerUrl)))
                },
            )
        }
        return
    }

    CompositionLocalProvider(LocalContext provides localizedContext) {
        Scaffold(
            bottomBar = {
                NavigationBar {
                    val navBackStackEntry by navController.currentBackStackEntryAsState()
                    val currentDestination = navBackStackEntry?.destination

                    AppTab.entries.forEach { tab ->
                        val selected = currentDestination
                            ?.hierarchy
                            ?.any { it.route == tab.route } == true

                        NavigationBarItem(
                            selected = selected,
                            onClick = {
                                navController.navigate(tab.route) {
                                    popUpTo(navController.graph.findStartDestination().id) {
                                        saveState = true
                                    }
                                    launchSingleTop = true
                                    restoreState = true
                                }
                            },
                            icon = { Text("•") },
                            label = { Text(stringResource(tab.titleRes)) }
                        )
                    }
                }
            }
        ) { padding ->
            NavHost(
                navController = navController,
                startDestination = AppTab.Sessions.route,
                modifier = Modifier.padding(padding)
            ) {
                composable(AppTab.Sessions.route) {
                    SessionsScreen(
                        state = state,
                        orders = orders,
                        initialName = pendingSessionName,
                        onNameConsumed = { pendingSessionName = null },
                        onCreate = viewModel::createSession,
                        onSelect = viewModel::selectSession,
                        onDelete = viewModel::deleteSession,
                        onAttachToOrder = { sessionId, order ->
                            viewModel.attachSessionToOrder(sessionId, order)
                        },
                        onOpenOrdersTab = {
                            navController.navigate(AppTab.Orders.route) {
                                launchSingleTop = true
                            }
                        },
                    )
                }
                composable(AppTab.Orders.route) {
                    LaunchedEffect(Unit) {
                        if (orders.isEmpty() && !ordersLoading) {
                            ordersLoading = true
                            when (val refresh = ordersRepository.refreshOrders()) {
                                is OrdersRepoResult.Success -> {
                                    orders = refresh.orders
                                    ordersError = null
                                }
                                is OrdersRepoResult.Unauthorized -> {
                                    authStorage.clear()
                                    isLoggedIn = false
                                }
                                is OrdersRepoResult.Error -> {
                                    ordersError = refresh.message
                                }
                            }
                            ordersLoading = false
                        }
                    }
                    OrdersScreen(
                        orders = orders,
                        currentUserId = authStorage.getUserId(),
                        isLoading = ordersLoading,
                        error = ordersError,
                        debugMode = debugMode,
                        onRefresh = {
                            coroutineScope.launch {
                                ordersLoading = true
                                when (val refresh = ordersRepository.refreshOrders()) {
                                    is OrdersRepoResult.Success -> { orders = refresh.orders; ordersError = null }
                                    is OrdersRepoResult.Unauthorized -> {
                                        ordersError = "Сессия истекла. Войдите снова."
                                        authStorage.clear()
                                        isLoggedIn = false
                                    }
                                    is OrdersRepoResult.Error -> {
                                        ordersError = refresh.message.ifBlank { "Ошибка загрузки заявок" }
                                    }
                                }
                                ordersLoading = false
                            }
                        },
                        onOpen = {
                            selectedOrder = it
                            viewModel.selectOrder(it)
                            navController.navigate("order_work")
                        },
                        onTakeOrder = { order ->
                            coroutineScope.launch {
                                when (val result = ordersRepository.takeOrder(order.id)) {
                                    is TakeOrderRepoResult.Success -> {
                                        orders = result.orders
                                        ordersError = null
                                    }
                                    is TakeOrderRepoResult.Unauthorized -> {
                                        authStorage.clear()
                                        isLoggedIn = false
                                    }
                                    is TakeOrderRepoResult.Conflict -> {
                                        ordersError = result.message
                                        when (val refresh = ordersRepository.refreshOrders()) {
                                            is OrdersRepoResult.Success -> { orders = refresh.orders }
                                            else -> {}
                                        }
                                    }
                                    is TakeOrderRepoResult.Error -> {
                                        ordersError = result.message
                                    }
                                }
                            }
                        }
                    )
                }
                composable("order_work") {
                    val orderSessions = selectedOrder?.let { order ->
                        state.sessions.filter { it.serverOrderId == order.id }
                    }.orEmpty()
                    val selectedOrderSession = orderSessions.firstOrNull { it.id == state.selectedSessionId }
                    val hasSelectedOrderSession = selectedOrderSession != null
                    OrderWorkScreen(
                        order = selectedOrder,
                        currentUserId = authStorage.getUserId(),
                        orderSessions = orderSessions,
                        selectedSessionId = state.selectedSessionId,
                        selectedOrderSession = selectedOrderSession,
                        uploadQueueSessionIds = state.uploadQueue.map { it.sessionId }.toSet(),
                        onStartCapture = {
                            if (!hasSelectedOrderSession) return@OrderWorkScreen
                            navController.navigate(AppTab.Camera.route)
                        },
                        onDraft = {
                            if (!hasSelectedOrderSession) return@OrderWorkScreen
                            navController.navigate(AppTab.Draft.route)
                        },
                        onVideoScans = {
                            if (!hasSelectedOrderSession) return@OrderWorkScreen
                            navController.navigate(AppTab.Camera.route)
                        },
                        onUploads = {
                            if (!hasSelectedOrderSession) return@OrderWorkScreen
                            navController.navigate(AppTab.Queue.route)
                        },
                        onCreateLocalSession = {
                            selectedOrder?.let { order ->
                                viewModel.createSession(
                                    order.title,
                                    order.address,
                                    "Заявка #${order.id}",
                                )
                            }
                        },
                        onSelectOrderSession = viewModel::selectSession,
                        onOpenOrderSessionDraft = { sessionId ->
                            viewModel.selectSession(sessionId)
                            navController.navigate(AppTab.Draft.route)
                        },
                        onContinueOrderSessionCapture = { sessionId ->
                            viewModel.selectSession(sessionId)
                            navController.navigate(AppTab.Camera.route)
                        },
                        onTakeOrder = {
                            selectedOrder?.let { order ->
                                coroutineScope.launch {
                                    when (val result = ordersRepository.takeOrder(order.id)) {
                                        is TakeOrderRepoResult.Success -> {
                                            orders = result.orders
                                            selectedOrder = result.orders.firstOrNull { it.id == order.id } ?: selectedOrder
                                            selectedOrder?.let { viewModel.selectOrder(it) }
                                            ordersError = null
                                        }
                                        is TakeOrderRepoResult.Unauthorized -> {
                                            authStorage.clear()
                                            isLoggedIn = false
                                        }
                                        is TakeOrderRepoResult.Conflict -> {
                                            ordersError = result.message
                                        }
                                        is TakeOrderRepoResult.Error -> {
                                            ordersError = result.message
                                        }
                                    }
                                }
                            }
                        },
                        onBack = { navController.popBackStack() },
                        debugMode = debugMode,
                    )
                }
                composable(AppTab.Camera.route) {
                    CameraScreen(
                        connected = state.cameraStatus.isConnected,
                        model = state.cameraStatus.model,
                        battery = state.cameraStatus.batteryPercent,
                        freeStorageMb = state.cameraStatus.freeStorageMb,
                        onConnect = viewModel::connectCamera,
                        onDisconnect = viewModel::disconnectCamera,
                        onRefresh = viewModel::refreshCameraStatus,
                        onCapture = viewModel::capturePoint,
                        selectedSessionName = state.selectedSessionName,
                        selectedSessionPointsCount = state.selectedSessionPointsCount,
                        isCapturing = state.isCapturing,
                        isRecordingScanVideo = state.isRecordingScanVideo,
                        videoScanUiState = state.videoScanUiState,
                        scanVideos = state.selectedSessionScanVideos,
                        onStartVideoScan = viewModel::startVideoScan,
                        onStopVideoScan = viewModel::stopVideoScan,
                        onCreateSessionRequested = {
                            pendingSessionName = localizedContext.getString(R.string.quick_capture)
                            navController.navigate(AppTab.Sessions.route) {
                                popUpTo(navController.graph.findStartDestination().id) {
                                    saveState = true
                                }
                                launchSingleTop = true
                                restoreState = true
                            }
                        },
                        onDeleteVideoScan = viewModel::deleteScanVideo,
                        onDownloadVideoScan = viewModel::downloadVideoScan,
                        debugMode = debugMode,
                        selectedOrder = selectedOrder,
                    )
                }
                composable(AppTab.Draft.route) {
                    val selectedSession =
                        state.sessions.firstOrNull { it.id == state.selectedSessionId }
                    DraftScreen(
                        sessionName = selectedSession?.name
                            ?: localizedContext.getString(R.string.session_not_selected),
                        sessionOrderId = selectedSession?.serverOrderId,
                        sessionOrderTitle = selectedSession?.orderTitle,
                        points = selectedSession?.points.orEmpty(),
                        onRename = viewModel::renamePoint,
                        onDelete = viewModel::deletePoint,
                        onMoveUp = viewModel::movePointUp,
                        onMoveDown = viewModel::movePointDown,
                        onDownloadOriginals = viewModel::downloadOriginalsForSession,
                        onSyncServer = viewModel::syncSessionMetadata,
                        onAddToUploadQueue = viewModel::enqueueUpload,
                        onClearConfirmed = viewModel::clearConfirmedLocalOriginals,
                        rooms = state.selectedSessionRooms,
                        startPointId = state.selectedSessionStartPointId,
                        connections = state.selectedSessionConnections,
                        onCreateRoom = viewModel::createRoom,
                        onAssignPointToRoom = viewModel::assignPointToRoom,
                        onSetStartPoint = viewModel::setStartPoint,
                        onCreateConnection = viewModel::createConnection,
                        onDeleteConnection = viewModel::deleteConnection,
                        scanVideos = state.selectedSessionScanVideos,
                        onDeleteVideoScan = viewModel::deleteScanVideo,
                        onDownloadVideoScan = viewModel::downloadVideoScan,
                        debugMode = debugMode,
                    )
                }
                composable(AppTab.Queue.route) {
                    QueueScreen(
                        selectedOrder = selectedOrder,
                        queue = state.uploadQueue,
                        onEnqueue = viewModel::enqueueUpload,
                        onUpload = viewModel::processUpload,
                        uploadError = state.uploadError,
                        onExportDiagnostics = { viewModel.exportDiagnosticJson(debugMode) },
                    )
                }

                composable(AppTab.Settings.route) {
                    SettingsScreen(
                        currentLanguage = appLanguage,
                        onLanguageSelected = { language ->
                            appLanguage = language
                            LanguagePreferences.set(baseContext, language)
                        },
                        debugMode = debugMode,
                        onDebugModeChanged = { enabled ->
                            debugMode = enabled
                            DebugPreferences.set(baseContext, enabled)
                        },
                        onLogout = {
                            val currentToken = authStorage.getToken()
                            coroutineScope.launch {
                                if (!currentToken.isNullOrBlank()) {
                                    authApi.logout(currentToken)
                                }
                                authStorage.clear()
                                isLoggedIn = false
                                showLogin = false
                            }
                        },
                    )
                }
            }
        }
    }
}

@Composable
private fun OrdersScreen(
    orders: List<MobileOrder>,
    currentUserId: Long?,
    isLoading: Boolean,
    error: String?,
    debugMode: Boolean,
    onRefresh: () -> Unit,
    onOpen: (MobileOrder) -> Unit,
    onTakeOrder: (MobileOrder) -> Unit,
) {
    var filter by remember { mutableStateOf("ALL") }
    var filterMenuExpanded by remember { mutableStateOf(false) }
    val orderFilters = listOf(
        "ALL" to "Все",
        "AVAILABLE" to "Доступные",
        "MY_WORK" to "В работе",
        "MY_CREATED" to "Мои заявки",
        "CAPTURED" to "Отснято",
        "UPLOADED" to "Загружено",
        "READY" to "Готово",
    )
    val currentFilterLabel = orderFilters.firstOrNull { it.first == filter }?.second ?: "Все"
    val filtered = orders.filter { order ->
        when (filter) {
            "ALL" -> {
                val mine = currentUserId != null && order.brokerId == currentUserId
                val publishedAvailable = order.isPublished &&
                        order.status == "NEW" &&
                        order.operatorId == null
                mine || publishedAvailable
            }
            "AVAILABLE" -> order.isPublished &&
                    order.status == "NEW" &&
                    order.operatorId == null
            "MY_CREATED" -> currentUserId != null &&
                    order.brokerId == currentUserId
            "MY_WORK" -> currentUserId != null &&
                    order.operatorId == currentUserId &&
                    order.status in listOf(
                "ASSIGNED",
                "IN_PROGRESS",
                "CAPTURED",
                "UPLOADING",
                "UPLOADED",
                "PROCESSING"
            )
            "CAPTURED" -> currentUserId != null &&
                order.operatorId == currentUserId &&
                order.status == "CAPTURED"
            "UPLOADED" -> currentUserId != null &&
                order.operatorId == currentUserId &&
                order.status == "UPLOADED"
            "READY" -> currentUserId != null &&
                    (order.operatorId == currentUserId || order.brokerId == currentUserId) &&
                    order.status == "READY"
            else -> false
        }
    }
    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Text("Заявки", style = MaterialTheme.typography.headlineSmall)
            Button(onClick = onRefresh, enabled = !isLoading) { Text("Refresh") }
        }
        if (isLoading) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                CircularProgressIndicator(modifier = Modifier.size(18.dp))
                Text("Загрузка заявок...")
            }
        }
        if (!error.isNullOrBlank()) Text(error, color = Color.Red)
        Box {
            Button(onClick = { filterMenuExpanded = true }, modifier = Modifier.fillMaxWidth()) {
                Text("Фильтр: $currentFilterLabel")
            }

            DropdownMenu(
                expanded = filterMenuExpanded,
                onDismissRequest = { filterMenuExpanded = false },
            ) {
                orderFilters.forEach { (key, label) ->
                    DropdownMenuItem(
                        text = { Text(label) },
                        onClick = {
                            filter = key
                            filterMenuExpanded = false
                        },
                    )
                }
            }
        }
        if (debugMode) {
            Text("DEBUG: total=${orders.size}, filtered=${filtered.size}, loading=$isLoading, error=${error ?: "-"}, filter=$filter")
        }
        if (!isLoading && error == null && orders.isEmpty()) {
            Text("Сервер вернул 0 заявок или список не обновился. Нажмите Refresh.")
        } else if (!isLoading && filtered.isEmpty() && orders.isNotEmpty()) {
            Text("Нет заявок по выбранному фильтру. Всего получено: ${orders.size}")
        }
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(filtered.size) { i ->
                val o = filtered[i]
                Card(onClick = { onOpen(o) }) {
                    Column(Modifier.padding(12.dp)) {
                        Text("#${o.id} ${o.title}")
                        Text(o.address)
                        Text("Площадь: ${o.areaM2 ?: "-"}")
                        Text("Клиент: ${o.customerName ?: "-"}")
                        Text("Телефон: ${o.customerPhone ?: "-"}")
                        Text("Email: ${o.customerEmail ?: "-"}")
                        Text("Статус: ${o.status}")
                        Text("Обновлено: ${o.updatedAt ?: "-"}")
                        if (o.isPublished && o.status == "NEW" && o.operatorId == null) {                            Button(onClick = { onTakeOrder(o) }, modifier = Modifier.fillMaxWidth()) {
                                Text("Взять в работу")
                            }
                        }
                        if (debugMode) {
                            Text("debug brokerId=${o.brokerId ?: "-"}, operatorId=${o.operatorId ?: "-"}, isPublished=${o.isPublished}")
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun OrderWorkScreen(
    order: MobileOrder?,
    currentUserId: Long?,
    orderSessions: List<com.maklertour.domain.Session>,
    selectedSessionId: String?,
    selectedOrderSession: com.maklertour.domain.Session?,
    uploadQueueSessionIds: Set<String>,
    onStartCapture: () -> Unit,
    onDraft: () -> Unit,
    onVideoScans: () -> Unit,
    onUploads: () -> Unit,
    onCreateLocalSession: () -> Unit,
    onSelectOrderSession: (String) -> Unit,
    onOpenOrderSessionDraft: (String) -> Unit,
    onContinueOrderSessionCapture: (String) -> Unit,
    onTakeOrder: () -> Unit,
    onBack: () -> Unit,
    debugMode: Boolean,
) {
    val canWork = order != null && currentUserId != null && order.operatorId == currentUserId
    val isAvailable = order?.isPublished == true && order.status == "NEW" && order.operatorId == null
    val isAssignedToAnother = order?.status == "ASSIGNED" && order.operatorId != null && order.operatorId != currentUserId
    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(onClick = onBack) { Text("← К списку заявок") }
        Text("Работа по заявке", style = MaterialTheme.typography.headlineSmall)
        Text("ID: ${order?.id ?: "-"}")
        Text(order?.title ?: "—")
        Text(order?.address ?: "—")
        Text("Площадь: ${order?.areaM2 ?: "-"}")
        Text("Клиент: ${order?.customerName ?: "-"}")
        Text("Телефон: ${order?.customerPhone ?: "-"}")
        Text("Email: ${order?.customerEmail ?: "-"}")
        Text("Статус: ${order?.status ?: "-"}")
        if (debugMode) Text("debug brokerId=${order?.brokerId ?: "-"}, operatorId=${order?.operatorId ?: "-"}, isPublished=${order?.isPublished ?: "-"}")
        if (isAvailable) {
            Button(onClick = onTakeOrder, modifier = Modifier.fillMaxWidth()) { Text("Взять в работу") }
        }
        if (isAssignedToAnother) {
            Text("Заявка уже в работе у другого оператора", color = Color.Red)
        }
        Button(
            onClick = onCreateLocalSession,
            modifier = Modifier.fillMaxWidth(),
            enabled = canWork,
        ) { Text("Создать локальную сессию для этой заявки") }

        Text("Локальные сессии этой заявки", style = MaterialTheme.typography.titleMedium)
        if (orderSessions.isEmpty()) {
            Text("По этой заявке пока нет локальных сессий.")
            Text("Создайте новую сессию или привяжите существующую во вкладке 'Сессии'.")
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                orderSessions.forEach { session ->
                    var menuExpanded by remember(session.id) { mutableStateOf(false) }

                    val isSelected = session.id == selectedSessionId
                    val uploadedCount = session.points.count {
                        it.serverUploadState == com.maklertour.domain.ServerUploadState.CONFIRMED
                    }

                    val queueState = when {
                        uploadQueueSessionIds.contains(session.id) -> "в очереди"
                        session.serverCaptureSessionId != null -> "загружено"
                        else -> "локально"
                    }

                    Card(modifier = Modifier.fillMaxWidth()) {
                        Column(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(12.dp),
                            verticalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.Top,
                            ) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(
                                        session.name.ifBlank { "Без названия" },
                                        style = MaterialTheme.typography.titleSmall,
                                    )

                                    if (isSelected) {
                                        Text(
                                            "Выбрана для этой заявки",
                                            color = MaterialTheme.colorScheme.primary,
                                        )
                                    }
                                }

                                Box {
                                    IconButton(onClick = { menuExpanded = true }) {
                                        Text("⋮")
                                    }

                                    DropdownMenu(
                                        expanded = menuExpanded,
                                        onDismissRequest = { menuExpanded = false },
                                    ) {
                                        DropdownMenuItem(
                                            text = { Text("Выбрать сессию") },
                                            onClick = {
                                                menuExpanded = false
                                                onSelectOrderSession(session.id)
                                            },
                                        )

                                        DropdownMenuItem(
                                            text = { Text("Открыть черновик") },
                                            onClick = {
                                                menuExpanded = false
                                                onOpenOrderSessionDraft(session.id)
                                            },
                                        )

                                        DropdownMenuItem(
                                            text = { Text("Продолжить съемку") },
                                            onClick = {
                                                menuExpanded = false
                                                onContinueOrderSessionCapture(session.id)
                                            },
                                        )

                                        DropdownMenuItem(
                                            text = { Text("Открыть очередь") },
                                            onClick = {
                                                menuExpanded = false
                                                onSelectOrderSession(session.id)
                                                onUploads()
                                            },
                                        )
                                    }
                                }
                            }

                            Text("Фото: ${session.points.size}")
                            Text("Видео: —")
                            Text("Статус: $queueState")
                            Text("Подтверждено фото: $uploadedCount")
                        }
                    }
                }
            }
        }
        if (selectedOrderSession == null) {
            Text("Выберите сессию этой заявки", color = Color.Red)
        } else {
            Text("Активная сессия: ${selectedOrderSession.name}")
        }
        val canOpenSelectedSession = canWork && selectedOrderSession != null

        Button(
            onClick = onStartCapture,
            modifier = Modifier.fillMaxWidth(),
            enabled = canOpenSelectedSession,
        ) {
            Text("Начать/продолжить съемку")
        }

        Button(
            onClick = onDraft,
            modifier = Modifier.fillMaxWidth(),
            enabled = canOpenSelectedSession,
        ) {
            Text("Черновик")
        }

        Button(
            onClick = onVideoScans,
            modifier = Modifier.fillMaxWidth(),
            enabled = canOpenSelectedSession,
        ) {
            Text("Видео сканы")
        }

        Button(
            onClick = onUploads,
            modifier = Modifier.fillMaxWidth(),
            enabled = canOpenSelectedSession,
        ) {
            Text("Загрузки")
        }
    }
}

@Composable
private fun WelcomeScreen(
    onLoginClick: () -> Unit,
    onRegisterClick: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterVertically),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("MaklerTour", style = MaterialTheme.typography.headlineMedium)
        Text("Войдите в аккаунт или зарегистрируйтесь на сайте.")
        Button(onClick = onLoginClick, modifier = Modifier.fillMaxWidth()) { Text("Войти") }
        Button(onClick = onRegisterClick, modifier = Modifier.fillMaxWidth()) { Text("Зарегистрироваться") }
    }
}

@Composable
private fun LoginScreen(
    onLogin: (String, String) -> Unit,
    onBack: () -> Unit,
    isLoading: Boolean,
    errorMessage: String?,
) {
    var login by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterVertically),
    ) {
        Text("Вход", style = MaterialTheme.typography.headlineMedium)
        OutlinedTextField(value = login, onValueChange = { login = it }, label = { Text("Login / Email") }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(value = password, onValueChange = { password = it }, label = { Text("Password") }, modifier = Modifier.fillMaxWidth())
        Button(
            onClick = { onLogin(login, password) },
            modifier = Modifier.fillMaxWidth(),
            enabled = !isLoading,
        ) { Text(if (isLoading) "Загрузка..." else "Войти") }
        if (!errorMessage.isNullOrBlank()) {
            Text(errorMessage, color = Color.Red)
        }
        TextButton(onClick = onBack, modifier = Modifier.fillMaxWidth(), enabled = !isLoading) { Text("Назад") }
    }
}

@Composable
private fun SettingsScreen(
    currentLanguage: AppLanguage,
    onLanguageSelected: (AppLanguage) -> Unit,
    debugMode: Boolean,
    onDebugModeChanged: (Boolean) -> Unit,
    onLogout: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(stringResource(R.string.settings))
        Text(stringResource(R.string.app_language))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Switch(checked = debugMode, onCheckedChange = onDebugModeChanged)
            Column {
                Text(stringResource(R.string.debug_mode_title))
                Text(stringResource(R.string.debug_mode_description), style = MaterialTheme.typography.bodySmall)
            }
        }

        AppLanguage.entries.forEach { language ->
            Button(
                onClick = { onLanguageSelected(language) },
                modifier = Modifier.fillMaxWidth(),
            ) {
                val selectedMark = if (language == currentLanguage) "✓ " else ""
                Text(selectedMark + language.label)
            }
        }
        Button(onClick = onLogout, modifier = Modifier.fillMaxWidth()) {
            Text("Выйти из аккаунта")
        }
    }
}

@Composable
private fun SessionsScreen(
    state: com.maklertour.state.AppUiState,
    orders: List<MobileOrder>,
    initialName: String?,
    onNameConsumed: () -> Unit,
    onCreate: (String, String, String) -> Unit,
    onSelect: (String) -> Unit,
    onDelete: (String) -> Unit,
    onAttachToOrder: (sessionId: String, order: MobileOrder) -> Unit,
    onOpenOrdersTab: () -> Unit,
) {
    var name by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var comment by remember { mutableStateOf("") }
    var deleteSessionId by remember { mutableStateOf<String?>(null) }
    var attachSessionId by remember { mutableStateOf<String?>(null) }
    val context = LocalContext.current

    LaunchedEffect(initialName) {
        if (!initialName.isNullOrBlank()) {
            name = initialName
            onNameConsumed()
        }
    }

    Column(modifier = Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(stringResource(R.string.dashboard))
        OutlinedTextField(value = name, onValueChange = { name = it }, label = { Text(stringResource(R.string.name)) }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(value = address, onValueChange = { address = it }, label = { Text(stringResource(R.string.address)) }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(value = comment, onValueChange = { comment = it }, label = { Text(stringResource(R.string.comment)) }, modifier = Modifier.fillMaxWidth())
        Button(
            onClick = {
                if (name.isNotBlank()) {
                    onCreate(name, address, comment)
                    name = ""
                    address = ""
                    comment = ""
                }
            }
        ) { Text(stringResource(R.string.create_session)) }

        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            itemsIndexed(state.sessions) { _, session ->
                Card(onClick = { onSelect(session.id) }, modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(12.dp)) {

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(session.name)
                            var expanded by remember { mutableStateOf(false) }
                            Box {
                                TextButton(onClick = { expanded = true }) { Text("⋮") }
                                DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
                                    DropdownMenuItem(
                                        text = { Text("Удалить сессию") },
                                        onClick = {
                                            expanded = false
                                            deleteSessionId = session.id
                                        }
                                    )
                                    DropdownMenuItem(
                                        text = { Text("Привязать к заявке") },
                                        onClick = {
                                            expanded = false
                                            attachSessionId = session.id
                                        }
                                    )
                                }
                            }
                        }
                        if (session.id == state.selectedSessionId) {
                            Text(stringResource(R.string.active_session))
                        }
                        Text(session.address)
                        Text("${stringResource(R.string.points)}: ${session.points.size}")
                        if (session.serverOrderId != null) {
                            Text("Заявка: #${session.serverOrderId} ${session.orderTitle.orEmpty()}".trim())
                            Text("Адрес заявки: ${session.orderAddress ?: "—"}")
                        } else {
                            Text("Заявка: не привязана")
                        }
                    }
                }
            }
        }
    }

    deleteSessionId?.let { sessionId ->
        AlertDialog(
            onDismissRequest = { deleteSessionId = null },
            title = { Text("Удаление сессии") },
            text = { Text("Вы уверены, что хотите удалить эту сессию? Это действие нельзя отменить.") },
            confirmButton = {
                TextButton(
                    onClick = {
                        onDelete(sessionId)
                        deleteSessionId = null
                    }
                ) { Text(stringResource(R.string.delete)) }
            },
            dismissButton = {
                TextButton(onClick = { deleteSessionId = null }) {
                    Text(stringResource(android.R.string.cancel))
                }
            }
        )
    }

    attachSessionId?.let { sessionId ->
        AlertDialog(
            onDismissRequest = { attachSessionId = null },
            title = { Text("Привязать к заявке") },
            text = {
                if (orders.isEmpty()) {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Список заявок пуст. Откройте вкладку Заявки и нажмите Refresh")
                        Button(onClick = {
                            attachSessionId = null
                            onOpenOrdersTab()
                        }) {
                            Text("Открыть заявки")
                        }
                    }
                } else {
                    LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        itemsIndexed(orders) { _, order ->
                            Card(onClick = {
                                onAttachToOrder(sessionId, order)
                                Toast.makeText(context, "Сессия привязана к заявке #${order.id}", Toast.LENGTH_SHORT).show()
                                attachSessionId = null
                            }, modifier = Modifier.fillMaxWidth()) {
                                Column(modifier = Modifier.padding(8.dp)) {
                                    Text("Заявка #${order.id}")
                                    Text(order.title ?: "Без названия")
                                    Text(order.address ?: "Адрес не указан")
                                }
                            }
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { attachSessionId = null }) { Text("Закрыть") }
            },
        )
    }
}

@Composable
private fun CameraScreen(
    connected: Boolean,
    model: String?,
    battery: Int?,
    freeStorageMb: Long?,
    onConnect: () -> Unit,
    onDisconnect: () -> Unit,
    onRefresh: () -> Unit,
    onCapture: (String) -> Unit,
    selectedSessionName: String?,
    selectedSessionPointsCount: Int,
    isCapturing: Boolean,
    isRecordingScanVideo: Boolean,
    videoScanUiState: VideoScanUiState,
    scanVideos: List<com.maklertour.domain.ScanVideo>,
    onStartVideoScan: (String) -> Unit,
    onStopVideoScan: () -> Unit,
    onCreateSessionRequested: () -> Unit,
    onDeleteVideoScan: (String) -> Unit,
    onDownloadVideoScan: (String) -> Unit,
    debugMode: Boolean,
    selectedOrder: MobileOrder?,
) {
    var captureMode by remember { mutableStateOf(CaptureMode.PHOTO_POINT) }
    var pointName by remember { mutableStateOf("") }
    var scanName by remember { mutableStateOf("") }
    var showNoSessionDialog by remember { mutableStateOf(false) }
    val videoScanBusy = videoScanUiState in setOf(VideoScanUiState.SWITCHING_MODE, VideoScanUiState.RECORDING, VideoScanUiState.STOPPING)
    val defaultPointName = stringResource(R.string.point_default_format, selectedSessionPointsCount + 1)
    val defaultScanName = stringResource(R.string.scan_video_default_name_format, scanVideos.size + 1)
    LaunchedEffect(connected) {
        while (connected) {
            delay(5_000)
            onRefresh()
        }
    }
    LaunchedEffect(selectedSessionName, selectedSessionPointsCount) {
        if (pointName.isBlank()) pointName = defaultPointName
    }
    LaunchedEffect(selectedSessionName, scanVideos.size) {
        if (scanName.isBlank()) scanName = defaultScanName
    }

    Box(modifier = Modifier.fillMaxSize()) {
        Column(
            modifier = Modifier.fillMaxSize().padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            AppSectionCard(
                title = stringResource(R.string.tab_camera),
                subtitle = if (selectedSessionName != null) stringResource(R.string.active_session_format, selectedSessionName) else stringResource(R.string.session_not_selected),
            ) {
                Text(if (connected) stringResource(R.string.camera_connected) else stringResource(R.string.camera_not_connected))
                Text(stringResource(R.string.model_format, model ?: "—"))
                Text(stringResource(R.string.battery_format, battery?.let { "$it%" } ?: "—"))
                Text(stringResource(R.string.free_space_format, freeStorageMb?.let { stringResource(R.string.mb_format, it.toInt()) } ?: "—"))

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = onConnect, enabled = !isCapturing && !isRecordingScanVideo && !videoScanBusy) { Text(stringResource(R.string.connect)) }
                    Button(onClick = onDisconnect, enabled = !isCapturing && !isRecordingScanVideo && !videoScanBusy) { Text(stringResource(R.string.disconnect)) }
                }
            }

            AppSectionCard(title = stringResource(R.string.capture_point)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        onClick = { captureMode = CaptureMode.PHOTO_POINT },
                        enabled = captureMode != CaptureMode.PHOTO_POINT && !isCapturing && !isRecordingScanVideo && !videoScanBusy,
                    ) { Text(stringResource(R.string.capture_mode_photo)) }
                    Button(
                        onClick = { captureMode = CaptureMode.VIDEO_SCAN },
                        enabled = captureMode != CaptureMode.VIDEO_SCAN && !isCapturing && !isRecordingScanVideo && !videoScanBusy,
                    ) { Text(stringResource(R.string.capture_mode_video)) }
                }

                if (captureMode == CaptureMode.PHOTO_POINT) {
                    OutlinedTextField(
                        value = pointName,
                        onValueChange = { pointName = it },
                        label = { Text(stringResource(R.string.point_name)) },
                        modifier = Modifier.fillMaxWidth(),
                        enabled = !isCapturing && !isRecordingScanVideo && !videoScanBusy,
                    )
                    Button(
                        onClick = {
                            if (selectedSessionName == null) {
                                showNoSessionDialog = true
                            } else if (connected && pointName.isNotBlank()) {
                                val capturedName = pointName.trim()
                                onCapture(capturedName)
                                pointName = nextPointName(capturedName)
                            }
                        },
                        enabled = pointName.isNotBlank() && !isCapturing && !isRecordingScanVideo && !videoScanBusy,
                    ) { Text(stringResource(R.string.capture_point)) }
                }

                if (captureMode == CaptureMode.VIDEO_SCAN) {
                    OutlinedTextField(
                        value = scanName,
                        onValueChange = { scanName = it },
                        label = { Text(stringResource(R.string.video_scan_name)) },
                        modifier = Modifier.fillMaxWidth(),
                        enabled = !isCapturing && !isRecordingScanVideo && !videoScanBusy,
                    )
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(
                            onClick = {
                                if (selectedSessionName == null) {
                                    showNoSessionDialog = true
                                } else if (connected && scanName.isNotBlank()) {
                                    onStartVideoScan(scanName.trim())
                                }
                            },
                            enabled = connected && !videoScanBusy && !isCapturing && scanName.isNotBlank(),
                        ) { Text(stringResource(R.string.start_video_scan)) }
                        Button(onClick = onStopVideoScan, enabled = connected && isRecordingScanVideo) {
                            Text(stringResource(R.string.stop_video_scan))
                        }
                    }
                    when (videoScanUiState) {
                        VideoScanUiState.SWITCHING_MODE, VideoScanUiState.RECORDING, VideoScanUiState.STOPPING -> {
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                                CircularProgressIndicator(modifier = Modifier.size(20.dp))
                                Text(
                                    when (videoScanUiState) {
                                        VideoScanUiState.SWITCHING_MODE -> "Preparing video mode..."
                                        VideoScanUiState.RECORDING -> "Recording video scan..."
                                        VideoScanUiState.STOPPING -> "Saving video scan..."
                                        else -> ""
                                    }
                                )
                            }
                        }
                        VideoScanUiState.CAPTURED -> Text("Video scan saved")
                        VideoScanUiState.FAILED -> Text("Video scan failed. Photo capture is still available.")
                        else -> Unit
                    }
                    VideoScansBlock(
                        scanVideos = scanVideos,
                        onDelete = onDeleteVideoScan,
                        onDownload = onDownloadVideoScan,
                        debugMode = debugMode,
                    )
                }
            }
        }

        if (isCapturing && !isRecordingScanVideo) {
            CaptureOverlay()
        }
    }
    if (showNoSessionDialog) {
        AlertDialog(
            onDismissRequest = { showNoSessionDialog = false },
            title = { Text(stringResource(R.string.session_not_selected)) },
            text = { Text(stringResource(R.string.to_capture_select_session)) },
            confirmButton = {
                TextButton(onClick = {
                    showNoSessionDialog = false
                    onCreateSessionRequested()
                    }) { Text(stringResource(R.string.create_session)) }
                },
            dismissButton = {
                TextButton(onClick = { showNoSessionDialog = false }) { Text(stringResource(R.string.cancel)) }
            }
        )
    }

}


@Composable
private fun CaptureOverlay() {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color.Black.copy(alpha = 0.45f))
            .zIndex(1f),
        contentAlignment = Alignment.Center,
    ) {
        Surface {
            Column(
                modifier = Modifier.padding(horizontal = 24.dp, vertical = 20.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                CircularProgressIndicator(modifier = Modifier.size(36.dp))
                Text(stringResource(R.string.capturing_point), style = MaterialTheme.typography.titleMedium)
                Text(stringResource(R.string.do_not_turn_off_camera))
            }
        }
    }
}
private fun nextPointName(currentName: String): String {
    val trimmed = currentName.trim()
    if (trimmed.isBlank()) return "Точка 1"

    val match = Regex("^(.*?)(?:\\s+(\\d+))?$").matchEntire(trimmed)
        ?: return "$trimmed 1"

    val prefix = match.groupValues.getOrNull(1)?.trim().orEmpty()
    val number = match.groupValues.getOrNull(2)?.toIntOrNull()

    return if (number != null) {
        "$prefix ${number + 1}".trim()
    } else {
        "$trimmed 1"
    }
}
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DraftScreen(
    sessionName: String,
    sessionOrderId: Long?,
    sessionOrderTitle: String?,
    points: List<com.maklertour.domain.CapturePoint>,
    onRename: (String, String) -> Unit,
    onDelete: (String) -> Unit,
    onMoveUp: (Int) -> Unit,
    onMoveDown: (Int) -> Unit,
    onDownloadOriginals: () -> Unit,
    onSyncServer: () -> Unit,
    onAddToUploadQueue: () -> EnqueueUploadResult,
    onClearConfirmed: () -> Unit,
    rooms: List<com.maklertour.domain.RoomDraft>,
    startPointId: String?,
    connections: List<com.maklertour.domain.TourDraftConnection>,
    scanVideos: List<com.maklertour.domain.ScanVideo>,
    onCreateRoom: (String, String) -> Unit,
    onAssignPointToRoom: (String, String?) -> Unit,
    onSetStartPoint: (String) -> Unit,
    onCreateConnection: (String, String) -> Unit,
    onDeleteConnection: (String) -> Unit,
    onDeleteVideoScan: (String) -> Unit,
    onDownloadVideoScan: (String) -> Unit,
    debugMode: Boolean,
) {
    val unassignedPoints = points.filter { it.roomId == null }
    val roomsSorted = rooms.sortedBy { it.orderIndex }
    val context = LocalContext.current
    LaunchedEffect(scanVideos.size) {
        Log.d("DraftScreen", "DraftScreen videoScans count=${scanVideos.size}")
    }
    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        item {
            Text(stringResource(R.string.session_points_format, sessionName))
            Text(stringResource(R.string.points_in_selected_session_format, points.size))
            if (sessionOrderId != null) {
                Text("Заявка: #$sessionOrderId ${sessionOrderTitle.orEmpty()}".trim())
            } else {
                Text("Заявка: не привязана")
            }
        }

        item {
            ProjectDraftPreviewBlock(points = points, scanVideos = scanVideos, debugMode = debugMode)
        }


        item {
            ProjectControlsBlock(
                onDownloadOriginals = onDownloadOriginals,
                onSyncServer = onSyncServer,
                onAddToUploadQueue = {
                    when (val result = onAddToUploadQueue()) {
                        EnqueueUploadResult.Enqueued -> Toast.makeText(context, "Сессия добавлена в очередь", Toast.LENGTH_SHORT).show()
                        is EnqueueUploadResult.Rejected -> Toast.makeText(context, result.reason, Toast.LENGTH_SHORT).show()
                    }
                },
                onClearConfirmed = onClearConfirmed,
            )
        }

        item {
            RoomsBlock(rooms = roomsSorted, points = points, onCreateRoom = onCreateRoom)
        }

        item {
            VideoScansBlock(
                scanVideos = scanVideos,
                onDelete = onDeleteVideoScan,
                onDownload = onDownloadVideoScan,
                debugMode = debugMode,
            )
        }

        item {
            DraftValidationCard(points = points, rooms = roomsSorted, startPointId = startPointId, scanVideos = scanVideos)
        }

        item {
            Text(stringResource(R.string.draft_points_by_rooms), style = MaterialTheme.typography.titleMedium)
        }

        if (points.isEmpty()) {
            item {
                Text(stringResource(R.string.no_points_in_session))
            }
        }

        if (points.isNotEmpty() && unassignedPoints.isNotEmpty()) {
            item {
                Text(stringResource(R.string.draft_room_points_format, stringResource(R.string.without_room), unassignedPoints.size))
            }
            itemsIndexed(items = unassignedPoints, key = { _, point -> point.id }) { _, point ->
                val originalIndex = points.indexOfFirst { it.id == point.id }
                DraftPointCard(originalIndex, point, roomsSorted, null, startPointId == point.id, points, onRename, onDelete, onMoveUp, onMoveDown, onAssignPointToRoom, onSetStartPoint, onCreateConnection, debugMode)
            }
        }

        roomsSorted.forEach { room ->
            val roomPoints = points.filter { it.roomId == room.id }
            item {
                Text(stringResource(R.string.draft_room_points_format, room.name, roomPoints.size))
            }
            itemsIndexed(items = roomPoints, key = { _, point -> point.id }) { _, point ->
                val originalIndex = points.indexOfFirst { it.id == point.id }
                DraftPointCard(originalIndex, point, roomsSorted, room.name, startPointId == point.id, points, onRename, onDelete, onMoveUp, onMoveDown, onAssignPointToRoom, onSetStartPoint, onCreateConnection, debugMode)

            }
        }

        item {
            ConnectionsBlock(points = points, connections = connections, onDeleteConnection = onDeleteConnection)
        }
    }
}

@Composable
private fun VideoScansBlock(
    scanVideos: List<com.maklertour.domain.ScanVideo>,
    onDelete: (String) -> Unit,
    onDownload: (String) -> Unit,
    debugMode: Boolean,
) {
    Text(stringResource(R.string.video_scans), style = MaterialTheme.typography.titleMedium)

    if (scanVideos.isEmpty()) {
        Text(stringResource(R.string.no_video_scans))
    } else {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            scanVideos.forEach { scan ->
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(
                        modifier = Modifier.padding(12.dp),
                        verticalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        Text(scan.name, style = MaterialTheme.typography.titleSmall)
                        if (scan.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED) {
                            Text(stringResource(R.string.video_captured))
                            scan.durationSec?.let { Text(stringResource(R.string.video_duration_sec_format, it)) }
                            Text(scan.cameraFileUrl?.substringAfterLast("/") ?: "—")
                        } else if (scan.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.FAILED) {
                            Text(stringResource(R.string.video_failed))
                        } else {
                            Text(stringResource(R.string.scan_video_status_format, scan.captureStatus.name))
                        }
                        val phoneFileText = when {
                            scan.localVideoPath != null -> stringResource(R.string.video_downloaded)
                            scan.downloadState == com.maklertour.domain.ScanVideoDownloadState.DOWNLOADING -> stringResource(R.string.video_downloading)
                            scan.downloadState == com.maklertour.domain.ScanVideoDownloadState.DOWNLOAD_ERROR -> stringResource(R.string.video_download_error)
                            else -> stringResource(R.string.phone_file_missing)
                        }
                        Text(phoneFileText)
                        Text(
                            if (scan.uploadState == com.maklertour.domain.ScanVideoUploadState.LOCAL_ONLY) {
                                stringResource(R.string.server_not_uploaded)
                            } else {
                                stringResource(R.string.scan_video_upload_format, scan.uploadState.name)
                            }
                        )
                        if (debugMode) {
                            Text(stringResource(R.string.debug_details))
                            Text("sessionId=${scan.sessionId}")
                            Text("createdAt=${scan.createdAt}")
                            Text("updatedAt=${scan.updatedAt}")
                            Text("captureStatus=${scan.captureStatus}")
                            Text("downloadState=${scan.downloadState}")
                            Text("uploadState=${scan.uploadState}")
                            Text("serverProcessingState=${scan.serverProcessingState}")
                            Text("cameraFileUrl=${scan.cameraFileUrl}")
                            Text("cameraLocalFileUrl=${scan.cameraLocalFileUrl}")
                            Text("localVideoPath=${scan.localVideoPath}")
                            Text("notes=${scan.notes}")
                            Text("durationSec=${scan.durationSec}")
                        }

                        if (scan.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED &&
                            scan.localVideoPath == null &&
                            scan.downloadState != com.maklertour.domain.ScanVideoDownloadState.DOWNLOADING
                        ) {
                            Button(
                                onClick = { onDownload(scan.id) },
                                modifier = Modifier.fillMaxWidth(),
                            ) {
                                Text(stringResource(R.string.download_video_to_phone))
                            }
                        }
                        if (scan.localVideoPath != null) {
                            Text(stringResource(R.string.video_downloaded))
                        }

                        Button(
                            onClick = { onDelete(scan.id) },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text(stringResource(R.string.delete_scan))
                        }
                    }
                }
            }
        }
    }
}


@Composable
private fun ProjectDraftPreviewBlock(
    points: List<com.maklertour.domain.CapturePoint>,
    scanVideos: List<com.maklertour.domain.ScanVideo>,
    debugMode: Boolean,
) {
    val previewReady = points.count { !it.localPreviewPath.isNullOrBlank() || !it.previewUri.isNullOrBlank() }
    val hasCapturedVideo = scanVideos.any { it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED }
    val hasError = scanVideos.any { it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.FAILED }
    val ready = points.isNotEmpty() && hasCapturedVideo && previewReady == points.size
    Text(stringResource(R.string.draft_preview_title), style = MaterialTheme.typography.titleMedium)
    Card {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(stringResource(R.string.photo_points_count_format, points.size))
            Text(stringResource(R.string.video_scans_count_format, scanVideos.size))
            Text(stringResource(R.string.preview_count_format, previewReady, points.size))
            Text(stringResource(R.string.video_exists_format, stringResource(if (hasCapturedVideo) R.string.yes else R.string.no)))
            Text(
                stringResource(
                    R.string.readiness_format,
                    stringResource(if (hasError) R.string.readiness_has_errors else if (ready) R.string.readiness_ready else R.string.readiness_needs_capture)
                )
            )
            if (debugMode) {
                Text("debug: hasCapturedVideo=$hasCapturedVideo ready=$ready")
            }
        }
    }
}
@Composable
private fun ProjectControlsBlock(
    onDownloadOriginals: () -> Unit,
    onSyncServer: () -> Unit,
    onAddToUploadQueue: () -> Unit,
    onClearConfirmed: () -> Unit,
) {
    Text(stringResource(R.string.draft_project_controls), style = MaterialTheme.typography.titleMedium)
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(onClick = onDownloadOriginals, modifier = Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.download_originals_to_phone))
        }
        Button(onClick = onSyncServer, modifier = Modifier.fillMaxWidth()) {
            Text("Синхронизировать metadata")
        }
        Button(onClick = onAddToUploadQueue, modifier = Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.sync_with_server))
        }
        Button(onClick = onClearConfirmed, modifier = Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.clear_local_originals_after_confirmation))
        }
    }
}


@Composable
private fun RoomsBlock(rooms: List<com.maklertour.domain.RoomDraft>, points: List<com.maklertour.domain.CapturePoint>, onCreateRoom: (String, String) -> Unit) { var roomName by remember { mutableStateOf("") }; Text(stringResource(R.string.draft_rooms), style = MaterialTheme.typography.titleMedium); OutlinedTextField(value = roomName, onValueChange = { roomName = it }, label = { Text(stringResource(R.string.room_name)) }, modifier = Modifier.fillMaxWidth()); Button(onClick = { if (roomName.isNotBlank()) { onCreateRoom(roomName, "OTHER"); roomName = "" } }) { Text(stringResource(R.string.create_room)) }; rooms.forEach { room -> Text(stringResource(R.string.draft_room_points_format, room.name, points.count { it.roomId == room.id })) } }

@Composable
private fun DraftValidationCard(points: List<com.maklertour.domain.CapturePoint>, rooms: List<com.maklertour.domain.RoomDraft>, startPointId: String?, scanVideos: List<com.maklertour.domain.ScanVideo>) { val totalPoints = points.size; val pointsWithoutRoom = points.count { it.roomId == null }; val hasStartPoint = startPointId != null; val previewsReady = points.count { !it.localPreviewPath.isNullOrBlank() || !it.previewUri.isNullOrBlank() }; val originalsReady = points.count { it.localOriginalState == com.maklertour.domain.FileLocalState.DOWNLOADED }; val serverReady = points.count { it.serverUploadState == com.maklertour.domain.ServerUploadState.CONFIRMED }; val hasCapturedVideo = scanVideos.any { it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED }; val draftReady = totalPoints > 0 && hasCapturedVideo && previewsReady == totalPoints; Text(stringResource(R.string.draft_validation), style = MaterialTheme.typography.titleMedium); Card { Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) { if (totalPoints == 0) Text("• ${stringResource(R.string.validation_no_points)}"); if (!hasCapturedVideo) Text("• ${stringResource(R.string.validation_no_video)}"); if (previewsReady < totalPoints) Text("• ${stringResource(R.string.validation_missing_preview)}"); if (rooms.isEmpty()) Text("! ${stringResource(R.string.validation_no_rooms)}"); if (pointsWithoutRoom > 0) Text("! ${stringResource(R.string.validation_points_without_room)}"); if (!hasStartPoint) Text("! ${stringResource(R.string.validation_no_start_point)}"); if (originalsReady < totalPoints) Text("! ${stringResource(R.string.validation_originals_not_downloaded)}"); if (serverReady < totalPoints) Text("! ${stringResource(R.string.validation_not_uploaded)}"); Text(stringResource(if (draftReady) R.string.draft_ready else R.string.draft_not_ready)) } } }


@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DraftPointCard(index: Int, point: com.maklertour.domain.CapturePoint, rooms: List<com.maklertour.domain.RoomDraft>, currentRoomName: String?, isStartPoint: Boolean, allPoints: List<com.maklertour.domain.CapturePoint>, onRename: (String, String) -> Unit, onDelete: (String) -> Unit, onMoveUp: (Int) -> Unit, onMoveDown: (Int) -> Unit, onAssignPointToRoom: (String, String?) -> Unit, onSetStartPoint: (String) -> Unit, onCreateConnection: (String, String) -> Unit, debugMode: Boolean) { var localName by remember(point.id) { mutableStateOf(point.name) };
    val previewSource = point.localPreviewPath ?: point.previewUri

    val previewModel = when {
        previewSource.isNullOrBlank() -> null
        previewSource.startsWith("file:") -> previewSource
        previewSource.startsWith("/") -> File(previewSource)
        previewSource.startsWith("http://192.168.42.1") -> null
        previewSource.startsWith("https://192.168.42.1") -> null
        previewSource.startsWith("http") -> previewSource
        else -> previewSource
    }

    val previewIsOnlyOnCamera = previewModel == null && !point.cameraFileUrl.isNullOrBlank()
    val cameraExists = point.cameraFileUrl != null && point.cameraDeleteState != com.maklertour.domain.DeleteState.DELETED;
    val phonePreviewExists = point.localPreviewPath != null;
    val phoneOriginalText = when (point.localOriginalState) { com.maklertour.domain.FileLocalState.DOWNLOADED -> stringResource(R.string.downloaded);
        com.maklertour.domain.FileLocalState.DOWNLOADING -> stringResource(R.string.downloading);
        com.maklertour.domain.FileLocalState.DOWNLOAD_ERROR -> stringResource(R.string.error);
        com.maklertour.domain.FileLocalState.NOT_DOWNLOADED -> stringResource(R.string.not_exists) };
    val serverText = when (point.serverUploadState) { com.maklertour.domain.ServerUploadState.CONFIRMED -> stringResource(R.string.uploaded);
        com.maklertour.domain.ServerUploadState.UPLOADING -> stringResource(R.string.downloading);
        com.maklertour.domain.ServerUploadState.QUEUED -> stringResource(R.string.upload_queued);
        com.maklertour.domain.ServerUploadState.ERROR -> stringResource(R.string.error);
        com.maklertour.domain.ServerUploadState.NOT_QUEUED -> stringResource(R.string.not_uploaded) };
    val cameraDeleteText = when (point.cameraDeleteState) { com.maklertour.domain.DeleteState.DELETED -> stringResource(R.string.deleted);
        com.maklertour.domain.DeleteState.DELETE_ERROR -> stringResource(R.string.delete_error);
        com.maklertour.domain.DeleteState.DELETE_REQUESTED -> stringResource(R.string.deleting);
        else -> stringResource(R.string.camera_clean) };
    Card(modifier = Modifier.fillMaxWidth()) { Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) { Text(stringResource(R.string.point_number_format, index + 1));
        Text(point.name); if (isStartPoint) { Text(stringResource(R.string.draft_start_point)) };
        Text(stringResource(R.string.draft_current_room_format, currentRoomName ?: "—"));
        if (previewModel != null) {
            AsyncImage(
                model = previewModel,
                contentDescription = point.name,
                modifier = Modifier.fillMaxWidth().height(100.dp),
                contentScale = ContentScale.Crop,
            )
        } else if (previewIsOnlyOnCamera) {
            Text("Preview is on camera. Waiting for local preview download.")
        } else {
            Text("Preview not ready")
        }
        AppStorageStatusRow(cameraExists = cameraExists, phonePreviewExists = phonePreviewExists, phoneOriginalText = phoneOriginalText, serverText = serverText, cameraDeleteText = cameraDeleteText); OutlinedTextField(value = localName, onValueChange = { localName = it }, label = { Text(stringResource(R.string.rename)) }, modifier = Modifier.fillMaxWidth()); Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) { Button(onClick = { onRename(point.id, localName) }) { Text(stringResource(R.string.save)) }; Button(onClick = { onMoveUp(index) }) { Text("↑") }; Button(onClick = { onMoveDown(index) }) { Text("↓") }; Button(onClick = { onDelete(point.id) }) { Text(stringResource(R.string.delete)) }; Button(onClick = { onSetStartPoint(point.id) }) { Text(stringResource(R.string.draft_make_start_point)) } }; if (debugMode) { Text(stringResource(R.string.debug_details)); Text("id=${point.id}"); Text("cameraFileUrl=${point.cameraFileUrl}"); Text("localPreviewPath=${point.localPreviewPath}"); Text("localOriginalPath=${point.localOriginalPath}"); Text("capturedAt=${point.capturedAt}"); Text("cameraDeleteState=${point.cameraDeleteState}"); Text("serverUploadState=${point.serverUploadState}") }; Text(stringResource(R.string.draft_assign_room)); FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) { Button(onClick = { onAssignPointToRoom(point.id, null) }) { Text(stringResource(R.string.without_room)) }; rooms.forEach { room -> TextButton(onClick = { onAssignPointToRoom(point.id, room.id) }) { Text(room.name) } } }; Text(stringResource(R.string.draft_connect_to)); FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) { allPoints.filter { it.id != point.id }.forEach { other -> TextButton(onClick = { onCreateConnection(point.id, other.id) }) { Text(other.name) } } } } } }

@Composable
private fun ConnectionsBlock(points: List<com.maklertour.domain.CapturePoint>, connections: List<com.maklertour.domain.TourDraftConnection>, onDeleteConnection: (String) -> Unit) { Text(stringResource(R.string.draft_connections), style = MaterialTheme.typography.titleMedium); if (connections.isEmpty()) { Text(stringResource(R.string.draft_no_connections)); return }; connections.forEach { c -> val fromName = points.firstOrNull { it.id == c.fromPointId }?.name ?: c.fromPointId; val toName = points.firstOrNull { it.id == c.toPointId }?.name ?: c.toPointId; Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) { Text("$fromName → $toName"); TextButton(onClick = { onDeleteConnection(c.id) }) { Text(stringResource(R.string.delete)) } } } }
@Composable
private fun QueueScreen(
    selectedOrder: MobileOrder?,
    queue: List<com.maklertour.domain.UploadItem>,
    onEnqueue: () -> EnqueueUploadResult,
    onUpload: (String) -> Unit,
    uploadError: String?,
    onExportDiagnostics: () -> String,
) {
    val clipboardManager = LocalClipboardManager.current
    val context = LocalContext.current
    var filter by remember { mutableStateOf("Новые") }
    val filteredQueue = when (filter) {
        "Новые" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Queued }
        "В процессе" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Uploading }
        "Ошибки" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Error }
        "Успешные" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Success }
        else -> queue
    }
    Column(modifier = Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (selectedOrder != null) {
            Text("Заявка #${selectedOrder.id} — ${selectedOrder.title}")
        } else {
            Text("Локальный черновик без заявки")
        }

        Text(stringResource(R.string.upload_queue))
        if (!uploadError.isNullOrBlank()) {
            Text("Ошибка upload: $uploadError", color = MaterialTheme.colorScheme.error)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("Новые", "В процессе", "Ошибки", "Успешные", "Все").forEach {
                TextButton(onClick = { filter = it }) { Text(it) }
            }
        }
        Button(
            onClick = {
                when (val result = onEnqueue()) {
                    EnqueueUploadResult.Enqueued -> {
                        Toast.makeText(context, "Сессия добавлена в очередь", Toast.LENGTH_SHORT).show()
                    }
                    is EnqueueUploadResult.Rejected -> {
                        Toast.makeText(context, result.reason, Toast.LENGTH_SHORT).show()
                    }
                }
            }
        ) { Text(stringResource(R.string.add_to_queue)) }
        Button(
            onClick = {
                val diagnostics = onExportDiagnostics()
                clipboardManager.setText(AnnotatedString(diagnostics))
                Toast.makeText(context, "diagnostic JSON скопирован", Toast.LENGTH_SHORT).show()
            }
        ) { Text(stringResource(R.string.copy_diagnostic_json)) }
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            itemsIndexed(filteredQueue) { _, item ->
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(stringResource(R.string.session_format, item.sessionId.take(8)))
                        Text(stringResource(R.string.status_format, item.status))
                        Text(stringResource(R.string.retry_format, item.retryCount.toString()))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = {
                                if (!hasAnyInternet(context)) {
                                    Toast.makeText(context, "Нет подключения к интернету", Toast.LENGTH_SHORT).show()
                                } else {
                                    onUpload(item.id)
                                }
                            }, enabled = item.status != com.maklertour.domain.UploadStatus.Uploading) {
                                Text(
                                    when (item.status) {
                                        com.maklertour.domain.UploadStatus.Uploading -> "Отправляется..."
                                        com.maklertour.domain.UploadStatus.Queued -> "Отправить на сервер"
                                        else -> "Отправить на сервер повторно"
                                    }
                                )
                            }
                        }
                        Text("progress=${if (item.status == com.maklertour.domain.UploadStatus.Success) 100 else item.progressPercent}%")
                        Text("step=${item.currentStep ?: "—"} file=${item.currentFileName ?: "—"}")
                        Text("bytes=${item.bytesUploaded}/${item.bytesTotal}")
                        Text("updatedAt=${item.updatedAt}")
                    }
                }
            }
        }
    }

}private enum class CaptureMode {
    PHOTO_POINT,
    VIDEO_SCAN,
}

private fun hasAnyInternet(context: android.content.Context): Boolean {
    val manager = context.getSystemService(ConnectivityManager::class.java) ?: return false
    val network = manager.activeNetwork ?: return false
    val caps = manager.getNetworkCapabilities(network) ?: return false
    return caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) || caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)
}