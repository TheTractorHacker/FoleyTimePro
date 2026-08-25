# FoleyTimePro Android — Development Rules (Kotlin + Compose + Hilt)

## Architecture (v4.0.0 modernization)

- **Single Activity**: `MainActivity : FragmentActivity` (Hilt-injected) → `setContent { FoleyTimeProTheme { AppNavigation(...) } }`
- **Navigation**: `NavHost` in `ui/navigation/AppNavigation.kt` — outer routes: `setup`, `login`, `dashboard`. Inner `DashboardShell` is adaptive: a 5-tab `ShortNavigationBar` under 600dp, a `WideNavigationRail` at or above it.
- **DI**: **Hilt** for everything. Modules live under `di/` — `NetworkModule`, `StorageModule`, `DispatcherModule`. Screens use `hiltViewModel()`.
- **Per-screen packages**: `ui/screen/<name>/` — `<Name>Screen.kt` + `<Name>ViewModel.kt` + `<Name>UiState.kt`. Screens are pure @Composable renderers observing a `StateFlow<UiState>`.
- **Repositories** (`data/repository/`): AuthRepository, PunchRepository, TimeOffRepository, ShiftRepository, UserRepository, BrandingRepository, NotificationRepository. VMs never call APIs directly.
- **APIs** (`data/remote/api/`): per-domain OkHttp classes (AuthApi, PunchApi, TimeOffApi, ShiftApi, UserApi, BrandingApi, NotificationApi) — **OkHttp only, NO Retrofit**.
- **Result type**: every repo/api call returns `ApiResult<T>` (sealed: Success, HttpError, Unauthorized, NetworkError, Unknown). No more `data class XResult(success, error)`.
- **Wire format**: `kotlinx.serialization` with `@Serializable` DTOs under `data/remote/dto/`. No manual `JSONObject` parsing.
- **Persistence**:
  - Auth tokens + kiosk settings → `utils/PrefsManager` (`EncryptedSharedPreferences`, AES-256-GCM/SIV).
  - Offline punch queue + cached history/shift → **Room** (`data/local/`), file `foley_timepro.db`.
- **Background work**: **WorkManager** (`work/`) with Hilt worker factory. Workers: `QueuedPunchWorker` (offline drain), `TokenRefreshWorker`, `FcmTokenSyncWorker`.
- **Push**: **FCM** — `push/FoleyMessagingService`, `push/NotificationChannels`. Enable via `APP_ENABLE_FIREBASE=true` in `gradle.properties` and drop `google-services.json` into `app/`.
- **Security helpers** (`security/`): `AuthEvents` (app-wide forced-logout bus), `CertPinProvider`, `KioskInactivityGuard`, `SecureScreen` (`FLAG_SECURE`), `RootEmulatorDetector`, `KioskAdminGate` (BiometricPrompt DEVICE_CREDENTIAL for admin actions).

## What NOT to do

- No `Fragment`/`FragmentManager`/`FragmentTransaction` (MainActivity extends `FragmentActivity` only so `BiometricPrompt` can attach — Compose still owns every screen).
- No `ViewBinding`/`DataBinding`, no `findViewById`, no `LayoutInflater.inflate(R.layout.*)`, no XML layouts (`res/layout/` is empty by policy).
- No `Retrofit` — the project is OkHttp + `kotlinx.serialization`.
- No `Thread {}`, no `runOnUiThread` — use coroutines. Repositories/APIs already `withContext(Dispatchers.IO)`.
- No plain `SharedPreferences` — everything sensitive goes through `PrefsManager` (encrypted).
- No `allowBackup="true"` — see manifest and `res/xml/{backup_rules,data_extraction_rules}.xml`.
- No storing bearer tokens in cleartext, logs, or unencrypted DBs.
- No manual token injection on requests — the `AuthInterceptor` handles it.
- No inline hex colors and no raw `Color(...)` in composables — use `MaterialTheme.colorScheme.*` or `MaterialTheme.statusColors.*`. `ui/theme/Color.kt` is generated and `internal`; screens must never import a tone directly.
- No hardcoded `fontSize` / `lineHeight` / `FontWeight` on `Text` — pick a `MaterialTheme.typography` role. If none fits, add a role, don't override at the call site.
- No `RoundedCornerShape(N.dp)` inline — use `MaterialTheme.shapes.*` or `AppShapeExtras`.
- No hardcoded `Color.White` on top of a brand color — every surface has an `on*` role that survives dark mode.
- No new screen without a ViewModel — even one-shot screens go through the MVVM pattern for consistency.

