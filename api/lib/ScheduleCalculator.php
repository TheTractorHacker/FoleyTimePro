<?php
/**
 * ScheduleCalculator — scheduled hours + approved time-off expansion for the
 * /api/external/v1 report surface.
 *
 * Same DST policy as HoursCalculator: shift spans are computed in seconds since
 * midnight from the bare TIME columns, and calendar iteration happens in UTC so a
 * DST-skipped local midnight can never drop or duplicate a day.
 *
 * A user has AT MOST ONE shift (user_shifts.UserID is UNIQUE), and only an active
 * shift counts — an employee whose shift was deactivated is treated as unscheduled
 * rather than silently keeping a stale denominator.
 */

require_once __DIR__ . '/HoursCalculator.php';

class ScheduleCalculator
{
    /** Safety valve for datesBetween(); ReportParams already caps ranges at 400 days. */
    private const MAX_RANGE_DAYS = 10000;

    /** Memo of decoded DaysOfWeek, keyed by the raw JSON string. */
    private static array $daysCache = [];

    /**
     * userId => shift row (ShiftID, ShiftName, StartTime, EndTime, DaysOfWeek,
     * LunchDuration, LunchStartTime, IsActive, GracePeriodMinutes, AutoClockOut).
     * Users with no assignment, or whose shift is inactive, are simply absent.
     *
     * @param int[] $userIds
     * @return array<int, array>
     */
    public static function loadShiftsForUsers(mysqli $conn, array $userIds): array
    {
        $ids = self::normalizeIds($userIds);
        if ($ids === []) {
            return [];
        }

        // Placeholders are built from the COUNT of already-int-cast ids, never from
        // the values themselves — the values only ever travel through bind_param.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types        = str_repeat('i', count($ids));

        $stmt = $conn->prepare(
            "SELECT us.UserID,
                    s.ShiftID, s.ShiftName, s.StartTime, s.EndTime, s.DaysOfWeek,
                    s.LunchDuration, s.LunchStartTime, s.IsActive,
                    s.GracePeriodMinutes, s.AutoClockOut
             FROM user_shifts us
             JOIN shifts s ON s.ShiftID = us.ShiftID
             WHERE us.UserID IN ($placeholders)
               AND s.IsActive = 1"
        );
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = [];
        foreach ($rows as $r) {
            $uid = (int)$r['UserID'];
            unset($r['UserID']);
            $out[$uid] = $r;
        }
        return $out;
    }

    /**
     * Scheduled seconds for one employee on one date.
     * 0 when there is no shift, the date is not a scheduled day, or DaysOfWeek is
     * unusable. Callers distinguish "no shift" from "day off" via has_schedule,
     * which they derive from whether loadShiftsForUsers returned a row.
     */
    public static function scheduledSecondsForDate(?array $shift, string $date): int
    {
        if ($shift === null) {
            return 0;
        }

        $days = self::shiftDays($shift);
        if ($days === []) {
            return 0;
        }

        $d = self::utcDate($date);
        if ($d === null) {
            return 0;
        }
        if (!in_array(strtolower($d->format('D')), $days, true)) {
            return 0;
        }

        $start = HoursCalculator::timeToSeconds($shift['StartTime'] ?? null);
        $end   = HoursCalculator::timeToSeconds($shift['EndTime'] ?? null);
        if ($start === null || $end === null) {
            return 0;
        }

        // EndTime <= StartTime means the shift crosses midnight.
        $span = $end <= $start ? ($end + 86400) - $start : $end - $start;

        $lunch = max(0, (int)($shift['LunchDuration'] ?? 0)) * 60;

        return (int)max(0, $span - $lunch);
    }

    /** Convenience wrapper: scheduled decimal hours, unrounded. */
    public static function scheduledHoursForDate(?array $shift, string $date): float
    {
        return self::scheduledSecondsForDate($shift, $date) / 3600.0;
    }

