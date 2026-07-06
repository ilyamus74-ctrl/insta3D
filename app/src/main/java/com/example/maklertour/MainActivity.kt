package com.maklertour

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.Uri
import android.hardware.Sensor
import android.hardware.SensorManager
import android.provider.Settings
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import android.util.Log
import android.view.TextureView
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.layout.ContentScale
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Switch
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.key
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size as ComposeSize
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.core.app.ActivityCompat
import androidx.camera.view.PreviewView
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.window.Dialog
import com.maklertour.data.phonecamera.PhoneCameraBindResult
import com.maklertour.data.phonecamera.PhoneCameraLensOption
import com.maklertour.data.phonecamera.PhoneCameraLensRepository
import androidx.compose.ui.window.DialogProperties
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
import com.maklertour.data.phonecamera.PhoneCameraScanProvider
import com.maklertour.data.phonecamera.StereoCaptureExperimentalManager
import com.maklertour.data.phonecamera.StereoRigConfig
import com.maklertour.data.phonecamera.UsbUvcStatus
import com.maklertour.data.phonecamera.PhoneScanCalibrationMetadata
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
import com.maklertour.data.calibration.CalibrationBoardDetector
import com.maklertour.data.calibration.CalibrationDetectionResult
import com.maklertour.data.calibration.OpenCvCalibrationBoardDetector
import com.maklertour.data.rig.CalibrationSettings
import com.maklertour.data.rig.CalibrationStatus
import com.maklertour.data.rig.CameraMode
import com.maklertour.data.rig.CameraModeSelection
import com.maklertour.data.rig.CameraModeSource
import com.maklertour.data.rig.StereoRigProfile
import com.maklertour.data.rig.StereoRigProfileStore
import com.maklertour.data.rig.toJson
import kotlinx.coroutines.launch
import kotlinx.coroutines.delay
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import org.json.JSONArray
import coil.compose.AsyncImage
import java.io.File
import java.util.TimeZone
import java.util.Locale
import java.util.Date
import java.text.SimpleDateFormat
import java.io.FileOutputStream
import android.graphics.Bitmap

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

private fun iconForTab(tab: AppTab): ImageVector = when (tab) {
    AppTab.Sessions -> Icons.Filled.List
    AppTab.Orders -> Icons.Filled.List
    AppTab.Camera -> Icons.Filled.List
    AppTab.Draft -> Icons.Filled.List
    AppTab.Queue -> Icons.Filled.List
    AppTab.Settings -> Icons.Filled.Settings
}

@Composable
private fun MaklerTourApp() {
    val navController = rememberNavController()
    val baseContext = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
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
            phoneCameraScanProvider = PhoneCameraScanProvider(baseContext.applicationContext, lifecycleOwner),
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
                if (
                    networkCapabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
                    networkCapabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) &&
                    networkCapabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)
                ) {
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
                            icon = { Icon(iconForTab(tab), contentDescription = stringResource(tab.titleRes)) },
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
                        onStartPhoneVideoScan = viewModel::startPhoneVideoScan,
                        onBindPhoneCameraPreview = viewModel::bindPhoneCameraPreview,
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
                        onClearSessionQueue = viewModel::clearUploadQueueForSelectedSession,
                        onRequeueAllVideos = viewModel::requeueAllVideosForSelectedSession,
                        onRequeueVideo = viewModel::requeueVideo,
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
                        onClearSessionQueue = viewModel::clearUploadQueueForSelectedSession,
                        onRequeueAllVideos = viewModel::requeueAllVideosForSelectedSession,
                        onRequeueVideo = viewModel::requeueVideo,
                        debugMode = debugMode,
                    )
                }
                composable(AppTab.Queue.route) {
                    QueueScreen(
                        selectedOrder = selectedOrder,
                        sessions = state.sessions,
                        queue = state.uploadQueue,
                        onEnqueue = viewModel::enqueueUpload,
                        onUpload = viewModel::processUpload,
                        onResetQueueItem = viewModel::resetUploadQueueItem,
                        onDeleteQueueItem = viewModel::deleteUploadQueueItem,
                        onClearAllQueue = viewModel::clearAllUploadQueue,
                        onClearCompletedQueue = viewModel::clearCompletedUploadQueue,
                        onClearFailedQueue = viewModel::clearFailedUploadQueue,
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
    val context = LocalContext.current
    val store = remember(context) { StereoRigProfileStore(context) }
    var activeProfile by remember { mutableStateOf(store.loadActiveProfile()) }
    var dialog by remember { mutableStateOf<String?>(null) }
    val refreshProfile: (StereoRigProfile) -> Unit = { profile ->
        store.saveActiveProfile(profile)
        activeProfile = profile
    }

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(stringResource(R.string.settings))
        StereoRigSettingsSection(
            profile = activeProfile,
            onEditProfile = { dialog = "edit" },
            onCameraModes = { dialog = "modes" },
            onCalibrationSetup = { dialog = "calibration" },
        )
        Text("System", style = MaterialTheme.typography.titleMedium)
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

    when (dialog) {
        "edit" -> EditRigProfileDialog(activeProfile, onDismiss = { dialog = null }) { refreshProfile(it); dialog = null }
        "modes" -> CameraModesDialog(activeProfile, onDismiss = { dialog = null }) { refreshProfile(it); dialog = null }
        "calibration" -> CalibrationSetupDialog(activeProfile, store, onDismiss = { dialog = null }) { activeProfile = it; dialog = null }
    }
}

@Composable
private fun StereoRigSettingsSection(
    profile: StereoRigProfile,
    onEditProfile: () -> Unit,
    onCameraModes: () -> Unit,
    onCalibrationSetup: () -> Unit,
) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text("Stereo rig / Cameras", style = MaterialTheme.typography.titleMedium)
            StereoRigSummary(profile)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = onEditProfile) { Text("Edit profile") }
                Button(onClick = onCameraModes) { Text("Camera modes") }
            }
            Button(onClick = onCalibrationSetup) { Text("Calibration setup") }
        }
    }
}

@Composable
private fun StereoRigSummary(profile: StereoRigProfile, textColor: Color = Color.Unspecified) {
    Text("Active profile: ${profile.rigId}", color = textColor)
    Text("Baseline: ${profile.baselineMm?.let { "${it} mm" } ?: "Not set"}", color = textColor)
    Text("cam0: ${profile.cam0Mode.modeLabel()}", color = textColor)
    Text("cam1: ${profile.cam1Mode.modeLabel()}", color = textColor)
    Text("Calibration: ${profile.calibrationStatus.name}", color = textColor)
}

private fun CameraMode?.modeLabel(): String = this?.let { mode ->
    val prefix = if (mode.selectedBy == CameraModeSelection.AUTO) "Auto " else ""
    "$prefix${mode.format} ${mode.width}x${mode.height}@${mode.fps}"
} ?: "Not set"

@Composable
private fun EditRigProfileDialog(profile: StereoRigProfile, onDismiss: () -> Unit, onSave: (StereoRigProfile) -> Unit) {
    var rigId by remember(profile) { mutableStateOf(profile.rigId) }
    var cam0Label by remember(profile) { mutableStateOf(profile.cam0Label) }
    var cam1Label by remember(profile) { mutableStateOf(profile.cam1Label) }
    var baseline by remember(profile) { mutableStateOf(profile.baselineMm?.toString().orEmpty()) }
    val baselineValue = baseline.toDoubleOrNull()
    val error = when {
        rigId.isBlank() -> "rig_id cannot be blank"
        baseline.isNotBlank() && (baselineValue == null || baselineValue <= 0.0) -> "baseline_mm must be positive if provided"
        else -> null
    }
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Edit profile") }, text = {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(rigId, { rigId = it }, label = { Text("rig_id") })
            OutlinedTextField(cam0Label, { cam0Label = it }, label = { Text("cam0_label") })
            OutlinedTextField(cam1Label, { cam1Label = it }, label = { Text("cam1_label") })
            OutlinedTextField(baseline, { baseline = it }, label = { Text("baseline_mm") })
            error?.let { Text(it, color = Color.Red) }
        }
    }, confirmButton = { TextButton(enabled = error == null, onClick = { onSave(profile.copy(rigId = rigId.trim(), cam0Label = cam0Label.trim(), cam1Label = cam1Label.trim(), baselineMm = baselineValue)) }) { Text("Save") } }, dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } })
}

@Composable
private fun CameraModesDialog(profile: StereoRigProfile, onDismiss: () -> Unit, onSave: (StereoRigProfile) -> Unit) {
    val cam0Options = listOf(
        CameraMode(CameraModeSource.PHONE_CAMERA, "Auto", 1920, 1080, 30, CameraModeSelection.AUTO),
        CameraMode(CameraModeSource.PHONE_CAMERA, "Default", 1920, 1080, 30, CameraModeSelection.MANUAL),
        CameraMode(CameraModeSource.PHONE_CAMERA, "Default", 1280, 720, 30, CameraModeSelection.MANUAL),
    )
    val cam1Options = listOf(
        CameraMode(CameraModeSource.USB_UVC, "MJPEG", 640, 480, 30, CameraModeSelection.AUTO),
        CameraMode(CameraModeSource.USB_UVC, "MJPEG", 1920, 1080, 30, CameraModeSelection.MANUAL),
        CameraMode(CameraModeSource.USB_UVC, "MJPEG", 1280, 720, 30, CameraModeSelection.MANUAL),
        CameraMode(CameraModeSource.USB_UVC, "MJPEG", 640, 480, 30, CameraModeSelection.MANUAL),
        CameraMode(CameraModeSource.USB_UVC, "YUYV", 640, 480, 30, CameraModeSelection.MANUAL),
    )
    var cam0 by remember(profile) { mutableStateOf(profile.cam0Mode ?: cam0Options.first()) }
    var cam1 by remember(profile) { mutableStateOf(profile.cam1Mode ?: cam1Options.first()) }
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Camera modes") }, text = {
        Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Phone cam0 options")
            cam0Options.forEach { Button(onClick = { cam0 = it }, modifier = Modifier.fillMaxWidth()) { Text((if (cam0 == it) "✓ " else "") + it.modeLabel()) } }
            Text("USB UVC cam1 options")
            cam1Options.forEach { Button(onClick = { cam1 = it }, modifier = Modifier.fillMaxWidth()) { Text((if (cam1 == it) "✓ " else "") + it.modeLabel()) } }
            Text("Prefer stability")
            Text("Prefer MJPEG for USB camera")
            Text("Mode applies on next camera refresh", style = MaterialTheme.typography.bodySmall)
        }
    }, confirmButton = { TextButton(onClick = { onSave(profile.copy(cam0Mode = cam0, cam1Mode = cam1)) }) { Text("Save") } }, dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } })
}

