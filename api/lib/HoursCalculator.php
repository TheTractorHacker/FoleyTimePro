<?php
/**
 * HoursCalculator — worked-hours + overtime math for /api/external/v1 reports.
 *
 * TIMEZONE / DST POLICY (read before touching this file):
 * timepunches stores a DATE plus bare TIME columns, not absolute instants. There
 * is no offset recorded anywhere, so a punch on a DST transition day is genuinely
 * ambiguous. Building local DateTime objects out of (Date, TimeIN/TimeOut) and
 * subtracting them would silently add or remove an hour on the two transition
 * days each year — and would *invent* an hour of paid time that the employee did
 * not work (or delete one they did). So ALL duration math here is done in plain
 * seconds-since-midnight, which is exactly what the schema stores. Timezones are
 * used only when an absolute instant is formatted for output (see meta.generated_at
 * in ReportParams) — never for durations.
 *
 * Date arithmetic (week boundaries, day iteration) is done in UTC for the same
 * reason: a UTC midnight always exists, whereas 2026-03-08 00:00 America/Santiago
 * does not.
 */

class HoursCalculator
{
    /** A single punch longer than this is treated as bad data and clamped. */
    private const MAX_PUNCH_SECONDS = 86400;

    /** Warning codes emitted on rows / meta. Stable strings — integrators match on them. */
    public const WARN_OPEN_PUNCH       = 'open_punch';
    public const WARN_INCOMPLETE_LUNCH = 'incomplete_lunch';
    public const WARN_LUNCH_CLAMPED    = 'lunch_clamped';
    public const WARN_EXCEEDS_24H      = 'punch_exceeds_24h';

    /**
     * Standard meta warning for a range that does not align to week boundaries.
     * Centralized so every report words this identically — the understatement is
     * real and integrators must not have to guess which endpoint is honest about it.
     */
    public const PARTIAL_WEEK_WARNING =
        'One or more weeks in this range are partial: hours worked outside the requested '
        . 'start/end are not counted, so weekly overtime for those weeks may be understated.';

    /**
     * Worked seconds for one punch row.
     *
     * $punch keys: TimeIN, LunchStart, LunchEnd, TimeOut (TimeOUT also accepted —
     * legacy call sites in this codebase spell the column both ways).
     *
     * @param array $punch
     * @return array{seconds: int, warnings: string[]}
     */
    public static function punchWorkedSeconds(array $punch): array
    {
        $warnings = [];

        $in  = self::timeToSeconds($punch['TimeIN'] ?? null);
        $out = self::timeToSeconds($punch['TimeOut'] ?? ($punch['TimeOUT'] ?? null));

        // Open/incomplete punch: contributes zero hours but must be surfaced,
        // otherwise a still-clocked-in employee silently looks like a no-show.
        if ($in === null || $out === null) {
            return ['seconds' => 0, 'warnings' => [self::WARN_OPEN_PUNCH]];
        }

        // Overnight shift: the clock-out TIME lives on the clock-in Date row.
        if ($out < $in) {
            $out += 86400;
        }
        $span = $out - $in;

        $lunchStart = self::timeToSeconds($punch['LunchStart'] ?? null);
        $lunchEnd   = self::timeToSeconds($punch['LunchEnd'] ?? null);

        $lunch = 0;
        if ($lunchStart !== null && $lunchEnd !== null) {
            // Anchor both ends after the clock-in so an overnight lunch subtracts
            // the right amount instead of a negative or a ~23h "lunch".
            if ($lunchStart < $in)         { $lunchStart += 86400; }
            if ($lunchEnd   < $lunchStart) { $lunchEnd   += 86400; }

            $lunch = max(0, $lunchEnd - $lunchStart);
            if ($lunch > $span) {
                // Bad edit (e.g. lunch end after clock out). Never let lunch make
                // worked time negative.
                $lunch      = $span;
                $warnings[] = self::WARN_LUNCH_CLAMPED;
            }
        } elseif ($lunchStart !== null || $lunchEnd !== null) {
            // Half a lunch is unusable: subtracting nothing overstates pay, and
            // guessing a duration invents data. Subtract nothing, flag the row.
            $warnings[] = self::WARN_INCOMPLETE_LUNCH;
        }

        $seconds = $span - $lunch;
        if ($seconds < 0) {
            $seconds = 0;
        }
        if ($seconds > self::MAX_PUNCH_SECONDS) {
            $seconds    = self::MAX_PUNCH_SECONDS;
            $warnings[] = self::WARN_EXCEEDS_24H;
        }

        return ['seconds' => (int)$seconds, 'warnings' => $warnings];
    }

    /**
     * Raw seconds -> decimal hours, deliberately UNROUNDED. Rounding is a policy
     * applied once to the daily total (see roundHours), not to every punch.
     */
    public static function secondsToHours(int $seconds): float
    {
        return $seconds / 3600.0;
    }

