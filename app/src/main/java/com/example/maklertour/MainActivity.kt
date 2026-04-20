package com.maklertour

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.maklertour.data.repository.SharedPrefsSessionRepository
import com.maklertour.data.repository.SharedPrefsUploadQueueRepository
import com.maklertour.state.AppStateViewModel

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaklerTourApp()
        }
    }
}

private enum class AppTab(val route: String, val title: String) {
    Sessions("sessions", "Сессии"),
    Camera("camera", "Камера"),
    Draft("draft", "Черновик"),
    Queue("queue", "Очередь")
}

@Composable
private fun MaklerTourApp() {
    val navController = rememberNavController()
    val context = LocalContext.current
    val viewModel = remember {
        AppStateViewModel(
            sessionRepository = SharedPrefsSessionRepository(context.applicationContext),
            uploadQueueRepository = SharedPrefsUploadQueueRepository(context.applicationContext),
        )
    }
    val state by viewModel.uiState.collectAsState()

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
                        label = { Text(tab.title) }
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
                    onCreate = viewModel::createSession,
                    onSelect = viewModel::selectSession,
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
                )
            }
            composable(AppTab.Draft.route) {
                val selectedSession = state.sessions.firstOrNull { it.id == state.selectedSessionId }
                DraftScreen(
                    sessionName = selectedSession?.name ?: "Сессия не выбрана",
                    points = selectedSession?.points.orEmpty(),
                    onRename = viewModel::renamePoint,
                    onDelete = viewModel::deletePoint,
                    onMoveUp = viewModel::movePointUp,
                    onMoveDown = viewModel::movePointDown,
                )
            }
            composable(AppTab.Queue.route) {
                QueueScreen(
                    queue = state.uploadQueue,
                    onEnqueue = viewModel::enqueueUpload,
                    onUpload = viewModel::processUpload,
                )
            }
        }
    }
}

@Composable

private fun SessionsScreen(
    state: com.maklertour.state.AppUiState,
    onCreate: (String, String, String) -> Unit,
    onSelect: (String) -> Unit,
) {
    var name by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var comment by remember { mutableStateOf("") }

    Column(modifier = Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Dashboard")
        OutlinedTextField(value = name, onValueChange = { name = it }, label = { Text("Название") }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(value = address, onValueChange = { address = it }, label = { Text("Адрес") }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(value = comment, onValueChange = { comment = it }, label = { Text("Комментарий") }, modifier = Modifier.fillMaxWidth())
        Button(
            onClick = {
                if (name.isNotBlank()) {
                    onCreate(name, address, comment)
                    name = ""
                    address = ""
                    comment = ""
                }
            }
        ) { Text("Создать сессию") }

        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            itemsIndexed(state.sessions) { _, session ->
                Card(onClick = { onSelect(session.id) }, modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(12.dp)) {
                        Text(session.name)
                        Text(session.address)
                        Text("Точек: ${session.points.size}")
                    }
                }
            }
        }
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
) {
    var pointName by remember { mutableStateOf("Точка") }
    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(if (connected) "Камера подключена" else "Камера не подключена")
        Text("Модель: ${model ?: "—"}")
        Text("Батарея: ${battery?.let { "$it%" } ?: "—"}")
        Text("Свободно: ${freeStorageMb?.let { "$it MB" } ?: "—"}")

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = onConnect) { Text("Подключить") }
            Button(onClick = onDisconnect) { Text("Отключить") }
            Button(onClick = onRefresh) { Text("Обновить") }
        }

        OutlinedTextField(
            value = pointName,
            onValueChange = { pointName = it },
            label = { Text("Имя точки") },
            modifier = Modifier.fillMaxWidth(),
        )
        Button(onClick = { if (pointName.isNotBlank()) onCapture(pointName) }, enabled = connected) {
            Text("Снять точку")
        }
    }

}

@Composable
private fun DraftScreen(
    sessionName: String,
    points: List<com.maklertour.domain.CapturePoint>,
    onRename: (String, String) -> Unit,
    onDelete: (String) -> Unit,
    onMoveUp: (Int) -> Unit,
    onMoveDown: (Int) -> Unit,
) {
    Column(modifier = Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Session Points: $sessionName")
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            itemsIndexed(points) { index, point ->
                var localName by remember(point.id) { mutableStateOf(point.name) }
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("#${index + 1} ${point.name}")
                        OutlinedTextField(
                            value = localName,
                            onValueChange = { localName = it },
                            label = { Text("Переименовать") },
                            modifier = Modifier.fillMaxWidth(),
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Button(onClick = { onRename(point.id, localName) }) { Text("Сохранить") }
                            Button(onClick = { onMoveUp(index) }) { Text("↑") }
                            Button(onClick = { onMoveDown(index) }) { Text("↓") }
                            Button(onClick = { onDelete(point.id) }) { Text("Удалить") }
                        }
                    }
                }
            }
        }
    }
}
@Composable
private fun QueueScreen(
    queue: List<com.maklertour.domain.UploadItem>,
    onEnqueue: () -> Unit,
    onUpload: (String) -> Unit,
) {
    Column(modifier = Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Upload Queue")
        Button(onClick = onEnqueue) { Text("Добавить в очередь") }

        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            itemsIndexed(queue) { _, item ->
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Session: ${item.sessionId.take(8)}")
                        Text("Status: ${item.status}")
                        Text("Retry: ${item.retryCount}")
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { onUpload(item.id) }, enabled = item.status != com.maklertour.domain.UploadStatus.Uploading) {
                                Text("Отправить (mock API)")
                            }
                        }
                    }
                }
            }
        }
    }

}
