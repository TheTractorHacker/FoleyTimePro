<?php
/**
 * GET /api/external/v1/reports/attendance
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * The attendance CALENDAR: one row per employee per DATE for the whole requested
 * range, whether or not anything happened that day. That is the entire point of
 * this endpoint — an absence is the ABSENCE of a punch row, so a report shaped
 * like a fact table (/api/external/v1/reports/hours, which only emits days that
 * have punches) can never count one. admin/attendance.php draws the same grid for
 * humans; this is the machine-readable version, and it additionally knows about
 * shifts (scheduled vs. not) and approved time off, which the admin page does not.
 *
 * `status` is exactly one of, in this precedence order:
 *   present        there is punch activity and every punch has both ends
 *   incomplete     there is punch activity but at least one punch is one-sided
 *   time_off       no punches, and an APPROVED time_off_request covers the date
 *   absent         no punches, no approved time off, and the date IS scheduled
 *   not_scheduled  no punches, no approved time off, and the date is not scheduled
 *
 * Two precedence decisions worth stating out loud:
 *   1. Punch activity outranks time off. Someone who worked on an approved
 *      vacation day is `present`, not on vacation — but `time_off_type` is still
 *      reported on that row so the conflict is visible instead of hidden.
 *   2. time_off outranks not_scheduled as well as absent. An org that assigns no
 *      shifts has no scheduled days at all, and hiding its approved leave behind
 *      not_scheduled would make the status worthless there. The price is that a
 *      multi-day request expands across weekends (ScheduleCalculator expands every
 *      calendar date a request covers), so `is_scheduled` is how a consumer tells
 *      a vacation Wednesday from the Saturday in the middle of it — and
 *      meta.warnings says so whenever it actually happens.
 *
 * Lateness: minutes_late is first_in - shifts.StartTime (negative = early
 * arrival), and is_late applies that shift's own GracePeriodMinutes, so this
 * endpoint never invents a tardiness policy of its own. Both are null when the
 * employee was not scheduled — "late" has no meaning without a scheduled start.
 * Differences are normalized into +/-12h because the TIME columns carry no date:
 * a 23-hour "tardiness" against an overnight shift is really a one-hour early
 * arrival that crossed midnight.
 *
 * Worked hours are recomputed from the raw TIME columns (timepunches.TotalHours is
 * not trustworthy — one insert path writes a literal 0) and rounded once per day
 * by the shared calculator, so a date here always ties out to the same date in
 * /reports/hours and admin/summary.php.
 *
 * SIZE: the grid is employees x dates, so 400 days over a 100-person roster is
 * 40,000 rows. The library's 400-day range cap bounds it; limit/offset page it
 * deterministically (employee name, then date); total_count is the true size of
 * the FILTERED grid, and meta.grid reports the unfiltered employees x dates size.
 * The pass over the roster is chunked by employee so only one chunk's punches are
 * ever resident, and only the requested page of rows is retained.
 *
 * `totals` describe the ENTIRE filtered result set, not the returned page — a
 * grand total that changes as you page is a grand total nobody can use.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, rounding, tz, format, limit, offset,
 *         status (comma list of the five values above), scheduled_only.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

/**
 * Boolean parser for a report-specific parameter ReportParams does not own.
 * A closure rather than a named function so two endpoints loaded in the same
 * process can never collide in the global namespace.
 */
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

    $echo = htmlspecialchars(substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '', 0, 64), ENT_QUOTES, 'UTF-8');
    ApiResponse::error(
        'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are 1/0, true/false, yes/no.',
        400
    );
};

/**
 * Comma-list parser for the `status` filter, validated against a closed set.
 * An unrecognized value is a 400 rather than a silent empty result: "status=late"
 * returning zero rows looks like an outage, not like a typo.
 *
 * @return string[] deduped, lowercased; empty means "no status filter"
 */
