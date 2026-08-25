package com.foleyit.foleytimepro.ui.navigation

import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccessTimeFilled
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.EventAvailable
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.WorkHistory
import androidx.compose.material.icons.outlined.AccessTime
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.EventAvailable
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.WorkHistory
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.ShortNavigationBar
import androidx.compose.material3.ShortNavigationBarItem
import androidx.compose.material3.Text
import androidx.compose.material3.WideNavigationRail
import androidx.compose.material3.WideNavigationRailDefaults
import androidx.compose.material3.WideNavigationRailItem
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.foleyit.foleytimepro.security.AuthEvents
import com.foleyit.foleytimepro.security.KioskInactivityGuard
import com.foleyit.foleytimepro.ui.screen.clock.ClockScreen
import com.foleyit.foleytimepro.ui.screen.login.LoginScreen
import com.foleyit.foleytimepro.ui.screen.profile.ProfileScreen
import com.foleyit.foleytimepro.ui.screen.schedule.ScheduleScreen
import com.foleyit.foleytimepro.ui.screen.setup.SetupScreen
import com.foleyit.foleytimepro.ui.screen.timeoff.TimeOffScreen
import com.foleyit.foleytimepro.ui.screen.timesheet.TimesheetScreen

/**
 * A destination in the authenticated shell.
 *
 * Each tab carries both icon weights. Material 3 signals the active tab with a
 * filled glyph inside the indicator and an outlined one everywhere else, which
 * is what makes the selection readable when the indicator itself is a low
 * contrast tint.
 */
data class BottomNavItem(
    val route: String,
    val label: String,
    val selectedIcon: ImageVector,
    val unselectedIcon: ImageVector,
)

private val navItems = listOf(
    BottomNavItem("clock",     "Clock",     Icons.Filled.AccessTimeFilled, Icons.Outlined.AccessTime),
    BottomNavItem("timesheet", "Timesheet", Icons.Filled.WorkHistory,      Icons.Outlined.WorkHistory),
    BottomNavItem("timeoff",   "Time Off",  Icons.Filled.EventAvailable,   Icons.Outlined.EventAvailable),
    BottomNavItem("schedule",  "Schedule",  Icons.Filled.CalendarMonth,    Icons.Outlined.CalendarMonth),
    BottomNavItem("profile",   "Profile",   Icons.Filled.Person,           Icons.Outlined.Person),
)

/** Below this the shell uses a bottom bar; at or above it, a side rail. */
private val RailBreakpoint = 600.dp

@Composable
fun AppNavigation(
    startDestination: String,
    routeHostViewModel: RouteHostViewModel = hiltViewModel(),
) {
    val navController = rememberNavController()

    // Global forced-logout listener: any AuthEvents.forceLogout(...) from
    // repos / interceptors / inactivity guard sends the user back to Login.
    LaunchedEffect(Unit) {
        routeHostViewModel.authEvents.events.collect {
            navController.navigate("login") {
                popUpTo(0) { inclusive = true }
                launchSingleTop = true
            }
        }
    }

    NavHost(
        navController = navController,
        startDestination = startDestination,
        enterTransition  = { fadeIn() },
        exitTransition   = { fadeOut() },
    ) {
        composable("setup") {
            SetupScreen(
                onConnected = {
                    navController.navigate("login") {
                        popUpTo("setup") { inclusive = true }
                    }
                }
            )
        }
        composable("login") {
            LoginScreen(
                onLoggedIn = {
                    navController.navigate("dashboard") {
                        popUpTo("login") { inclusive = true }
                    }
                },
                onChangeServer = {
                    navController.navigate("setup") {
                        popUpTo(0) { inclusive = true }
                    }
                },
            )
        }
        composable("dashboard") {
            DashboardShell(
                onChangeServer = {
                    navController.navigate("setup") {
                        popUpTo(0) { inclusive = true }
                    }
                }
            )
        }
    }
}

/**
 * The authenticated shell.
 *
 * Phones get a [ShortNavigationBar] along the bottom; tablets and landscape
 * kiosks get a [WideNavigationRail] down the side. A wall-mounted tablet is a
 * first-class deployment target here, and a bottom bar on one puts the tabs at
 * waist height while wasting the width the device actually has.
 */
