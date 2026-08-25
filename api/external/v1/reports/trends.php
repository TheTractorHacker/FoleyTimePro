<?php
/**
 * GET /api/external/v1/reports/trends
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * Time series for BI trending — the endpoint a Power BI line chart binds to
 * directly. One row per interval bucket (day/week/month), optionally split by
 * employee or office.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, rounding, week_start, ot_weekly, ot_daily, dt_daily,
 *         tz, format, limit, offset, plus:
 *
 *   interval  "day" (default) | "week" | "month"
 *   group_by  "none" (default) | "employee" | "office"
 *
 * GAP-FREE SERIES — the reason this endpoint exists rather than "just filter
 * /summary by week": every bucket in [start, end] gets a row, including buckets
 * with zero activity, and (when group_by is set) every employee/office in the
 * filtered roster gets a row for every bucket, including one where they did
 * nothing. A chart binding to a query that silently OMITS empty periods draws a
 * misleading straight line across a gap instead of showing a real drop to zero.
 * The roster and bucket list are therefore both built FIRST, independently of
 * which rows the punch data actually touches, and every combination is emitted.
 *
 * OVERTIME COMES FROM HoursCalculator::splitOvertime — never reimplemented here.
 * Per employee, one call over the WHOLE requested range produces both the daily
 * breakdown (days[]) and the weekly breakdown (weeks[]), and every bucket size
 * below is folded from those two structures rather than re-deriving punch math:
 *
 *   interval=day   uses days[date] directly. Its 'overtime' field is the DAILY
 *                  (ot_daily/dt_daily) threshold only — splitOvertime never
 *                  folds the WEEKLY threshold into a single day, because a
 *                  40-hour week's overtime hour cannot be pinned to one day of
 *                  it. So day-level overtime_hours understates true overtime
 *                  whenever ot_weekly matters, and meta.warnings says so
 *                  explicitly rather than silently mislabeling it. totals.*
 *                  below is unaffected — it comes from splitOvertime's
 *                  whole-range totals, not from summing the day rows.
 *   interval=week  uses weeks[week_start] directly — weekly overtime is exactly
 *                  what that structure was built to report, no ambiguity.
 *   interval=month has the same edge case one level up: a single ISO week can
 *                  straddle a calendar-month boundary, and a 7-day OT test
 *                  cannot be split across two months without inventing a rule
 *                  payroll itself doesn't apply. The unambiguous per-day pieces
 *                  (daily overtime, double-time, and each day's pre-weekly
 *                  "regular base") are summed by the calendar month the DAY
 *                  falls in; the weekly-threshold component of each week is
 *                  attributed whole to the month containing that WEEK's start
 *                  date. meta.warnings names this when it actually happens in
 *                  the requested range. regular/overtime/double_time are always
 *                  derived so they sum to worked_hours for that bucket even in
 *                  the (rare) case a week's overtime exceeds that month's slice
 *                  of its regular base.
 *
 * `totals` (periods, employees, worked_hours, regular_hours, overtime_hours)
 * describes the entire filtered population over the whole range — computed once
 * per employee from splitOvertime's own totals, independent of `interval` and
 * of pagination, so it is never subject to the day/month caveats above and never
 * changes as an integrator pages through rows.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

/** Same closure-per-file convention as utilization.php: no global function to collide with. */
$parseEnumParam = static function (array $get, string $name, array $allowed, string $default): string {
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
    if (in_array($v, $allowed, true)) {
        return $v;
    }
    $echo = htmlspecialchars(substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '', 0, 64), ENT_QUOTES, 'UTF-8');
    ApiResponse::error(
        'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are '
        . implode(', ', array_map(static fn($a) => '"' . $a . '"', $allowed)) . '.',
        400
    );
};

/** Percentage, or null when there is no denominator — see utilization.php for why null (not 0) matters. */
$pct = static function (float $numerator, float $denominator): ?float {
    if ($denominator <= 0) {
        return null;
    }
    return round($numerator / $denominator * 100, 2);
};

