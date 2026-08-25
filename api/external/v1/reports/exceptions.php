<?php
/**
 * GET /api/external/v1/reports/exceptions
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * Payroll-prep data quality: the punch records that will make payroll WRONG if
 * they go out uncorrected.
 *
 * ONE ROW PER DETECTED PROBLEM — not per employee, and not per punch. A single
 * punch legitimately raises several exceptions (a 19-hour punch with half a lunch
 * is both excessive_shift and incomplete_lunch), each has a different fix, and an
 * admin has to be able to work them off one at a time. exception_id is
 * "<type>:<punch_id>", which is unique because a punch can raise each type at most
 * once, and stable across runs so a BI tool can key on it.
 *
 * TYPES, and the severity fixed to each (severity is a property of the type, never
 * of the row, so a consumer can filter on it without reading the detail text):
 *   high    missing_clock_out, overlapping_punch, negative_duration
 *   medium  excessive_shift, zero_hours, incomplete_lunch
 *   low     missing_clock_in, no_lunch_long_shift, unscheduled_day, future_punch
 *
 * THE ONE RULE THAT DECIDES WHETHER THIS REPORT IS USABLE: an open punch dated
 * TODAY is a person currently on the clock, not an exception. Flagging it would
 * put every working employee on the report every morning, the report would be
 * ignored inside a week, and the genuinely stranded punch from last Thursday would
 * be lost in the noise. missing_clock_out therefore requires the punch date to be
 * strictly BEFORE today in the report timezone.
 *
 * Worked hours come from HoursCalculator::punchWorkedSeconds() — the same
 * overnight-safe, lunch-clamping math /summary, /payroll and admin/summary.php use
 * — never from timepunches.TotalHours, which one insert path writes as a literal 0.
 * They are per PUNCH here, not per day: this report is about individual records,
 * and a payroll `rounding` policy would only blur the raw numbers an admin needs
 * to see, so `rounding` is deliberately not accepted.
 *
 * OVERLAP AT THE RANGE EDGE: punches are read from one day either side of the
 * requested range, so an overnight punch dated the day before `start` is still
 * compared against the first morning inside it. Those neighbouring punches are
 * used ONLY as overlap partners; they never produce rows of their own, and no
 * other detector sees them.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, tz, format, limit, offset, plus:
 *
 *   type                comma list over the ten type values (unknown value -> 400)
 *   severity            comma list over high|medium|low
 *   long_shift_hours    float, default 16, 0 disables excessive_shift
 *   no_lunch_threshold  float, default 6, 0 disables no_lunch_long_shift
 *
 * `totals` describe the ENTIRE filtered result set, not the returned page — a
 * grand total that changes as you page is a grand total nobody can use.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../../lib/LiveStatus.php';

/**
 * Float parser for a report-specific threshold ReportParams does not own.
 * A closure rather than a named function so two endpoints loaded in the same
 * process can never collide in the global namespace.
 *
 * 0 is accepted and means "disable this check" — the same convention ReportParams
 * uses for ot_daily/dt_daily. The ceiling is 24 because HoursCalculator clamps any
 * punch at 24 hours, so a threshold above that could never fire.
 */
$parseFloatParam = static function (array $get, string $name, float $default, float $max): float {
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
    $echo = htmlspecialchars(substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '', 0, 64), ENT_QUOTES, 'UTF-8');
    if (!preg_match('/^\d+(\.\d+)?$/', $v)) {
        ApiResponse::error(
            'Invalid parameter `' . $name . '`: "' . $echo
            . '". Expected a non-negative number of hours (0 disables this check).',
            400
        );
    }
    $f = (float)$v;
    if ($f > $max) {
        ApiResponse::error(
            'Invalid parameter `' . $name . '`: ' . $f . ' exceeds the maximum of ' . $max
            . ' hours (a single punch is clamped at 24 hours, so a higher threshold can never fire).',
            400
        );
    }
    return $f;
};

/**
 * Comma-list parser validated against a closed vocabulary. An unrecognized value
 * is a 400 rather than a silent empty result: `type=missing_punch` returning zero
 * rows looks like an outage, not like a typo.
 *
 * @return string[] deduped, lowercased; empty means "no filter on this field"
 */
