package com.foleyit.foleytimepro.ui.navigation

import androidx.compose.animation.*
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ViewList
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.compose.*
import com.foleyit.foleytimepro.ui.screen.*
import com.foleyit.foleytimepro.ui.theme.Navy
import com.foleyit.foleytimepro.utils.PrefsManager

data class BottomNavItem(val route: String, val label: String, val icon: ImageVector)

private val navItems = listOf(
    BottomNavItem("clock",     "Clock",     Icons.Filled.AccessTime),
    BottomNavItem("timesheet", "Timesheet", Icons.Filled.DateRange),
    BottomNavItem("timeoff",   "Time Off",  Icons.Filled.EventAvailable),
    BottomNavItem("schedule",  "Schedule",  Icons.AutoMirrored.Filled.ViewList),
    BottomNavItem("profile",   "Profile",   Icons.Filled.Person),
)

@Composable
fun AppNavigation(startDestination: String, prefs: PrefsManager) {
    val navController = rememberNavController()

    NavHost(
        navController   = navController,
        startDestination = startDestination,
        enterTransition  = { fadeIn() },
        exitTransition   = { fadeOut() },
    ) {
        composable("setup") {
            SetupScreen(prefs = prefs, onConnected = { navController.navigate("login") { popUpTo("setup") { inclusive = true } } })
        }
        composable("login") {
            LoginScreen(prefs = prefs, onLoggedIn = { navController.navigate("dashboard") { popUpTo("login") { inclusive = true } } })
        }
        composable("dashboard") {
            DashboardShell(prefs = prefs, onLogout = { navController.navigate("login") { popUpTo("dashboard") { inclusive = true } } })
        }
    }
}

@Composable
fun DashboardShell(prefs: PrefsManager, onLogout: () -> Unit) {
    val innerNav = rememberNavController()
    val navBackStack by innerNav.currentBackStackEntryAsState()
    val currentRoute = navBackStack?.destination?.route

    Scaffold(
        bottomBar = {
            NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
                navItems.forEach { item ->
                    val selected = currentRoute == item.route
                    NavigationBarItem(
                        selected = selected,
                        onClick  = { if (!selected) innerNav.navigate(item.route) { launchSingleTop = true } },
                        icon     = { Icon(item.icon, contentDescription = item.label) },
                        label    = { Text(item.label) },
                        colors   = NavigationBarItemDefaults.colors(
                            selectedIconColor   = Navy,
                            selectedTextColor   = Navy,
                            indicatorColor      = Navy.copy(alpha = 0.12f),
                            unselectedIconColor = MaterialTheme.colorScheme.onSurfaceVariant,
                            unselectedTextColor = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    )
                }
            }
        }
    ) { padding ->
        NavHost(
            navController    = innerNav,
            startDestination = "clock",
            modifier         = Modifier.padding(padding),
            enterTransition  = { fadeIn() },
            exitTransition   = { fadeOut() },
        ) {
            composable("clock")     { ClockScreen(prefs) }
            composable("timesheet") { TimesheetScreen(prefs) }
            composable("timeoff")   { TimeOffScreen(prefs) }
            composable("schedule")  { ScheduleScreen(prefs) }
            composable("profile")   { ProfileScreen(prefs, onLogout) }
        }
    }
}