/**
 * Bucket calendar math for /reports/trends. A class (not global functions) so
 * this file can never collide with punches.php's global formatDateTime() etc.
 * All arithmetic is on plain Y-m-d strings in UTC — same DST rationale as
 * HoursCalculator/ScheduleCalculator: these are calendar dates, not instants.
 */
final class TrendsBuckets
{
    /** Safety valve; a 400-day range (ReportParams' own cap) is ~58 weeks / ~14 months. */
    private const MAX_BUCKETS = 600;

    /**
     * Every bucket in [$start, $end], keyed by its bucket key and in chronological
     * order. Buckets whose calendar span extends outside [$start, $end] carry
     * is_partial=true — the range edges are the only place this can happen.
     *
     * @return array<string, array{period_start:string, period_end:string, period_label:string, is_partial:bool}>
     */
    public static function build(string $start, string $end, string $interval, string $weekStart): array
    {
        return match ($interval) {
            'week'  => self::weekBuckets($start, $end, $weekStart),
            'month' => self::monthBuckets($start, $end),
            default => self::dayBuckets($start, $end),
        };
    }

    /** The bucket key a punch DATE falls into. Must use the exact same rule build() used. */
    public static function keyFor(string $date, string $interval, string $weekStart): string
    {
        return match ($interval) {
            'week'  => HoursCalculator::weekStartFor($date, $weekStart),
            'month' => substr($date, 0, 7) . '-01',
            default => $date,
        };
    }

    /**
     * Fold one employee's splitOvertime() result into per-bucket
     * {regular, overtime, double_time, worked}, keyed exactly like build()/keyFor().
     * See the file-level docblock for the day/week/month attribution rules.
     *
     * @return array<string, array{regular:float, overtime:float, double_time:float, worked:float}>
     */
    public static function foldSplit(array $split, string $interval): array
    {
        if ($interval === 'week') {
            $out = [];
            foreach ($split['weeks'] as $wk => $w) {
                $out[(string)$wk] = [
                    'regular'     => (float)$w['regular'],
                    'overtime'    => (float)$w['overtime'],
                    'double_time' => (float)$w['double_time'],
                    'worked'      => (float)$w['total'],
                ];
            }
            return $out;
        }

        if ($interval === 'month') {
            // Unambiguous per-day components, summed by the day's own month.
            $base = []; // month => [regular_base, daily_ot, dt]
            foreach ($split['days'] as $date => $d) {
                $m = substr((string)$date, 0, 7) . '-01';
                if (!isset($base[$m])) {
                    $base[$m] = ['regular_base' => 0.0, 'daily_ot' => 0.0, 'dt' => 0.0];
                }
                $base[$m]['regular_base'] += (float)$d['regular'];
                $base[$m]['daily_ot']     += (float)$d['overtime'];
                $base[$m]['dt']           += (float)$d['double_time'];
            }
            // The weekly-threshold component, attributed whole to the month
            // containing each week's START date (see docblock).
            $weeklyOtByMonth = [];
            foreach ($split['weeks'] as $wk => $w) {
                $m = substr((string)$wk, 0, 7) . '-01';
                $weeklyOtByMonth[$m] = ($weeklyOtByMonth[$m] ?? 0.0) + (float)$w['weekly_overtime'];
            }
            $out = [];
            foreach ($base as $m => $b) {
                $weeklyOt = $weeklyOtByMonth[$m] ?? 0.0;
                $worked   = round($b['regular_base'] + $b['daily_ot'] + $b['dt'], 2);
                $dt       = round($b['dt'], 2);
                $regular  = round(max(0.0, $b['regular_base'] - $weeklyOt), 2);
                // Derived, never summed independently, so worked = regular + overtime
                // + double_time always ties out even if weeklyOt exceeds this month's
                // slice of the week's regular base (rare, but must never go negative).
                $overtime = round($worked - $regular - $dt, 2);
                $out[$m] = ['regular' => $regular, 'overtime' => $overtime, 'double_time' => $dt, 'worked' => $worked];
            }
            return $out;
        }

        // interval=day: days[] IS the bucket, already excludes weekly attribution.
        $out = [];
        foreach ($split['days'] as $date => $d) {
            $out[(string)$date] = [
                'regular'     => (float)$d['regular'],
                'overtime'    => (float)$d['overtime'],
                'double_time' => (float)$d['double_time'],
                'worked'      => round((float)$d['regular'] + (float)$d['overtime'] + (float)$d['double_time'], 2),
            ];
        }
        return $out;
    }