@Composable
private fun CalibrationSetupDialog(profile: StereoRigProfile, store: StereoRigProfileStore, onDismiss: () -> Unit, onSaved: (StereoRigProfile) -> Unit) {
    var cols by remember(profile) { mutableStateOf(profile.calibrationSettings.checkerboardInnerCols.toString()) }
    var rows by remember(profile) { mutableStateOf(profile.calibrationSettings.checkerboardInnerRows.toString()) }
    var square by remember(profile) { mutableStateOf(profile.calibrationSettings.squareSizeMm.toString()) }
    var pairs by remember(profile) { mutableStateOf(profile.calibrationSettings.requiredPairs.toString()) }
    var baseline by remember(profile) { mutableStateOf(profile.baselineMm?.toString().orEmpty()) }
    var sessionMessage by remember { mutableStateOf<String?>(null) }
    fun updatedProfile(): StereoRigProfile? {
        val c = cols.toIntOrNull(); val r = rows.toIntOrNull(); val s = square.toDoubleOrNull(); val p = pairs.toIntOrNull(); val b = baseline.toDoubleOrNull()
        if (c == null || c <= 0 || r == null || r <= 0 || s == null || s <= 0.0 || p == null || p <= 0) return null
        if (baseline.isNotBlank() && (b == null || b <= 0.0)) return null
        return profile.copy(calibrationSettings = CalibrationSettings(c, r, s, p), baselineMm = b)
    }
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Calibration setup") }, text = {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(cols, { cols = it }, label = { Text("checkerboard inner columns") })
            OutlinedTextField(rows, { rows = it }, label = { Text("checkerboard inner rows") })
            OutlinedTextField(square, { square = it }, label = { Text("square size mm") })
            OutlinedTextField(pairs, { pairs = it }, label = { Text("required pairs") })
            OutlinedTextField(baseline, { baseline = it }, label = { Text("baseline mm") })
            if (updatedProfile() == null) Text("All numeric values must be positive; baseline may be blank.", color = Color.Red)
            sessionMessage?.let { Text(it, color = Color(0xFF2E7D32)) }
        }
    }, confirmButton = {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            TextButton(enabled = updatedProfile() != null, onClick = { updatedProfile()?.let { store.saveActiveProfile(it); onSaved(it) } }) { Text("Save") }
            TextButton(enabled = updatedProfile() != null, onClick = { updatedProfile()?.let { store.saveActiveProfile(it); val dir = store.createCalibrationSession(it); sessionMessage = "Session created: ${dir.name}"; onSaved(it) } }) { Text("Start calibration session") }
        }
    }, dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } })
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
    onStartPhoneVideoScan: (String) -> Unit,
    onBindPhoneCameraPreview: (PreviewView, String?, Float, (PhoneCameraBindResult) -> Unit) -> Unit,
    onStopVideoScan: () -> Unit,
    onCreateSessionRequested: () -> Unit,
    onDeleteVideoScan: (String) -> Unit,
    onDownloadVideoScan: (String) -> Unit,
    onClearSessionQueue: () -> Unit,
    onRequeueAllVideos: () -> String,
    onRequeueVideo: (String) -> EnqueueUploadResult,
    debugMode: Boolean,
    selectedOrder: MobileOrder?,
) {
    var captureMode by remember { mutableStateOf(CaptureMode.PHOTO_POINT) }
    var pointName by remember { mutableStateOf("") }
    var scanName by remember { mutableStateOf("") }
    var showNoSessionDialog by remember { mutableStateOf(false) }
    var showPhoneCameraScan by remember { mutableStateOf(false) }
    var showStereoCapture by remember { mutableStateOf(false) }
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
                    }
                    Button(
                        onClick = {
                            if (selectedSessionName == null) showNoSessionDialog = true else showPhoneCameraScan = true
                        },
                        enabled = selectedSessionName != null && !isCapturing && !isRecordingScanVideo && videoScanUiState != VideoScanUiState.STOPPING && scanName.isNotBlank(),
                    ) { Text("Снять видео на телефон") }
                    Button(
                        onClick = { if (selectedSessionName == null) showNoSessionDialog = true else showStereoCapture = true },
                        enabled = selectedSessionName != null && !isCapturing && !isRecordingScanVideo && videoScanUiState != VideoScanUiState.STOPPING,
                    ) { Text("Stereo Capture Experimental") }
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
        if (showStereoCapture) {
            Dialog(
                onDismissRequest = { showStereoCapture = false },
                properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false),
            ) {
                StereoCaptureExperimentalScreen(
                    orderId = selectedOrder?.id?.toString(),
                    captureSessionId = selectedSessionName ?: "local",
                    onClose = { showStereoCapture = false },
                )
            }
        }
        if (showPhoneCameraScan) {
            Dialog(
                onDismissRequest = {
                    if (!isRecordingScanVideo && videoScanUiState != VideoScanUiState.STOPPING) {
                        showPhoneCameraScan = false
                    }
                },
                properties = DialogProperties(
                    usePlatformDefaultWidth = false,
                    decorFitsSystemWindows = false,
                ),
            ) {
                PhoneCameraScanScreen(
                    scanName = scanName,
                    isRecordingScanVideo = isRecordingScanVideo,
                    videoScanUiState = videoScanUiState,
                    scanVideos = scanVideos,
                    onBindPreview = onBindPhoneCameraPreview,
                    onStartPhoneVideoScan = { name -> onStartPhoneVideoScan(name) },
                    onStopPhoneVideoScan = onStopVideoScan,
                    onClose = { showPhoneCameraScan = false },
                )
            }
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
private fun PhoneCameraScanScreen(
    scanName: String,
    isRecordingScanVideo: Boolean,
    videoScanUiState: VideoScanUiState,
    scanVideos: List<com.maklertour.domain.ScanVideo>,
    onBindPreview: (PreviewView, String?, Float, (PhoneCameraBindResult) -> Unit) -> Unit,
    onStartPhoneVideoScan: (String) -> Unit,
    onStopPhoneVideoScan: () -> Unit,
    onClose: () -> Unit,
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    var hasCameraPermission by remember { mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) }
    var previewBound by remember { mutableStateOf(false) }
    var isRebindingCamera by remember { mutableStateOf(false) }
    var activeBoundCameraId by remember { mutableStateOf<String?>(null) }
    var bindError by remember { mutableStateOf<String?>(null) }
    var elapsedSec by remember { mutableStateOf(0L) }
    var showCalibrationDialog by remember { mutableStateOf(false) }
    var showCameraSettingsDialog by remember { mutableStateOf(false) }
    var guideConfirmed by remember { mutableStateOf(false) }
    var startedInThisScreen by remember { mutableStateOf(false) }
    var lastShownCapturedScanId by remember { mutableStateOf<String?>(null) }
    val debugMode = remember(context) { DebugPreferences.get(context) }
    val lensRepository = remember(context) { PhoneCameraLensRepository(context) }
    var cameraOptions by remember { mutableStateOf(lensRepository.listBackCameras()) }
    var selectedCameraId by remember { mutableStateOf(lensRepository.getSelectedCameraId() ?: cameraOptions.firstOrNull()?.cameraId) }
    var requestedZoomRatio by remember { mutableStateOf(lensRepository.getSelectedZoomRatio()) }
    var effectiveZoomRatio by remember { mutableStateOf<Float?>(null) }
    var minZoomRatio by remember { mutableStateOf<Float?>(null) }
    var maxZoomRatio by remember { mutableStateOf<Float?>(null) }
    var cameraXZoomStateCurrent by remember { mutableStateOf<Float?>(null) }
    var lastZoomApplyResult by remember { mutableStateOf<String?>(null) }
    val selectedLens = cameraOptions.firstOrNull { it.cameraId == selectedCameraId } ?: cameraOptions.firstOrNull()
    val selectedCameraWarning = if (selectedCameraId != null && cameraOptions.none { it.cameraId == selectedCameraId }) "Выбранная камера недоступна. Будет использована камера по умолчанию: ${selectedLens?.lensLabel ?: "—"}." else null
    val nextRole = remember(scanVideos) { nextVideoScanRole(scanVideos) }
    val isBackbone = nextRole != com.maklertour.domain.ScanVideoRole.DETAIL
    val latestPhoneScan = scanVideos.filter { it.source == com.maklertour.domain.ScanSource.PHONE_CAMERA }.maxByOrNull { it.updatedAt }

    val sensorAvailability = rememberSensorAvailabilityState()
    val capturedPhoneScan = latestPhoneScan?.takeIf {
        startedInThisScreen &&
            videoScanUiState == VideoScanUiState.CAPTURED &&
            it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED &&
            it.id != lastShownCapturedScanId
    }
    DisposableEffect(lifecycleOwner, context) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                hasCameraPermission = ContextCompat.checkSelfPermission(
                    context,
                    Manifest.permission.CAMERA,
                ) == PackageManager.PERMISSION_GRANTED
                if (!hasCameraPermission) {
                    previewBound = false
                }
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    val status = when {
        !hasCameraPermission -> "Нет доступа"
        bindError != null || videoScanUiState == VideoScanUiState.FAILED -> "Ошибка"
        videoScanUiState == VideoScanUiState.RECORDING -> "Запись"
        videoScanUiState == VideoScanUiState.STOPPING -> "Сохранение видео..."
        videoScanUiState == VideoScanUiState.CAPTURED -> "Видео сохранено"
        previewBound -> "Камера готова"
        else -> "Открытие камеры..."
    }
    LaunchedEffect(videoScanUiState) {
        if (videoScanUiState == VideoScanUiState.RECORDING) {
            elapsedSec = 0L
            while (true) {
                delay(1_000)
                elapsedSec += 1L
            }
        } else if (videoScanUiState != VideoScanUiState.STOPPING) {
            elapsedSec = 0L
        }
    }

    Surface(modifier = Modifier.fillMaxSize().zIndex(10f), color = Color.Black) {
        Column(modifier = Modifier.fillMaxSize()) {
            Row(
                modifier = Modifier.fillMaxWidth().background(Color.Black.copy(alpha = 0.75f)).padding(12.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                TextButton(onClick = onClose, enabled = !isRecordingScanVideo && videoScanUiState != VideoScanUiState.STOPPING) { Text("Закрыть") }
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("Скан смартфоном", color = Color.White, style = MaterialTheme.typography.titleMedium)
                    Text(status, color = if (status == "Ошибка" || status == "Нет доступа") Color(0xFFFFB4AB) else Color.White)
                }
                TextButton(onClick = onClose, enabled = !isRecordingScanVideo && videoScanUiState != VideoScanUiState.STOPPING) { Text("Назад") }
            }

            Box(modifier = Modifier.fillMaxWidth().weight(1f), contentAlignment = Alignment.Center) {
                if (hasCameraPermission) {
                    key(selectedCameraId, requestedZoomRatio) {
                    AndroidView(
                        modifier = Modifier.fillMaxSize(),
                        factory = { ctx ->
                            PreviewView(ctx).apply {
                                scaleType = PreviewView.ScaleType.FILL_CENTER
                                implementationMode = PreviewView.ImplementationMode.COMPATIBLE
                                isRebindingCamera = true
                                onBindPreview(this, selectedCameraId, requestedZoomRatio) { result ->
                                    isRebindingCamera = false
                                    previewBound = result.success
                                    activeBoundCameraId = if (result.success) result.activeBoundCameraId else activeBoundCameraId
                                    effectiveZoomRatio = result.effectiveZoomRatio
                                    minZoomRatio = result.minZoomRatio
                                    maxZoomRatio = result.maxZoomRatio
                                    cameraXZoomStateCurrent = result.cameraXZoomStateCurrent
                                    lastZoomApplyResult = result.error ?: "Requested zoom=${result.requestedZoomRatio}, effective=${result.effectiveZoomRatio ?: "—"}, min=${result.minZoomRatio ?: "—"}, max=${result.maxZoomRatio ?: "—"}"
                                    bindError = if (result.success) null else (result.error ?: "preview bind failed")
                                }
                            }
                        },
                    )
                    }
                } else {
                    Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.padding(24.dp)) {
                        Text("Нет доступа к камере. Разрешите доступ к камере в настройках приложения.", color = Color.White)
                        Button(
                            onClick = {
                                context.startActivity(
                                    Intent(
                                        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                                        Uri.parse("package:${context.packageName}"),
                                    )
                                )
                            }
                        ) { Text("Открыть настройки приложения") }
                    }
                }
                bindError?.takeIf { hasCameraPermission }?.let { Text(it, color = Color(0xFFFFB4AB), modifier = Modifier.padding(16.dp)) }
                    if (showCalibrationDialog) PreparationPanel(sensorAvailability, onDismiss = { showCalibrationDialog = false })
            }

            Column(modifier = Modifier.fillMaxWidth().background(Color.Black.copy(alpha = 0.85f)).padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (videoScanUiState == VideoScanUiState.RECORDING) {
                    Text("Таймер: ${elapsedSec}s", color = Color.White)
                    Text("Идите ногами по периметру. Не стойте на месте и не вращайтесь только корпусом.", color = Color.White)
                    Text("Один плавный круг", color = Color.White)
                    Text("Не делайте резких поворотов", color = Color.White)
                    Text("Держите перекрытие кадров", color = Color.White)
                }
                selectedCameraWarning?.let { Text(it, color = Color(0xFFFFD166)) }
                if (selectedLens?.lensLabel?.contains("Ultrawide") == true) {
                    Text("Широкий угол помогает видеть больше стен, но всё равно нужен проход с физическим смещением.", color = Color.White.copy(alpha = 0.9f))
                }
                if (videoScanUiState != VideoScanUiState.RECORDING) {
                    VideoRoleGuide(
                        nextRole,
                        guideConfirmed,
                        onConfirm = { guideConfirmed = true },
                    )
                }
                Button(
                    onClick = {
                        if (isRecordingScanVideo) {
                            onStopPhoneVideoScan()
                        } else {
                            startedInThisScreen = true
                            lastShownCapturedScanId = null
                            PhoneCameraScanProvider.setSessionCalibration(defaultPhoneScanCalibrationMetadata())
                            onStartPhoneVideoScan(defaultVideoScanNameForRole(nextRole, scanVideos.size + 1, scanName.trim()))
                        }
                    },
                    enabled = hasCameraPermission && previewBound && !isRebindingCamera && (!isBackbone || guideConfirmed) && videoScanUiState !in setOf(VideoScanUiState.SWITCHING_MODE, VideoScanUiState.STOPPING),
                    shape = CircleShape,
                    colors = ButtonDefaults.buttonColors(containerColor = if (isRecordingScanVideo) Color.DarkGray else Color.Red),
                    modifier = Modifier.size(76.dp),
                ) { Text(if (isRecordingScanVideo) "■" else "●", color = Color.White) }
                Text(if (isRecordingScanVideo) "Остановить" else "Начать запись", color = Color.White)
                Text("Requested: ${com.maklertour.data.phonecamera.zoomPresetLabel(requestedZoomRatio)}, effective: ${effectiveZoomRatio?.let { com.maklertour.data.phonecamera.zoomPresetLabel(it) } ?: "—"}", color = Color.White.copy(alpha = 0.9f))
                if (requestedZoomRatio <= 0.51f && effectiveZoomRatio != null && kotlin.math.abs((effectiveZoomRatio ?: 1f) - requestedZoomRatio) > 0.01f) {
                    Text("Запрошено 0.5x, но CameraX применил ${com.maklertour.data.phonecamera.zoomPresetLabel(effectiveZoomRatio ?: 1f)}.", color = Color(0xFFFFD166))
                    Text("Для широкого угла снимите видео в родной камере на 0.5x и импортируйте его в проект.", color = Color(0xFFFFD166))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { showCalibrationDialog = true }) { Text("Калибровка") }
                    Button(onClick = { showCameraSettingsDialog = true }, enabled = !isRecordingScanVideo && videoScanUiState != VideoScanUiState.STOPPING) { Text("Настройки камеры") }
                }
                if (!isBackbone) {
                    Text("Снимайте детали плавно и сохраняйте перекрытие с основным проходом.", color = Color.White.copy(alpha = 0.85f))
                }
                if (capturedPhoneScan != null) {
                    Text("Видео сохранено", color = Color.White, style = MaterialTheme.typography.titleMedium)
                    Text("Размер: ${formatBytes(capturedPhoneScan.fileSizeBytes ?: 0L)}", color = Color.White)
                    Text("Длительность: ${capturedPhoneScan.durationSec ?: 0} сек", color = Color.White)
                    if (debugMode) {
                        Text(
                            "Путь: ${capturedPhoneScan.localVideoPath ?: "—"}",
                            color = Color.White.copy(alpha = 0.65f),
                            style = MaterialTheme.typography.bodySmall,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    Button(onClick = {
                        lastShownCapturedScanId = capturedPhoneScan.id
                        onClose()
                    }) { Text("Готово") }
                }
                latestPhoneScan?.notes?.takeIf { videoScanUiState == VideoScanUiState.FAILED }?.let { Text("Ошибка: $it", color = Color(0xFFFFB4AB)) }
                if (debugMode) {
                    Text(
                        "Debug: selectedCameraId=${selectedCameraId ?: "—"} · requestedZoomRatio=$requestedZoomRatio · effectiveZoomRatio=${effectiveZoomRatio ?: "—"} · minZoomRatio=${minZoomRatio ?: "—"} · maxZoomRatio=${maxZoomRatio ?: "—"} · cameraXZoomStateCurrent=${cameraXZoomStateCurrent ?: "—"} · lastZoomApplyResult=${lastZoomApplyResult ?: "—"} · activeBoundCameraId=${activeBoundCameraId ?: "—"} · savedCameraId=${lensRepository.getSelectedCameraId() ?: "—"} · previewBound=$previewBound · rebinding=$isRebindingCamera · lastBindError=${bindError ?: "—"}",
                        color = Color.White.copy(alpha = 0.65f),
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        }
    }
    if (showCameraSettingsDialog) CameraSettingsDialog(
        options = cameraOptions,
        selectedCameraId = selectedCameraId,
        requestedZoomRatio = requestedZoomRatio,
        effectiveZoomRatio = effectiveZoomRatio,
        minZoomRatio = minZoomRatio,
        maxZoomRatio = maxZoomRatio,
        cameraXZoomStateCurrent = cameraXZoomStateCurrent,
        lastZoomApplyResult = lastZoomApplyResult,
        debugMode = debugMode,
        warning = selectedCameraWarning,
        activeBoundCameraId = activeBoundCameraId,
        onRefresh = { cameraOptions = lensRepository.listBackCameras() },
        onSelect = { option ->
            lensRepository.saveSelection(option.cameraId, requestedZoomRatio)
            selectedCameraId = option.cameraId
            previewBound = false
            isRebindingCamera = true
            bindError = null
            Toast.makeText(context, "Камера выбрана: ${option.lensLabel}", Toast.LENGTH_SHORT).show()
        },
        onSelectPreset = { preset ->
            val cameraId = selectedCameraId ?: cameraOptions.firstOrNull()?.cameraId
            if (cameraId != null) lensRepository.saveSelection(cameraId, preset.zoomRatio)
            requestedZoomRatio = preset.zoomRatio
            previewBound = false
            isRebindingCamera = true
            bindError = null
            Toast.makeText(context, "Зум выбран: ${preset.label}", Toast.LENGTH_SHORT).show()
        },
        onDismiss = { showCameraSettingsDialog = false },
    )
}

@Composable
private fun CameraSettingsDialog(
    options: List<PhoneCameraLensOption>,
    selectedCameraId: String?,
    requestedZoomRatio: Float,
    effectiveZoomRatio: Float?,
    minZoomRatio: Float?,
    maxZoomRatio: Float?,
    cameraXZoomStateCurrent: Float?,
    lastZoomApplyResult: String?,
    debugMode: Boolean,
    warning: String?,
    activeBoundCameraId: String?,
    onRefresh: () -> Unit,
    onSelect: (PhoneCameraLensOption) -> Unit,
    onSelectPreset: (com.maklertour.data.phonecamera.PhoneLensPreset) -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Настройки камеры") },
        text = {
            Column(modifier = Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                val current = options.firstOrNull { it.cameraId == selectedCameraId } ?: options.firstOrNull()
                Text("Найдено cameraId: ${options.size}", style = MaterialTheme.typography.titleMedium)
                Text("Текущая камера: ${current?.cameraId ?: "—"}, requested ${com.maklertour.data.phonecamera.zoomPresetLabel(requestedZoomRatio)}, effective ${effectiveZoomRatio?.let { com.maklertour.data.phonecamera.zoomPresetLabel(it) } ?: "—"}")
                Text("Для маленьких помещений используйте 0.5x, если доступно.")
                warning?.let { Text(it, color = Color(0xFF8A5A00)) }
                if (options.isEmpty()) {
                    Text("Задние камеры не найдены.")
                } else if (options.size == 1) {
                    Text("Производительская камера может переключать физические модули внутри logical camera. В приложении используется CameraX zoom ratio.")
                    if (minZoomRatio != null && minZoomRatio <= 0.5f && effectiveZoomRatio != null && kotlin.math.abs(effectiveZoomRatio - 0.5f) <= 0.05f) Text("Доступен широкий угол через zoom ratio")
                }
                val presets = listOf(com.maklertour.data.phonecamera.PhoneLensPreset("0.5x", 0.5f), com.maklertour.data.phonecamera.PhoneLensPreset("1x", 1f), com.maklertour.data.phonecamera.PhoneLensPreset("2x", 2f), com.maklertour.data.phonecamera.PhoneLensPreset("3x", 3f))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    presets.forEach { preset ->
                        val min = minZoomRatio
                        val max = maxZoomRatio
                        val rangeKnown = min != null && max != null
                        val enabled = rangeKnown && preset.zoomRatio >= min!! && preset.zoomRatio <= max!!
                        val isEffective = effectiveZoomRatio != null && kotlin.math.abs(effectiveZoomRatio - preset.zoomRatio) <= 0.01f
                        Button(onClick = { onSelectPreset(preset) }, enabled = enabled && !isEffective) { Text(if (isEffective) "${preset.label}\nИспользуется сейчас" else preset.label) }
                    }
                }
                if (minZoomRatio == null || maxZoomRatio == null) Text("Zoom range is not confirmed yet. Open preview first.")
                if (minZoomRatio != null && minZoomRatio >= 1.0f) Text("0.5x недоступен через CameraX на этом устройстве. Родная камера может иметь приватный доступ к широкоугольному модулю. Используйте импорт видео из родной камеры.", color = Color(0xFF8A5A00)) else Text("0.5x рекомендуется для маленьких помещений, только если CameraX подтверждает диапазон.")
                if (requestedZoomRatio <= 0.51f && effectiveZoomRatio != null && kotlin.math.abs(effectiveZoomRatio - requestedZoomRatio) > 0.01f) Text("Запрошено 0.5x, но CameraX применил ${com.maklertour.data.phonecamera.zoomPresetLabel(effectiveZoomRatio)}.", color = Color(0xFF8A5A00))
                options.forEach { option ->
                    Card(
                        colors = CardDefaults.cardColors(containerColor = if (option.cameraId == selectedCameraId) Color(0xFFE0F2F1) else MaterialTheme.colorScheme.surfaceVariant),
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(option.lensLabel, style = MaterialTheme.typography.titleSmall)
                            if (option.cameraId == selectedCameraId) Text("Используется сейчас")
                            if (option.lensLabel.contains("Ultrawide", ignoreCase = true)) {
                                Text("Рекомендуется для маленьких помещений")
                            }
                            Text("Camera ID: ${option.cameraId}; facing: ${option.lensFacing}")
                            Text("Разрешение/FPS: ${option.supportedVideoSizes.firstOrNull()?.let { "${it.width}×${it.height}" } ?: "—"} · ${option.supportedFpsRanges.maxByOrNull { it.upper }?.let { "${it.lower}-${it.upper} fps" } ?: "—"}")
                            Text("Lens/FOV: ${option.primaryFocalLengthMm?.let { String.format(java.util.Locale.US, "%.1f mm", it) } ?: "—"} · ${option.approximateFovDeg?.let { String.format(java.util.Locale.US, "≈ %.0f°×%.0f°", it.horizontal, it.vertical) } ?: "FOV —"}")
                            Text("Сенсор: ${option.sensorPhysicalSizeMm?.let { String.format(java.util.Locale.US, "%.2f×%.2f mm", it.width, it.height) } ?: "—"}")
                            if (debugMode) {
                                Text("Debug: cameraIdList=${options.map { it.cameraId }} selectedCameraId=${selectedCameraId ?: "—"} requestedZoomRatio=$requestedZoomRatio · effectiveZoomRatio=${effectiveZoomRatio ?: "—"} · minZoomRatio=${minZoomRatio ?: "—"} · maxZoomRatio=${maxZoomRatio ?: "—"} · cameraXZoomStateCurrent=${cameraXZoomStateCurrent ?: "—"} · lastZoomApplyResult=${lastZoomApplyResult ?: "—"} activeBoundCameraId=${activeBoundCameraId ?: "—"}", style = MaterialTheme.typography.bodySmall)
                                Text("Raw camera2: cameraId=${option.cameraId}, facing=${option.lensFacing}, capabilities logical=${option.logicalMultiCameraCapable}, physicalCameraIds=${option.physicalCameraIds}, focal_lengths_mm=${option.focalLengthsMm}, sensor=${option.sensorPhysicalSizeMm}, FOV=${option.approximateFovDeg}, minZoom=${if (option.cameraId == selectedCameraId) minZoomRatio else option.minZoomRatio}, maxZoom=${if (option.cameraId == selectedCameraId) maxZoomRatio else option.maxZoomRatio}, sizes=${option.supportedVideoSizes.take(8)}, fps=${option.supportedFpsRanges}", style = MaterialTheme.typography.bodySmall)
                            }
                            Button(onClick = { onSelect(option) }, enabled = option.cameraId != selectedCameraId) { Text(if (option.cameraId == selectedCameraId) "Используется сейчас" else "Выбрать") }
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("ОК") } },
        dismissButton = { TextButton(onClick = onRefresh) { Text("Обновить") } },
    )
}

private fun formatBytes(bytes: Long): String {
    if (bytes < 1024L) return "$bytes B"
    val units = listOf("KB", "MB", "GB")
    var value = bytes.toDouble() / 1024.0
    var unitIndex = 0
    while (value >= 1024.0 && unitIndex < units.lastIndex) {
        value /= 1024.0
        unitIndex += 1
    }
    return String.format(java.util.Locale.US, "%.1f %s", value, units[unitIndex])
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
    onClearSessionQueue: () -> Unit,
    onRequeueAllVideos: () -> String,
    onRequeueVideo: (String) -> EnqueueUploadResult,
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
            SessionQueueControlsBlock(
                onClearSessionQueue = onClearSessionQueue,
                onRequeueAllVideos = onRequeueAllVideos,
            )
            ProjectControlsBlock(
                onDownloadOriginals = onDownloadOriginals,
                onSyncServer = onSyncServer,
                onAddToUploadQueue = {
                    when (val result = onAddToUploadQueue()) {
                        EnqueueUploadResult.Enqueued -> Toast.makeText(context, "Сессия добавлена в очередь", Toast.LENGTH_SHORT).show()
                        EnqueueUploadResult.RequeuedNewMedia -> Toast.makeText(context, "Новые файлы добавлены в очередь", Toast.LENGTH_SHORT).show()
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
            VideoBackboneStatusCard(scanVideos)
        }

        item {
            VideoScansBlock(
                scanVideos = scanVideos,
                onDelete = onDeleteVideoScan,
                onDownload = onDownloadVideoScan,
                onSyncVideo = { onAddToUploadQueue() },
                onRequeueVideo = onRequeueVideo,
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
private fun SessionQueueControlsBlock(
    onClearSessionQueue: () -> Unit,
    onRequeueAllVideos: () -> String,
) {
    val context = LocalContext.current
    var showClearSessionConfirm by remember { mutableStateOf(false) }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
        Button(onClick = { showClearSessionConfirm = true }, modifier = Modifier.weight(1f)) {
            Text("Очистить очередь этой сессии")
        }
        Button(
            onClick = {
                val summary = onRequeueAllVideos()
                Toast.makeText(context, summary, Toast.LENGTH_LONG).show()
            },
            modifier = Modifier.weight(1f),
        ) {
            Text("Переотправить все видео сессии")
        }
    }
    if (showClearSessionConfirm) {
        AlertDialog(
            onDismissRequest = { showClearSessionConfirm = false },
            title = { Text("Очистить очередь сессии") },
            text = { Text("Очистить очередь загрузки для этой сессии? Локальные файлы останутся на устройстве.") },
            confirmButton = {
                TextButton(onClick = {
                    onClearSessionQueue()
                    showClearSessionConfirm = false
                    Toast.makeText(context, "Очередь очищена", Toast.LENGTH_SHORT).show()
                }) { Text("Очистить") }
            },
            dismissButton = { TextButton(onClick = { showClearSessionConfirm = false }) { Text("Отмена") } },
        )
    }
}

@Composable
private fun VideoScansBlock(
    scanVideos: List<com.maklertour.domain.ScanVideo>,
    onDelete: (String) -> Unit,
    onDownload: (String) -> Unit,
    onSyncVideo: (String) -> EnqueueUploadResult = { EnqueueUploadResult.Rejected("Сессия не выбрана.") },
    onRequeueVideo: (String) -> EnqueueUploadResult = onSyncVideo,
    debugMode: Boolean = false,
) {
    val context = LocalContext.current
    var requeueConfirmId by remember { mutableStateOf<String?>(null) }
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
                        Text(videoRoleLabel(effectiveVideoScanRole(scanVideos, scan)))
                        if (scan.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED && effectiveVideoScanRole(scanVideos, scan) != com.maklertour.domain.ScanVideoRole.DETAIL) {
                            Text("Подсказка: ровный периметр помогает SfM-реконструкции")
                        } else if (scan.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED) {
                            Text("Подсказка: сохраняйте перекрытие с основным проходом")
                        }
                        if (scan.source == com.maklertour.domain.ScanSource.PHONE_CAMERA) {
                            Text("Phone video")
                        }
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
                            Text("queueItemCount is shown in Queue tab")
                            Text("sidecars: imu=${scan.localVideoPath?.let { java.io.File(it).resolveSibling(java.io.File(it).nameWithoutExtension + "_imu.jsonl").exists() } ?: false}, camera=${scan.localVideoPath?.let { java.io.File(it).resolveSibling("camera_info.json").exists() } ?: false}, manifest=${scan.localVideoPath?.let { java.io.File(it).resolveSibling("manifest.json").exists() } ?: false}")
                            Text("cameraFileUrl=${scan.cameraFileUrl}")
                            Text("cameraLocalFileUrl=${scan.cameraLocalFileUrl}")
                            Text("localVideoPath=${scan.localVideoPath}")
                            Text("notes=${scan.notes}")
                            Text("durationSec=${scan.durationSec}")
                        }

                        if (scan.source != com.maklertour.domain.ScanSource.PHONE_CAMERA &&
                            scan.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED &&
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

                        val syncEnabled = scan.uploadState !in setOf(
                            com.maklertour.domain.ScanVideoUploadState.UPLOADED,
                            com.maklertour.domain.ScanVideoUploadState.CONFIRMED,
                        )
                        Button(
                            onClick = {
                                when (val result = onSyncVideo(scan.id)) {
                                    EnqueueUploadResult.Enqueued -> Toast.makeText(context, "Видео добавлено в очередь", Toast.LENGTH_SHORT).show()
                                    EnqueueUploadResult.RequeuedNewMedia -> Toast.makeText(context, "Видео добавлено в очередь", Toast.LENGTH_SHORT).show()
                                    is EnqueueUploadResult.Rejected -> Toast.makeText(context, result.reason, Toast.LENGTH_SHORT).show()
                                }
                            },
                            enabled = syncEnabled,
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text(
                                when (scan.uploadState) {
                                    com.maklertour.domain.ScanVideoUploadState.UPLOADED,
                                    com.maklertour.domain.ScanVideoUploadState.CONFIRMED -> "Уже загружено"
                                    com.maklertour.domain.ScanVideoUploadState.UPLOAD_ERROR -> "Повторить"
                                    else -> "Добавить видео в очередь"
                                }
                            )
                        }

                        Button(
                            onClick = { requeueConfirmId = scan.id },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text("Переотправить видео")
                        }
                        TextButton(onClick = { onRequeueVideo(scan.id) }) { Text("Сбросить статус загрузки") }

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

    val confirmId = requeueConfirmId
    if (confirmId != null) {
        AlertDialog(
            onDismissRequest = { requeueConfirmId = null },
            title = { Text("Переотправить видео") },
            text = { Text("Переотправить это видео на сервер?") },
            confirmButton = { TextButton(onClick = {
                when (val result = onRequeueVideo(confirmId)) {
                    EnqueueUploadResult.Enqueued, EnqueueUploadResult.RequeuedNewMedia -> Toast.makeText(context, "Видео добавлено в очередь повторно", Toast.LENGTH_SHORT).show()
                    is EnqueueUploadResult.Rejected -> Toast.makeText(context, result.reason, Toast.LENGTH_SHORT).show()
                }
                requeueConfirmId = null
            }) { Text("Переотправить") } },
            dismissButton = { TextButton(onClick = { requeueConfirmId = null }) { Text("Отмена") } },
        )
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


@Composable
private fun DraftPointCard(index: Int, point: com.maklertour.domain.CapturePoint, rooms: List<com.maklertour.domain.RoomDraft>, currentRoomName: String?, isStartPoint: Boolean, allPoints: List<com.maklertour.domain.CapturePoint>, onRename: (String, String) -> Unit, onDelete: (String) -> Unit, onMoveUp: (Int) -> Unit, onMoveDown: (Int) -> Unit, onAssignPointToRoom: (String, String?) -> Unit, onSetStartPoint: (String) -> Unit, onCreateConnection: (String, String) -> Unit, debugMode: Boolean) {
    var localName by remember(point.id) { mutableStateOf(point.name) };
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
    val cameraExists =
        point.cameraFileUrl != null && point.cameraDeleteState != com.maklertour.domain.DeleteState.DELETED;
    val phonePreviewExists = point.localPreviewPath != null;
    val phoneOriginalText = when (point.localOriginalState) {
        com.maklertour.domain.FileLocalState.DOWNLOADED -> stringResource(R.string.downloaded);
        com.maklertour.domain.FileLocalState.DOWNLOADING -> stringResource(R.string.downloading);
        com.maklertour.domain.FileLocalState.DOWNLOAD_ERROR -> stringResource(R.string.error);
        com.maklertour.domain.FileLocalState.NOT_DOWNLOADED -> stringResource(R.string.not_exists)
    };
    val serverText = when (point.serverUploadState) {
        com.maklertour.domain.ServerUploadState.CONFIRMED -> stringResource(R.string.uploaded);
        com.maklertour.domain.ServerUploadState.UPLOADING -> stringResource(R.string.downloading);
        com.maklertour.domain.ServerUploadState.QUEUED -> stringResource(R.string.upload_queued);
        com.maklertour.domain.ServerUploadState.ERROR -> stringResource(R.string.error);
        com.maklertour.domain.ServerUploadState.NOT_QUEUED -> stringResource(R.string.not_uploaded)
    };
    val cameraDeleteText = when (point.cameraDeleteState) {
        com.maklertour.domain.DeleteState.DELETED -> stringResource(R.string.deleted);
        com.maklertour.domain.DeleteState.DELETE_ERROR -> stringResource(R.string.delete_error);
        com.maklertour.domain.DeleteState.DELETE_REQUESTED -> stringResource(R.string.deleting);
        else -> stringResource(R.string.camera_clean)
    };
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            Text(stringResource(R.string.point_number_format, index + 1));
            Text(point.name); if (isStartPoint) {
            Text(stringResource(R.string.draft_start_point))
        };
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
            AppStorageStatusRow(
                cameraExists = cameraExists,
                phonePreviewExists = phonePreviewExists,
                phoneOriginalText = phoneOriginalText,
                serverText = serverText,
                cameraDeleteText = cameraDeleteText
            ); OutlinedTextField(
            value = localName,
            onValueChange = { localName = it },
            label = { Text(stringResource(R.string.rename)) },
            modifier = Modifier.fillMaxWidth()
        ); Row(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Button(onClick = {
                onRename(
                    point.id,
                    localName
                )
            }) { Text(stringResource(R.string.save)) }; Button(onClick = { onMoveUp(index) }) {
            Text(
                "↑"
            )
        }; Button(
            onClick = { onMoveDown(index) }) { Text("↓") }; Button(onClick = { onDelete(point.id) }) {
            Text(
                stringResource(R.string.delete)
            )
        }; Button(
            onClick = { onSetStartPoint(point.id) }) { Text(stringResource(R.string.draft_make_start_point)) }
        }; if (debugMode) {
            Text(stringResource(R.string.debug_details)); Text("id=${point.id}"); Text("cameraFileUrl=${point.cameraFileUrl}"); Text(
                "localPreviewPath=${point.localPreviewPath}"
            ); Text("localOriginalPath=${point.localOriginalPath}"); Text("capturedAt=${point.capturedAt}"); Text(
                "cameraDeleteState=${point.cameraDeleteState}"
            ); Text("serverUploadState=${point.serverUploadState}")
        }; Text(stringResource(R.string.draft_assign_room));
            Text(stringResource(R.string.draft_connect_to))
            Column(
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                allPoints
                    .filter { it.id != point.id }
                    .forEach { other ->
                        TextButton(
                            onClick = { onCreateConnection(point.id, other.id) },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text(other.name)
                        }
                    }
                }
            }
        }
    }

    @Composable
    private fun ConnectionsBlock(
        points: List<com.maklertour.domain.CapturePoint>,
        connections: List<com.maklertour.domain.TourDraftConnection>,
        onDeleteConnection: (String) -> Unit
    ) {
        Text(
            stringResource(R.string.draft_connections),
            style = MaterialTheme.typography.titleMedium
        ); if (connections.isEmpty()) {
            Text(stringResource(R.string.draft_no_connections)); return
        }; connections.forEach { c ->
            val fromName = points.firstOrNull { it.id == c.fromPointId }?.name ?: c.fromPointId;
            val toName = points.firstOrNull { it.id == c.toPointId }?.name ?: c.toPointId; Row(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text("$fromName → $toName"); TextButton(onClick = { onDeleteConnection(c.id) }) {
            Text(
                stringResource(R.string.delete)
            )
        }
        }
        }
    }

    @Composable
    private fun QueueScreen(
        selectedOrder: MobileOrder?,
        sessions: List<com.maklertour.domain.Session>,
        queue: List<com.maklertour.domain.UploadItem>,
        onEnqueue: () -> EnqueueUploadResult,
        onUpload: (String) -> Unit,
        uploadError: String?,
        onResetQueueItem: (String) -> Unit,
        onDeleteQueueItem: (String) -> Unit,
        onClearAllQueue: () -> Unit,
        onClearCompletedQueue: () -> Unit,
        onClearFailedQueue: () -> Unit,
        onExportDiagnostics: () -> String,
    ) {
        val clipboardManager = LocalClipboardManager.current
        val context = LocalContext.current
        val queuedCount = queue.count { it.status == com.maklertour.domain.UploadStatus.Queued }
        val uploadingCount = queue.count { it.status == com.maklertour.domain.UploadStatus.Uploading }
        val errorCount = queue.count { it.status == com.maklertour.domain.UploadStatus.Error }
        val successCount = queue.count { it.status == com.maklertour.domain.UploadStatus.Success }
        var filter by remember { mutableStateOf("Новые") }
        var showClearAllConfirm by remember { mutableStateOf(false) }
        val filteredQueue = when (filter) {
            "Новые" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Queued }
            "Загружаются" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Uploading }
            "Ошибки" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Error }
            "Успешные" -> queue.filter { it.status == com.maklertour.domain.UploadStatus.Success }
            else -> queue
        }
        val filterLabels = mapOf(
            "Новые" to "Новые / Queued ($queuedCount)",
            "Загружаются" to "Загружаются / Uploading ($uploadingCount)",
            "Ошибки" to "Ошибки / Error ($errorCount)",
            "Успешные" to "Успешные / Success ($successCount)",
            "Все" to "Все (${queue.size})",
        )
        Column(
            modifier = Modifier.fillMaxSize().padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            if (selectedOrder != null) {
                Text("Заявка #${selectedOrder.id} — ${selectedOrder.title}")
            } else {
                Text("Локальный черновик без заявки")
            }

            Text(stringResource(R.string.upload_queue))
            if (!uploadError.isNullOrBlank()) {
                Text("Ошибка upload: $uploadError", color = MaterialTheme.colorScheme.error)
            }
            SimpleFilterDropdown(
                label = "Фильтр очереди",
                selected = filterLabels[filter] ?: filter,
                options = listOf(
                    filterLabels.getValue("Новые"),
                    filterLabels.getValue("Загружаются"),
                    filterLabels.getValue("Ошибки"),
                    filterLabels.getValue("Успешные"),
                    filterLabels.getValue("Все"),
                ),
                onSelected = { selected ->
                    filter = filterLabels.entries.firstOrNull { it.value == selected }?.key ?: "Все"
                }
            )
            Text("Фильтр очереди: ${filterLabels[filter] ?: filter}")
            Button(
                onClick = {
                    when (val result = onEnqueue()) {
                        EnqueueUploadResult.Enqueued -> {
                            Toast.makeText(context, "Сессия добавлена в очередь", Toast.LENGTH_SHORT).show()
                        }
                        EnqueueUploadResult.RequeuedNewMedia -> {
                            Toast.makeText(context, "Новые файлы добавлены в очередь", Toast.LENGTH_SHORT).show()
                        }

                        is EnqueueUploadResult.Rejected -> {
                            Toast.makeText(context, result.reason, Toast.LENGTH_SHORT).show()
                        }
                    }
                }
            ) { Text(stringResource(R.string.add_to_queue)) }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { showClearAllConfirm = true }) { Text("Очистить очередь загрузки") }
                Button(onClick = { onClearCompletedQueue(); Toast.makeText(context, "Очередь очищена", Toast.LENGTH_SHORT).show() }) { Text("Очистить завершённые") }
                Button(onClick = { onClearFailedQueue(); Toast.makeText(context, "Очередь очищена", Toast.LENGTH_SHORT).show() }) { Text("Очистить ошибки") }
            }
            if (showClearAllConfirm) {
                AlertDialog(
                    onDismissRequest = { showClearAllConfirm = false },
                    title = { Text("Очистить очередь загрузки") },
                    text = { Text("Очистить всю очередь загрузки? Локальные видео и фото не будут удалены.") },
                    confirmButton = { TextButton(onClick = { onClearAllQueue(); showClearAllConfirm = false; Toast.makeText(context, "Очередь очищена", Toast.LENGTH_SHORT).show() }) { Text("Очистить") } },
                    dismissButton = { TextButton(onClick = { showClearAllConfirm = false }) { Text("Отмена") } },
                )
            }
            Button(
                onClick = {
                    val diagnostics = onExportDiagnostics()
                    clipboardManager.setText(AnnotatedString(diagnostics))
                    Toast.makeText(context, "diagnostic JSON скопирован", Toast.LENGTH_SHORT).show()
                }
            ) { Text(stringResource(R.string.copy_diagnostic_json)) }
            if (filter == "Новые" && queuedCount == 0 && (uploadingCount > 0 || errorCount > 0)) {
                Text(
                    "Новых элементов нет. Есть элементы в процессе или с ошибкой — переключите фильтр очереди.",
                    color = MaterialTheme.colorScheme.error
                )
            }
            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                itemsIndexed(filteredQueue) { _, item ->
                    val session = sessions.firstOrNull { it.id == item.sessionId }
                    val inferredLegacyOrderLabel = if (item.orderId == null && session?.serverOrderId != null) {
                        "Заявка #${session.serverOrderId} — ${session.orderTitle ?: "без названия"} (legacy item: order inferred from session)"
                    } else null
                    val isLegacyItem = item.orderId == null
                    val isStaleUploading = item.status == com.maklertour.domain.UploadStatus.Uploading &&
                            java.time.Duration.between(item.updatedAt, java.time.Instant.now()).toMinutes() > 10
                    val orderLabel = if (item.orderId != null) {
                        "Заявка #${item.orderId} — ${item.orderTitle ?: "без названия"}"
                    } else if (inferredLegacyOrderLabel != null) {
                        inferredLegacyOrderLabel
                    } else {
                        "Заявка: не выбрана"
                    }
                    val sessionLabel = item.sessionTitle ?: session?.name ?: "Сессия ${item.sessionId.take(8)}"
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Column(
                            modifier = Modifier.padding(12.dp),
                            verticalArrangement = Arrangement.spacedBy(6.dp)
                        ) {
                            Text(orderLabel, style = MaterialTheme.typography.titleMedium)
                            Text("Сессия: $sessionLabel")
                            if (!item.orderAddress.isNullOrBlank()) {
                                Text("Адрес: ${item.orderAddress}")
                            }
                            Text("Статус: ${item.status}")
                            Text("Попыток: ${item.retryCount}")
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Button(
                                    onClick = {
                                        if (!hasValidatedInternet(context)) {
                                            Toast.makeText(
                                                context,
                                                "Нет интернет-соединения для отправки на сервер",
                                                Toast.LENGTH_SHORT
                                            ).show()
                                        } else {
                                            onUpload(item.id)
                                        }
                                    },
                                    enabled = item.status != com.maklertour.domain.UploadStatus.Uploading && !isLegacyItem
                                ) {
                                    Text(
                                        when (item.status) {
                                            com.maklertour.domain.UploadStatus.Uploading -> "Отправляется..."
                                            com.maklertour.domain.UploadStatus.Queued -> "Отправить на сервер"
                                            com.maklertour.domain.UploadStatus.Error -> "Отправить повторно"
                                            com.maklertour.domain.UploadStatus.Success -> "Отправить повторно"
                                        }
                                    )
                                }
                                if (item.status == com.maklertour.domain.UploadStatus.Uploading ||
                                    item.status == com.maklertour.domain.UploadStatus.Error ||
                                    item.status == com.maklertour.domain.UploadStatus.Success
                                ) {
                                    Button(onClick = { onResetQueueItem(item.id) }) {
                                        Text(
                                            when (item.status) {
                                                com.maklertour.domain.UploadStatus.Uploading -> "Добавить новые файлы в очередь"
                                                com.maklertour.domain.UploadStatus.Error -> "Добавить новые файлы в очередь"
                                                com.maklertour.domain.UploadStatus.Success -> "Добавить новые файлы в очередь"
                                                com.maklertour.domain.UploadStatus.Queued -> "Сбросить в новые"
                                            }
                                        )
                                    }
                                }
                                Button(
                                    onClick = { onDeleteQueueItem(item.id) },

                                ) {
                                    Text("Удалить из очереди")
                                }
                            }
                            Text("Прогресс: ${if (item.status == com.maklertour.domain.UploadStatus.Success) 100 else item.progressPercent}%")
                            Text("Шаг: ${item.currentStep ?: "—"}")
                            Text("Файл: ${item.currentFileName ?: "—"}")
                            Text("Bytes: ${item.bytesUploaded}/${item.bytesTotal}")
                            Text("Обновлено: ${item.updatedAt}")

                            if (isLegacyItem) {
                                Text(
                                    "Эта загрузка создана старой версией APP или без выбранной заявки. Удалите её и добавьте сессию в очередь из нужной заявки.",
                                    color = MaterialTheme.colorScheme.error
                                )
                            }
                            if (isStaleUploading) {
                                Text(
                                    "Загрузка давно в статусе Uploading. Можно сбросить или удалить.",
                                    color = MaterialTheme.colorScheme.error
                                )
                            }
                        }
                    }
                }
            }
        }

    }

    private enum class CaptureMode {
        PHOTO_POINT,
        VIDEO_SCAN,
    }