    /**
     * Approved time off, expanded to individual dates clipped to [$start, $end].
     *
     * HoursRequested is only trusted for a SINGLE-DAY request — on a multi-day
     * request it is the total for the whole span, and dividing it across days would
     * invent per-day numbers. Multi-day dates therefore report hours = null and the
     * caller falls back to that date's scheduled hours.
     *
     * When two approved requests cover the same date (data entry overlap), the
     * lowest request ID wins so the result is deterministic across runs.
     *
     * @param int[] $userIds
     * @return array<int, array<string, array{type: string, hours: ?float, request_id: int}>>
     */
    public static function loadApprovedTimeOff(mysqli $conn, array $userIds, string $start, string $end): array
    {
        $ids = self::normalizeIds($userIds);
        if ($ids === [] || $end < $start) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types        = str_repeat('i', count($ids)) . 'ss';

        // Overlap test, not containment: a request that starts before the range and
        // ends inside it still consumes scheduled days inside the range.
        $stmt = $conn->prepare(
            "SELECT ID, EmployeeID, StartDate, EndDate, RequestType, HoursRequested
             FROM time_off_requests
             WHERE Status = 'Approved'
               AND EmployeeID IN ($placeholders)
               AND StartDate <= ?
               AND EndDate   >= ?
             ORDER BY ID ASC"
        );
        $bind = array_merge($ids, [$end, $start]);
        $stmt->bind_param($types, ...$bind);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = [];
        foreach ($rows as $r) {
            $uid       = (int)$r['EmployeeID'];
            $reqStart  = (string)$r['StartDate'];
            $reqEnd    = (string)$r['EndDate'];
            $singleDay = $reqStart === $reqEnd;
            $hours     = ($singleDay && $r['HoursRequested'] !== null)
                ? (float)$r['HoursRequested']
                : null;

            $from = $reqStart > $start ? $reqStart : $start;
            $to   = $reqEnd   < $end   ? $reqEnd   : $end;

            foreach (self::datesBetween($from, $to) as $date) {
                if (isset($out[$uid][$date])) {
                    continue; // lowest request ID already owns this date
                }
                $out[$uid][$date] = [
                    'type'       => (string)$r['RequestType'],
                    'hours'      => $hours,
                    'request_id' => (int)$r['ID'],
                ];
            }
        }

        foreach ($out as $uid => $dates) {
            ksort($out[$uid]);
        }
        return $out;
    }

    /**
     * The dates in [$start, $end] on which this shift is scheduled to work.
     * Empty array when there is no shift or DaysOfWeek is unusable.
     *
     * @return string[] Y-m-d
     */
    public static function scheduledDaysBetween(?array $shift, string $start, string $end): array
    {
        if ($shift === null) {
            return [];
        }
        $days = self::shiftDays($shift);
        if ($days === []) {
            return [];
        }

        $out = [];
        foreach (self::datesBetween($start, $end) as $date) {
            $d = self::utcDate($date);
            if ($d !== null && in_array(strtolower($d->format('D')), $days, true)) {
                $out[] = $date;
            }
        }
        return $out;
    }

    /**
     * Inclusive Y-m-d list from $start to $end. Empty when either date is invalid
     * or $end < $start.
     *
     * @return string[]
     */
    public static function datesBetween(string $start, string $end): array
    {
        $from = self::utcDate($start);
        $to   = self::utcDate($end);
        if ($from === null || $to === null || $end < $start) {
            return [];
        }

        $out    = [];
        $cursor = $from;
        $guard  = 0;
        while ($cursor->format('Y-m-d') <= $end) {
            $out[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
            if (++$guard > self::MAX_RANGE_DAYS) {
                error_log('[report-lib/ScheduleCalculator] datesBetween exceeded '
                    . self::MAX_RANGE_DAYS . ' days for ' . $start . '..' . $end);
                break;
            }
        }
        return $out;
    }

    /**
     * Lowercase 3-letter day codes for a shift ('mon','tue',...).
     * DaysOfWeek is a JSON array stored in a VARCHAR, so it can be malformed by a
     * bad import or a hand edit; malformed input is logged and treated as "no
     * scheduled days" rather than throwing mid-report.
     *
     * @return string[]
     */
    private static function shiftDays(?array $shift): array
    {
        $raw = (string)($shift['DaysOfWeek'] ?? '');
        if ($raw === '') {
            return [];
        }
        if (array_key_exists($raw, self::$daysCache)) {
            return self::$daysCache[$raw];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('[report-lib/ScheduleCalculator] Malformed shifts.DaysOfWeek JSON for ShiftID '
                . (string)($shift['ShiftID'] ?? '?') . ': ' . substr($raw, 0, 120));
            return self::$daysCache[$raw] = [];
        }

        $valid = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $days  = [];
        foreach ($decoded as $d) {
            if (!is_string($d)) {
                continue;
            }
            $code = strtolower(substr(trim($d), 0, 3));
            if (in_array($code, $valid, true) && !in_array($code, $days, true)) {
                $days[] = $code;
            }
        }
        return self::$daysCache[$raw] = $days;
    }

    /** @param int[] $userIds @return int[] deduped, positive, re-indexed */
    private static function normalizeIds(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
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
