<?php
/**
 * GET /api/external/v1/shifts
 * Authorization: Bearer <api-key>   Scope: shifts:read
 *
 * The shift DIMENSION: every shift definition, with the days it runs and how many
 * hours a day of it is worth. /api/external/v1/schedule expands these rules into
 * dated rows; this endpoint is the rule set itself, so a BI model can label a
 * schedule row without re-deriving anything.
 *
 * Params: include_inactive (default false — IsActive = 1 only), tz, format,
 *         limit, offset. No date range: a shift definition is not a time series.
 *
 * Row:
 *   {
 *     "shift_id": 4, "shift_name": "Day Crew",
 *     "start_time": "08:00:00", "end_time": "16:30:00",
 *     "days_of_week": ["Mon","Tue","Wed","Thu","Fri"],
 *     "scheduled_hours_per_day": 8.0,
 *     "lunch_duration_minutes": 30, "lunch_start_time": "12:00:00",
 *     "grace_period_minutes": 60, "auto_clock_out": false, "is_active": true,
 *     "assigned_employee_count": 12, "active_assigned_employee_count": 11,
 *     "created_at": "2026-01-04T09:00:00-06:00",
 *     "updated_at": "2026-07-30T14:12:00-05:00"
 *   }
 *
 * shifts.DaysOfWeek is a JSON array in a VARCHAR with nothing enforcing its shape.
 * A value that will not decode, or that carries tokens outside
 * Mon/Tue/Wed/Thu/Fri/Sat/Sun, yields an empty (or partial) days_of_week AND a
 * meta warning naming the shift — a shift that quietly schedules nobody is exactly
 * the kind of defect an integrator should be told about rather than left to infer
 * from missing /schedule rows.
 */

require_once __DIR__ . '/../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../lib/ApiTimeFormat.php';