    /** True when interval=month and any week in $split straddles a calendar-month boundary. */
    public static function hasMonthSpanningWeek(array $split): bool
    {
        foreach ($split['weeks'] as $w) {
            if (substr((string)$w['week_start'], 0, 7) !== substr((string)$w['week_end'], 0, 7)) {
                return true;
            }
        }
        return false;
    }

    private static function dayBuckets(string $start, string $end): array
    {
        $out = [];
        foreach (ScheduleCalculator::datesBetween($start, $end) as $d) {
            $out[$d] = ['period_start' => $d, 'period_end' => $d, 'period_label' => $d, 'is_partial' => false];
        }
        return $out;
    }

    private static function weekBuckets(string $start, string $end, string $weekStart): array
    {
        $out    = [];
        $cursor = HoursCalculator::weekStartFor($start, $weekStart);
        $guard  = 0;
        while ($cursor <= $end) {
            $wkEnd = HoursCalculator::weekEndFor($cursor, $weekStart);
            $out[$cursor] = [
                'period_start' => $cursor,
                'period_end'   => $wkEnd,
                'period_label' => self::isoWeekLabel($cursor),
                'is_partial'   => HoursCalculator::isPartialWeek($cursor, $wkEnd, $start, $end),
            ];
            $cursor = self::addDays($cursor, 7);
            if (++$guard > self::MAX_BUCKETS) {
                error_log('[reports/trends] weekBuckets exceeded ' . self::MAX_BUCKETS . ' for ' . $start . '..' . $end);
                break;
            }
        }
        return $out;
    }

    private static function monthBuckets(string $start, string $end): array
    {
        $out    = [];
        $cursor = substr($start, 0, 7) . '-01';
        $guard  = 0;
        while ($cursor <= $end) {
            $eom = self::endOfMonth($cursor);
            $out[$cursor] = [
                'period_start' => $cursor,
                'period_end'   => $eom,
                'period_label' => substr($cursor, 0, 7),
                'is_partial'   => ($cursor < $start) || ($eom > $end),
            ];
            $cursor = self::addOneMonth($cursor);
            if (++$guard > self::MAX_BUCKETS) {
                error_log('[reports/trends] monthBuckets exceeded ' . self::MAX_BUCKETS . ' for ' . $start . '..' . $end);
                break;
            }
        }
        return $out;
    }

    /**
     * ISO-8601 week label of $date (e.g. "2026-W34"), always monotonically
     * sortable — which is the one property a chart axis label actually needs.
     * When week_start=sun the bucket boundaries are Sunday-based while this
     * label is the ISO (Monday-based) week of the bucket's start date; the two
     * can name a bucket with the week number of the ISO week it merely
     * OVERLAPS rather than exactly coincides with. Still sorts correctly.
     */
    private static function isoWeekLabel(string $date): string
    {
        $d = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        return $d->format('o') . '-W' . $d->format('W');
    }