@Composable
private fun SimpleFilterDropdown(
    label: String,
    selected: String,
    options: List<String>,
    onSelected: (String) -> Unit
) {
    var expanded by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label)
        Box {
            Button(
                onClick = { expanded = true },
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(selected)
            }
            DropdownMenu(
                expanded = expanded,
                onDismissRequest = { expanded = false }
            ) {
                options.forEach { option ->
                    DropdownMenuItem(
                        text = { Text(option) },
                        onClick = {
                            onSelected(option)
                            expanded = false
                        }
                    )
                }
            }
        }
    }
}
private fun hasValidatedInternet(context: android.content.Context): Boolean {
        val manager = context.getSystemService(ConnectivityManager::class.java) ?: return false
        val network = manager.activeNetwork ?: return false
        val caps = manager.getNetworkCapabilities(network) ?: return false
    return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) &&
            (
                    caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) ||
                            caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)
                    )
    }

private class SensorAvailabilityState {
    var accelerometerAvailable by mutableStateOf(false)
        private set
    var gyroscopeAvailable by mutableStateOf(false)
        private set
    var rotationVectorAvailable by mutableStateOf(false)
        private set
    var gravityAvailable by mutableStateOf(false)
        private set
    var activeSensorSummary by mutableStateOf("Проверка датчиков...")
        private set