## State & Async

```kotlin
// ViewModel — the canonical pattern
@HiltViewModel
class MyViewModel @Inject constructor(
    private val repo: MyRepository,
) : ViewModel() {
    private val _state = MutableStateFlow(MyUiState())
    val state: StateFlow<MyUiState> = _state.asStateFlow()

    fun doThing() {
        _state.update { it.copy(loading = true) }
        viewModelScope.launch {
            when (val r = repo.thing()) {
                is ApiResult.Success -> _state.update { it.copy(data = r.data, loading = false) }
                is ApiResult.NetworkError -> _state.update { it.copy(loading = false, isOffline = true) }
                else -> _state.update { it.copy(loading = false, error = r.errorMessage) }
            }
        }
    }
}

// Screen — pure renderer
@Composable
fun MyScreen(viewModel: MyViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    // ...render state, call viewModel functions from UI events
}
```

## Networking (OkHttp, per project rule)

- `di/NetworkModule.kt` provides two clients:
  - Primary `OkHttpClient` with `CertificatePinner`, `AuthInterceptor` (adds `Authorization: Bearer <token>`), `UserAgentInterceptor`, and `TokenAuthenticator` (auto-refresh on 401).
  - `@BareOkHttp OkHttpClient` with **no interceptors/authenticator**, used by `TokenAuthenticator` itself to hit `?action=refresh` without re-entrancy.
- Every API call: `suspend` + `ApiResult<T>` via `JsonHttp.execute(...)` or `JsonHttp.executeNoPayload(...)`.
- Anonymous endpoints (login, refresh, branding) tag their request `Request.Builder().tag(Anonymous)` so the auth interceptor skips them.
- Cert pins live in `BuildConfig.CERT_PIN_PRIMARY` / `CERT_PIN_BACKUP` (set via `~/.gradle/gradle.properties`). Empty in dev = no pinning.

## Offline punch queue

- `PunchRepository.punchOrQueue(...)`: tries the API. On `NetworkError` inserts a `QueuedPunchEntity` with the capture timestamp and enqueues `QueuedPunchWorker` (constraint: NetworkType.CONNECTED). HTTP 4xx/5xx do NOT queue — that's a client bug, not connectivity.
- `QueuedPunchWorker` drains rows FIFO, sending `source=offline_sync` and the original `captured_at` millis so the server records the real punch time.
- Backend contract: `/api/v1/punch.php` accepts `captured_at` (epoch ms) + `source` (`live`|`offline_sync`|`kiosk`). Out-of-range timestamps (>2 min future, >7 days past) are silently clamped to server-now with source rewritten to `live`.
- `ClockScreen` shows a `QueueBadge` in the top bar when `queuedCount > 0`.

## Push notifications (FCM)

- Backend endpoints already exist: `/api/v1/notifications.php?action=register` (POST body: `token`, `platform`, `app_version`, `device_model`, `os_version`) and `?action=unregister`.
- On successful login, `LoginViewModel` enqueues `FcmTokenSyncWorker` to register the current token.
- On sign-out, `AuthRepository.signOut()` best-effort unregisters the token, revokes the refresh token, deletes the FCM registration, and wipes local caches — with 2s timeouts so a bad network can't block sign-out.
- `POST_NOTIFICATIONS` runtime permission is requested by `MainActivity.onCreate` (Android 13+).

