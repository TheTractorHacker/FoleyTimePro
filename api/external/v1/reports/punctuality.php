<?php
/**
 * GET /api/external/v1/reports/punctuality
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * Arrival/departure behaviour per employee over the range, measured against each
 * employee's OWN scheduled shift (a user has at most one ACTIVE shift —
 * user_shifts.UserID is UNIQUE, the same rule ScheduleCalculator enforces
 * everywhere else on this surface).
 *
 * REQUIRES AN ACTIVE ASSIGNED SHIFT. An employee with none is excluded from this
 * report ENTIRELY — not emitted with nulls, not counted in totals — because there
 * is no scheduled start to measure lateness against; a null-filled row would read
 * as "on time, worked nothing" rather than "unmeasurable". How many were excluded
 * is stated in meta.warnings so a consumer cannot mistake "not late" for "everyone
 * accounted for".
 *
 * WHAT COUNTS AS A MEASURED DAY: a date named by shifts.DaysOfWeek inside
 * [start,end] on which the employee has a punch bearing a usable TimeIN. A
 * scheduled day with no punch at all is an absence, not a punctuality data point,
 * and is left out of days_worked and every rate derived from it — the same way
 * /reports/utilization keeps an absence out of the utilization denominator instead
 * of scoring it 0%.
 *
 * LATE vs EARLY vs ON TIME, for one measured day:
 *   minutes_late = (first TimeIN of the day) − shifts.StartTime, in minutes.
 *     late     minutes_late  > GracePeriodMinutes — the shift's own authoritative
 *              grace window; the app treats it as authoritative elsewhere and this
 *              report does too, rather than re-deriving a different tolerance.
 *     early    minutes_late  < -5   (arrived more than 5 minutes before start)
 *     on_time  everything between, INCLUDING up to `grace` minutes late or up to
 *              5 minutes early — both are within the shift's own tolerance and are
 *              not behaviour worth flagging.
 *
 * EARLY DEPARTURE mirrors the arrival rule with the SAME 5-minute buffer. The task
 * gives no separate departure threshold, so reusing the one number it does give us
 * is a smaller leap than inventing an unrelated one — documented here rather than
 * silently assumed. A day's departure (last TimeOut of the day, when present) is
 * an early departure when it lands more than 5 minutes before shifts.EndTime.
 * Overnight shifts (EndTime <= StartTime) are handled the same overnight-safe way
 * HoursCalculator wraps a clock-out: the scheduled end and the actual departure
 * are both measured in seconds past the ARRIVAL day's midnight, wrapping past
 * 86400 when they cross into the next day. A day with no TimeOut (still clocked
 * in) contributes no departure data — it is not an error and is not counted.
 *
 * first_in_median is the median arrival time over the employee's measured days,
 * formatted HH:MM. The median, not the mean: one very early or very late outlier
 * must not drag a "typical arrival time" around the way a mean would.
 *
 * `totals` describe the ENTIRE filtered population (every included employee),
 * never just the returned page, so a grand total never changes as you page.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, tz, format, limit, offset.
 * `rounding` is deliberately not accepted: nothing here is a payroll hours figure,
 * and a rounding policy would only blur the minute-level numbers this report is
 * about.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

try {
    ReportBootstrap::begin('/api/external/v1/reports/punctuality', 'reports:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office',
        'include_inactive', 'limit', 'offset',
    ]);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    // Fixed, not query params: the task defines "early" arrival at exactly 5
    // minutes with no knob to move it (unlike /reports/exceptions'
    // long_shift_hours/no_lunch_threshold, which the task DOES make tunable).
    // The departure buffer reuses the same number — see the header note.
    $earlyArrivalBufferMinutes   = 5;
    $earlyDepartureBufferMinutes = 5;

    $filter      = $params->buildUserFilter('u');
    $rosterWhere = $filter['sql'] !== '' ? $filter['sql'] : '1=1';

    // Not paginated in SQL: totals and worst_offender need the WHOLE filtered
    // population (same reasoning as /reports/utilization), so pagination is
    // applied to the shaped rows at the very end instead.
    $stmt = $conn->prepare(
        "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office
         FROM users u
         WHERE $rosterWhere
         ORDER BY u.FirstName ASC, u.LastName ASC, u.ID ASC"
    );
    if ($filter['types'] !== '') {
        $stmt->bind_param($filter['types'], ...$filter['values']);
    }
    $stmt->execute();
    $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rosterIds = array_map(static fn($u) => (int)$u['ID'], $roster);

    // At most one ACTIVE shift per user; an employee absent from this map has no
    // active assignment and is excluded below.
    $shifts = $rosterIds === [] ? [] : ScheduleCalculator::loadShiftsForUsers($conn, $rosterIds);

    // Only employees who HAVE an active shift can appear in this report at all, so
    // only their punches are worth fetching.
    $scheduledIds = array_values(array_filter($rosterIds, static fn($id) => isset($shifts[$id])));

    $punchesByEmployeeDate = [];
    if ($scheduledIds !== []) {
        $ph = implode(',', array_fill(0, count($scheduledIds), '?'));
        $stmt = $conn->prepare(
            "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.TimeOut
             FROM timepunches tp
             WHERE tp.Date BETWEEN ? AND ?
               AND tp.EmployeeID IN ($ph)
             ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.TimeIN ASC, tp.id ASC"
        );
        $stmt->bind_param(
            'ss' . str_repeat('i', count($scheduledIds)),
            ...array_merge([$start, $end], $scheduledIds)
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($p = $res->fetch_assoc()) {
            $punchesByEmployeeDate[(int)$p['EmployeeID']][(string)$p['Date']][] = $p;
        }
        $res->free();
        $stmt->close();
    }

    $nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

    /**
     * Seconds-since-midnight -> "HH:MM", wrapping defensively. MySQL TIME legally
     * allows values outside 0-24h (HoursCalculator::timeToSeconds does not reject
     * them); a real arrival stamp never should be, but this keeps a malformed row
     * from producing a garbage string instead of a clock-face reading.
     */
    $hhmm = static function (?int $sec): ?string {
        if ($sec === null) {
            return null;
        }
        $wrapped = (($sec % 86400) + 86400) % 86400;
        return sprintf('%02d:%02d', intdiv($wrapped, 3600), intdiv($wrapped % 3600, 60));
    };

    /** Median of seconds-since-midnight; an even count averages the two middle
     *  values rather than arbitrarily picking one. */
    $median = static function (array $values): ?int {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return (int)$values[$mid];
        }
        return (int)round(($values[$mid - 1] + $values[$mid]) / 2);
    };

    $rows                = [];
    $excludedNoSchedule  = 0;
    $excludedUnusableTod = 0;   // active shift, but StartTime is unusable — cannot measure lateness

    foreach ($roster as $u) {
        $eid   = (int)$u['ID'];
        $shift = $shifts[$eid] ?? null;
        if ($shift === null) {
            $excludedNoSchedule++;
            continue;
        }

        $scheduledStart = HoursCalculator::timeToSeconds($shift['StartTime'] ?? null);
        if ($scheduledStart === null) {
            // Malformed StartTime (bad hand edit / bad import): nothing to measure
            // lateness against, so this employee is unmeasurable exactly like one
            // with no shift at all — not silently coerced to a 0 baseline.
            $excludedUnusableTod++;
            continue;
        }

        // Overnight-safe span, mirroring ScheduleCalculator::scheduledSecondsForDate's
        // own rule: EndTime at or before StartTime means the shift crosses
        // midnight. A null/unusable EndTime just disables the departure check
        // below — arrival/lateness measurement is unaffected.
        $scheduledEndRaw = HoursCalculator::timeToSeconds($shift['EndTime'] ?? null);
        $scheduledEnd    = null;
        if ($scheduledEndRaw !== null) {
            $span = $scheduledEndRaw <= $scheduledStart
                ? ($scheduledEndRaw + 86400 - $scheduledStart)
                : ($scheduledEndRaw - $scheduledStart);
            $scheduledEnd = $scheduledStart + $span;
        }

        $grace = max(0, (int)($shift['GracePeriodMinutes'] ?? 0));

        $scheduledDates  = ScheduleCalculator::scheduledDaysBetween($shift, $start, $end);
        $datesForEmployee = $punchesByEmployeeDate[$eid] ?? [];

        $daysWorked         = 0;
        $onTime              = 0;
        $late                = 0;
        $early               = 0;
        $lateMinutesSum      = 0.0;
        $lateMinutesMax      = 0.0;
        $earlyDepartures     = 0;
        $earlyDepMinutesSum  = 0.0;
        $arrivals            = [];

        foreach ($scheduledDates as $date) {
            $dayPunches = $datesForEmployee[$date] ?? [];
            if ($dayPunches === []) {
                continue;   // absence: not a punctuality data point
            }

            $arrivalSec = null;
            foreach ($dayPunches as $p) {
                $t = HoursCalculator::timeToSeconds($p['TimeIN'] ?? null);
                if ($t !== null && ($arrivalSec === null || $t < $arrivalSec)) {
                    $arrivalSec = $t;
                }
            }
            if ($arrivalSec === null) {
                continue;   // no usable clock-in this date; nothing to measure
            }

            $daysWorked++;
            $arrivals[] = $arrivalSec;

            $minutesLate = ($arrivalSec - $scheduledStart) / 60.0;
            if ($minutesLate > $grace) {
                $late++;
                $lateMinutesSum += $minutesLate;
                if ($minutesLate > $lateMinutesMax) {
                    $lateMinutesMax = $minutesLate;
                }
            } elseif ($minutesLate < -$earlyArrivalBufferMinutes) {
                $early++;
            } else {
                $onTime++;
            }

            if ($scheduledEnd === null) {
                continue;   // shift has no usable EndTime; departure not evaluated
            }
            $departureSec = null;
            foreach ($dayPunches as $p) {
                $t = HoursCalculator::timeToSeconds($p['TimeOut'] ?? null);
                if ($t !== null && ($departureSec === null || $t > $departureSec)) {
                    $departureSec = $t;
                }
            }
            if ($departureSec === null) {
                continue;   // still clocked in / no clock-out this date: no departure data
            }
            // Overnight-safe, same wrap rule HoursCalculator uses for a clock-out:
            // anchored to the ARRIVAL day's midnight so an overnight shift's real
            // departure the next morning is compared against the right instant.
            $actualOut    = $departureSec < $arrivalSec ? $departureSec + 86400 : $departureSec;
            $minutesEarly = ($scheduledEnd - $actualOut) / 60.0;
            if ($minutesEarly > $earlyDepartureBufferMinutes) {
                $earlyDepartures++;
                $earlyDepMinutesSum += $minutesEarly;
            }
        }

        $rows[] = [
            'employee_id'                 => $eid,
            'employee_email'              => $nullIfBlank($u['Email']),
            'employee_name'               => trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? '')),
            'office'                      => $nullIfBlank($u['Office']),
            'shift_name'                  => $nullIfBlank($shift['ShiftName'] ?? null),
            'grace_period_minutes'        => $grace,
            'scheduled_days'              => count($scheduledDates),
            'days_worked'                 => $daysWorked,
            'on_time_days'                => $onTime,
            'late_days'                   => $late,
            'early_days'                  => $early,
            'late_rate'                   => $daysWorked > 0 ? round($late / $daysWorked * 100, 2) : null,
            // Mean over LATE days ONLY — folding in on-time/early days would
            // understate how late a late arrival typically runs.
            'avg_minutes_late'            => $late > 0 ? round($lateMinutesSum / $late, 2) : null,
            'max_minutes_late'            => $late > 0 ? round($lateMinutesMax, 2) : null,
            // A sum, not a rate: 0.0 with no late days is a real fact ("no
            // lateness"), unlike the null averages above which have no defined
            // value at all when there is nothing to average.
            'total_minutes_late'          => round($lateMinutesSum, 2),
            'early_departures'            => $earlyDepartures,
            'avg_minutes_early_departure' => $earlyDepartures > 0
                ? round($earlyDepMinutesSum / $earlyDepartures, 2)
                : null,
            'first_in_median'             => $hhmm($median($arrivals)),
        ];
    }

    // ------------------------------------------------------------------- totals
    $totalScheduledDays = (int)array_sum(array_column($rows, 'scheduled_days'));
    $totalLateDays      = (int)array_sum(array_column($rows, 'late_days'));
    $totalMinutesLate   = (float)array_sum(array_column($rows, 'total_minutes_late'));

    // Highest late_rate wins; ties broken by more late days, then name, so the
    // same filtered population always names the same worst offender.
    $candidates = array_values(array_filter($rows, static fn($r) => $r['late_rate'] !== null));
    usort($candidates, static function (array $a, array $b): int {
        $c = $b['late_rate'] <=> $a['late_rate'];
        if ($c !== 0) { return $c; }
        $c = $b['late_days'] <=> $a['late_days'];
        if ($c !== 0) { return $c; }
        return $a['employee_name'] <=> $b['employee_name'];
    });
    $worstOffender = $candidates === [] ? null : [
        'employee_name' => $candidates[0]['employee_name'],
        'late_rate'     => $candidates[0]['late_rate'],
    ];

    $totals = [
        'employees'          => count($rows),
        'scheduled_days'     => $totalScheduledDays,
        'late_days'          => $totalLateDays,
        'total_minutes_late' => round($totalMinutesLate, 2),
        'worst_offender'     => $worstOffender,
    ];

    // --------------------------------------------------------------- pagination
    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    // ------------------------------------------------------------------ warnings
    $warnings = [];
    if ($excludedNoSchedule > 0) {
        $warnings[] = $excludedNoSchedule . ' employee(s) matching the filters have no active assigned '
            . 'shift and are excluded from this report entirely (rows and totals) — there is no scheduled '
            . 'start to measure arrival against.';
    }
    if ($excludedUnusableTod > 0) {
        $warnings[] = $excludedUnusableTod . ' employee(s) have an active shift with an unusable '
            . 'StartTime and are excluded the same way as an employee with no shift.';
    }

    $meta = $params->meta('punctuality', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        // The shared rules block (rounding/week_start/ot_*) describes payroll
        // allocation this report never performs; publishing it here would claim
        // rules that were never applied. These are the rules THIS report applies.
        'rules'       => [
            'late_threshold'                  => 'minutes_late > shift GracePeriodMinutes '
                . '(per employee — see each row\'s grace_period_minutes)',
            'early_arrival_buffer_minutes'    => $earlyArrivalBufferMinutes,
            'early_departure_buffer_minutes'  => $earlyDepartureBufferMinutes,
        ],
        'warnings'    => $warnings,
    ]);

    $data = [
        'rows'   => $page,
        'meta'   => $meta,
        'totals' => $totals,
    ];

    // Pinned so a zero-row CSV still carries a header row for the importer, and so
    // column order can never drift with the first row's key order.
    Output::send($data, $params, 'punctuality', [
        'employee_id', 'employee_email', 'employee_name', 'office', 'shift_name',
        'grace_period_minutes', 'scheduled_days', 'days_worked', 'on_time_days',
        'late_days', 'early_days', 'late_rate', 'avg_minutes_late', 'max_minutes_late',
        'total_minutes_late', 'early_departures', 'avg_minutes_early_departure',
        'first_in_median',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/punctuality] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