$parseVocabList = static function (array $get, string $name, array $valid): array {
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
                . implode(', ', $valid) . '.',
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
    ReportBootstrap::begin('/api/external/v1/reports/exceptions', 'reports:read');

    /**
     * The catalog. Order is the tie-break order in the sort and the key order of
     * totals.by_type, so it is declared once and never rebuilt from a row set.
     */
    $typeSeverity = [
        'missing_clock_out'   => 'high',
        'overlapping_punch'   => 'high',
        'negative_duration'   => 'high',
        'excessive_shift'     => 'medium',
        'zero_hours'          => 'medium',
        'incomplete_lunch'    => 'medium',
        'missing_clock_in'    => 'low',
        'no_lunch_long_shift' => 'low',
        'unscheduled_day'     => 'low',
        'future_punch'        => 'low',
    ];
    $severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];

    /**
     * A clock-out earlier on the clock than the clock-in is read as OVERNIGHT by
     * HoursCalculator, and payroll depends on that reading. So "TimeOut < TimeIN"
     * on its own cannot be the negative_duration test — it would flag every night
     * shift in the company.
     *
     * Note that the naive form of the rule ("the wrapped gap exceeds 24h") can
     * never fire: wrapping adds exactly one day, so out + 86400 - in is always
     * strictly under 24h whenever out < in. The meaningful test is how CLOSE to
     * 24h the wrapped span lands. A real overnight shift clocks out hours after
     * midnight and well short of the following day's start time; a clock-out
     * sitting just before the clock-in on the clock face (08:00 in, 07:30 out ->
     * a 23.5h "shift") is the signature of a clock-out written on the wrong side
     * of the clock-in, which is a bad edit, not a shift anyone worked. 20 hours is
     * the ceiling: longer than any real shift, short enough to catch the mistyped
     * ones. Anything between long_shift_hours and 20h is still surfaced, as
     * excessive_shift.
     */
    $overnightCredibleMaxSeconds = 20 * 3600;

    /** Arrivals/lunches are reported to the minute; seconds are noise to an admin. */
    $hhmm = static function (?string $time): ?string {
        if ($time === null || $time === '') {
            return null;
        }
        // MySQL TIME legally holds hours above 23, so the hour is matched as up to
        // three digits rather than validated as a clock hour.
        if (preg_match('/^(-?\d{1,3}):([0-5]\d)/', trim($time), $m)) {
            return $m[1] . ':' . $m[2];
        }
        return trim($time);
    };

    $dayOfWeek = static function (string $date): string {
        // UTC: a local midnight does not exist on every spring-forward date.
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $d === false ? '' : $d->format('D');
    };

    /** Midnight UTC epoch for a punch date, memoized — the ranges repeat dates hard. */
    $midnightEpoch = static function (string $date) use (&$epochMemo): ?int {
        if (array_key_exists($date, $epochMemo)) {
            return $epochMemo[$date];
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $epochMemo[$date] = ($d === false || $d->format('Y-m-d') !== $date) ? null : $d->getTimestamp();
    };
    $epochMemo = [];

    /** Whole days from $from to $to, UTC so a DST transition cannot lose one. */
    $daysBetween = static function (string $from, string $to) use ($midnightEpoch): ?int {
        $a = $midnightEpoch($from);
        $b = $midnightEpoch($to);
        return ($a === null || $b === null) ? null : (int)(($b - $a) / 86400);
    };

    $nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'limit', 'offset', 'type', 'severity', 'long_shift_hours', 'no_lunch_threshold',
    ]);

    // Declared in $accepted above so they are not reported as ignored params, but
    // parsed here because they are specific to this report.
    $typeFilter       = $parseVocabList($_GET, 'type', array_keys($typeSeverity));
    $severityFilter   = $parseVocabList($_GET, 'severity', ['high', 'medium', 'low']);
    $longShiftHours   = $parseFloatParam($_GET, 'long_shift_hours', 16.0, 24.0);
    $noLunchThreshold = $parseFloatParam($_GET, 'no_lunch_threshold', 6.0, 24.0);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    // "Today" in the REPORT timezone, not the server's: an integrator in another
    // tz asking for their own tz must not see yesterday's still-open punches
    // flagged an hour early (or their own open punches flagged as stranded).
    $nowInstant = new DateTimeImmutable('now', $params->timezone());
    $today      = $nowInstant->format('Y-m-d');

    // One day either side, for overlap partners only. See the header note.
    $windowStart = $start;
    $windowEnd   = $end;
    $ws = DateTimeImmutable::createFromFormat('!Y-m-d', $start, new DateTimeZone('UTC'));
    $we = DateTimeImmutable::createFromFormat('!Y-m-d', $end, new DateTimeZone('UTC'));
    if ($ws !== false) { $windowStart = $ws->modify('-1 day')->format('Y-m-d'); }
    if ($we !== false) { $windowEnd   = $we->modify('+1 day')->format('Y-m-d'); }

    $filter = $params->buildUserFilter('u');

    // ------------------------------------------------------------------ roster
    // Only employees who actually have punches in the window can raise a punch
    // exception, so the roster is narrowed by EXISTS rather than by pulling every
    // user. EXISTS rides idx_employee_date (EmployeeID, Date).
    $rosterSql = "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office
                  FROM users u
                  WHERE EXISTS (
                      SELECT 1 FROM timepunches tp
                      WHERE tp.EmployeeID = u.ID AND tp.Date BETWEEN ? AND ?
                  )"
        . ($filter['sql'] !== '' ? " AND {$filter['sql']}" : '')
        . " ORDER BY u.LastName ASC, u.FirstName ASC, u.ID ASC";

    $stmt = $conn->prepare($rosterSql);
    // Types string is 'ss' for the two dates plus whatever buildUserFilter needs,
    // in exactly the order the placeholders appear above.
    $stmt->bind_param(
        'ss' . $filter['types'],
        ...array_merge([$windowStart, $windowEnd], $filter['values'])
    );
    $stmt->execute();
    $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // --------------------------------------------------------------- detection
    $rows                = [];
    $noScheduleEmployees = 0;
    $neighbourPunches    = 0;

    /** Type + severity filters applied at emit time, so totals match the rows. */
    $accepted = static function (string $type) use ($typeSeverity, $typeFilter, $severityFilter): bool {
        if ($typeFilter !== [] && !in_array($type, $typeFilter, true)) {
            return false;
        }
        if ($severityFilter !== [] && !in_array($typeSeverity[$type], $severityFilter, true)) {
            return false;
        }
        return true;
    };

    $buildRow = static function (
        string $type,
        array $fact,
        array $emp,
        string $detail,
        string $action
    ) use ($typeSeverity, $dayOfWeek, $hhmm): array {
        return [
            // type + punch id: unique (a punch raises each type at most once) and
            // stable across runs, which is what a BI tool needs to key on.
            'exception_id'     => $type . ':' . $fact['punch_id'],
            'type'             => $type,
            'severity'         => $typeSeverity[$type],
            'punch_id'         => $fact['punch_id'],
            'employee_id'      => $emp['id'],
            'employee_email'   => $emp['email'],
            'employee_name'    => $emp['name'],
            'office'           => $emp['office'],
            // Not in the minimum field list, but unscheduled_day is silently
            // un-evaluable without a shift; without this column a consumer cannot
            // tell "worked their schedule" from "has no schedule to work".
            'has_schedule'     => $emp['has_schedule'],
            'date'             => $fact['date'],
            'day_of_week'      => $dayOfWeek($fact['date']),
            'time_in'          => $hhmm($fact['time_in']),
            'lunch_start'      => $hhmm($fact['lunch_start']),
            'lunch_end'        => $hhmm($fact['lunch_end']),
            'time_out'         => $hhmm($fact['time_out']),
            'worked_hours'     => $fact['worked_hours'],
            'detail'           => $detail,
            'suggested_action' => $action,
        ];
    };

    // Chunked by employee so only one chunk's punches are ever resident. 25 x 402
    // days bounds a chunk the same way /reports/attendance does.
    foreach (array_chunk($roster, 25) as $chunk) {
        $ids = array_map(static fn($u) => (int)$u['ID'], $chunk);

        // Placeholders are built from the COUNT of already-int-cast ids; the values
        // themselves only ever travel through bind_param.
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT tp.id, tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut
             FROM timepunches tp
             WHERE tp.Date BETWEEN ? AND ?
               AND tp.EmployeeID IN ($ph)
             ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.TimeIN ASC, tp.id ASC"
        );
        $stmt->bind_param(
            'ss' . str_repeat('i', count($ids)),
            ...array_merge([$windowStart, $windowEnd], $ids)
        );
        $stmt->execute();
        $res = $stmt->get_result();

        $punchesByEmployee = [];
        while ($p = $res->fetch_assoc()) {
            $punchesByEmployee[(int)$p['EmployeeID']][] = $p;
        }
        $res->free();
        $stmt->close();

        $shifts = ScheduleCalculator::loadShiftsForUsers($conn, $ids);

        foreach ($chunk as $u) {
            $eid   = (int)$u['ID'];
            $shift = $shifts[$eid] ?? null;

            $emp = [
                'id'           => $eid,
                'email'        => $nullIfBlank($u['Email']),
                'name'         => trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? '')),
                'office'       => $nullIfBlank($u['Office']),
                'has_schedule' => $shift !== null,
            ];
            if ($shift === null) {
                $noScheduleEmployees++;
            }

            // Derived from the day-code list rather than from scheduled seconds, so
            // a shift whose TIME columns are unusable still counts its days as
            // scheduled instead of turning the whole week into unscheduled_day rows.
            $scheduledSet = $shift !== null
                ? array_flip(ScheduleCalculator::scheduledDaysBetween($shift, $start, $end))
                : [];

            // ---------------------------------------------------- per-punch facts
            $facts = [];
            foreach ($punchesByEmployee[$eid] ?? [] as $p) {
                $date   = (string)$p['Date'];
                $inSec  = HoursCalculator::timeToSeconds($p['TimeIN']);
                $outSec = HoursCalculator::timeToSeconds($p['TimeOut']);

                // The shared calculator, never a second implementation: overnight
                // wrap, lunch anchoring, the lunch clamp and the 24h ceiling all
                // have to mean the same thing here as they do in payroll.
                $calc = HoursCalculator::punchWorkedSeconds($p);

                $inRange = ($date >= $start && $date <= $end);
                if (!$inRange) {
                    $neighbourPunches++;
                }

                $span = null;
                if ($inSec !== null && $outSec !== null) {
                    $span = ($outSec < $inSec ? $outSec + 86400 : $outSec) - $inSec;
                }

                $epoch    = $midnightEpoch($date);
                $absStart = ($epoch !== null && $inSec !== null) ? $epoch + $inSec : null;
                $absEnd   = ($absStart !== null && $span !== null) ? $absStart + $span : null;

                $facts[] = [
                    'punch_id'    => (int)$p['id'],
                    'date'        => $date,
                    'in_range'    => $inRange,
                    'time_in'     => $p['TimeIN'],
                    'lunch_start' => $p['LunchStart'],
                    'lunch_end'   => $p['LunchEnd'],
                    'time_out'    => $p['TimeOut'],
                    'in_sec'      => $inSec,
                    'out_sec'     => $outSec,
                    'span'        => $span,
                    'abs_start'   => $absStart,
                    'abs_end'     => $absEnd,
                    // null, not 0.0: a punch missing an end did not work zero hours,
                    // it has no computable duration at all, and 0 would read as a
                    // measured fact. Rounded through the shared rounder at 2dp.
                    'worked_hours' => ($inSec !== null && $outSec !== null)
                        ? HoursCalculator::roundHours(
                            HoursCalculator::secondsToHours($calc['seconds']), 0
                        )
                        : null,
                ];
            }

            // -------------------------------------------------------- overlaps
            // Compared as ABSOLUTE instants (punch date + time, overnight-aware),
            // so a punch ending 02:00 the next morning is measured against the next
            // day's punches instead of looking like an early-morning punch on its
            // own date. Zero-length punches are excluded — they cannot overlap
            // anything, and zero_hours already reports them. Touching boundaries
            // (one ends exactly when the next begins) are NOT an overlap.
            $intervals = [];
            foreach ($facts as $i => $f) {
                if ($f['abs_start'] !== null && $f['abs_end'] !== null && $f['abs_end'] > $f['abs_start']) {
                    $intervals[] = $i;
                }
            }
            usort($intervals, static function ($a, $b) use ($facts) {
                return [$facts[$a]['abs_start'], $facts[$a]['punch_id']]
                   <=> [$facts[$b]['abs_start'], $facts[$b]['punch_id']];
            });

            $overlaps = [];   // fact index => ['count' => n, 'partner' => fact index]
            $n = count($intervals);
            for ($x = 0; $x < $n; $x++) {
                $i = $intervals[$x];
                for ($y = $x + 1; $y < $n; $y++) {
                    $j = $intervals[$y];
                    // Sorted by start: once one punch begins at or after i's end,
                    // no later punch can overlap i either.
                    if ($facts[$j]['abs_start'] >= $facts[$i]['abs_end']) {
                        break;
                    }
                    foreach ([[$i, $j], [$j, $i]] as [$self, $other]) {
                        if (!isset($overlaps[$self])) {
                            $overlaps[$self] = ['count' => 0, 'partner' => $other];
                        }
                        $overlaps[$self]['count']++;
                    }
                }
            }

            /** "2026-07-26 20:09-20:10" / "... (+1d)" for an overnight partner. */
            $intervalLabel = static function (array $f) use ($hhmm): string {
                $out = $hhmm($f['time_out']);
                if ($out !== null && $f['in_sec'] !== null && $f['out_sec'] !== null
                    && $f['out_sec'] < $f['in_sec']) {
                    $out .= ' (+1d)';
                }
                return $f['date'] . ' ' . ($hhmm($f['time_in']) ?? '??:??') . '-' . ($out ?? '??:??');
            };

            // --------------------------------------------------------- detectors
            $datesSeen = [];

            foreach ($facts as $i => $f) {
                if (!$f['in_range']) {
                    continue;   // neighbour day: an overlap partner only
                }

                $punchNo   = '#' . $f['punch_id'];
                $inLabel   = $hhmm($f['time_in']);
                $outLabel  = $hhmm($f['time_out']);
                $worked    = $f['worked_hours'];
                $workedTxt = $worked === null ? 'n/a' : number_format($worked, 2);

                $emit = static function (string $type, string $detail, string $action)
                    use (&$rows, $accepted, $buildRow, $f, $emp): void {
                    if ($accepted($type)) {
                        $rows[] = $buildRow($type, $f, $emp, $detail, $action);
                    }
                };

                // future_punch first: a punch dated ahead of today is a data error
                // in its own right, and it also explains why the others stay quiet.
                if ($f['date'] > $today) {
                    $ahead = $daysBetween($today, $f['date']);
                    $emit(
                        'future_punch',
                        'Punch is dated ' . $f['date'] . ', ' . ($ahead === null ? 'after' : $ahead . ' day(s) after')
                        . ' today (' . $today . '); it cannot describe work that has happened.',
                        'Correct the punch date'
                    );
                }

                // An open punch dated TODAY is someone currently working. Only a
                // punch left open on a PAST day is stranded. See the header note.
                //
                // That rule alone false-positives on an overnight shift: someone on
                // a 22:00-06:00 shift clocks in on date D, and a request made at, say,
                // 01:30 the NEXT calendar day sees Date=D < today and flags a person
                // who is still actively clocked in and will close normally at 06:00 —
                // every overnight worker, every night, on the report's own
                // highest-severity type. Before flagging, check whether this punch's
                // date is exactly the start date of the employee's CURRENT shift
                // instance (LiveStatus::currentShiftInstance — the same overnight-aware
                // resolution /live/status and /live/headcount already use) and that
                // instance has not ended yet; if so this is functionally still a
                // same-day open punch and gets the same pass, not a stranded one.
                if ($f['in_sec'] !== null && $f['out_sec'] === null && $f['date'] < $today) {
                    $stillRunningOvernight = false;
                    if ($shift !== null) {
                        $instance = LiveStatus::currentShiftInstance($shift, $nowInstant, $params->timezone());
                        if ($instance !== null
                            && $instance['start']->format('Y-m-d') === $f['date']
                            && $nowInstant < $instance['end']
                        ) {
                            $stillRunningOvernight = true;
                        }
                    }

                    if (!$stillRunningOvernight) {
                        $age = $daysBetween($f['date'], $today);
                        $emit(
                            'missing_clock_out',
                            'Clocked in ' . ($inLabel ?? 'unknown') . ' with no clock-out; punch is '
                            . ($age === null ? 'older than today' : $age . ' day(s) old')
                            . ' and contributes 0 paid hours until it is closed.',
                            'Add a clock-out or delete the punch'
                        );
                    }
                }

                if ($f['out_sec'] !== null && $f['in_sec'] === null) {
                    $emit(
                        'missing_clock_in',
                        'Clocked out ' . ($outLabel ?? 'unknown') . ' with no clock-in, so no worked '
                        . 'time can be computed for this punch.',
                        'Add a clock-in or delete the punch'
                    );
                }

                if ($f['in_sec'] !== null && $f['out_sec'] !== null
                    && $f['out_sec'] < $f['in_sec']
                    && $f['span'] !== null && $f['span'] >= $overnightCredibleMaxSeconds) {
                    $emit(
                        'negative_duration',
                        'Clocked out ' . ($outLabel ?? 'unknown') . ', earlier on the clock than the '
                        . ($inLabel ?? 'unknown') . ' clock-in; read as an overnight shift that is '
                        . number_format($f['span'] / 3600, 2) . ' h, which is not a shift anyone worked.',
                        'Correct the clock-out time'
                    );
                }

                if ($worked !== null && $longShiftHours > 0 && $worked > $longShiftHours) {
                    $emit(
                        'excessive_shift',
                        'Worked ' . $workedTxt . ' h on this punch (' . ($inLabel ?? '??') . ' to '
                        . ($outLabel ?? '??') . '), above the ' . number_format($longShiftHours, 2)
                        . ' h long-shift threshold.',
                        'Verify the clock-out time and correct it'
                    );
                }

                // Flagged on the PUBLISHED worked_hours, so the report can never
                // show a row reading 0.00 h that it did not also flag. That means a
                // 40-second mis-punch counts as zero, which is exactly right.
                if ($worked !== null && $worked === 0.0) {
                    $emit(
                        'zero_hours',
                        'Clocked in ' . ($inLabel ?? '??') . ' and out ' . ($outLabel ?? '??')
                        . ' with computed worked time of 0.00 h'
                        . ($f['lunch_start'] !== null && $f['lunch_end'] !== null
                            ? ' after the recorded lunch is deducted.' : '.'),
                        'Delete the punch or correct its times'
                    );
                }

                $hasLunchStart = $f['lunch_start'] !== null && $f['lunch_start'] !== '';
                $hasLunchEnd   = $f['lunch_end']   !== null && $f['lunch_end']   !== '';

                if ($hasLunchStart !== $hasLunchEnd) {
                    // Exactly one end recorded. The calculator deducts NOTHING in
                    // this case (guessing a duration would invent data), so the
                    // punch is being paid straight through its break.
                    $emit(
                        'incomplete_lunch',
                        ($hasLunchStart
                            ? 'Lunch start ' . $hhmm($f['lunch_start']) . ' recorded with no lunch end'
                            : 'Lunch end ' . $hhmm($f['lunch_end']) . ' recorded with no lunch start')
                        . '; no break was deducted from the ' . $workedTxt . ' h on this punch.',
                        'Complete the missing lunch time or clear both'
                    );
                } elseif (!$hasLunchStart && $worked !== null
                          && $noLunchThreshold > 0 && $worked >= $noLunchThreshold) {
                    $emit(
                        'no_lunch_long_shift',
                        'Worked ' . $workedTxt . ' h with no lunch recorded, at or above the '
                        . number_format($noLunchThreshold, 2) . ' h threshold.',
                        'Add the lunch break or confirm none was taken'
                    );
                }

                if (isset($overlaps[$i])) {
                    $o       = $overlaps[$i];
                    $partner = $facts[$o['partner']];
                    $emit(
                        'overlapping_punch',
                        'Worked interval ' . $intervalLabel($f) . ' overlaps punch #' . $partner['punch_id']
                        . ' (' . $intervalLabel($partner) . ')'
                        . ($o['count'] > 1 ? ' and ' . ($o['count'] - 1) . ' other punch(es)' : '')
                        . ' for the same employee, so those hours are paid twice.',
                        'Merge or delete one of the overlapping punches'
                    );
                }

                // Day-level, not punch-level: two punches on one unscheduled
                // Saturday are ONE problem. Anchored on the first punch of the date
                // (the query orders by Date, TimeIN, id) so exception_id stays
                // unique and stable.
                if ($emp['has_schedule'] && !isset($datesSeen[$f['date']])
                    && !isset($scheduledSet[$f['date']])) {
                    $shiftName = (string)($shift['ShiftName'] ?? 'assigned shift');
                    $emit(
                        'unscheduled_day',
                        'Punch on ' . $dayOfWeek($f['date']) . ' ' . $f['date']
                        . ', a day shift "' . $shiftName . '" does not cover.',
                        'Confirm the work was authorized or fix the shift assignment'
                    );
                }
                $datesSeen[$f['date']] = true;
            }
        }

        unset($punchesByEmployee, $shifts);
    }

    // ------------------------------------------------------------------- totals
    // Accumulated over EVERY matching row, before paging, so the headline counts
    // do not change as an integrator walks the pages.
    $byType     = array_fill_keys(array_keys($typeSeverity), 0);
    $bySeverity = ['high' => 0, 'medium' => 0, 'low' => 0];
    $affected   = [];

    foreach ($rows as $r) {
        $byType[$r['type']]++;
        $bySeverity[$r['severity']]++;
        $affected[$r['employee_id']] = true;
    }

    // High first, then the newest problem, then a stable employee/type/punch tail
    // so the same request always returns the same page.
    usort($rows, static function (array $a, array $b) use ($severityRank, $typeSeverity) {
        $c = $severityRank[$a['severity']] <=> $severityRank[$b['severity']];
        if ($c !== 0) return $c;
        $c = strcmp($b['date'], $a['date']);                // date desc
        if ($c !== 0) return $c;
        $c = strcmp($a['employee_name'], $b['employee_name']);
        if ($c !== 0) return $c;
        $c = array_search($a['type'], array_keys($typeSeverity), true)
         <=> array_search($b['type'], array_keys($typeSeverity), true);
        if ($c !== 0) return $c;
        return $a['punch_id'] <=> $b['punch_id'];
    });

    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    // ----------------------------------------------------------------- warnings
    $warnings = [
        // Stated on every response, not only when something was found: the punch
        // this report cannot see is the expensive one.
        'This report only examines punches dated ' . $start . ' to ' . $end . '. A punch left open '
        . 'before ' . $start . ' — the most expensive kind of missing clock-out — will not appear '
        . 'here. Widen the range to find it.',
    ];
    if ($noScheduleEmployees > 0) {
        $warnings[] = $noScheduleEmployees . ' employee(s) with punches in this range have no active '
            . 'assigned shift (has_schedule=false). unscheduled_day cannot be evaluated for them, so '
            . 'work on a day they were never scheduled for is not reported.';
    }
    if ($neighbourPunches > 0) {
        $warnings[] = $neighbourPunches . ' punch(es) dated one day outside the range were read as '
            . 'overlap partners so an overnight punch at the boundary is still compared. They raise '
            . 'no exceptions of their own — request the adjacent range to check them.';
    }

    $meta = $params->meta('exceptions', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        // No rounding and no overtime allocation happen here, so publishing the
        // shared rules block would misrepresent what was applied.
        'rules'       => [
            'long_shift_hours'          => $longShiftHours,
            'no_lunch_threshold'        => $noLunchThreshold,
            'open_punch_grace'          => 'a punch dated today with no clock-out is treated as work in '
                                           . 'progress, not as a missing clock-out',
            'overnight_credible_max_hours' => $overnightCredibleMaxSeconds / 3600,
        ],
        'warnings'    => $warnings,
    ]);
    // Report-specific filters, appended to the shared filters block rather than
    // replacing it, so the published key order of meta itself never shifts.
    $meta['filters']['type']     = $typeFilter;
    $meta['filters']['severity'] = $severityFilter;

    $data = [
        'rows'   => $page,
        'meta'   => $meta,
        'totals' => [
            'exceptions'         => $totalCount,
            'employees_affected' => count($affected),
            // Every type is published, including the zeros: a fixed key set is what
            // lets a dashboard bind columns once instead of re-deriving them.
            'by_type'            => $byType,
            'by_severity'        => $bySeverity,
        ],
    ];

    // Pinned so a zero-row CSV still carries a header row for the importer, and so
    // column order can never drift with the first row's key order.
    Output::send($data, $params, 'exceptions', [
        'exception_id', 'type', 'severity', 'punch_id', 'employee_id', 'employee_email',
        'employee_name', 'office', 'has_schedule', 'date', 'day_of_week',
        'time_in', 'lunch_start', 'lunch_end', 'time_out', 'worked_hours',
        'detail', 'suggested_action',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/exceptions] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