    fun update(manager: SensorManager?) {
        accelerometerAvailable = manager?.getDefaultSensor(Sensor.TYPE_ACCELEROMETER) != null
        gyroscopeAvailable = manager?.getDefaultSensor(Sensor.TYPE_GYROSCOPE) != null
        val hasRotation = manager?.getDefaultSensor(Sensor.TYPE_ROTATION_VECTOR) != null ||
            manager?.getDefaultSensor(Sensor.TYPE_GAME_ROTATION_VECTOR) != null
        rotationVectorAvailable = hasRotation
        gravityAvailable = manager?.getDefaultSensor(Sensor.TYPE_GRAVITY) != null
        activeSensorSummary = "Акселерометр: ${if (accelerometerAvailable) "есть" else "нет"}; " +
            "гироскоп: ${if (gyroscopeAvailable) "есть" else "нет"}; " +
            "rotation vector / gravity: ${if (rotationVectorAvailable || gravityAvailable) "есть" else "нет"}"
    }
}

@Composable
private fun rememberSensorAvailabilityState(): SensorAvailabilityState {
    val context = LocalContext.current
    val state = remember { SensorAvailabilityState() }
    DisposableEffect(context) {
        state.update(context.getSystemService(SensorManager::class.java))
        onDispose { }
    }
    return state
}

