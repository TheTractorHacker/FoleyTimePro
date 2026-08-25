# FoleyTimePro — Android

Native Android client for FoleyTimePro, built as a single Activity on Jetpack
Compose, Material 3 Expressive, and Hilt. Talks to the FoleyTimePro server's
`/api/v1/*` endpoints and works offline: punches queue locally and drain on
reconnect.

See [CLAUDE.md](CLAUDE.md) for the architecture and the do's/don'ts a
contributor should know before touching this codebase — it is kept current
and is the real source of truth. This file is just how to get a build running.

## Requirements

- JDK 17
- Android SDK, `compileSdk 35` (install `platform-tools`, `platforms;android-35`,
  `build-tools;35.0.0` via `sdkmanager`, or open the project in Android Studio
  and let it resolve them)
- No Android Studio required — `./gradlew` is self-contained via the Gradle
  wrapper (pinned to 8.13, checksum-verified)

## Building

```bash
./gradlew assembleDebug
```

Debug APK lands at `app/build/outputs/apk/debug/app-debug.apk`. Debug builds
use the auto-generated Android debug keystore — no signing setup needed.

### Configuration

`gradle.properties` controls white-labeling and defaults:

```properties
APP_PACKAGE_NAME=com.foleyit.foleytimepro
APP_NAME=FoleyTimePro
VERSION_CODE=4
VERSION_NAME=4.0.0

# Blank in dev. The app falls back to the in-app "Connect to your server" setup
# screen when this is empty, so you don't need to set it to build.
API_BASE_URL_DEFAULT=

# Leave blank in dev — see "Release builds" below.
CERT_PIN_PRIMARY=
CERT_PIN_BACKUP=

# Firebase Cloud Messaging is off by default. See "Push notifications" below.
APP_ENABLE_FIREBASE=false
```

### Release builds

Release signing is never committed. Populate these in `~/.gradle/gradle.properties`
(not this repo) or pass them as `-P` flags in CI:

```properties
FOLEY_KEYSTORE_PATH=/absolute/path/to/keystore.jks
FOLEY_KEYSTORE_PASS=...
FOLEY_KEY_ALIAS=...
FOLEY_KEY_PASS=...
```

Certificate pins for `CERT_PIN_PRIMARY` / `CERT_PIN_BACKUP` are extracted with:

```bash
openssl s_client -servername HOST -connect HOST:443 < /dev/null \
  | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der \
  | openssl dgst -sha256 -binary | base64
```

Then:

```bash
./gradlew assembleRelease
```

### Push notifications (FCM)

Firebase is compiled in but inert until both of these are true:

1. `APP_ENABLE_FIREBASE=true` in `gradle.properties` (or as a `-P` flag)
2. A real `google-services.json` dropped into `app/`

Without both, the app builds and runs fine with push simply disabled.

## Architecture at a glance

- **UI**: Jetpack Compose, Material 3 Expressive (`material3` 1.4.0), one
  `NavHost` per outer flow (`setup` → `login` → `dashboard`), a per-screen
  MVVM package under `ui/screen/<name>/`.
- **DI**: Hilt, modules under `di/`.
- **Networking**: OkHttp + kotlinx.serialization — no Retrofit.
- **Persistence**: Room for the offline punch queue and cached reads;
  `EncryptedSharedPreferences` for tokens and kiosk settings.
- **Background work**: WorkManager for the offline-punch drain, token
  refresh, and FCM token sync.

Full detail, conventions, and the "what not to do" list live in
[CLAUDE.md](CLAUDE.md).

## Kiosk deployments

This app is designed to run both on an employee's personal phone and on a
shared, wall-mounted kiosk tablet — see `security/KioskInactivityGuard`,
`security/KioskAdminGate`, and the adaptive rail/bottom-bar navigation in
`ui/navigation/AppNavigation.kt`.