@Composable
fun DashboardShell(
    onChangeServer: () -> Unit,
    routeHostViewModel: RouteHostViewModel = hiltViewModel(),
) {
    val innerNav = rememberNavController()
    val navBackStack by innerNav.currentBackStackEntryAsState()
    val currentRoute = navBackStack?.destination?.route

    val inactivityMs = routeHostViewModel.prefs.getInactivityTimeoutMs()

    KioskInactivityGuard(
        timeoutMs = inactivityMs,
        onTimeout = { routeHostViewModel.authEvents.forceLogout(AuthEvents.Reason.Inactivity) },
    ) {
        BoxWithConstraints(Modifier.fillMaxSize()) {
            val useRail = maxWidth >= RailBreakpoint

            if (useRail) {
                Row(Modifier.fillMaxSize()) {
                    WideNavigationRail(
                        colors = WideNavigationRailDefaults.colors(
                            containerColor = MaterialTheme.colorScheme.surfaceContainer,
                        ),
                    ) {
                        navItems.forEach { item ->
                            val selected = currentRoute == item.route
                            WideNavigationRailItem(
                                selected = selected,
                                onClick  = { innerNav.switchTab(item.route, selected) },
                                icon     = { TabIcon(item, selected) },
                                label    = { Text(item.label) },
                                railExpanded = false,
                            )
                        }
                    }
                    DashboardNavHost(
                        navController = innerNav,
                        onChangeServer = onChangeServer,
                        modifier = Modifier
                            .weight(1f)
                            .windowInsetsPadding(
                                WindowInsets.safeDrawing.only(
                                    WindowInsetsSides.End + WindowInsetsSides.Bottom
                                )
                            ),
                    )
                }
            } else {
                Scaffold(
                    bottomBar = {
                        ShortNavigationBar(
                            containerColor = MaterialTheme.colorScheme.surfaceContainer,
                        ) {
                            navItems.forEach { item ->
                                val selected = currentRoute == item.route
                                ShortNavigationBarItem(
                                    selected = selected,
                                    onClick  = { innerNav.switchTab(item.route, selected) },
                                    icon     = { TabIcon(item, selected) },
                                    label    = { Text(item.label) },
                                )
                            }
                        }
                    },
                    // Each screen's own top app bar applies the top inset and the
                    // navigation bar applies the bottom one, so the Scaffold must not
                    // add a third helping in between.
                    contentWindowInsets = WindowInsets(0, 0, 0, 0),
                ) { padding ->
                    DashboardNavHost(
                        navController  = innerNav,
                        onChangeServer = onChangeServer,
                        modifier       = Modifier.padding(padding),
                    )
                }
            }
        }
    }
}

@Composable
private fun TabIcon(item: BottomNavItem, selected: Boolean) {
    Icon(
        imageVector = if (selected) item.selectedIcon else item.unselectedIcon,
        contentDescription = item.label,
    )
}

/**
 * Tab switches deliberately do not save or restore per-tab back stacks: a punch
 * screen restored from a stale state on a shared kiosk would show the previous
 * employee's clock. Every tab re-reads from its ViewModel on entry.
 */
private fun NavHostController.switchTab(route: String, alreadySelected: Boolean) {
    if (alreadySelected) return
    navigate(route) {
        popUpTo(graph.startDestinationId) {
            inclusive = false
            saveState = false
        }
        launchSingleTop = true
        restoreState = false
    }
}

@Composable
private fun DashboardNavHost(
    navController: NavHostController,
    onChangeServer: () -> Unit,
    modifier: Modifier = Modifier,
) {
    NavHost(
        navController    = navController,
        startDestination = "clock",
        modifier         = modifier,
        enterTransition  = { fadeIn() },
        exitTransition   = { fadeOut() },
    ) {
        composable("clock")     { ClockScreen() }
        composable("timesheet") { TimesheetScreen() }
        composable("timeoff")   { TimeOffScreen() }
        composable("schedule")  { ScheduleScreen() }
        composable("profile")   { ProfileScreen(onChangeServer = onChangeServer) }
    }
}