private fun defaultPhoneScanCalibrationMetadata(): PhoneScanCalibrationMetadata = PhoneScanCalibrationMetadata(
    baselinePitchDeg = null,
    baselineRollDeg = null,
    baselineQuaternion = null,
    calibrationTimestamp = java.time.Instant.now().toString(),
    rollGreenThresholdDeg = 0f,
    pitchGreenThresholdDeg = 0f,
    rollYellowThresholdDeg = 0f,
    pitchYellowThresholdDeg = 0f,
    markerMode = "none",
    markersUsed = false,
)

@Composable
private fun PreparationPanel(sensors: SensorAvailabilityState, onDismiss: () -> Unit) {
    Surface(
        modifier = Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.55f)).padding(16.dp),
        color = Color.Black.copy(alpha = 0.86f),
    ) {
        LazyColumn(modifier = Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    Text("Подготовка к съёмке", color = Color.White, style = MaterialTheme.typography.titleLarge)
                    TextButton(onClick = onDismiss) { Text("Закрыть") }
                }
            }
            item {
                CalibrationSection("Камера") {
                    Text("Камера: телефонная камера", color = Color.White)
                    Text("Разрешение/FPS: будет сохранено в camera_info.json после записи", color = Color.White)
                    Text("Фокус: авто / блокировка фокуса — скоро", color = Color.White)
                    Text("Экспозиция: авто / блокировка экспозиции — скоро", color = Color.White)
                    Text("Баланс белого: авто / блокировка — скоро", color = Color.White)
                }
            }
            item {
                CalibrationSection("IMU") {
                    Text("Акселерометр: ${if (sensors.accelerometerAvailable) "есть" else "нет"}", color = Color.White)
                    Text("Гироскоп: ${if (sensors.gyroscopeAvailable) "есть" else "нет"}", color = Color.White)
                    Text("Rotation vector / gravity: ${if (sensors.rotationVectorAvailable || sensors.gravityAvailable) "есть" else "нет"}", color = Color.White)
                    Text("IMU будет записан автоматически", color = Color.White)
                }
            }
            item {
                CalibrationSection("Маркеры") {
                    Text("ArUco / AprilTag маркеры", color = Color.White)
                    Text("Маркеры можно использовать позже для масштаба и ориентации", color = Color.White)
                    Text("Печать маркеров — скоро", color = Color.White.copy(alpha = 0.75f))
                }
            }
            item {
                CalibrationSection("Рекомендации") {
                    listOf(
                        "Снимайте медленно",
                        "Не делайте резких поворотов",
                        "Держите перекрытие кадров",
                        "Лучше использовать стабилизатор, если доступен",
                    ).forEach { Text("• $it", color = Color.White) }
                }
            }
        }
    }
}

