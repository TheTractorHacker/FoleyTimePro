package com.foleyit.foleytimepro.ui.navigation

import androidx.lifecycle.ViewModel
import com.foleyit.foleytimepro.security.AuthEvents
import com.foleyit.foleytimepro.utils.PrefsManager
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject

/**
 * Thin holder so `AppNavigation` and `DashboardShell` can inject the
 * [AuthEvents] bus and [PrefsManager] without every screen needing them.
 * Kept separate from screen ViewModels so navigation doesn't leak repo deps.
 */
@HiltViewModel
class RouteHostViewModel @Inject constructor(
    val authEvents: AuthEvents,
    val prefs: PrefsManager,
) : ViewModel()
