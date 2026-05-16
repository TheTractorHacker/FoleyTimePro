# FoleyTimePro Android — Development Rules

## Project Facts
- Package: `com.foleyit.foleytimepro`
- Min SDK: 24 (Android 7.0) | Target SDK: 34 | AGP: 8.7.3
- Language: Kotlin only
- Architecture: `DashboardActivity` hosts 5 fragments via `BottomNavigationView`
- Fragments: `ClockFragment`, `TimesheetFragment`, `TimeOffFragment`, `ScheduleFragment`, `ProfileFragment`
- API base: `ApiClient` in `network/` — all calls go through it
- Prefs: `PrefsManager` in `utils/` — JWT token, user info, instance URL

## Threading — ALWAYS use Thread{}, NEVER coroutines (not yet added)
```kotlin
// Correct
Thread {
    val result = api.getPunchStatus(token)
    activity?.runOnUiThread { applyStatus(result) }
}.start()
```
Do NOT use `GlobalScope.launch`, `viewModelScope`, or any coroutine without first adding the `kotlinx-coroutines-android` dependency.

## List Rows — ALWAYS use XML layouts, NEVER build in Kotlin
```kotlin
// CORRECT — inflate item_punch_row.xml
val row = layoutInflater.inflate(R.layout.item_punch_row, container, false)
row.findViewById<TextView>(R.id.tvDate).text = entry.date
container.addView(row)

// WRONG — ugly, unstyled, impossible to maintain
val row = LinearLayout(requireContext()).apply { ... }
container.addView(row)

// WRONG — 1990s Android layout
val row = layoutInflater.inflate(android.R.layout.two_line_list_item, ...)
```

## Adding dividers — add to PARENT container, not inflated row
```kotlin
// CORRECT
container.addView(row)
if (i < list.size - 1) {
    container.addView(View(requireContext()).apply {
        layoutParams = LinearLayout.LayoutParams(MATCH_PARENT, 1)
        setBackgroundColor(...)
    })
}

// WRONG — View doesn't have addView()
row.addView(divider)
```

## Status card backgrounds — use separate drawables, NOT runtime mutation
```kotlin
// CORRECT — swap drawable per status
statusCard.setBackgroundResource(when (status) {
    "In"    -> R.drawable.status_card_in
    "Lunch" -> R.drawable.status_card_lunch
    else    -> R.drawable.status_card_bg
})

// WRONG — GradientDrawable.colors setter often fails at runtime
val grad = card.background as GradientDrawable
grad.colors = intArrayOf(...)
```

## Kotlin — no early return in expression-body functions
```kotlin
// WRONG — return not allowed in expression body
fun getShift(token: String): ShiftInfo = try {
    val s = data?.get(0) ?: return ShiftInfo(false)  // COMPILE ERROR
    ...
}

// CORRECT — use if/else
fun getShift(token: String): ShiftInfo = try {
    val s = data?.get(0)
    if (s == null) ShiftInfo(false) else ShiftInfo(true, s.name)
}
```

## Android XML — attributes that DO NOT EXIST
```xml
<!-- WRONG — CSS, not Android -->
android:textColor="rgba(255,255,255,0.75)"
android:autocomplete="off"
android:gap="12dp"

<!-- WRONG — missing layout_ prefix -->
android:marginStart="16dp"

<!-- WRONG — line element doesn't exist in VectorDrawable -->
<line android:x1="0" android:y1="0" android:x2="10" android:y2="10"/>

<!-- CORRECT -->
android:textColor="#BFFFFFFF"
android:importantForAutofill="no"
android:layout_marginStart="16dp"
<path android:pathData="M0,0 L10,10" .../>
```

## PHP — MySQLi vs PDO method names
```php
// CORRECT — MySQLi
$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// WRONG — PDO syntax, fails silently in MySQLi
$stmt->get_result()->fetchAll(MYSQLI_ASSOC);
```

## API URL format — direct .php?action= (no pretty URLs, nginx ignores .htaccess)
```kotlin
// CORRECT
"$baseUrl/api/v1/auth.php?action=login"
"$baseUrl/api/v1/punch.php?action=status"

// WRONG — nginx ignores .htaccess, these 404
"$baseUrl/api/v1/auth/login"
"$baseUrl/api/v1/punch/status"
```

## Colors — use hex ARGB not CSS
```xml
#BFFFFFFF  → 75% white  (B0=176/255)
#80FFFFFF  → 50% white
#33FFFFFF  → 20% white
```

## Animation — keep durations short
- Fragment transitions: 180ms
- Row stagger: max 30ms per item (7 rows = 210ms total, not 500ms)
- Press effects: scale down 80ms, bounce back 120ms