@Composable
private fun CalibrationSection(title: String, content: @Composable ColumnScope.() -> Unit) {
    Card(modifier = Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color(0xFF202124))) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(title, color = Color.White, style = MaterialTheme.typography.titleMedium)
            content()
        }
    }
}

@Composable
private fun VideoRoleGuide(role: com.maklertour.domain.ScanVideoRole, confirmed: Boolean, onConfirm: () -> Unit) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (role == com.maklertour.domain.ScanVideoRole.DETAIL) {
                Text("Дополнительное видео", style = MaterialTheme.typography.titleMedium)
                Text("Можно снимать углы, пол, потолок, мебель и сложные зоны. Главное — сохраняйте перекрытие с основным проходом.")
            } else {
                Text("Первое видео: основной проход", style = MaterialTheme.typography.titleMedium)
                Text("Держите телефон ровно и стабильно. Пройдите периметр одним плавным кругом. Не делайте резких поворотов.")
                listOf(
                    "Телефон держим ровно",
                    "Идём медленно",
                    "Один плавный круг по периметру",
                    "Держим перекрытие кадров",
                    "Не снимаем слишком близко к стенам",
                ).forEach { Text("• $it") }
                Button(onClick = onConfirm, enabled = !confirmed) { Text(if (confirmed) "Готово" else "Понятно, начать основной проход") }
            }
        }
    }
}

@Composable
private fun VideoBackboneStatusCard(scanVideos: List<com.maklertour.domain.ScanVideo>) {
    val captured = scanVideos.filter { it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED }
    val hasBackbone = captured.any { effectiveVideoScanRole(scanVideos, it) != com.maklertour.domain.ScanVideoRole.DETAIL }
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(if (hasBackbone) "Основной проход есть. Можно добавлять дополнительные видео с деталями." else "Нет основного прохода. Сначала снимите одно ровное видео по периметру.")
        }
    }
}

private fun nextVideoScanRole(scanVideos: List<com.maklertour.domain.ScanVideo>): com.maklertour.domain.ScanVideoRole =
    if (scanVideos.any { it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED && effectiveVideoScanRole(scanVideos, it) != com.maklertour.domain.ScanVideoRole.DETAIL }) com.maklertour.domain.ScanVideoRole.DETAIL
    else if (scanVideos.any { it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED }) com.maklertour.domain.ScanVideoRole.DETAIL
    else com.maklertour.domain.ScanVideoRole.BACKBONE

private fun effectiveVideoScanRole(all: List<com.maklertour.domain.ScanVideo>, scan: com.maklertour.domain.ScanVideo): com.maklertour.domain.ScanVideoRole {
    scan.role?.let { return if (it == com.maklertour.domain.ScanVideoRole.MAIN_PASS) com.maklertour.domain.ScanVideoRole.BACKBONE else it }
    val captured = all.filter { it.captureStatus == com.maklertour.domain.ScanVideoCaptureStatus.CAPTURED }.sortedWith(compareBy({ it.sequenceNumber }, { it.createdAt }))
    return if (captured.firstOrNull()?.id == scan.id) com.maklertour.domain.ScanVideoRole.BACKBONE else com.maklertour.domain.ScanVideoRole.DETAIL
}

private fun defaultVideoScanNameForRole(role: com.maklertour.domain.ScanVideoRole, sequenceNumber: Int, manualName: String = ""): String {
    if (manualName.isNotBlank() && manualName != "Scan" && !manualName.startsWith("Scan ")) return manualName
    return if (role == com.maklertour.domain.ScanVideoRole.DETAIL) "Детали $sequenceNumber" else "Основной проход"
}

private fun videoRoleLabel(role: com.maklertour.domain.ScanVideoRole): String =
    if (role == com.maklertour.domain.ScanVideoRole.DETAIL) "Детали" else "Основной проход"