    /**
     * Round decimal hours to the nearest $intervalMinutes.
     * Mirrors admin/summary.php roundToNearestMinutes() exactly so the API and the
     * admin UI never disagree about a payroll number. interval <= 0 => 2dp only.
     */
    public static function roundHours(float $hours, int $intervalMinutes): float
    {
        if ($intervalMinutes <= 0) {
            return round($hours, 2);
        }
        $totalMinutes   = $hours * 60;
        $roundedMinutes = round($totalMinutes / $intervalMinutes) * $intervalMinutes;
        return round($roundedMinutes / 60, 2);
    }

    /**
     * Group raw punch rows into per-day totals with rounding applied to the DAY.
     *
     * $punches: rows with at least Date, TimeIN, LunchStart, LunchEnd, TimeOut.
     *
     * @return array{
     *   days: array<string, array{date: string, seconds: int, hours: float, punch_count: int,
     *                             open_punch_count: int, warnings: string[]}>,
     *   open_punch_count: int,
     *   warnings: string[]
     * }
     */
    public static function dailyHoursFromPunches(array $punches, int $roundingMinutes = 0): array
    {
        $days      = [];
        $openTotal = 0;

        foreach ($punches as $p) {
            $date = (string)($p['Date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (!isset($days[$date])) {
                $days[$date] = [
                    'date'             => $date,
                    'seconds'          => 0,
                    'hours'            => 0.0,
                    'punch_count'      => 0,
                    'open_punch_count' => 0,
                    'warnings'         => [],
                ];
            }

            $calc = self::punchWorkedSeconds($p);
            $days[$date]['seconds']     += $calc['seconds'];
            $days[$date]['punch_count'] += 1;
            if (in_array(self::WARN_OPEN_PUNCH, $calc['warnings'], true)) {
                $days[$date]['open_punch_count'] += 1;
                $openTotal++;
            }
            foreach ($calc['warnings'] as $w) {
                if (!in_array($w, $days[$date]['warnings'], true)) {
                    $days[$date]['warnings'][] = $w;
                }
            }
        }

        $allWarnings = [];
        foreach ($days as $date => $d) {
            // Rounding is applied once, to the summed day — rounding each punch
            // first would compound the error across a multi-punch day.
            $days[$date]['hours'] = self::roundHours(self::secondsToHours($d['seconds']), $roundingMinutes);
            foreach ($d['warnings'] as $w) {
                if (!in_array($w, $allWarnings, true)) {
                    $allWarnings[] = $w;
                }
            }
        }
        ksort($days);

        return [
            'days'             => $days,
            'open_punch_count' => $openTotal,
            'warnings'         => $allWarnings,
        ];
    }

    /**
     * Split one employee's daily hours into regular / overtime / double-time.
     *
     * $dailyHoursByDate: ['Y-m-d' => decimal hours] for a SINGLE employee.
     *
     * Daily thresholds are taken FIRST and their hours are removed from the weekly
     * base, so an hour is never counted as both daily and weekly overtime.
     *
     * dt_daily is honored even when ot_daily is 0 (a shop can pay double-time over
     * 12h without a daily OT tier); that is a strict generalization of the rule —
     * with ot_daily > 0 it reduces to the documented behavior exactly.
     *
     * @return array{
     *   days: array<string, array{date: string, hours: float, regular: float, overtime: float,
     *                             double_time: float, week_start: string}>,
     *   weeks: array<string, array{week_start: string, week_end: string, hours: float,
     *                              regular: float, overtime: float, daily_overtime: float,
     *                              weekly_overtime: float, double_time: float, total: float}>,
     *   totals: array{hours: float, regular: float, overtime: float, daily_overtime: float,
     *                 weekly_overtime: float, double_time: float, total: float}
     * }
     */
    public static function splitOvertime(
        array $dailyHoursByDate,
        float $otDaily,
        float $dtDaily,
        float $otWeekly,
        string $weekStart
    ): array {
        $days  = [];
        $weeks = [];

        foreach ($dailyHoursByDate as $date => $hours) {
            $date  = (string)$date;
            $hours = (float)$hours;
            if ($hours < 0) {
                $hours = 0.0;
            }

            $dailyDt = 0.0;
            if ($dtDaily > 0) {
                $dailyDt = max(0.0, $hours - $dtDaily);
            }

            $dailyOt = 0.0;
            if ($otDaily > 0) {
                $cap     = $dtDaily > 0 ? min($hours, $dtDaily) : $hours;
                $dailyOt = max(0.0, $cap - $otDaily);
            }

            $dailyRegular = $hours - $dailyOt - $dailyDt;
            if ($dailyRegular < 0) {
                $dailyRegular = 0.0;
            }

            $wk = self::weekStartFor($date, $weekStart);
            if (!isset($weeks[$wk])) {
                $weeks[$wk] = [
                    'week_start'      => $wk,
                    'week_end'        => self::weekEndFor($date, $weekStart),
                    '_hours'          => 0.0,
                    '_regular_base'   => 0.0,
                    '_daily_overtime' => 0.0,
                    '_double_time'    => 0.0,
                ];
            }
            $weeks[$wk]['_hours']          += $hours;
            $weeks[$wk]['_regular_base']   += $dailyRegular;
            $weeks[$wk]['_daily_overtime'] += $dailyOt;
            $weeks[$wk]['_double_time']    += $dailyDt;

            $days[$date] = [
                'date'        => $date,
                'hours'       => round($hours, 2),
                'regular'     => round($dailyRegular, 2),
                'overtime'    => round($dailyOt, 2),
                'double_time' => round($dailyDt, 2),
                'week_start'  => $wk,
            ];
        }

        $totals = [
            'hours'           => 0.0,
            'regular'         => 0.0,
            'overtime'        => 0.0,
            'daily_overtime'  => 0.0,
            'weekly_overtime' => 0.0,
            'double_time'     => 0.0,
            'total'           => 0.0,
        ];

        foreach ($weeks as $wk => $w) {
            $weeklyOt = 0.0;
            if ($otWeekly > 0) {
                $weeklyOt = max(0.0, $w['_regular_base'] - $otWeekly);
            }
            $regular  = $w['_regular_base'] - $weeklyOt;
            $overtime = $w['_daily_overtime'] + $weeklyOt;
            $dt       = $w['_double_time'];

            // Round the components, then derive total from the ROUNDED parts so a
            // spreadsheet column always ties out to the total we publish.
            $rRegular = round($regular, 2);
            $rOt      = round($overtime, 2);
            $rDt      = round($dt, 2);

            $weeks[$wk] = [
                'week_start'      => $w['week_start'],
                'week_end'        => $w['week_end'],
                'hours'           => round($w['_hours'], 2),
                'regular'         => $rRegular,
                'overtime'        => $rOt,
                'daily_overtime'  => round($w['_daily_overtime'], 2),
                'weekly_overtime' => round($weeklyOt, 2),
                'double_time'     => $rDt,
                'total'           => round($rRegular + $rOt + $rDt, 2),
            ];

            $totals['hours']           += $w['_hours'];
            $totals['regular']         += $regular;
            $totals['overtime']        += $overtime;
            $totals['daily_overtime']  += $w['_daily_overtime'];
            $totals['weekly_overtime'] += $weeklyOt;
            $totals['double_time']     += $dt;
        }

        ksort($days);
        ksort($weeks);

        $totals['hours']           = round($totals['hours'], 2);
        $totals['regular']         = round($totals['regular'], 2);
        $totals['overtime']        = round($totals['overtime'], 2);
        $totals['daily_overtime']  = round($totals['daily_overtime'], 2);
        $totals['weekly_overtime'] = round($totals['weekly_overtime'], 2);
        $totals['double_time']     = round($totals['double_time'], 2);
        $totals['total']           = round(
            $totals['regular'] + $totals['overtime'] + $totals['double_time'],
            2
        );

        return ['days' => $days, 'weeks' => $weeks, 'totals' => $totals];
    }

    /**
     * Y-m-d of the week containing $date. $weekStart is 'mon' or 'sun'.
     * Computed in UTC: see the DST note at the top of this file.
     */
    public static function weekStartFor(string $date, string $weekStart): string
    {
        $d = self::utcDate($date);
        if ($d === null) {
            return $date;
        }
        if (strtolower($weekStart) === 'sun') {
            $offset = (int)$d->format('w');          // 0 = Sunday
        } else {
            $offset = (int)$d->format('N') - 1;      // 1 = Monday
        }
        return $d->modify('-' . $offset . ' days')->format('Y-m-d');
    }

    /** Y-m-d of the last day of the week containing $date (week_start + 6). */
    public static function weekEndFor(string $date, string $weekStart): string
    {
        $start = self::weekStartFor($date, $weekStart);
        $d     = self::utcDate($start);
        if ($d === null) {
            return $start;
        }
        return $d->modify('+6 days')->format('Y-m-d');
    }

    /**
     * True when the calendar week extends outside the requested range, which means
     * its overtime is computed from an incomplete set of hours.
     */
    public static function isPartialWeek(
        string $weekStart,
        string $weekEnd,
        string $rangeStart,
        string $rangeEnd
    ): bool {
        return $weekStart < $rangeStart || $weekEnd > $rangeEnd;
    }

    /**
     * Parse a MySQL TIME into seconds since midnight.
     * Returns null for NULL/empty/unparseable. MySQL TIME legitimately allows
     * hour components above 23 (range is -838:59:59..838:59:59), so the hour is
     * parsed as an unbounded integer rather than validated as a clock hour.
     */
    public static function timeToSeconds(?string $time): ?int
    {
        if ($time === null) {
            return null;
        }
        $time = trim($time);
        if ($time === '') {
            return null;
        }
        if (!preg_match('/^(-?)(\d{1,3}):([0-5]\d)(?::([0-5]\d))?$/', $time, $m)) {
            return null;
        }
        $seconds = ((int)$m[2]) * 3600 + ((int)$m[3]) * 60 + (int)($m[4] ?? 0);
        return $m[1] === '-' ? -$seconds : $seconds;
    }

    /** Strict Y-m-d parse in UTC; null when the string is not a real calendar date. */
    private static function utcDate(string $date): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $date) {
            return null;
        }
        return $d;
    }
}
