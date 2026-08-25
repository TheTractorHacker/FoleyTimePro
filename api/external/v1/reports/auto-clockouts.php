<?php
/**
 * GET /api/external/v1/reports/auto-clockouts
 * Authorization: Bearer <api-key>   Scope: audit:read
 *
 * Punches the auto-clockout cron (functions/auto_clockout_cron.php) closed on
 * an employee's behalf: it stamps TimeOut with the shift's end time (plus
 * grace) and logs the event to auto_clockout_log, joined here to timepunches
 * (for the punch's own Date/TimeIN, and to recompute worked_hours) and users.
 *
 * worked_hours is recomputed via HoursCalculator from the punch's raw
 * TimeIN/LunchStart/LunchEnd/TimeOut — never timepunches.TotalHours, which is
 * unreliable elsewhere in this schema (see the shared library's own warning)
 * and in any case was itself written by the SAME cron run this report is
 * auditing, so trusting it here would mean grading the cron's arithmetic
 * using the cron's own arithmetic. `rounding`/`week_start`/OT parameters are
 * deliberately not accepted: this is a compliance count, not a payroll figure,
 * and HoursCalculator::roundHours() is still called (interval 0, i.e. its
 * plain 2-decimal-place mode) rather than a hand-rolled round() so the number
 * goes through the one function every other endpoint's hours pass through.
 *
 * `punch_id` always resolves: an INNER JOIN to timepunches is safe (not just
 * convenient) because `start`/`end` are required here and filter on the
 * punch's own Date — a log row whose punch has since been deleted has no
 * Date to test against and could never pass that filter under a LEFT JOIN
 * either, so the two join types are equivalent in every reachable case; INNER
 * is simpler. users IS LEFT JOINed (see audit/punch-changes.php's file header
 * for why an audit surface should degrade rather than drop a row when an
 * employee record is gone) — employee_id itself still comes from
 * auto_clockout_log.EmployeeID, not the (possibly missing) join.
 *
 * minutes_past_shift_end = AutoClockOutTime − OriginalShiftEndTime, in
 * minutes, wrapped +86400 when the difference is negative (an overnight
 * shift's end time can sit just after midnight while the grace window is
 * still measured forward from it) — the same overnight-safe pattern
 * HoursCalculator uses for a wrapped clock-out. Either time can be malformed
 * (a bad hand edit); HoursCalculator::timeToSeconds returns null rather than
 * throwing, and this field is null right along with it instead of a
 * fabricated number.
 *
 * Not paginated in SQL: worked_hours requires per-row HoursCalculator math
 * that cannot be expressed in SQL (see the library's own docs: "never
 * duplicate HoursCalculator's punch math"), so the full filtered set is
 * fetched, shaped, and summed for `totals` BEFORE the page is sliced off —
 * the same reason /reports/utilization does this. Auto-clockouts are cron
 * events bounded at roughly one per employee per missed clock-out, so this
 * does not carry the row-volume risk that ruled out the same approach for
 * /audit/access (whose api_key_audit_log arm can grow unboundedly).
 *
 * Params: start, end (required, filters on the PUNCH's Date, not
 *   ProcessedAt — an employee auto-clocked-out just after midnight for a
 *   shift worked the day before is reported under that worked day),
 *   employee_id, employee_email, office, include_inactive, tz, format,
 *   limit, offset.
 * Totals describe the full filtered set, not just the returned page.
 * Sort: processed_at DESC, log_id DESC (stable tie-break; no sort was
 * specified for this endpoint, so it matches the two audit siblings it
 * shares a scope with).
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../../lib/ApiTimeFormat.php';

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

try {
    ReportBootstrap::begin('/api/external/v1/reports/auto-clockouts', 'audit:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'limit', 'offset',
    ]);

    $tz    = $params->timezone();
    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    $filter = $params->buildUserFilter('u');

    $where  = ['tp.Date BETWEEN ? AND ?'];
    $types  = 'ss';
    $values = [$start, $end];
    if ($filter['sql'] !== '') {
        $where[] = $filter['sql'];
        $types  .= $filter['types'];
        $values  = array_merge($values, $filter['values']);
    }

    $sql = "SELECT acl.ID, acl.EmployeeID, acl.PunchID,
                   acl.OriginalShiftEndTime, acl.AutoClockOutTime, acl.NotificationSent,
                   UNIX_TIMESTAMP(acl.ProcessedAt) AS ProcessedAtEpoch,
                   tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut,
                   u.Email, u.FirstName, u.LastName, u.Office
            FROM auto_clockout_log acl
            JOIN timepunches tp ON tp.id = acl.PunchID
            LEFT JOIN users u ON u.ID = acl.EmployeeID
            WHERE " . implode(' AND ', $where) . "
            ORDER BY acl.ProcessedAt DESC, acl.ID DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $logRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rows              = [];
    $employeeIdsSeen   = [];
    $sumHours          = 0.0;
    $notificationsSent = 0;
    $hoursWarningsSeen = [];

    foreach ($logRows as $r) {
        $calc = HoursCalculator::punchWorkedSeconds([
            'TimeIN'     => $r['TimeIN'],
            'LunchStart' => $r['LunchStart'],
            'LunchEnd'   => $r['LunchEnd'],
            'TimeOut'    => $r['TimeOut'],
        ]);
        $hours = HoursCalculator::roundHours(HoursCalculator::secondsToHours($calc['seconds']), 0);
        foreach ($calc['warnings'] as $w) {
            if (!in_array($w, $hoursWarningsSeen, true)) $hoursWarningsSeen[] = $w;
        }

        $shiftEndSec = HoursCalculator::timeToSeconds($r['OriginalShiftEndTime']);
        $autoOutSec  = HoursCalculator::timeToSeconds($r['AutoClockOutTime']);
        $minutesPast = null;
        if ($shiftEndSec !== null && $autoOutSec !== null) {
            $diff = $autoOutSec - $shiftEndSec;
            if ($diff < 0) { $diff += 86400; }
            $minutesPast = (int)round($diff / 60);
        }

        $eid = (int)$r['EmployeeID'];
        $employeeIdsSeen[$eid] = true;
        $sumHours += $hours;
        $notified  = (int)$r['NotificationSent'] === 1;
        if ($notified) $notificationsSent++;

        $rows[] = [
            'log_id'                  => (int)$r['ID'],
            'employee_id'             => $eid,
            'employee_email'          => $nullIfBlank($r['Email']),
            'employee_name'           => $nullIfBlank(trim((string)$r['FirstName'] . ' ' . (string)$r['LastName'])),
            'office'                  => $nullIfBlank($r['Office']),
            'punch_id'                => (int)$r['PunchID'],
            'punch_date'              => ApiTimeFormat::dateOnly($r['Date']),
            'original_shift_end'      => ApiTimeFormat::timeOnly($r['OriginalShiftEndTime']),
            'auto_clock_out_time'     => ApiTimeFormat::timeOnly($r['AutoClockOutTime']),
            'clock_in_time'           => ApiTimeFormat::timeOnly($r['TimeIN']),
            'worked_hours'            => $hours,
            'minutes_past_shift_end'  => $minutesPast,
            'notification_sent'       => $notified,
            'processed_at'            => ApiTimeFormat::fromEpoch(ApiTimeFormat::epochOrNull($r['ProcessedAtEpoch']), $tz),
        ];
    }

    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    $totals = [
        'auto_clockouts'     => $totalCount,
        'employees_affected' => count($employeeIdsSeen),
        'total_hours'        => round($sumHours, 2),
        'notifications_sent' => $notificationsSent,
    ];

    $warnings = [];
    if ($hoursWarningsSeen !== []) {
        $warnings[] = 'One or more auto-clocked-out punches had a data anomaly recomputing worked_hours ('
            . implode(', ', $hoursWarningsSeen) . '); see the affected row(s) — worked_hours reflects the '
            . 'clamped/best-effort value, the same rule every other report on this API applies.';
    }

    $meta = $params->meta('auto-clockouts', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    // Interpretive guidance rather than a data-quality caveat, so it lives
    // beside `warnings` rather than inside it: this report's real value is in
    // WHO recurs, not the raw event count.
    $meta['notes'] = [
        'A high auto_clockouts count for one employee usually means they are forgetting to clock out — '
        . 'that per-employee pattern, not the raw total, is what makes this report actionable rather than '
        . 'merely informational.',
    ];

    $csvColumns = [
        'log_id', 'employee_id', 'employee_email', 'employee_name', 'office', 'punch_id', 'punch_date',
        'original_shift_end', 'auto_clock_out_time', 'clock_in_time', 'worked_hours',
        'minutes_past_shift_end', 'notification_sent', 'processed_at',
    ];

    Output::send(['rows' => $page, 'meta' => $meta, 'totals' => $totals], $params, 'auto-clockouts', $csvColumns);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/auto-clockouts] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
