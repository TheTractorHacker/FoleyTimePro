<?php
/**
 * GET /api/external/v1/schedule
 * Authorization: Bearer <api-key>   Scope: shifts:read
 *
 * The EXPANDED schedule: one row per employee per scheduled DATE in [start, end].
 * /api/external/v1/shifts is the rule set; this endpoint applies that rule set to
 * a calendar so a BI model can do "scheduled vs actual" by joining this against
 * /api/external/v1/punches on (employee_id, date) without re-deriving DaysOfWeek,
 * overnight spans or lunch deductions itself.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, tz, format, limit, offset.
 * No rounding/week_start/ot_* — nothing here is worked time.
 *
 * ROW SELECTION: an employee contributes a row only for a date that (a) falls
 * inside [start, end] AND (b) is named in their ACTIVE assigned shift's
 * DaysOfWeek. An employee with no active shift (has_schedule=false on
 * /employees, absent from ScheduleCalculator::loadShiftsForUsers) contributes NO
 * rows at all — there is no rule to expand. This is a dimension expansion, not a
 * roster listing, so "no rows for this employee" is the correct and expected
 * result rather than something to chase.
 *
 * scheduled_hours is the shift's OWN rule for that date (span minus lunch,
 * overnight-safe) and is reported EVEN ON a date covered by approved time off.
 * That is deliberate: this endpoint answers "what did the shift say", and
 * is_working_day / time_off_type is the separate overlay answering "did leave
 * override that". Netting time off out of the hours figure the way
 * /reports/utilization does to its denominator would silently discard the shift
 * rule this endpoint exists to expose, and a consumer who wants the netted
 * version can compute scheduled_hours * is_working_day themselves. Time off is
 * looked up with the same ScheduleCalculator::loadApprovedTimeOff() rule as
 * every other report, so "is this date covered by approved leave" can never
 * disagree across endpoints.
 *
 * `totals` describes the ENTIRE filtered/date-expanded result, not just the
 * returned page — same convention as /reports/utilization — because a BI import
 * that pages through the result still needs one true grand total, and totaling
 * only the current page would silently shrink every time the page size changes.
 */

require_once __DIR__ . '/../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../lib/ApiTimeFormat.php';

try {
    ReportBootstrap::begin('/api/external/v1/schedule', 'shifts:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office',
        'include_inactive', 'limit', 'offset',
    ]);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();
    // No $tz needed: `date` is a plain Y-m-d and every other field on this row is
    // either a bare TIME or a value with no instant to localize.

    // ------------------------------------------------------------ roster pull
    // Full filtered roster, not paged: the page boundary applies to expanded
    // (employee, date) rows below, not to which employees are considered — a
    // limit=50 request must not silently truncate the employee set before the
    // date expansion even runs.
    $filter = $params->buildUserFilter('u');
    $sql = "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office
            FROM users u"
        . ($filter['sql'] !== '' ? " WHERE {$filter['sql']}" : '')
        . " ORDER BY u.FirstName, u.LastName, u.ID";
    $stmt = $conn->prepare($sql);
    if ($filter['types'] !== '') {
        $stmt->bind_param($filter['types'], ...$filter['values']);
    }
    $stmt->execute();
    $userRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $userIds = array_map(static fn($u) => (int)$u['ID'], $userRows);

    $shifts  = ScheduleCalculator::loadShiftsForUsers($conn, $userIds);
    $timeOff = ScheduleCalculator::loadApprovedTimeOff($conn, $userIds, $start, $end);

    // -------------------------------------------------------------- expansion
    $allRows              = [];
    $employeesWithSchedule = 0;
    $employeesNoSchedule    = 0;

    foreach ($userRows as $u) {
        $uid   = (int)$u['ID'];
        $shift = $shifts[$uid] ?? null;
        if ($shift === null) {
            $employeesNoSchedule++;
            continue;
        }

        $dates = ScheduleCalculator::scheduledDaysBetween($shift, $start, $end);
        if ($dates === []) {
            // Has an active shift, but nothing in it falls inside this range
            // (short range, weekend-only shift, or malformed DaysOfWeek — the
            // last of those is already logged by ScheduleCalculator). No row
            // is still the correct answer; not counted as "no schedule" since
            // the employee genuinely has one.
            continue;
        }
        $employeesWithSchedule++;

        $startSec    = HoursCalculator::timeToSeconds($shift['StartTime'] ?? null);
        $endSec      = HoursCalculator::timeToSeconds($shift['EndTime'] ?? null);
        $isOvernight = ($startSec !== null && $endSec !== null) && ($endSec <= $startSec);

        $scheduledStart = ApiTimeFormat::timeOnly($shift['StartTime'] ?? null);
        $scheduledEnd   = ApiTimeFormat::timeOnly($shift['EndTime'] ?? null);
        $shiftId        = (int)$shift['ShiftID'];
        $shiftName       = (string)$shift['ShiftName'];

        $name   = trim((string)($u['FirstName'] ?? '') . ' ' . (string)($u['LastName'] ?? ''));
        $email  = ($u['Email']  !== null && $u['Email']  !== '') ? $u['Email']  : null;
        $office = ($u['Office'] !== null && $u['Office'] !== '') ? $u['Office'] : null;

        foreach ($dates as $date) {
            $off = $timeOff[$uid][$date] ?? null;

            $allRows[] = [
                'employee_id'     => $uid,
                'employee_email'  => $email,
                'employee_name'   => $name,
                'office'          => $office,
                'shift_id'        => $shiftId,
                'shift_name'      => $shiftName,
                'date'            => $date,
                'day_of_week'     => ApiTimeFormat::dayOfWeek($date),
                'scheduled_start' => $scheduledStart,
                'scheduled_end'   => $scheduledEnd,
                'scheduled_hours' => round(ScheduleCalculator::scheduledHoursForDate($shift, $date), 2),
                'is_overnight'    => $isOvernight,
                'time_off_type'   => $off['type'] ?? null,
                'is_working_day'  => $off === null,
            ];
        }
    }

    // $allRows is already ordered by (employee name/id, date) because $userRows
    // was fetched in that order and scheduledDaysBetween() walks dates forward.

    $totalCount   = count($allRows);
    $totalHours   = 0.0;
    $employeeSet  = [];
    foreach ($allRows as $r) {
        $totalHours += $r['scheduled_hours'];
        $employeeSet[$r['employee_id']] = true;
    }

    $rows     = array_slice($allRows, $params->offset(), $params->limit());
    $rowCount = count($rows);

    // -------------------------------------------------------------- warnings
    $warnings = [];
    if ($employeesNoSchedule > 0) {
        $warnings[] = $employeesNoSchedule . ' employee(s) matching the filters have no active '
            . 'assigned shift and contribute no rows here. See /api/external/v1/employees '
            . '(has_schedule) or /api/external/v1/shifts to identify them.';
    }

    // ------------------------------------------------------------------ output
    $meta = $params->meta('schedule', [
        'rules'       => (object)[],
        'row_count'   => $rowCount,
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'employees'       => count($employeeSet),
            'scheduled_days'  => $totalCount,
            'scheduled_hours' => round($totalHours, 2),
        ],
    ];

    Output::send($data, $params, 'schedule', [
        'employee_id', 'employee_email', 'employee_name', 'office',
        'shift_id', 'shift_name', 'date', 'day_of_week',
        'scheduled_start', 'scheduled_end', 'scheduled_hours', 'is_overnight',
        'time_off_type', 'is_working_day',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/schedule] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
