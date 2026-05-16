# TimeClock Android TWA (Trusted Web Activity)

This is an Android wrapper for the TimeClock Progressive Web App using Trusted Web Activity (TWA). It allows publishing the PWA to the Google Play Store as a native Android app.

## Requirements

- Android Studio Arctic Fox (2020.3.1) or later
- JDK 17
- Android SDK 34
- Gradle 8.2+

## White-Label Configuration

### 1. Update gradle.properties

Edit `gradle.properties` with your installation's details:

```properties
# Package name - must be unique for each installation
APP_PACKAGE_NAME=com.yourcompany.timeclock

# App display name
APP_NAME=YourCompany TimeClock

# Your website URL
SITE_URL=https://yourcompany.timeclock.com

# Version information
VERSION_CODE=1
VERSION_NAME=1.0.0
```

### 2. Update strings.xml

Edit `app/src/main/res/values/strings.xml`:
- Update `hostName` to your domain
- Update `launchUrl` to your full URL

### 3. Update colors.xml

Edit `app/src/main/res/values/colors.xml` with your brand colors.

### 4. Generate App Icons

Replace the launcher icons in the `mipmap-*` directories:
- `ic_launcher.png` - Standard app icon
- `ic_launcher_round.png` - Round app icon (Android 7.1+)

Recommended sizes:
- mdpi: 48x48
- hdpi: 72x72
- xhdpi: 96x96
- xxhdpi: 144x144
- xxxhdpi: 192x192

Use Android Studio's Image Asset Studio (right-click on `res` > New > Image Asset) for easy generation.

## Domain Verification Setup

### 1. Get Your App's SHA256 Fingerprint

For debug builds:
```bash
keytool -list -v -keystore ~/.android/debug.keystore -alias androiddebugkey -storepass android -keypass android
```

For release builds (after creating your signing key):
```bash
keytool -list -v -keystore your-release.keystore -alias your-key-alias
```

### 2. Configure Asset Links on Your Server

Add the SHA256 fingerprint to your TimeClock admin settings:
1. Go to Admin > Settings
2. Find "TWA Configuration"
3. Enter your package name
4. Enter your SHA256 fingerprint

The server will automatically generate `/.well-known/assetlinks.json`.

### 3. Verify Configuration

Use Google's Asset Links verification tool:
https://developers.google.com/digital-asset-links/tools/generator

## Building the App

### Debug Build

```bash
./gradlew assembleDebug
```

APK will be at: `app/build/outputs/apk/debug/app-debug.apk`

### Release Build

1. Create a signing keystore:
```bash
keytool -genkey -v -keystore release.keystore -alias timeclock -keyalg RSA -keysize 2048 -validity 10000
```

2. Configure signing in `app/build.gradle` (uncomment the signingConfigs section)

3. Build:
```bash
./gradlew assembleRelease
```

APK will be at: `app/build/outputs/apk/release/app-release.apk`

## Play Store Submission

### Required Assets

1. **App Icon**: 512x512 PNG (32-bit, alpha)
2. **Feature Graphic**: 1024x500 PNG/JPG
3. **Screenshots**:
   - Phone: 2-8 screenshots, 16:9 or 9:16 ratio
   - Tablet (7"): Optional but recommended
   - Tablet (10"): Optional but recommended
4. **Short Description**: Max 80 characters
5. **Full Description**: Max 4000 characters
6. **Privacy Policy URL**: Required

### App Signing

Google Play requires apps to be signed. You can:
1. Use Google Play App Signing (recommended) - upload your app signing key
2. Manage your own signing key

### Content Rating

Complete the content rating questionnaire in Play Console.

### App Category

Suggested: Business or Productivity

## Troubleshooting

### App opens in Chrome instead of full-screen

1. Verify `/.well-known/assetlinks.json` is accessible
2. Check SHA256 fingerprint matches exactly
3. Clear Chrome's cache: Chrome > Settings > Site Settings > [your domain] > Clear & reset
4. Uninstall and reinstall the app

### Geolocation not working

1. Ensure location permissions are granted in Android settings
2. Check that `enableLocation` is true in `bools.xml`
3. Verify your PWA requests geolocation correctly

### Push notifications not working

1. Ensure notification permissions are granted
2. Check that `enableNotification` is true in `bools.xml`
3. Verify your PWA has push notification support with service worker

## Support

For issues with:
- **Android app build**: Check Android Studio's build output
- **TWA/Chrome**: Visit https://web.dev/trusted-web-activity/
- **TimeClock features**: Contact your TimeClock administrator