## Security hardening (v4)

- `AndroidManifest.xml`: `allowBackup="false"`, `dataExtractionRules`, `networkSecurityConfig`, `usesCleartextTraffic="false"`.
- `res/xml/backup_rules.xml` + `data_extraction_rules.xml`: exclude the encrypted prefs file and the Room DB from cloud backup and device transfer.
- `SecureScreen()` composable — adds/clears `FLAG_SECURE` for the screen's lifetime. Use on Login + Profile.
- `KioskInactivityGuard(timeoutMs, onTimeout) { content }` wraps the authenticated area. Every tap resets. On timeout it emits `AuthEvents.Reason.Inactivity`, which the outer `AppNavigation` collector redirects to `login`.
- `AuthEvents` (@Singleton) is the app-wide forced-logout bus. `TokenAuthenticator`, `TokenRefreshWorker`, inactivity, and biometric-gate failures all funnel through it.
- `KioskAdminGate` uses `BiometricPrompt` with `DEVICE_CREDENTIAL | BIOMETRIC_WEAK` for admin actions on shared kiosks — NOT for employee login (biometric login is wrong on shared devices).

## Theme (Material 3, material3 1.4.0)

`ui/theme/Theme.kt` provides `FoleyTimeProTheme`, built on the stable
`MaterialTheme(colorScheme, shapes, typography, content)`.

**Not `MaterialExpressiveTheme`.** It and the whole `MotionScheme` API are
compiled into the material3 1.4.0 jar but still `internal` there — confirmed by
probing the actual resolved dependency with a standalone `kotlinc` compile
(`cannot access ...: it is internal in file`). Google shipped the bytecode ahead
of making it public API; it only becomes callable in the still-alpha 1.5.x line.
Do not reach for it until the project moves to a release where it has
stabilized — check with the same kind of probe compile before relying on any
new-looking material3 symbol, since the presence of a public-looking method in
the `.aar`'s bytecode does not mean the Kotlin compiler will let you call it.

| File | Holds | Edit by hand? |
|------|-------|---------------|
| `ui/theme/Color.kt` | Tonal palettes (`Primary0..100`, `ClockedIn*`, …), `internal` | **No** — generated |
| `ui/theme/ColorSchemes.kt` | `BrandLightScheme` / `BrandDarkScheme`, `StatusColors` | Yes |
| `ui/theme/Type.kt` | The 15-role type scale + `ClockReadoutStyle` | Yes |
| `ui/theme/Shape.kt` | `AppShapes`, `AppShapeExtras`, `Spacing` | Yes |
| `ui/theme/Theme.kt` | `FoleyTimeProTheme`, `MaterialTheme.statusColors` | Yes |

**Colors are generated, not chosen.** `tools/tonal.py` + `tools/emit_palette.py`
take the brand seeds (`#073851` navy, `#00BCD4` cyan, plus the punch-state hues),
hold each hue while pushing chroma to the sRGB gamut edge at every Material tone,
and write `ui/theme/Color.kt`. To change a brand color, edit the seed in
`tools/emit_palette.py` and re-run it — never hand-edit a tone:

```bash
python3 tools/emit_palette.py && mv Color.kt app/src/main/java/com/foleyit/foleytimepro/ui/theme/
```

**`dynamicColor` defaults to `false`.** The same build runs on personal phones and
on shared wall-mounted kiosks; Material You would repaint a kiosk to whatever
wallpaper it happens to have, taking the punch-state colors with it.

**Punch-state colors are roles, not constants.** Reach them through
`MaterialTheme.statusColors` (`clockedIn`, `lunch`, `warning`, each with
`on*`/`*Container`/`on*Container`). Clocking out is destructive and uses the
`error` roles; ending lunch uses `secondary`. There is no green/orange/red `Color`
constant to import.

