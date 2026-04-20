package com.maklertour

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
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
    val viewModel = remember { AppStateViewModel() }
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
                PlaceholderScreen(
                    title = "Сессии",
                    subtitle = "Создано сессий: ${state.sessions.size}"
                )
            }
            composable(AppTab.Camera.route) {
                val cameraTitle = if (state.cameraStatus.isConnected) "Камера подключена" else "Камера не подключена"
                PlaceholderScreen(
                    title = "Камера",
                    subtitle = cameraTitle
                )
            }
            composable(AppTab.Draft.route) {
                val points = state.sessions.sumOf { it.points.size }
                PlaceholderScreen(
                    title = "Черновик тура",
                    subtitle = "Снято точек: $points"
                )
            }
            composable(AppTab.Queue.route) {
                PlaceholderScreen(
                    title = "Очередь",
                    subtitle = "Пакетов в очереди: ${state.uploadQueue.size}"
                )
            }
        }
    }
}

@Composable
private fun PlaceholderScreen(title: String, subtitle: String) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(text = title)
        Text(text = subtitle)
    }

}