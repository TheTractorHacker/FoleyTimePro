# FoleyTimePro Android — Development Rules (Jetpack Compose)

## Architecture
- **Single Activity**: `MainActivity` → `setContent { FoleyTimeProTheme { AppNavigation(...) } }`
- **Navigation**: `NavHost` in `AppNavigation.kt` — routes: `setup`, `login`, `dashboard`
- **Dashboard**: `DashboardShell` hosts inner `NavHost` with `NavigationBar` for 5 tabs
- **Screens**: `ui/screen/` — all `@Composable`, no Fragments, no XML layouts
- **Theme**: `ui/theme/Color.kt` + `Theme.kt` — Material 3 `lightColorScheme`
- **API**: `network/ApiClient.kt` — Kotlin, OkHttp, all calls dispatched via `Dispatchers.IO`
- **Prefs**: `utils/PrefsManager.kt` — SharedPreferences wrapper

## Dependencies (build.gradle)
```groovy
// Compose BOM pins all versions
def composeBom = platform('androidx.compose:compose-bom:2024.02.00')
implementation composeBom
implementation 'androidx.compose.material3:material3'
implementation 'androidx.compose.material:material-icons-extended'
implementation 'androidx.activity:activity-compose:1.8.2'
implementation 'androidx.navigation:navigation-compose:2.7.7'
implementation 'org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3'
// composeOptions { kotlinCompilerExtensionVersion '1.5.7' }
```

## State & Async — ALWAYS use Coroutines, NEVER Thread{}
```kotlin
// CORRECT
val scope = rememberCoroutineScope()
var data by remember { mutableStateOf<MyData?>(null) }

LaunchedEffect(Unit) {
    data = withContext(Dispatchers.IO) { api.fetchSomething(token) }
}

// Button action
scope.launch {
    val result = withContext(Dispatchers.IO) { api.doAction(token) }
    // update state here
}

// WRONG — crashes on rotation, leaks threads
Thread { val r = api.fetch(); runOnUiThread { ... } }.start()
```

## UI — Pure Compose, zero XML layouts
```kotlin
// CORRECT — everything in @Composable functions
@Composable
fun PunchRowItem(entry: PunchEntry) {
    Row(modifier = Modifier.fillMaxWidth().padding(12.dp)) {
        Column(Modifier.weight(1f)) {
            Text(entry.date, fontWeight = FontWeight.Bold)
            Text("${entry.inTime} → ${entry.outTime}", color = TextSecondary)
        }
        Text("${entry.hours}h", color = Navy, fontWeight = FontWeight.Bold)
    }
}

// WRONG — no more XML layouts, LayoutInflater, or ViewBinding
val row = layoutInflater.inflate(R.layout.item_punch_row, container, false)
row.findViewById<TextView>(R.id.tvDate).text = entry.date
```

## Gradients in Compose — use Brush, not drawables
```kotlin
// CORRECT
Box(modifier = Modifier.background(
    Brush.linearGradient(listOf(GreenDark, GreenIn))
)) { ... }

// WRONG — runtime drawable mutation, can fail silently
val grad = card.background as GradientDrawable
grad.colors = intArrayOf(...)
```

## Navigation
```kotlin
// Navigate and clear back stack
navController.navigate("dashboard") {
    popUpTo("login") { inclusive = true }
}

// Single-top (avoid duplicates in tab nav)
innerNav.navigate("clock") { launchSingleTop = true }
```

## Timer in Compose
```kotlin
var elapsed by remember { mutableLongStateOf(initialSeconds) }
LaunchedEffect(status) {
    if (status == "In") {
        while (true) { delay(1000); elapsed++ }
    }
}
Text(formatElapsed(elapsed), fontFamily = FontFamily.Monospace)
```

## Dialogs — use AlertDialog composable
```kotlin
AlertDialog(
    onDismissRequest = { showDialog = false },
    title   = { Text("Title") },
    text    = { /* content */ },
    confirmButton = { Button(onClick = { ... }) { Text("OK") } },
    dismissButton = { TextButton(onClick = { showDialog = false }) { Text("Cancel") } }
)
```

## Colors — use Color.kt constants, not hex strings in composables
```kotlin
// CORRECT
Text("Hello", color = Navy)
Box(Modifier.background(GreenIn))

// WRONG — magic strings buried in UI code
Text("Hello", color = Color(0xFF073851))
```

## Key Compose rules
- `remember { mutableStateOf() }` — local UI state
- `LaunchedEffect(key)` — side effects tied to composition lifecycle
- `rememberCoroutineScope()` — for button-triggered coroutines
- `withContext(Dispatchers.IO)` — ALL network/disk calls
- `Modifier` chain order matters: `padding` before `background` clips the bg
- Use `Spacer(Modifier.height(N.dp))` for vertical spacing, not padding hacks
- `HorizontalDivider(color = DividerColor)` for list dividers
- `FontWeight.Bold / SemiBold / Medium / Normal` — not numeric weights

## What NOT to do
- No `Fragment`, `FragmentManager`, `FragmentTransaction`
- No `ViewBinding` or `DataBinding`
- No `findViewById`
- No `LayoutInflater.inflate(R.layout.*)`
- No `android.R.layout.*`
- No `Thread { }` for async
- No `runOnUiThread { }` — use `withContext(Dispatchers.Main)` if needed
- No XML anim files — use `AnimatedVisibility`, `animateContentSize()`, `AnimatedContent`