@Composable
private fun StereoCaptureExperimentalScreen(
    orderId: String?,
    captureSessionId: String,
    onClose: () -> Unit,
) {
    val context = LocalContext.current
    val configuration = LocalConfiguration.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val scope = rememberCoroutineScope()
    val manager = remember(context, lifecycleOwner) { StereoCaptureExperimentalManager(context, lifecycleOwner) }
    DisposableEffect(manager) { onDispose { manager.close() } }
    LaunchedEffect(Unit) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            (context as? ComponentActivity)?.let { ActivityCompat.requestPermissions(it, arrayOf(Manifest.permission.RECORD_AUDIO), 13031) }
        }
    }
    val lensRepository = remember(context) { PhoneCameraLensRepository(context) }
    var cameraOptions by remember { mutableStateOf(lensRepository.listBackCameras()) }
    var selectedCameraId by remember { mutableStateOf(lensRepository.getSelectedCameraId() ?: cameraOptions.firstOrNull()?.cameraId) }
    var requestedZoomRatio by remember { mutableStateOf(if (lensRepository.getSelectedZoomRatio() <= 0f) 0.5f else lensRepository.getSelectedZoomRatio()) }
    val cam1Info by manager.cam1State.collectAsState()
    var usbInfo by remember { mutableStateOf(manager.detectUsbUvcCamera()) }
    val profileStore = remember(context) { StereoRigProfileStore(context) }
    var activeProfile by remember { mutableStateOf(profileStore.loadActiveProfile()) }
    var showRigSettings by remember { mutableStateOf(false) }
    var showDiagnostics by remember { mutableStateOf(false) }
    var showCalibrationCapture by remember { mutableStateOf(false) }
    var cam0PreviewView by remember { mutableStateOf<PreviewView?>(null) }
    var cam1TextureView by remember { mutableStateOf<TextureView?>(null) }
    var status by remember { mutableStateOf("Ready") }
    var isRecording by remember { mutableStateOf(false) }
    var elapsedSec by remember { mutableStateOf(0L) }
    var validationText by remember { mutableStateOf("Not recorded") }
    val baseline = activeProfile.baselineMm
    val baselineReady = baseline != null && baseline > 0.0
    val cam1Fresh = (cam1Info.cam1LastFrameAgeMs ?: Long.MAX_VALUE) < 1_500L
    val cam1Ready = cam1Info.error == null &&
        cam1Info.cam1FramesRendered > 0L &&
        cam1Fresh &&
        cam1Info.status == UsbUvcStatus.UVC_PREVIEW_ACTIVE
    val canRecord = !isRecording && baselineReady && cam1Ready
    val desiredCam1 = activeProfile.cam1Mode.modeLabel()
    val activeCam1 = listOfNotNull(cam1Info.selectedPixelFormat, cam1Info.selectedResolutionFps).joinToString(" ").ifBlank { "not active" }
    val previewDroppingFrames = cam1Info.status == UsbUvcStatus.UVC_PREVIEW_ACTIVE && cam1Info.cam1PreviewFpsEstimate > 0.0 && cam1Info.cam1PreviewFpsEstimate < 20.0
    val modeMismatch = activeProfile.cam1Mode?.let { mode ->
        if (mode.selectedBy != CameraModeSelection.MANUAL) false
        else {
            val activeFormat = cam1Info.selectedPixelFormat
            val activeRes = cam1Info.selectedResolutionFps
            activeFormat != null && (
                !mode.format.equals(activeFormat, ignoreCase = true) ||
                    activeRes?.contains("${mode.width}x${mode.height}@${mode.fps}") != true
            )
        }
    } == true
    val isLandscape = configuration.screenWidthDp > configuration.screenHeightDp

    LaunchedEffect(isRecording) {
        elapsedSec = 0L
        while (isRecording) {
            delay(1_000)
            elapsedSec += 1L
        }
    }

    fun refreshProfileAndUsb() {
        activeProfile = profileStore.loadActiveProfile()
        usbInfo = manager.refreshCam1(activeProfile.cam1Mode, null)
    }

    Surface(modifier = Modifier.fillMaxSize().zIndex(20f), color = Color.Black) {
        Column(modifier = Modifier.fillMaxSize().padding(12.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = onClose, enabled = !isRecording) { Text("Close") }
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("Stereo Capture", color = Color.White, style = MaterialTheme.typography.titleMedium)
                    Text("${elapsedSec}s · USB ${cam1Info.status.label()} · cam0 ${if (status.contains("cam0 preview active")) "active" else "ready"}", color = Color.White, style = MaterialTheme.typography.bodySmall)
                }
                Box(modifier = Modifier.size(96.dp, 1.dp))
            }

            val cam0Card: @Composable (Modifier) -> Unit = { modifier ->
                Box(modifier = modifier.background(Color.DarkGray), contentAlignment = Alignment.Center) {
                    AndroidView(modifier = Modifier.fillMaxSize(), factory = { ctx ->
                        PreviewView(ctx).apply {
                            cam0PreviewView = this
                            scaleType = PreviewView.ScaleType.FILL_CENTER
                            implementationMode = PreviewView.ImplementationMode.COMPATIBLE
                            scope.launch {
                                runCatching { manager.bindCam0Preview(this@apply, selectedCameraId, requestedZoomRatio) }
                                    .onSuccess { status = if (it.success) "cam0 preview active" else "cam0 preview error: ${it.error}" }
                                    .onFailure { status = "cam0 preview error: ${it.message}" }
                            }
                        }
                    })
                    Text("cam0", color = Color.White, modifier = Modifier.align(Alignment.TopStart).padding(8.dp))
                }
            }
            val cam1Card: @Composable (Modifier) -> Unit = { modifier ->
                Box(modifier = modifier.background(Color(0xFF202020)), contentAlignment = Alignment.Center) {
                    AndroidView(modifier = Modifier.fillMaxSize(), factory = { ctx ->
                        TextureView(ctx).apply {
                            cam1TextureView = this
                            surfaceTextureListener = object : TextureView.SurfaceTextureListener {
                                override fun onSurfaceTextureAvailable(surface: android.graphics.SurfaceTexture, width: Int, height: Int) { usbInfo = manager.refreshCam1(activeProfile.cam1Mode, this@apply) }
                                override fun onSurfaceTextureSizeChanged(surface: android.graphics.SurfaceTexture, width: Int, height: Int) = Unit
                                override fun onSurfaceTextureDestroyed(surface: android.graphics.SurfaceTexture): Boolean = true
                                override fun onSurfaceTextureUpdated(surface: android.graphics.SurfaceTexture) { manager.onCam1PreviewFrameRendered() }
                            }
                        }
                    })
                    Column(modifier = Modifier.align(Alignment.TopStart).background(Color.Black.copy(alpha = 0.55f)).padding(8.dp)) {
                        Text("cam1", color = Color.White, style = MaterialTheme.typography.labelLarge)
                        if (showDiagnostics) {
                            Text(cam1Info.status.label(), color = Color.White, style = MaterialTheme.typography.bodySmall)
                            Text(activeCam1, color = Color.White, style = MaterialTheme.typography.bodySmall)
                            Text("input ${String.format(java.util.Locale.US, "%.1f", cam1Info.cam1FpsEstimate)} fps", color = Color.White, style = MaterialTheme.typography.bodySmall)
                            Text("preview ${String.format(java.util.Locale.US, "%.1f", cam1Info.cam1PreviewFpsEstimate)} fps", color = Color.White, style = MaterialTheme.typography.bodySmall)
                            Text("frames ${cam1Info.cam1FramesReceived}", color = Color.White, style = MaterialTheme.typography.bodySmall)
                            if (previewDroppingFrames) Text("Preview is dropping frames. Use 640x480 for smoother capture.", color = Color(0xFFFFD166), style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
            if (isLandscape) {
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    cam0Card(Modifier.weight(1f).aspectRatio(16f / 9f))
                    cam1Card(Modifier.weight(1f).aspectRatio(16f / 9f))
                }
            } else {
                cam0Card(Modifier.fillMaxWidth().aspectRatio(16f / 9f))
                cam1Card(Modifier.fillMaxWidth().aspectRatio(16f / 9f))
            }

            Card(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text("Rig / status", style = MaterialTheme.typography.titleSmall)
                    Text("Active profile: ${activeProfile.rigId}")
                    Text("Baseline: ${baseline?.let { "${it} mm" } ?: "not set"}")
                    Text("Calibration: ${activeProfile.calibrationStatus.name.lowercase().replace('_', ' ')}")
                    Text("cam0 desired mode: ${activeProfile.cam0Mode.modeLabel()}")
                    Text("cam1 desired mode: $desiredCam1")
                    Text("cam1 active mode: $activeCam1")
                    if (!baselineReady) Text("Set baseline in Stereo rig / Cameras settings.", color = Color(0xFFFFD166))
                    if (!cam1Ready) Text("Wait for live cam1 preview before recording.", color = Color(0xFFFFD166))
                    if (previewDroppingFrames) Text("Preview is dropping frames. Use 640x480 for smoother capture.", color = Color(0xFFFFD166))
                    if (modeMismatch) Text("Selected mode is not active. It will be tried on the next automatic USB bind.", color = Color(0xFFFFD166))
                    Text("Status: $status · Validation: $validationText")
                }
            }

            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { showCalibrationCapture = true }, enabled = !isRecording) { Text("Calibration") }
                Button(onClick = {
                    val currentProfile = profileStore.loadActiveProfile().also { activeProfile = it }
                    val currentBaseline = currentProfile.baselineMm
                    if (currentBaseline == null || currentBaseline <= 0.0) { validationText = "Set baseline in Stereo rig / Cameras settings."; return@Button }
                    scope.launch {
                        runCatching { manager.start(orderId, captureSessionId, StereoRigConfig(currentProfile.rigId, currentProfile.cam0Label, currentProfile.cam1Label, currentBaseline)) }
                            .onSuccess { isRecording = true; status = "recording to ${it.name}" }
                            .onFailure { status = "start failed: ${it.message}" }
                    }
                }, enabled = canRecord) { Text("Record stereo video") }
                Button(onClick = {
                    scope.launch {
                        val result = manager.stop()
                        isRecording = false
                        validationText = if (result.ok) "OK: ${result.bundleDir.name}" else "Failed: ${result.errors.joinToString()}"
                    }
                }, enabled = isRecording) { Text("Stop video") }
                Button(onClick = { showRigSettings = true }, enabled = !isRecording) { Text("Open settings") }
            }
            if (!canRecord && !isRecording) {
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    if (!baselineReady) Text("Set baseline in Stereo rig / Cameras settings.", color = Color(0xFFFFD166))
                    if (!cam1Ready) Text("Wait for live cam1 preview before recording.", color = Color(0xFFFFD166))
                }
            }
            Text("Stereo video records cam0.mp4, cam1.mp4, imu.jsonl, rig.json and manifests.", color = Color.White, style = MaterialTheme.typography.bodySmall)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Button(onClick = { showDiagnostics = !showDiagnostics }) { Text(if (showDiagnostics) "Hide diagnostics" else "Show diagnostics") }
                Button(onClick = { cameraOptions = lensRepository.listBackCameras(); selectedCameraId = cameraOptions.firstOrNull()?.cameraId }, enabled = !isRecording) { Text("Refresh lenses") }
            }
            if (showDiagnostics) {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Diagnostics", style = MaterialTheme.typography.titleSmall)
                        Text("USB device: ${cam1Info.productName ?: cam1Info.deviceName ?: "no device"}")
                        Text("cam1_input_fps_estimate=${String.format(java.util.Locale.US, "%.1f", cam1Info.cam1FpsEstimate)}")
                        Text("cam1_preview_fps_estimate=${String.format(java.util.Locale.US, "%.1f", cam1Info.cam1PreviewFpsEstimate)}")
                        Text("cam1_frames_received=${cam1Info.cam1FramesReceived}")
                        Text("cam1_frames_decoded=${cam1Info.cam1FramesDecoded}")
                        Text("cam1_frames_rendered=${cam1Info.cam1FramesRendered}")
                        Text("cam1_last_frame_age_ms=${cam1Info.cam1LastFrameAgeMs ?: "none"}")
                        Text("cam1_decode_errors=${cam1Info.cam1DecodeErrors}")
                        Text("cam1_render_errors=${cam1Info.cam1RenderErrors}")
                        Text("cam1_backend=native libuvc")
                        cam1Info.error?.let { Text(it, color = Color(0xFFFFD166)) }
                    }
                }
            }
        }
    }
    if (showCalibrationCapture) {
        CalibrationCaptureDialog(
            profile = activeProfile,
            cam0BitmapProvider = { cam0PreviewView?.bitmap },
            cam1BitmapProvider = { cam1TextureView?.bitmap },
            onDismiss = { showCalibrationCapture = false },
            onSessionSaved = { updatedProfile -> activeProfile = updatedProfile },
        )
    }
    if (showRigSettings) {
        AlertDialog(
            onDismissRequest = { showRigSettings = false },
            title = { Text("Stereo rig / Cameras") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(8.dp)) { StereoRigSummary(activeProfile); Text("Open Settings tab -> Stereo rig / Cameras") } },
            confirmButton = { TextButton(onClick = { activeProfile = profileStore.loadActiveProfile(); showRigSettings = false }) { Text("Close") } },
        )
    }
}


