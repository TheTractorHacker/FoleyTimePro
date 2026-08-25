<?php
/**
 * GET /api/external/v1/live/status
 * Authorization: Bearer <api-key>   Scope: live:read
 *
 * Point-in-time roster status: who is on the clock right now. No start/end —
 * this is "now", not a range — and cheap enough to poll every 30 seconds: one
 * roster query, one open-punch query (both bounded by the employee/office
 * filter), no per-row hours allocation.
 *
 * Params: employee_id, employee_email, office, include_inactive, tz, format,
 * limit, offset, plus:
 *
 *   status               comma list of "in","on_lunch","out" — filters the
 *                        returned rows to these statuses. If it names "out"
 *                        explicitly, out employees are included in the result
 *                        even when include_out=false (asking for them by name
 *                        overrides the wallboard default), which is called
 *                        out in meta.warnings so the override isn't a silent
 *                        surprise.
 *   include_out          bool (default false) — when true, also return
 *                        currently-out employees so a wallboard can render
 *                        the full roster in one call.
 *   overdue_after_minutes int (default 60; 0-1440) — minutes past an
 *                        employee's scheduled shift end before is_overdue
 *                        flips true. Mirrors the 60-minute default most
 *                        shifts.GracePeriodMinutes rows carry, but is a
 *                        single request-level knob, not a per-shift lookup —
 *                        an integrator who wants per-shift grace periods
 *                        joins against /api/external/v1/shifts themselves.
 *
 * STATUS is derived from the punch data itself (TimeIN/TimeOut/LunchStart/
 * LunchEnd), NOT from users.ClockStatus — that column is a cached mirror the
 * app updates on each punch action and can in principle drift from the punch
 * rows it was derived from; the punch rows are the source of truth.
 *
 * ELAPSED TIME matches api/v1/punch.php's handleStatus() exactly (see
 * LiveStatus::elapsedFor) so the native app's live counter for an employee and
 * this endpoint's elapsed_seconds for the same employee never disagree.
 *
 * SCHEDULED_END / OVERDUE: computed against the shift instance most relevant
 * to right now (today's, or an overnight shift still running from yesterday —
 * see LiveStatus::currentShiftInstance). An employee with no active shift, or
 * whose shift doesn't cover today (or yesterday's overnight tail), reports
 * scheduled_end = null and is never flagged overdue — "unscheduled right now"
 * even if they work other days.
 *
 * TOTALS describe the FULL filtered roster (matching employee_id/
 * employee_email/office/include_inactive), independent of `status` and
 * `include_out` — a wallboard tile needs the whole picture even when it only
 * rendered a subset of rows. meta.warnings says so explicitly whenever the
 * returned rows are a subset of what the totals describe, so totals.out > 0
 * next to zero visible "out" rows is never a silent surprise.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../../lib/ApiTimeFormat.php';
require_once __DIR__ . '/../../../lib/LiveStatus.php';

/** Same closure-per-file convention as utilization.php / geofence.php. */
$parseBoolParam = static function (array $get, string $name, bool $default): bool {
    if (!array_key_exists($name, $get)) {
        return $default;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = strtolower(trim((string)$raw));
    if ($v === '') {
        return $default;
    }
    if (in_array($v, ['1', 'true', 'yes'], true))  return true;
    if (in_array($v, ['0', 'false', 'no'], true))  return false;
    ApiResponse::error(
        'Invalid parameter `' . $name . '`: "' . htmlspecialchars(substr($v, 0, 64), ENT_QUOTES, 'UTF-8')
        . '". Accepted values are 1/0, true/false, yes/no.',
        400
    );
};

$parseBoundedInt = static function (array $get, string $name, int $default, int $min, int $max): int {
    if (!array_key_exists($name, $get)) {
        return $default;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = trim((string)$raw);
    if ($v === '') {
        return $default;
    }
    if (!preg_match('/^\d+$/', $v)) {
        ApiResponse::error(
            'Invalid parameter `' . $name . '`: "' . htmlspecialchars(substr($v, 0, 64), ENT_QUOTES, 'UTF-8')
            . '". Expected an integer between ' . $min . ' and ' . $max . '.',
            400
        );
    }
    $i = (int)$v;
    if ($i < $min || $i > $max) {
        ApiResponse::error(
            'Invalid parameter `' . $name . '`: ' . $i . ' is out of range (' . $min . '-' . $max . ').',
            400
        );
    }
    return $i;
};

/** Comma list validated against a fixed vocabulary; empty means "no filter". */
$parseStatusList = static function (array $get, string $name, array $allowed): array {
    if (!array_key_exists($name, $get)) {
        return [];
    }
    $raw = $get[$name];
    if (is_array($raw)) {
        $flat = [];
        foreach ($raw as $v) { if (is_scalar($v)) $flat[] = (string)$v; }
        $raw = implode(',', $flat);
    } elseif (!is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a value or comma list.', 400);
    }
    $out = [];
    foreach (explode(',', (string)$raw) as $piece) {
        $v = strtolower(trim($piece));
        if ($v === '') continue;
        if (!in_array($v, $allowed, true)) {
            $echo = htmlspecialchars(substr($v, 0, 64), ENT_QUOTES, 'UTF-8');
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are '
                . implode(', ', $allowed) . ' (comma list).',
                400
            );
        }
        if (!in_array($v, $out, true)) $out[] = $v;
    }
    return $out;
};

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

try {
    ReportBootstrap::begin('/api/external/v1/live/status', 'live:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'employee_id', 'employee_email', 'office', 'include_inactive', 'limit', 'offset',
        'status', 'include_out', 'overdue_after_minutes',
    ]);

    $statusFilter        = $parseStatusList($_GET, 'status', ['in', 'on_lunch', 'out']);
    $includeOut           = $parseBoolParam($_GET, 'include_out', false);
    $overdueAfterMinutes  = $parseBoundedInt($_GET, 'overdue_after_minutes', 60, 0, 1440);

    // Asking for status=out by name is a clearer signal than the include_out
    // default should override — otherwise "status=out" silently returns nothing
    // whenever a caller forgot include_out=true, which is a confusing 200.
    $statusRequestsOut = in_array('out', $statusFilter, true);
    $effectiveIncludeOut = $includeOut || $statusRequestsOut;

    $tz  = $params->timezone();
    $now = new DateTimeImmutable('now', $tz);

    // ------------------------------------------------------------------ roster
    $filter = $params->buildUserFilter('u');
    $rosterWhere = $filter['sql'] !== '' ? $filter['sql'] : '1=1';
    $stmt = $conn->prepare(
        "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
         FROM users u
         WHERE $rosterWhere
         ORDER BY u.LastName ASC, u.FirstName ASC, u.ID ASC"
    );
    if ($filter['types'] !== '') {
        $stmt->bind_param($filter['types'], ...$filter['values']);
    }
    $stmt->execute();
    $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rosterIds = array_map(static fn($u) => (int)$u['ID'], $roster);

    $shifts      = $rosterIds === [] ? [] : ScheduleCalculator::loadShiftsForUsers($conn, $rosterIds);
    $openPunches = $rosterIds === [] ? [] : LiveStatus::loadOpenPunches($conn, $rosterIds);

    // --------------------------------------------------------- build all rows
    // Computed for the FULL roster first (totals need the whole population);
    // row-level filtering (status / include_out) happens after, over this array.
    $all = [];

    $totClockedIn = 0;
    $totOnLunch   = 0;
    $totOut       = 0;
    $totOverdue   = 0;
    $occupiedOffices = [];

    foreach ($roster as $u) {
        $eid   = (int)$u['ID'];
        $shift = $shifts[$eid] ?? null;
        $punch = $openPunches[$eid] ?? null;

        $status         = 'out';
        $punchId        = null;
        $clockInTime    = null;
        $lunchStartTime = null;
        $elapsedSeconds = 0;
        $elapsedHours   = 0.0;

        if ($punch !== null) {
            $status         = LiveStatus::statusForOpenPunch($punch);
            $punchId        = (string)$punch['punch_id'];
            $clockInTime    = LiveStatus::combine($punch['Date'], $punch['TimeIN'], $tz);
            // Matches punch.php's handleStatus(): lunch_start_time is reported
            // whenever LunchStart is set, regardless of whether lunch has ended.
            $lunchStartTime = LiveStatus::combine($punch['Date'], $punch['LunchStart'], $tz);
            $elapsed        = LiveStatus::elapsedFor($punch, $now, $tz);
            $elapsedSeconds = $elapsed['seconds'];
            $elapsedHours   = $elapsed['hours'];
        }

        $instance = LiveStatus::currentShiftInstance($shift, $now, $tz);
        $scheduledEnd = null;
        $minutesPast  = null;
        $isOverdue    = false;
        if ($instance !== null) {
            $scheduledEnd = $instance['end']->format('c');
            if ($status !== 'out') {
                $minutesPast = (int)floor(($now->getTimestamp() - $instance['end']->getTimestamp()) / 60);
                $isOverdue   = $minutesPast > $overdueAfterMinutes;
            }
        }

        if ($status === 'in')       { $totClockedIn++; }
        elseif ($status === 'on_lunch') { $totOnLunch++; }
        else                          { $totOut++; }
        if ($isOverdue) { $totOverdue++; }

        $office = $nullIfBlank($u['Office']);
        if ($office !== null && $status !== 'out') {
            $occupiedOffices[$office] = true;
        }

        $all[] = [
            'employee_id'                => $eid,
            'employee_email'             => $nullIfBlank($u['Email']),
            'employee_name'              => trim((string)$u['FirstName'] . ' ' . (string)$u['LastName']),
            'office'                     => $office,
            'job_title'                  => $nullIfBlank($u['JobTitle']),
            'shift_name'                 => $shift !== null ? $nullIfBlank($shift['ShiftName'] ?? null) : null,
            'status'                     => $status,
            'punch_id'                   => $punchId,
            'clock_in_time'              => $clockInTime,
            'lunch_start_time'           => $lunchStartTime,
            'elapsed_seconds'            => $elapsedSeconds,
            'elapsed_hours'              => $elapsedHours,
            'scheduled_end'              => $scheduledEnd,
            'minutes_past_scheduled_end' => $minutesPast,
            'is_overdue'                 => $isOverdue,
        ];
    }

    // ------------------------------------------------------------ row filters
    $rows = [];
    foreach ($all as $row) {
        if ($row['status'] === 'out' && !$effectiveIncludeOut) {
            continue;
        }
        if ($statusFilter !== [] && !in_array($row['status'], $statusFilter, true)) {
            continue;
        }
        $rows[] = $row;
    }

    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    // ------------------------------------------------------------------ totals
    $totals = [
        'clocked_in'       => $totClockedIn,
        'on_lunch'         => $totOnLunch,
        'out'              => $totOut,
        'overdue'          => $totOverdue,
        'offices_occupied' => count($occupiedOffices),
    ];

    // ----------------------------------------------------------------- meta
    $warnings = [];
    if ($totalCount < count($all)) {
        $warnings[] = 'totals describe the full filtered roster (' . count($all) . ' employee(s)), not just the '
            . $totalCount . ' row(s) returned after the `status`/`include_out` filters. Pass include_out=true '
            . 'and no `status` filter to see every employee behind the totals.';
    }
    if ($statusRequestsOut && !$includeOut) {
        $warnings[] = '`status` named "out" explicitly, so out employees were included even though '
            . 'include_out=false (the default).';
    }
    if (!LiveStatus::isOpenColumnExists($conn)) {
        $warnings[] = 'This install has no timepunches.IsOpen column; open-punch detection fell back to '
            . 'TimeOut IS NULL, which cannot distinguish more than one concurrently open punch per employee.';
    }

    $meta = $params->meta('live_status', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
        // No date range and no hours-allocation policy applies to a point-in-time
        // status snapshot — publishing the shared rounding/OT defaults here would
        // describe behavior this endpoint does not have (same reasoning as
        // /api/external/v1/offices).
        'rules'       => (object)[],
    ]);
    $meta['filters']['status']                 = $statusFilter;
    $meta['filters']['include_out']            = $includeOut;
    $meta['filters']['overdue_after_minutes']  = $overdueAfterMinutes;
    // A polling client needs the server's own clock to compute its drift against
    // clock_in_time / elapsed_seconds — same field name/shape as /meta's server_time.
    $meta['server_time'] = ApiTimeFormat::now($tz);

    $csvColumns = [
        'employee_id', 'employee_email', 'employee_name', 'office', 'job_title', 'shift_name',
        'status', 'punch_id', 'clock_in_time', 'lunch_start_time', 'elapsed_seconds', 'elapsed_hours',
        'scheduled_end', 'minutes_past_scheduled_end', 'is_overdue',
    ];

    Output::send(
        ['rows' => $page, 'meta' => $meta, 'totals' => $totals],
        $params,
        'live_status',
        $csvColumns
    );

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/live/status] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
