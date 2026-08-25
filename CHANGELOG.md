# Change Log

## v1.4.0 (August 25, 2026)

### External Write API (shipped reserved — not yet active)

*   **New POST/PATCH handlers** on `api/external/v1/{punches,timeoff,employees}.php`, gated by
    three new scopes: `punches:write`, `timeoff:write`, `employees:write`. The code is complete,
    adversarially reviewed, and verified against the live database — but the scopes ship
    `available => false` in `ApiScopes::CATALOG`, and that flag is now genuinely enforced at
    authorization time (`WriteBootstrap`), not just described in the admin UI's copy. A key
    already holding one of these scope strings gets the same `403` as a key that was never
    granted it. Turning this on for real is a deliberate follow-up, not a side effect of this
    release.
*   `employees:write` can create/update an employee's name, email, office, job title, phone, and
    lock-out status only — never a badge ID, password, PIN, or 2FA setting.
*   Every write is idempotent via an optional `Idempotency-Key` header, and every punch/time-off
    write is protected against concurrent-request races (proven with genuinely parallel requests,
    not just reasoned about).

### Security & correctness fixes

*   Fixed a bug where an overnight-shift punch (e.g. 11pm–6am) could overlap an existing punch
    without being detected, allowing double-counted paid hours.
*   `users.Email` now has a real database-level uniqueness constraint — previously two concurrent
    account creations could share an email with no error at all.
*   Fixed a data bug where a blank Tag ID was stored as an empty string instead of `NULL`,
    which could crash the *next* save of any badge-less employee profile (admin console *and*
    employee self-service) with an unhandled error. One already-affected production record was
    corrected.
*   Fixed a bug where approving a time-off request that spans a year boundary deducted the leave
    balance against the wrong year (present in both the admin console and the new write API).

## v1.3.0 (August 25, 2026)

### External Reporting API

*   **New read-only API surface** (`api/external/v1/`, API-key authenticated): hours, summary,
    overtime, utilization, attendance, payroll, exceptions, trends, punctuality, and geofence
    reports; office/shift/schedule dimension endpoints; a live clock-in status feed; and an
    audit trail (punch changes, access log, auto-clockouts).
*   **OData v4 endpoint** (`api/external/odata/`) with `$filter`/`$select`/`$orderby`/`$top`/
    `$skip`/`$count` support, so Power BI's native connector can browse the API directly.
*   **OpenAPI 3.1 specification** generated from `api/docs/openapi.yaml`, plus a new admin-facing
    reference page (`Admin → API Docs`) with copy-paste-ready examples and a one-click demo key.

### API Key Management

*   The create-key form now shows a permission checklist instead of a free-text scope field.
*   Keys can be **viewed again** after creation (encrypted at rest, separate from the existing
    hash used for authentication; viewing requires re-entering the admin password) and **deleted**
    outright, in addition to the existing revoke action.

### Fixes

*   Admin modals with content taller than the viewport (e.g. the new permission checklist) are
    now scrollable instead of being silently clipped.
*   Admin CSS/JS assets are now cache-busted on every edit.

## Recent Updates (September 5, 2025)

This log summarizes the recent modifications and bug fixes implemented in the Timeclock application.

### `functions/clock_action.php`

*   **Resolved PHP Parse Errors:** Fixed syntax errors related to incorrect array key assignments (`=>`) in `echo json_encode` statements.
*   **Addressed `mysqli_stmt::bind_param()` Argument Error:** Corrected a fatal error where literal values from ternary operations were passed by reference to `bind_param`. This was resolved by introducing temporary variables to hold the results of these operations.
*   **Reverted to Stable Base:** The `clock_action.php` file was reverted to a more stable and feature-rich version (`clock_action.php.old`) as per user request.
*   **Integrated Enhanced Email Notification for Time Adjustments:**
    *   Incorporated PHPMailer library for robust email sending.
    *   Added encryption and decryption functions for secure handling of sensitive mail settings.
    *   Implemented a `sendAdjustmentEmail` function to send notifications when time punches are adjusted.
    *   Calls to `sendAdjustmentEmail` were added to the `clockin`, `lunchstart`, `lunchend`, and `clockout` actions, specifically when a pending time adjustment is logged.
    *   **Improved Email Content:** The adjustment notification email now includes the employee's full name and displays both original and adjusted times in a user-friendly 12-hour format (e.g., "09:25 PM").
    *   **Conditional Email Sending:** The `sendAdjustmentEmail` function now includes a check to automatically skip email notifications if essential mail server configuration settings are not provided, preventing errors in unconfigured environments.

### `admin/edits_timesheet.php`

*   **Fixed Duplicate Entries Display:** Resolved an issue where pending time edits were appearing twice on the "Employee Punch Adjustments" page. This was caused by a duplicate field name in the array used to iterate through potential editable fields.

## Upcoming Features

### 2FA Email Codes

*   Implementation of Two-Factor Authentication (2FA) using email-based codes for enhanced security.