    private static function addDays(string $date, int $n): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify("+{$n} days")->format('Y-m-d');
    }

    private static function addOneMonth(string $firstOfMonth): string
    {
        return (new DateTimeImmutable($firstOfMonth, new DateTimeZone('UTC')))->modify('+1 month')->format('Y-m-d');
    }

    private static function endOfMonth(string $firstOfMonth): string
    {
        return (new DateTimeImmutable($firstOfMonth, new DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
    }
}

try {
    ReportBootstrap::begin('/api/external/v1/reports/trends', 'reports:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'rounding', 'week_start', 'ot_weekly', 'ot_daily', 'dt_daily',
        'limit', 'offset', 'interval', 'group_by',
    ]);

    $interval = $parseEnumParam($_GET, 'interval', ['day', 'week', 'month'], 'day');
    $groupBy  = $parseEnumParam($_GET, 'group_by', ['none', 'employee', 'office'], 'none');

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();

    $buckets = TrendsBuckets::build($start, $end, $interval, $params->weekStart());

    // ------------------------------------------------------------------ roster
    // Full filtered population, not paginated — every employee needs a zero row
    // in every bucket even if they touched nothing, and office rollups need the
    // whole set. Pagination happens on the shaped rows below, like utilization.php.
    $filter      = $params->buildUserFilter('u');
    $rosterWhere = $filter['sql'] !== '' ? $filter['sql'] : '1=1';
    $stmt = $conn->prepare(
        "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office
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

    // ------------------------------------------------------------------ punches
    $punchesByEmployee = [];
    if ($roster !== []) {
        $sql = "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut
                FROM timepunches tp
                JOIN users u ON u.ID = tp.EmployeeID
                WHERE tp.Date BETWEEN ? AND ?"
            . ($filter['sql'] !== '' ? " AND {$filter['sql']}" : '')
            . " ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.id ASC";
        $types  = 'ss' . $filter['types'];
        $values = array_merge([$start, $end], $filter['values']);

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($p = $res->fetch_assoc()) {
            $punchesByEmployee[(int)$p['EmployeeID']][] = $p;
        }
        $res->free();
        $stmt->close();
    }

    // --------------------------------------------------------------- aggregate
    $rows = [];

    // Zero-initialized so a bucket with no activity at all still has a company
    // row — the whole reason this report exists (see file-level docblock).
    $companyBuckets = [];
    foreach ($buckets as $key => $b) {
        $companyBuckets[$key] = [
            'employees_active' => 0, 'days_worked' => 0,
            'regular' => 0.0, 'overtime' => 0.0, 'double_time' => 0.0, 'worked' => 0.0,
        ];
    }
    $officeBuckets = [];

    $employeeCount     = count($roster);
    $openPunches       = 0;
    $anyPartialWeek    = false;
    $anyMonthSpanWeek  = false;

    $totalWorked   = 0.0;
    $totalRegular  = 0.0;
    $totalOvertime = 0.0;

    foreach ($roster as $u) {
        $eid   = (int)$u['ID'];
        $name  = trim((string)$u['FirstName'] . ' ' . (string)$u['LastName']);
        $rawOffice = trim((string)($u['Office'] ?? ''));
        $office    = $rawOffice !== '' ? $rawOffice : 'Unassigned';

        if ($groupBy === 'office' && !isset($officeBuckets[$office])) {
            $officeBuckets[$office] = [];
            foreach ($buckets as $key => $b) {
                $officeBuckets[$office][$key] = [
                    'employees_active' => 0, 'days_worked' => 0,
                    'regular' => 0.0, 'overtime' => 0.0, 'double_time' => 0.0, 'worked' => 0.0,
                ];
            }
        }

        $punchRows = $punchesByEmployee[$eid] ?? [];
        $daily     = HoursCalculator::dailyHoursFromPunches($punchRows, $params->rounding());
        $openPunches += (int)$daily['open_punch_count'];

        $hoursByDate = [];
        foreach ($daily['days'] as $date => $d) {
            $hoursByDate[$date] = (float)$d['hours'];
        }
        $split = HoursCalculator::splitOvertime(
            $hoursByDate, $params->otDaily(), $params->dtDaily(), $params->otWeekly(), $params->weekStart()
        );

        foreach ($split['weeks'] as $w) {
            if (HoursCalculator::isPartialWeek((string)$w['week_start'], (string)$w['week_end'], $start, $end)) {
                $anyPartialWeek = true;
            }
        }
        if ($interval === 'month' && TrendsBuckets::hasMonthSpanningWeek($split)) {
            $anyMonthSpanWeek = true;
        }

        $totalWorked   += (float)$split['totals']['total'];
        $totalRegular  += (float)$split['totals']['regular'];
        $totalOvertime += (float)$split['totals']['overtime'];

        // Buckets this employee had ANY punch in (dates present in split['days'],
        // regardless of computed hours — an open punch is still "active").
        $activeBucketKeys = [];
        foreach (array_keys($split['days']) as $date) {
            $activeBucketKeys[TrendsBuckets::keyFor($date, $interval, $params->weekStart())] = true;
        }
        foreach (array_keys($activeBucketKeys) as $key) {
            if (isset($companyBuckets[$key])) {
                $companyBuckets[$key]['employees_active']++;
            }
            if ($groupBy === 'office' && isset($officeBuckets[$office][$key])) {
                $officeBuckets[$office][$key]['employees_active']++;
            }
        }

        // days_worked counts a DATE only when it produced paid time, matching the
        // convention used across the rest of this report surface (open punches
        // never count as a day worked).
        $daysWorkedByBucket = [];
        foreach ($split['days'] as $date => $d) {
            if ((float)$d['hours'] <= 0) {
                continue;
            }
            $key = TrendsBuckets::keyFor((string)$date, $interval, $params->weekStart());
            $daysWorkedByBucket[$key] = ($daysWorkedByBucket[$key] ?? 0) + 1;
        }
        foreach ($daysWorkedByBucket as $key => $cnt) {
            if (isset($companyBuckets[$key])) {
                $companyBuckets[$key]['days_worked'] += $cnt;
            }
            if ($groupBy === 'office' && isset($officeBuckets[$office][$key])) {
                $officeBuckets[$office][$key]['days_worked'] += $cnt;
            }
        }

        $fold = TrendsBuckets::foldSplit($split, $interval);

        foreach ($buckets as $key => $b) {
            $f = $fold[$key] ?? ['regular' => 0.0, 'overtime' => 0.0, 'double_time' => 0.0, 'worked' => 0.0];

            $companyBuckets[$key]['regular']     += $f['regular'];
            $companyBuckets[$key]['overtime']    += $f['overtime'];
            $companyBuckets[$key]['double_time'] += $f['double_time'];
            $companyBuckets[$key]['worked']      += $f['worked'];

            if ($groupBy === 'office') {
                $officeBuckets[$office][$key]['regular']     += $f['regular'];
                $officeBuckets[$office][$key]['overtime']    += $f['overtime'];
                $officeBuckets[$office][$key]['double_time'] += $f['double_time'];
                $officeBuckets[$office][$key]['worked']      += $f['worked'];
            }

            if ($groupBy === 'employee') {
                $active = isset($activeBucketKeys[$key]) ? 1 : 0;
                $worked = round($f['worked'], 2);
                $ot     = round($f['overtime'], 2);
                $rows[] = [
                    'period_start'           => $b['period_start'],
                    'period_end'             => $b['period_end'],
                    'period_label'           => $b['period_label'],
                    'is_partial'             => $b['is_partial'],
                    'employee_id'            => $eid,
                    'employee_name'          => $name,
                    'employees_active'       => $active,
                    'days_worked'            => (int)($daysWorkedByBucket[$key] ?? 0),
                    'worked_hours'           => $worked,
                    'regular_hours'          => round($f['regular'], 2),
                    'overtime_hours'         => $ot,
                    'double_time_hours'      => round($f['double_time'], 2),
                    'avg_hours_per_employee' => $active > 0 ? round($worked / $active, 2) : null,
                    'overtime_pct'           => $pct($ot, $worked),
                ];
            }
        }
    }

    if ($groupBy === 'none') {
        foreach ($buckets as $key => $b) {
            $cb     = $companyBuckets[$key];
            $worked = round($cb['worked'], 2);
            $ot     = round($cb['overtime'], 2);
            $rows[] = [
                'period_start'           => $b['period_start'],
                'period_end'             => $b['period_end'],
                'period_label'           => $b['period_label'],
                'is_partial'             => $b['is_partial'],
                'employees_active'       => (int)$cb['employees_active'],
                'days_worked'            => (int)$cb['days_worked'],
                'worked_hours'           => $worked,
                'regular_hours'          => round($cb['regular'], 2),
                'overtime_hours'         => $ot,
                'double_time_hours'      => round($cb['double_time'], 2),
                'avg_hours_per_employee' => $cb['employees_active'] > 0
                    ? round($worked / $cb['employees_active'], 2) : null,
                'overtime_pct'           => $pct($ot, $worked),
            ];
        }
    } elseif ($groupBy === 'office') {
        ksort($officeBuckets);
        foreach ($officeBuckets as $office => $bucketsForOffice) {
            foreach ($buckets as $key => $b) {
                $cb     = $bucketsForOffice[$key];
                $worked = round($cb['worked'], 2);
                $ot     = round($cb['overtime'], 2);
                $rows[] = [
                    'period_start'           => $b['period_start'],
                    'period_end'             => $b['period_end'],
                    'period_label'           => $b['period_label'],
                    'is_partial'             => $b['is_partial'],
                    'office'                 => $office,
                    'employees_active'       => (int)$cb['employees_active'],
                    'days_worked'            => (int)$cb['days_worked'],
                    'worked_hours'           => $worked,
                    'regular_hours'          => round($cb['regular'], 2),
                    'overtime_hours'         => $ot,
                    'double_time_hours'      => round($cb['double_time'], 2),
                    'avg_hours_per_employee' => $cb['employees_active'] > 0
                        ? round($worked / $cb['employees_active'], 2) : null,
                    'overtime_pct'           => $pct($ot, $worked),
                ];
            }
        }
    }

    // ------------------------------------------------------------------- totals
    // Whole-range, whole-population, independent of `interval` and of paging —
    // see file-level docblock for why this is never subject to the day/month
    // attribution caveats that apply to individual rows.
    $totals = [
        'periods'        => count($buckets),
        'worked_hours'   => round($totalWorked, 2),
        'regular_hours'  => round($totalRegular, 2),
        'overtime_hours' => round($totalOvertime, 2),
        'employees'      => $employeeCount,
    ];

    // --------------------------------------------------------------- pagination
    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    // ------------------------------------------------------------------ warnings
    $warnings = [];
    if ($interval === 'day') {
        $warnings[] = 'interval=day: overtime_hours (and regular_hours) reflect only the DAILY '
            . 'overtime/double-time thresholds (ot_daily/dt_daily). A weekly overtime hour (ot_weekly) '
            . 'cannot be attributed to a single day, so it is excluded from every day-level row rather '
            . 'than mislabeled. totals.overtime_hours is unaffected — it is computed from the whole-range '
            . 'weekly split, not by summing these rows.';
    }
    if ($interval === 'month' && $anyMonthSpanWeek) {
        $warnings[] = 'interval=month: at least one calendar week in this range straddles a month '
            . 'boundary. That week\'s weekly-threshold overtime is attributed entirely to the month '
            . 'containing the week\'s start date, which can shift a small number of overtime hours across '
            . 'the boundary relative to a strict per-day allocation.';
    }
    if ($anyPartialWeek) {
        $warnings[] = HoursCalculator::PARTIAL_WEEK_WARNING;
    }
    if ($openPunches > 0) {
        $warnings[] = $openPunches . ' punch(es) in this range have no clock-out. They contribute 0 '
            . 'worked hours and are not counted in days_worked, but the employee still counts toward '
            . 'employees_active for the bucket their punch date falls in.';
    }

    $meta = $params->meta('trends', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    $meta['filters']['interval'] = $interval;
    $meta['filters']['group_by'] = $groupBy;

    // Pinned so a zero-row CSV still carries a header row, and column order can
    // never drift with the first row's key order.
    $csvColumns = ['period_start', 'period_end', 'period_label', 'is_partial'];
    if ($groupBy === 'employee') {
        array_push($csvColumns, 'employee_id', 'employee_name');
    } elseif ($groupBy === 'office') {
        $csvColumns[] = 'office';
    }
    array_push(
        $csvColumns,
        'employees_active', 'days_worked', 'worked_hours', 'regular_hours',
        'overtime_hours', 'double_time_hours', 'avg_hours_per_employee', 'overtime_pct'
    );

    Output::send(
        ['rows' => $page, 'meta' => $meta, 'totals' => $totals],
        $params,
        'trends',
        $csvColumns
    );

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/trends] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