$parseStatusFilter = static function (array $get, string $name, array $valid): array {
    if (!array_key_exists($name, $get)) {
        return [];
    }
    $raw = $get[$name];
    if (is_array($raw)) {
        $flat = [];
        foreach ($raw as $v) {
            if (is_scalar($v)) { $flat[] = (string)$v; }
        }
        $raw = implode(',', $flat);
    } elseif (!is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a value or comma list.', 400);
    }

    $out = [];
    foreach (explode(',', (string)$raw) as $piece) {
        $piece = strtolower(trim($piece));
        if ($piece === '') {
            continue;
        }
        if (!in_array($piece, $valid, true)) {
            $echo = htmlspecialchars(
                substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $piece) ?? '', 0, 64),
                ENT_QUOTES,
                'UTF-8'
            );
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are '
                . implode(', ', $valid) . ' (one value or a comma list).',
                400
            );
        }
        if (!in_array($piece, $out, true)) {
            $out[] = $piece;
        }
    }
    return $out;
};

try {
    ReportBootstrap::begin('/api/external/v1/reports/attendance', 'reports:read');

    // week_start / ot_* are deliberately NOT accepted: this report never allocates
    // overtime, and an accepted-but-unused parameter would make an integrator think
    // their OT rules were applied. `rounding` IS honored — worked_hours here must
    // equal worked_hours in /reports/hours for the same date.
    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office',
        'include_inactive', 'rounding', 'limit', 'offset',
        'status', 'scheduled_only',
    ]);

    // The five statuses this report can emit, and the closed set the `status`
    // filter validates against. A local rather than a global constant: two
    // endpoints loaded in the same process must not be able to collide.
    $validStatuses = ['present', 'incomplete', 'time_off', 'absent', 'not_scheduled'];

    // Both are declared in $accepted above so they are not reported as ignored
    // params, but they are parsed here because they belong to this report alone.
    $statusFilter  = $parseStatusFilter($_GET, 'status', $validStatuses);
    $scheduledOnly = $parseBoolParam($_GET, 'scheduled_only', false);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();
    $dates = ScheduleCalculator::datesBetween($start, $end);

    $limit     = $params->limit();
    $offset    = $params->offset();
    $windowEnd = $offset + $limit;

    // ----------------------------------------------------------------- roster
    // The grid's rows come from the ROSTER, not from the punches: an employee who
    // never clocked in during the range still owes us a row for every date, which
    // is exactly the case a punch-driven query cannot produce.
    // buildUserFilter() returns '' when nothing applies, hence the 1=1 anchor.
    // u.ID tie-breaks the name sort — two employees can share a display name, and
    // without a unique tail the same row can surface on two different pages.
    $filter    = $params->buildUserFilter('u');
    $userWhere = $filter['sql'] !== '' ? $filter['sql'] : '1=1';

    $stmt = $conn->prepare(
        "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office
         FROM users u
         WHERE $userWhere
         ORDER BY u.FirstName, u.LastName, u.ID"
    );
    if ($filter['types'] !== '') {
        $stmt->bind_param($filter['types'], ...$filter['values']);
    }
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ----------------------------------------------------------------- helpers

    /** Three-letter day name, matching the vocabulary in shifts.DaysOfWeek. */
    $dayOfWeek = static function (string $date): string {
        // UTC: a local midnight does not exist on every spring-forward date.
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $d === false ? '' : $d->format('D');
    };

    /**
     * A seconds difference rendered as minutes, normalized into (-12h, +12h].
     * timepunches TIME columns and shifts TIME columns both carry no date, so a
     * raw difference across midnight comes out ~24h wrong in one direction; the
     * shorter of the two readings is always the intended one for arrival and
     * departure deltas.
     */
    $minutesBetween = static function (int $diffSeconds): float {
        $d = $diffSeconds % 86400;              // PHP % keeps the dividend's sign
        if ($d >  43200) { $d -= 86400; }
        if ($d < -43200) { $d += 86400; }
        return round($d / 60, 2);
    };

    $nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

    // ------------------------------------------------------------- grid build
    // Chunked by employee: the whole grid (employees x up to 400 dates) is walked
    // so totals and total_count describe the full filtered result set, but only
    // one chunk's punch rows are held at a time and only the requested page of
    // rows is retained. 25 employees x 400 days bounds a chunk at ~10k punch rows.
    $chunkSize = 25;

    $rows       = [];
    $totalCount = 0;

    $statusCounts = [
        'present'       => 0,
        'incomplete'    => 0,
        'time_off'      => 0,
        'absent'        => 0,
        'not_scheduled' => 0,
    ];
    $totalWorked         = 0.0;
    $lateDays            = 0;
    $totalMinutesLate    = 0.0;
    $openPunchTotal      = 0;
    $noScheduleEmployees = 0;
    $timeOffOffSchedule  = 0;

    foreach (array_chunk($users, $chunkSize) as $chunk) {
        $ids = array_map(static fn($u) => (int)$u['ID'], $chunk);

        $shifts     = ScheduleCalculator::loadShiftsForUsers($conn, $ids);
        $timeOffAll = ScheduleCalculator::loadApprovedTimeOff($conn, $ids, $start, $end);

        // Placeholders are built from the COUNT of already-int-cast ids; the values
        // themselves only ever travel through bind_param.
        //
        // Note is fetched as a PRESENCE flag, never as content: punch notes are
        // free text that can carry medical or disciplinary detail, and this report
        // only ever claims that a note exists.
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut,
                    CASE WHEN tp.Note IS NOT NULL AND TRIM(tp.Note) <> '' THEN 1 ELSE 0 END AS HasNote
             FROM timepunches tp
             WHERE tp.Date BETWEEN ? AND ?
               AND tp.EmployeeID IN ($ph)
             ORDER BY tp.EmployeeID, tp.Date, tp.TimeIN, tp.id"
        );
        $stmt->bind_param('ss' . str_repeat('i', count($ids)), ...array_merge([$start, $end], $ids));
        $stmt->execute();
        $res = $stmt->get_result();

        $punchesByEmployeeDate = [];
        while ($p = $res->fetch_assoc()) {
            $punchesByEmployeeDate[(int)$p['EmployeeID']][(string)$p['Date']][] = $p;
        }
        $res->free();
        $stmt->close();

        foreach ($chunk as $u) {
            $empId = (int)$u['ID'];
            $shift = $shifts[$empId] ?? null;
            if ($shift === null) {
                // No assignment, or the assigned shift is inactive. Every non-punch
                // date for this employee will read not_scheduled, which is honest
                // but means their absences cannot be detected.
                $noScheduleEmployees++;
            }

            $byDate  = $punchesByEmployeeDate[$empId] ?? [];
            $offDays = $timeOffAll[$empId] ?? [];

            // Rounding is applied once to the summed DAY by the calculator, so the
            // per-employee call is made once and read back per date.
            $flat = [];
            foreach ($byDate as $dayPunches) {
                foreach ($dayPunches as $p) { $flat[] = $p; }
            }
            $daily = HoursCalculator::dailyHoursFromPunches($flat, $params->rounding());
            $openPunchTotal += (int)$daily['open_punch_count'];

            // Which dates the shift's DaysOfWeek actually covers. Derived from the
            // day-code list rather than from scheduled seconds, so a shift whose
            // times are unusable still reports is_scheduled=true with 0 hours
            // instead of silently becoming a non-workday.
            $scheduledSet = array_flip(ScheduleCalculator::scheduledDaysBetween($shift, $start, $end));

            $shiftStartSec = $shift !== null ? HoursCalculator::timeToSeconds($shift['StartTime'] ?? null) : null;
            $shiftEndSec   = $shift !== null ? HoursCalculator::timeToSeconds($shift['EndTime']   ?? null) : null;
            if ($shiftStartSec !== null && $shiftEndSec !== null && $shiftEndSec <= $shiftStartSec) {
                $shiftEndSec += 86400;   // overnight shift: the end belongs to the next day
            }
            $grace = $shift !== null ? max(0, (int)($shift['GracePeriodMinutes'] ?? 0)) : null;

            $employeeName  = trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? ''));
            $employeeEmail = $nullIfBlank($u['Email']);
            $office        = $nullIfBlank($u['Office']);

            foreach ($dates as $date) {
                $isScheduled = isset($scheduledSet[$date]);
                if ($scheduledOnly && !$isScheduled) {
                    continue;
                }

                $punchRows  = $byDate[$date] ?? [];
                $punchCount = count($punchRows);
                $dayInfo    = $daily['days'][$date] ?? null;
                $openCount  = $dayInfo !== null ? (int)$dayInfo['open_punch_count'] : 0;
                $worked     = $dayInfo !== null ? (float)$dayInfo['hours'] : 0.0;
                $off        = $offDays[$date] ?? null;

                if ($punchCount > 0) {
                    $status = $openCount > 0 ? 'incomplete' : 'present';
                } elseif ($off !== null) {
                    $status = 'time_off';
                } elseif ($isScheduled) {
                    $status = 'absent';
                } else {
                    $status = 'not_scheduled';
                }

                if ($statusFilter !== [] && !in_array($status, $statusFilter, true)) {
                    continue;
                }

                $firstInSec  = null;
                $firstIn     = null;
                $lastOutSec  = null;
                $lastOut     = null;
                $isOvernight = false;
                $notes       = false;

                foreach ($punchRows as $p) {
                    $in  = HoursCalculator::timeToSeconds($p['TimeIN']  ?? null);
                    $out = HoursCalculator::timeToSeconds($p['TimeOut'] ?? null);

                    if ($in !== null && ($firstInSec === null || $in < $firstInSec)) {
                        $firstInSec = $in;
                        $firstIn    = (string)$p['TimeIN'];
                    }
                    if ($out !== null) {
                        // Anchor each punch's clock-out after its OWN clock-in before
                        // comparing, so an overnight punch sorts after a daytime one
                        // instead of looking like the earliest event of the day.
                        $anchored = ($in !== null && $out < $in) ? $out + 86400 : $out;
                        if ($lastOutSec === null || $anchored > $lastOutSec) {
                            $lastOutSec = $anchored;
                            $lastOut    = (string)$p['TimeOut'];
                        }
                        if ($in !== null && $out < $in) {
                            $isOvernight = true;
                        }
                    }
                    if ((int)($p['HasNote'] ?? 0) === 1) {
                        $notes = true;
                    }
                }

                $minutesLate = null;
                if ($isScheduled && $firstInSec !== null && $shiftStartSec !== null) {
                    $minutesLate = $minutesBetween($firstInSec - $shiftStartSec);
                }
                $isLate = $minutesLate !== null && $grace !== null && $minutesLate > $grace;

                // An open punch means the employee may still be on the clock, so
                // the last clock-out of the day is not a departure yet.
                $minutesEarly = null;
                if ($isScheduled && $shiftEndSec !== null && $lastOutSec !== null && $openCount === 0) {
                    $minutesEarly = $minutesBetween($shiftEndSec - $lastOutSec);
                }

                $statusCounts[$status]++;
                $totalWorked += $worked;
                if ($isLate) {
                    $lateDays++;
                    // Only late days contribute: summing signed deltas would let an
                    // early arrival cancel someone else's tardiness.
                    $totalMinutesLate += $minutesLate;
                }
                if ($status === 'time_off' && !$isScheduled) {
                    $timeOffOffSchedule++;
                }

                $index = $totalCount;   // 0-based position in the full filtered grid
                $totalCount++;
                if ($index < $offset || $index >= $windowEnd) {
                    continue;           // counted and totaled, but not on this page
                }

                $rows[] = [
                    'employee_id'             => $empId,
                    'employee_email'          => $employeeEmail,
                    'employee_name'           => $employeeName,
                    'office'                  => $office,
                    'date'                    => $date,
                    'day_of_week'             => $dayOfWeek($date),
                    'is_scheduled'            => $isScheduled,
                    'scheduled_start'         => $isScheduled ? $nullIfBlank($shift['StartTime'] ?? null) : null,
                    'scheduled_end'           => $isScheduled ? $nullIfBlank($shift['EndTime']   ?? null) : null,
                    'scheduled_hours'         => $isScheduled
                        ? round(ScheduleCalculator::scheduledHoursForDate($shift, $date), 2)
                        : 0.0,
                    'grace_period_minutes'    => $grace,
                    'status'                  => $status,
                    'first_in'                => $firstIn,
                    'last_out'                => $lastOut,
                    'worked_hours'            => $worked,
                    'minutes_late'            => $minutesLate,
                    'is_late'                 => $isLate,
                    'minutes_early_departure' => $minutesEarly,
                    'is_overnight'            => $isOvernight,
                    'punch_count'             => $punchCount,
                    'time_off_type'           => $off['type'] ?? null,
                    'notes_present'           => $notes,
                ];
            }
        }

        unset($punchesByEmployeeDate, $shifts, $timeOffAll);
    }

    // ------------------------------------------------------------------ output
    $warnings = [];
    if ($noScheduleEmployees > 0) {
        $warnings[] = $noScheduleEmployees . ' employee(s) matching the filters have no active shift '
            . 'assignment. Their non-punch dates report status not_scheduled, so a genuine absence '
            . 'cannot be distinguished from a day they were never expected to work.';
    }
    if ($openPunchTotal > 0) {
        $warnings[] = $openPunchTotal . ' punch(es) in this range have no clock-out. They contribute 0 '
            . 'worked hours, their dates report status incomplete, and minutes_early_departure is null '
            . 'on those dates because the employee may still be on the clock.';
    }
    if ($timeOffOffSchedule > 0) {
        $warnings[] = $timeOffOffSchedule . ' date(s) report status time_off on a day the employee was '
            . 'not scheduled to work — a multi-day approved request covers every calendar date in its '
            . 'span, weekends included. Filter on is_scheduled if those should not count as leave taken.';
    }

    $meta = $params->meta('attendance', [
        'row_count'   => count($rows),
        'total_count' => $totalCount,
        // No overtime is allocated here, so publishing the OT defaults would
        // misrepresent what was applied.
        'rules'       => ['rounding_minutes' => $params->rounding()],
        'warnings'    => $warnings,
    ]);
    // Report-specific filters, appended to the shared filters block rather than
    // replacing it, so the published key order of meta itself never shifts.
    $meta['filters']['status']         = $statusFilter;
    $meta['filters']['scheduled_only'] = $scheduledOnly;
    // The unfiltered grid, so an integrator can see how much `status` /
    // `scheduled_only` trimmed: total_count is the filtered size, cells is not.
    $meta['grid'] = [
        'employees' => count($users),
        'dates'     => count($dates),
        'cells'     => count($users) * count($dates),
    ];

    $data = [
        'rows'   => $rows,
        'meta'   => $meta,
        'totals' => [
            'employee_days'      => $totalCount,
            'present'            => $statusCounts['present'],
            'incomplete'         => $statusCounts['incomplete'],
            'time_off'           => $statusCounts['time_off'],
            'absent'             => $statusCounts['absent'],
            'not_scheduled'      => $statusCounts['not_scheduled'],
            'late_days'          => $lateDays,
            'total_minutes_late' => round($totalMinutesLate, 2),
            'worked_hours'       => round($totalWorked, 2),
        ],
    ];

    // Pinned so a zero-row CSV still carries a header row for the importer, and so
    // column order can never drift with the first row's key order.
    Output::send($data, $params, 'attendance', [
        'employee_id', 'employee_email', 'employee_name', 'office',
        'date', 'day_of_week', 'is_scheduled', 'scheduled_start', 'scheduled_end',
        'scheduled_hours', 'grace_period_minutes', 'status', 'first_in', 'last_out',
        'worked_hours', 'minutes_late', 'is_late', 'minutes_early_departure',
        'is_overnight', 'punch_count', 'time_off_type', 'notes_present',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/attendance] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