try {
    ReportBootstrap::begin('/api/external/v1/shifts', 'shifts:read');

    $params = ReportParams::fromRequest($_GET, $conn, ['include_inactive', 'limit', 'offset']);

    // include_inactive here means the SHIFT's IsActive flag, not an employee's
    // LockOut. `IsActive = 1` (never `<> 0`) matches ScheduleCalculator, so a shift
    // with a NULL flag is treated as inactive by both.
    $activeOnly = !$params->includeInactive();
    $whereSql   = $activeOnly ? 'WHERE s.IsActive = 1' : '';

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM shifts s $whereSql");
    $stmt->execute();
    $totalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // Assignment counts come from the same LEFT JOIN, so a shift nobody is on still
    // returns a row with 0 rather than disappearing. The active count is joined
    // through users because user_shifts alone cannot tell a live assignment from
    // one held by a locked-out employee — a shift can look fully staffed while
    // every person on it is deactivated.
    //
    // user_shifts.UserID is UNIQUE, so no employee can be double counted here.
    $stmt = $conn->prepare(
        "SELECT s.ShiftID, s.ShiftName, s.StartTime, s.EndTime, s.DaysOfWeek,
                s.LunchDuration, s.LunchStartTime, s.IsActive,
                s.GracePeriodMinutes, s.AutoClockOut,
                UNIX_TIMESTAMP(s.CreatedAt) AS CreatedAtEpoch,
                UNIX_TIMESTAMP(s.UpdatedAt) AS UpdatedAtEpoch,
                COUNT(us.ID) AS AssignedCount,
                COALESCE(SUM(CASE WHEN us.ID IS NOT NULL
                                   AND (u.LockOut IS NULL OR u.LockOut = 0)
                              THEN 1 ELSE 0 END), 0) AS ActiveAssignedCount
         FROM shifts s
         LEFT JOIN user_shifts us ON us.ShiftID = s.ShiftID
         LEFT JOIN users u        ON u.ID       = us.UserID
         $whereSql
         GROUP BY s.ShiftID, s.ShiftName, s.StartTime, s.EndTime, s.DaysOfWeek,
                  s.LunchDuration, s.LunchStartTime, s.IsActive,
                  s.GracePeriodMinutes, s.AutoClockOut, s.CreatedAt, s.UpdatedAt
         ORDER BY s.ShiftName ASC, s.ShiftID ASC
         LIMIT ? OFFSET ?"
    );
    $limit  = $params->limit();
    $offset = $params->offset();
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $shiftRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ------------------------------------------------------------------ helpers

    /** Canonical week order, so days_of_week never depends on JSON key order. */
    $canonicalDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * Decode DaysOfWeek into canonical day names plus a decode status.
     * A closure rather than a named function: two endpoints loaded in one process
     * must never collide in the global namespace (punches.php already shows what
     * happens when helpers live there).
     *
     * @return array{days: string[], status: string} status: ok | malformed | partial | empty
     */
    $decodeDays = static function (?string $raw) use ($canonicalDays): array {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return ['days' => [], 'status' => 'empty'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['days' => [], 'status' => 'malformed'];
        }

        $found   = [];
        $dropped = 0;
        foreach ($decoded as $d) {
            if (!is_string($d)) { $dropped++; continue; }
            $code    = strtolower(substr(trim($d), 0, 3));
            $matched = null;
            foreach ($canonicalDays as $day) {
                if (strtolower($day) === $code) { $matched = $day; break; }
            }
            if ($matched === null)                  { $dropped++; continue; }
            if (!in_array($matched, $found, true))  { $found[] = $matched; }
        }

        $ordered = array_values(array_filter($canonicalDays, static fn($d) => in_array($d, $found, true)));
        if ($ordered === []) {
            return ['days' => [], 'status' => 'malformed'];
        }
        return ['days' => $ordered, 'status' => $dropped > 0 ? 'partial' : 'ok'];
    };

    /**
     * Hours one day of this shift is worth: span end-minus-start (rolling past
     * midnight for an overnight shift) less LunchDuration.
     *
     * Routed through ScheduleCalculator rather than recomputed here so shift-span
     * math lives in exactly one place and this dimension can never disagree with
     * the utilization denominator. scheduledSecondsForDate() is date-driven, and
     * hours-per-day is a property of the SPAN and not of any particular date, so
     * it is asked about a shift forced to run every day on a fixed reference
     * Monday — that isolates the span math from the shift's own day list.
     */
    $hoursPerDay = static function (array $shiftRow): float {
        $spanShift = $shiftRow;
        $spanShift['DaysOfWeek'] = '["Mon","Tue","Wed","Thu","Fri","Sat","Sun"]';
        return round(ScheduleCalculator::scheduledHoursForDate($spanShift, '2024-01-01'), 2);
    };

    // --------------------------------------------------------------- build rows
    $rows        = [];
    $warnings    = [];
    $badShifts   = [];
    $totalHours  = 0.0;
    $assignedSum = 0;
    $activeSum   = 0;

    foreach ($shiftRows as $r) {
        $shiftId = (int)$r['ShiftID'];
        $name    = (string)$r['ShiftName'];
        $decoded = $decodeDays($r['DaysOfWeek'] ?? null);

        if ($decoded['status'] !== 'ok') {
            $badShifts[] = [
                'id'     => $shiftId,
                'name'   => preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '',
                'status' => $decoded['status'],
            ];
        }

        $hours = $hoursPerDay($r);

        $rows[] = [
            'shift_id'                       => $shiftId,
            'shift_name'                     => $name,
            'start_time'                     => ApiTimeFormat::timeOnly($r['StartTime'] ?? null),
            'end_time'                       => ApiTimeFormat::timeOnly($r['EndTime'] ?? null),
            'days_of_week'                   => $decoded['days'],
            'scheduled_hours_per_day'        => $hours,
            'lunch_duration_minutes'         => (int)($r['LunchDuration'] ?? 0),
            'lunch_start_time'               => ApiTimeFormat::timeOnly($r['LunchStartTime'] ?? null),
            'grace_period_minutes'           => (int)($r['GracePeriodMinutes'] ?? 0),
            'auto_clock_out'                 => (int)($r['AutoClockOut'] ?? 0) === 1,
            'is_active'                      => (int)($r['IsActive'] ?? 0) === 1,
            'assigned_employee_count'        => (int)$r['AssignedCount'],
            'active_assigned_employee_count' => (int)$r['ActiveAssignedCount'],
            'created_at'                     => ApiTimeFormat::fromEpoch(
                ApiTimeFormat::epochOrNull($r['CreatedAtEpoch'] ?? null), $params->timezone()
            ),
            'updated_at'                     => ApiTimeFormat::fromEpoch(
                ApiTimeFormat::epochOrNull($r['UpdatedAtEpoch'] ?? null), $params->timezone()
            ),
        ];

        $totalHours  += $hours;
        $assignedSum += (int)$r['AssignedCount'];
        $activeSum   += (int)$r['ActiveAssignedCount'];
    }

    // -------------------------------------------------------------- diagnostics
    $unusable = array_values(array_filter($badShifts, static fn($b) => $b['status'] !== 'partial'));
    $partial  = array_values(array_filter($badShifts, static fn($b) => $b['status'] === 'partial'));

    if ($unusable !== []) {
        $names = array_map(static fn($b) => 'ShiftID ' . $b['id'] . ' ("' . $b['name'] . '")', array_slice($unusable, 0, 10));
        $warnings[] = count($unusable) . ' shift(s) have an unusable DaysOfWeek value and return '
            . 'days_of_week = []: ' . implode(', ', $names)
            . (count($unusable) > 10 ? ' (first 10)' : '')
            . '. Those shifts schedule nobody — /api/external/v1/schedule emits no rows for them and '
            . 'utilization counts their employees as unscheduled.';
    }
    if ($partial !== []) {
        $names = array_map(static fn($b) => 'ShiftID ' . $b['id'] . ' ("' . $b['name'] . '")', array_slice($partial, 0, 10));
        $warnings[] = count($partial) . ' shift(s) have DaysOfWeek entries outside Mon/Tue/Wed/Thu/Fri/Sat/Sun; '
            . 'the unrecognized entries were dropped: ' . implode(', ', $names)
            . (count($partial) > 10 ? ' (first 10)' : '') . '.';
    }
    if ($offset > 0 || ($offset + count($rows)) < $totalCount) {
        $warnings[] = 'Totals cover only the ' . count($rows) . ' shift(s) returned by this request, not all '
            . $totalCount . '. Page through the full result set to total it.';
    }

    // ------------------------------------------------------------------- output
    $meta = $params->meta('shifts', [
        // Only include_inactive applies here, and it filters SHIFTS, not employees;
        // publishing the employee-shaped filter defaults would misdescribe it.
        'filters'     => ['include_inactive' => $params->includeInactive()],
        'rules'       => (object)[],
        'row_count'   => count($rows),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'shifts'                         => count($rows),
            'scheduled_hours_per_day'        => round($totalHours, 2),
            'assigned_employee_count'        => $assignedSum,
            'active_assigned_employee_count' => $activeSum,
        ],
    ];

    // Pinned so an empty result still emits a header row and the column order never
    // drifts with the first row's key order.
    Output::send($data, $params, 'shifts', [
        'shift_id', 'shift_name', 'start_time', 'end_time', 'days_of_week',
        'scheduled_hours_per_day', 'lunch_duration_minutes', 'lunch_start_time',
        'grace_period_minutes', 'auto_clock_out', 'is_active',
        'assigned_employee_count', 'active_assigned_employee_count',
        'created_at', 'updated_at',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/shifts] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