**Edge-to-edge.** `MainActivity` calls `enableEdgeToEdge()`; the theme syncs
system-bar icon tint. Screens therefore pass `contentWindowInsets = WindowInsets(0, 0, 0, 0)`
to their `Scaffold` — the top app bar applies the top inset and the navigation bar
applies the bottom one, and a third helping in between shows up as a stray gap.

## Common UI

`ui/common/` is the shared vocabulary. Reach for it before writing a new composable:

- `Bars.kt` — `FoleyTopAppBar` (compact), `FoleyLargeTopAppBar` (collapsing, with subtitle)
- `Containers.kt` — `SectionCard`, `SectionHeader`, `KeyValueRow`, `RowDivider`, `DividedColumn`
- `Feedback.kt` — `LoadingState`, `EmptyState`, `ErrorState`, `OfflineBanner`, `WarningStrip`
- `Indicators.kt` — `Pill`, `QueuedPill`

`Pill` is the only pill: status chips, hours badges and the queue counter all go
through it, so they cannot drift apart.

## Experimental opt-ins

`ShortNavigationBar`, `WideNavigationRail`, `PullToRefreshBox` and similar are
opt-in APIs (`@ExperimentalMaterial3Api` / `@ExperimentalMaterial3ExpressiveApi`).
`app/build.gradle` declares both opt-ins once in `kotlinOptions`, so **do not**
add `@OptIn` per file. This is a different thing from a symbol being `internal`
(see the Theme section above) — opt-in APIs are callable, just annotated to warn
they may still change; `internal` APIs do not compile at all outside their
module, no annotation gets you past that.

## Compose rules (unchanged)

- `remember { mutableStateOf() }` — local UI state.
- `LaunchedEffect(key)` — side effects tied to composition lifecycle.
- `viewModelScope.launch { ... }` (in VM) or `rememberCoroutineScope()` (only for one-off UI-only effects).
- `withContext(Dispatchers.IO)` — network/disk (already inside JsonHttp/repos).
- `Modifier` chain order matters: `padding` before `background` clips the bg.
- `Spacer(Modifier.height(Spacing.md))` for vertical spacing — `Spacing`, not raw dp.
- `RowDivider()` for list dividers (it already uses the `outlineVariant` role).
- Prefer `Arrangement.spacedBy(Spacing.x)` over a chain of `Spacer`s.

## Dialogs

```kotlin
AlertDialog(
    onDismissRequest = { showDialog = false },
    title   = { Text("Title") },
    text    = { /* content */ },
    confirmButton = { Button(onClick = { ... }) { Text("OK") } },
    dismissButton = { TextButton(onClick = { showDialog = false }) { Text("Cancel") } }
)
```

## Heroes (not gradients)

The status/shift/identity cards were linear gradients with white text baked on
top. They are now tonal surfaces, which is what makes them survive dark mode:

```kotlin
Surface(
    shape = AppShapeExtras.extraLargeIncreased,
    color = MaterialTheme.statusColors.clockedInContainer,
    contentColor = MaterialTheme.statusColors.onClockedInContainer,
) { /* no explicit text colors needed -- contentColor cascades */ }
```

Do not reintroduce `Brush.linearGradient` for a hero. If a surface needs
emphasis, move it up the tonal ramp (`surfaceContainerHigh`) or onto a container
role.

## Navigation

```kotlin
// Navigate and clear back stack
navController.navigate("dashboard") {
    popUpTo("login") { inclusive = true }
}

// Single-top (tab nav). Tab switches deliberately do NOT saveState/restoreState:
// a punch screen restored from a previous employee's session on a shared kiosk
// would show the wrong clock. See NavHostController.switchTab.
innerNav.navigate("clock") { launchSingleTop = true }
```

## Timers in Compose

```kotlin
var elapsed by remember { mutableLongStateOf(initialSeconds) }
LaunchedEffect(status) {
    if (status == "In") {
        while (true) { delay(1000); elapsed++ }
    }
}
```