@Composable
private fun CalibrationCaptureDialog(
    profile: StereoRigProfile,
    cam0BitmapProvider: () -> Bitmap?,
    cam1BitmapProvider: () -> Bitmap?,
    onDismiss: () -> Unit,
    onSessionSaved: (StereoRigProfile) -> Unit,
) {
    val context = LocalContext.current
    val profileStore = remember(context) { StereoRigProfileStore(context) }
    val settings = profile.calibrationSettings
    val detector: CalibrationBoardDetector = remember { OpenCvCalibrationBoardDetector() }
    var sessionDir by remember { mutableStateOf<File?>(null) }
    var pairCount by remember { mutableStateOf(0) }
    var message by remember { mutableStateOf("Ready for assisted checkerboard captures") }
    var cam0Detection by remember { mutableStateOf<CalibrationDetectionResult?>(null) }
    var cam1Detection by remember { mutableStateOf<CalibrationDetectionResult?>(null) }
    var autoCapture by remember { mutableStateOf(false) }
    var lastAutoCaptureMs by remember { mutableStateOf(0L) }

    fun ensureSessionDir(): File {
        val existing = sessionDir
        if (existing != null) return existing
        val createdAt = utcNowIso8601()
        val dir = File(context.filesDir, "calibration_sessions/${calibrationTimestamp()}").apply { mkdirs() }
        File(dir, "pairs").mkdirs()
        val input = JSONObject()
            .put("created_at_utc", createdAt)
            .put("active_rig_profile", profile.toJson())
            .put("checkerboard_inner_cols", settings.checkerboardInnerCols)
            .put("checkerboard_inner_rows", settings.checkerboardInnerRows)
            .put("square_size_mm", settings.squareSizeMm)
            .put("required_pairs", settings.requiredPairs)
            .put("capture_source", "preview_bitmap")
            .put("note", "not hardware synchronized")
        File(dir, "calibration_input.json").writeText(input.toString(2))
        File(dir, "pairs_manifest.json").writeText(JSONObject().put("pairs", JSONArray()).toString(2))
        sessionDir = dir
        return dir
    }

    fun capturePair(requireValidDetection: Boolean): Boolean {
        val cam0 = cam0BitmapProvider()
        val cam1 = cam1BitmapProvider()
        val cam0Result = cam0Detection
        val cam1Result = cam1Detection
        if (cam0 == null || cam1 == null) {
            message = "Preview bitmap unavailable for ${if (cam0 == null) "cam0" else "cam1"}; wait for live previews."
            return false
        }
        if (requireValidDetection && (cam0Result?.found != true || cam1Result?.found != true)) {
            message = "Both cameras must show FOUND before assisted capture."
            return false
        }
        return runCatching {
            val dir = ensureSessionDir()
            val nextIndex = pairCount + 1
            val pairsDir = File(dir, "pairs").apply { mkdirs() }
            val cam0Name = "pair_${nextIndex.toString().padStart(4, '0')}_cam0.jpg"
            val cam1Name = "pair_${nextIndex.toString().padStart(4, '0')}_cam1.jpg"
            saveJpeg(cam0, File(pairsDir, cam0Name))
            saveJpeg(cam1, File(pairsDir, cam1Name))
            appendCalibrationPairManifest(dir, nextIndex, "pairs/$cam0Name", "pairs/$cam1Name", cam0Result, cam1Result, settings.checkerboardInnerCols * settings.checkerboardInnerRows)
            pairCount = nextIndex
            message = "Captured pair $nextIndex to ${dir.name}"
            true
        }.getOrElse {
            message = "Capture failed: ${it.message}"
            false
        }
    }

    LaunchedEffect(detector, settings, autoCapture, pairCount) {
        while (true) {
            val cam0 = cam0BitmapProvider()
            val cam1 = cam1BitmapProvider()
            if (cam0 != null && cam1 != null) {
                val (cam0Result, cam1Result) = withContext(Dispatchers.Default) {
                    detector.detect(cam0, settings) to detector.detect(cam1, settings)
                }
                cam0Detection = cam0Result
                cam1Detection = cam1Result
                if (autoCapture && pairCount < settings.requiredPairs && cam0Result.found && cam1Result.found) {
                    val now = System.currentTimeMillis()
                    if (now - lastAutoCaptureMs >= 1_200) {
                        if (capturePair(requireValidDetection = true)) lastAutoCaptureMs = now
                    }
                }
            }
            delay(500)
        }
    }

    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(modifier = Modifier.fillMaxSize().padding(12.dp), color = Color(0xFF101010)) {
            Column(modifier = Modifier.fillMaxSize().padding(12.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    Text("Calibration Capture", color = Color.White, style = MaterialTheme.typography.titleMedium)
                    TextButton(onClick = onDismiss) { Text("Close") }
                }
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Active profile: ${profile.rigId}")
                        Text("Checkerboard: ${settings.checkerboardInnerCols} x ${settings.checkerboardInnerRows} inner corners")
                        Text("Square size: ${settings.squareSizeMm} mm")
                        Text("Captured pairs: $pairCount / ${settings.requiredPairs}")
                        Text("Move checkerboard across the full frame: center, corners, tilted left/right/up/down, near/far.")
                        Text("Capture only when both cameras show FOUND.")
                        Text("Source: preview bitmap · not hardware synchronized")
                        Text(message)
                    }
                }
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PreviewBitmapPanel("cam0", cam0BitmapProvider, cam0Detection, Modifier.weight(1f).aspectRatio(16f / 9f))
                    PreviewBitmapPanel("cam1", cam1BitmapProvider, cam1Detection, Modifier.weight(1f).aspectRatio(16f / 9f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Button(onClick = { capturePair(requireValidDetection = true) }, enabled = cam0Detection?.found == true && cam1Detection?.found == true) { Text("Capture valid pair") }
                    Button(onClick = { capturePair(requireValidDetection = false) }) { Text("Capture pair (manual fallback)") }
                    Button(onClick = { autoCapture = !autoCapture }) { Text("Auto capture: ${if (autoCapture) "On" else "Off"}") }
                    Button(onClick = {
                        if (pairCount < 1) {
                            message = "Capture at least one pair before saving session."
                        } else {
                            val dir = ensureSessionDir()
                            val updated = profile.copy(calibrationStatus = CalibrationStatus.CAPTURED, lastCalibrationSessionPath = dir.absolutePath)
                            profileStore.saveActiveProfile(updated)
                            onSessionSaved(updated)
                            message = "Saved session and marked profile captured."
                        }
                    }) { Text("Save session") }
                    Button(onClick = onDismiss) { Text("Close") }
                }
            }
        }
    }
}

@Composable
private fun PreviewBitmapPanel(label: String, bitmapProvider: () -> Bitmap?, detection: CalibrationDetectionResult?, modifier: Modifier = Modifier) {
    Box(modifier = modifier.background(Color.DarkGray), contentAlignment = Alignment.Center) {
        val bitmap = bitmapProvider()
        if (bitmap != null) {
            AsyncImage(model = bitmap, contentDescription = label, modifier = Modifier.fillMaxSize(), contentScale = ContentScale.Fit)
        } else {
            Text("Waiting for $label preview", color = Color.White)
        }
        val status = detection?.let { if (it.found) "board found" else "board not found" } ?: "detecting"
        val count = detection?.let { " ${it.cornersFound}/${it.expectedCorners}" }.orEmpty()
        val statusColor = when (detection?.found) {
            true -> Color(0xFF00E676)
            false -> Color(0xFFFFD54F)
            null -> Color.White
        }
        val detectionResult = detection
        if (detectionResult != null && detectionResult.normalizedCornerPoints.isNotEmpty()) {
            val points = detectionResult.normalizedCornerPoints
            Canvas(modifier = Modifier.fillMaxSize()) {
                val imageAspect =
                    detectionResult.imageWidth.toFloat() /
                        detectionResult.imageHeight.toFloat().coerceAtLeast(1f)
                val boxAspect = size.width / size.height.coerceAtLeast(1f)
                val drawWidth: Float
                val drawHeight: Float
                val offsetX: Float
                val offsetY: Float
                if (imageAspect > boxAspect) {
                    drawWidth = size.width
                    drawHeight = size.width / imageAspect
                    offsetX = 0f
                    offsetY = (size.height - drawHeight) / 2f
                } else {
                    drawHeight = size.height
                    drawWidth = size.height * imageAspect
                    offsetX = (size.width - drawWidth) / 2f
                    offsetY = 0f
                }
                val offsets = points.map {
                    Offset(offsetX + it.x * drawWidth, offsetY + it.y * drawHeight)
                }
                offsets.forEach {
                    drawCircle(Color(0xFF00E676), radius = 4.dp.toPx(), center = it)
                }
                drawRect(
                    Color(0xFF00E676),
                    topLeft = Offset(offsetX, offsetY),
                    size = ComposeSize(drawWidth, drawHeight),
                    style = Stroke(width = 1.dp.toPx()),
                )
            }
        }
        Column(modifier = Modifier.align(Alignment.TopStart).background(Color.Black.copy(alpha = 0.65f)).padding(8.dp)) {
            Text("$label $status$count", color = statusColor)
            detection?.qualityMessage?.let { Text(it, color = Color.White) }
        }
    }
}

private fun saveJpeg(bitmap: Bitmap, file: File) {
    FileOutputStream(file).use { out -> bitmap.compress(Bitmap.CompressFormat.JPEG, 92, out) }
}

private fun appendCalibrationPairManifest(
    sessionDir: File,
    pairIndex: Int,
    cam0File: String,
    cam1File: String,
    cam0Detection: CalibrationDetectionResult?,
    cam1Detection: CalibrationDetectionResult?,
    expectedCorners: Int,
) {
    val manifestFile = File(sessionDir, "pairs_manifest.json")
    val manifest = if (manifestFile.exists()) JSONObject(manifestFile.readText()) else JSONObject().put("pairs", JSONArray())
    val pairs = manifest.optJSONArray("pairs") ?: JSONArray().also { manifest.put("pairs", it) }
    pairs.put(
        JSONObject()
            .put("pair_index", pairIndex)
            .put("cam0_file", cam0File)
            .put("cam1_file", cam1File)
            .put("captured_at_utc", utcNowIso8601())
            .put("cam0_source", "preview_bitmap")
            .put("cam1_source", "preview_bitmap")
            .put("cam0_checkerboard_found", cam0Detection?.found == true)
            .put("cam1_checkerboard_found", cam1Detection?.found == true)
            .put("cam0_corners_found", cam0Detection?.cornersFound ?: 0)
            .put("cam1_corners_found", cam1Detection?.cornersFound ?: 0)
            .put("expected_corners", expectedCorners)
            .put("capture_source", "preview_bitmap")
            .put("sync_status", "not_hardware_synchronized")
    )
    manifestFile.writeText(manifest.toString(2))
}

private fun utcNowIso8601(): String {
    val formatter = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US)
    formatter.timeZone = TimeZone.getTimeZone("UTC")
    return formatter.format(Date())
}

private fun calibrationTimestamp(): String {
    val formatter = SimpleDateFormat("yyyyMMdd_HHmmss_SSS", Locale.US)
    formatter.timeZone = TimeZone.getTimeZone("UTC")
    return formatter.format(Date())
}

private fun UsbUvcStatus.label(): String = when (this) {
    UsbUvcStatus.NOT_CONNECTED -> "USB not connected"
    UsbUvcStatus.DEVICE_FOUND -> "USB device found"
    UsbUvcStatus.PERMISSION_MISSING -> "USB permission missing"
    UsbUvcStatus.PERMISSION_REQUESTED -> "USB permission requested"
    UsbUvcStatus.PERMISSION_GRANTED -> "USB permission granted"
    UsbUvcStatus.PERMISSION_DENIED -> "USB permission denied"
    UsbUvcStatus.OPEN_DEVICE_SUCCESS -> "USB openDevice success"
    UsbUvcStatus.OPEN_DEVICE_FAILED -> "USB openDevice failed"
    UsbUvcStatus.UVC_ADAPTER_OPENING -> "UVC adapter opening"
    UsbUvcStatus.NATIVE_LIB_MISSING -> "native UVC library missing"
    UsbUvcStatus.NATIVE_UVC_INIT_FAILED -> "native UVC init failed"
    UsbUvcStatus.NATIVE_UVC_OPEN_FAILED -> "native UVC open failed"
    UsbUvcStatus.NATIVE_UVC_STREAM_START_FAILED -> "native UVC stream start failed"
    UsbUvcStatus.UVC_STREAM_STARTING -> "native UVC stream starting"
    UsbUvcStatus.UVC_STREAM_OPENED -> "real UVC stream opened, waiting for frames"
    UsbUvcStatus.UVC_STREAM_STARTED -> "native UVC stream started"
    UsbUvcStatus.UVC_FIRST_FRAME_RECEIVED -> "native UVC first frame received"
    UsbUvcStatus.UVC_PACKETS_RECEIVING -> "UVC packets receiving"
    UsbUvcStatus.UVC_FRAMES_ASSEMBLED -> "UVC frames assembled"
    UsbUvcStatus.UVC_FRAMES_DECODED -> "UVC frames decoded"
    UsbUvcStatus.UVC_PREVIEW_RENDERING -> "UVC preview rendering"
    UsbUvcStatus.UVC_STALLED_NO_PACKETS -> "UVC stalled: no packets"
    UsbUvcStatus.UVC_STALLED_NO_DECODED_FRAMES -> "UVC stalled: no decoded frames"
    UsbUvcStatus.UVC_STALLED_NO_NEW_FRAMES -> "native UVC no frames timeout"
    UsbUvcStatus.UVC_DECODE_FAILED -> "UVC decode failed"
    UsbUvcStatus.UVC_PREVIEW_ACTIVE -> "native UVC preview active"
    UsbUvcStatus.UVC_RENDER_FAILED -> "UVC render failed"
    UsbUvcStatus.UVC_PREVIEW_FAILED -> "native UVC stream failed"
    UsbUvcStatus.ACTIVE -> "UVC recording active"
    UsbUvcStatus.ERROR -> "USB error"
}
