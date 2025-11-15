# Change Log

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