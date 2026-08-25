<?php
/**
 * LiveStatus — shared "who is clocked in right now" logic for
 * /api/external/v1/live/status and /api/external/v1/live/headcount.
 *
 * Both endpoints need the exact same answer to "is this employee in, on lunch,
 * or out, and what does their schedule say about right now" — computing that
 * twice would be exactly the kind of drift the rest of this library exists to
 * prevent (see HoursCalculator's own warning about a second worked-hours
 * implementation). This file is the one place that logic lives.
 *
 * All static methods, same convention as GeofenceSupport in
 * reports/geofence.php — a class, not global functions, so this can never
 * collide with punches.php's global formatDateTime()/formatEpoch()/etc. if both
 * are ever loaded in one process.
 */

require_once __DIR__ . '/HoursCalculator.php';
require_once __DIR__ . '/ScheduleCalculator.php';

final class LiveStatus
{
    /** IsOpen is LIVE-ONLY (migration 013) — not in Install/timeclock-schema.sql. */
    private static ?bool $isOpenColumnExists = null;

    public static function isOpenColumnExists(mysqli $conn): bool
    {
        if (self::$isOpenColumnExists !== null) {
            return self::$isOpenColumnExists;
        }
        $exists = false;
        $res = $conn->query("SHOW COLUMNS FROM timepunches LIKE 'IsOpen'");
        if ($res) {
            $exists = $res->num_rows > 0;
            $res->free();
        }
        return self::$isOpenColumnExists = $exists;
    }

    /**
     * The most recent OPEN punch per employee, for the given employee ids.
     *
     * Uses IsOpen when the column exists (the authoritative flag as of
     * migration 013's unique-per-employee constraint); falls back to
     * TimeOut IS NULL otherwise. A ROW_NUMBER() window picks exactly one row
     * per employee even on an install where more than one open punch can
     * exist (pre-migration-013 schema, or a data anomaly) — "most recently
     * started" wins, same tie-break order as api/v1/punch.php's own
     * `ORDER BY Date DESC, TimeIN DESC` open-punch lookup.
     *
     * @param int[] $employeeIds
     * @return array<int, array{punch_id:int, Date:string, TimeIN:string, LunchStart:?string, LunchEnd:?string}>
     */
    public static function loadOpenPunches(mysqli $conn, array $employeeIds): array
    {
        $ids = [];
        foreach ($employeeIds as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $openCond = self::isOpenColumnExists($conn) ? 'tp.IsOpen = 1' : 'tp.TimeOut IS NULL';
        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmt = $conn->prepare(
            "SELECT x.EmployeeID, x.id AS PunchID, x.Date, x.TimeIN, x.LunchStart, x.LunchEnd
             FROM (
                 SELECT tp.EmployeeID, tp.id, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd,
                        ROW_NUMBER() OVER (
                            PARTITION BY tp.EmployeeID
                            ORDER BY tp.Date DESC, tp.TimeIN DESC, tp.id DESC
                        ) AS rn
                 FROM timepunches tp
                 WHERE $openCond AND tp.EmployeeID IN ($ph)
             ) x
             WHERE x.rn = 1"
        );
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['EmployeeID']] = [
                'punch_id'   => (int)$r['PunchID'],
                'Date'       => (string)$r['Date'],
                'TimeIN'     => (string)$r['TimeIN'],
                'LunchStart' => ($r['LunchStart'] !== null && $r['LunchStart'] !== '') ? (string)$r['LunchStart'] : null,
                'LunchEnd'   => ($r['LunchEnd']   !== null && $r['LunchEnd']   !== '') ? (string)$r['LunchEnd']   : null,
            ];
        }
        return $out;
    }

    /**
     * 'in' | 'on_lunch' for an open-punch row from loadOpenPunches(). There is no
     * 'out' case here by construction — the ABSENCE of a row for an employee IS
     * 'out'; callers branch on that before calling this.
     */
    public static function statusForOpenPunch(array $punch): string
    {
        return ($punch['LunchStart'] !== null && $punch['LunchEnd'] === null) ? 'on_lunch' : 'in';
    }

    /**
     * Elapsed seconds/hours for an OPEN punch, matching api/v1/punch.php's
     * handleStatus() bit-for-bit: freeze the counter at lunch start while on
     * lunch, subtract a completed lunch's duration otherwise. Lunch times are
     * anchored to the SAME Date as TimeIN with no overnight-rollover correction
     * — that is exactly what punch.php does (unlike HoursCalculator, which does
     * correct for it), reproduced here on purpose so the native app's live
     * counter and this endpoint's elapsed_seconds never disagree for the same
     * employee at the same moment.
     *
     * @return array{seconds:int, hours:float}
     */
    public static function elapsedFor(array $punch, DateTimeImmutable $now, DateTimeZone $tz): array
    {
        try {
            $in = new DateTimeImmutable($punch['Date'] . ' ' . $punch['TimeIN'], $tz);
        } catch (Exception $e) {
            return ['seconds' => 0, 'hours' => 0.0];
        }
        $elapsed = $now->getTimestamp() - $in->getTimestamp();

        if ($punch['LunchStart'] !== null && $punch['LunchEnd'] === null) {
            // On lunch — freeze at lunch start.
            try {
                $ls = new DateTimeImmutable($punch['Date'] . ' ' . $punch['LunchStart'], $tz);
                $elapsed = $ls->getTimestamp() - $in->getTimestamp();
            } catch (Exception $e) {
                // Unparseable LunchStart: keep the un-frozen elapsed rather than fail the row.
            }
        } elseif ($punch['LunchStart'] !== null && $punch['LunchEnd'] !== null) {
            // Back from lunch — subtract the completed lunch's duration.
            try {
                $ls = new DateTimeImmutable($punch['Date'] . ' ' . $punch['LunchStart'], $tz);
                $le = new DateTimeImmutable($punch['Date'] . ' ' . $punch['LunchEnd'], $tz);
                $elapsed -= max(0, $le->getTimestamp() - $ls->getTimestamp());
            } catch (Exception $e) {
                // Unparseable lunch times: keep elapsed un-adjusted rather than fail the row.
            }
        }

        $seconds = max(0, $elapsed);
        return ['seconds' => $seconds, 'hours' => round($seconds / 3600, 2)];
    }

    /** Combine a punch DATE + TIME into ISO 8601 in $tz, or null. */
    public static function combine(?string $date, ?string $time, DateTimeZone $tz): ?string
    {
        if ($date === null || $time === null || $time === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($date . ' ' . $time, $tz))->format('c');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The shift instance (start/end instants) relevant to `$now`: the scheduled
     * work block anchored to TODAY, or — for an overnight shift still running
     * past midnight — anchored to YESTERDAY, whichever most recently started
     * at-or-before `$now`. Falls back to today's instance when it exists but
     * hasn't started yet (an early clock-in), and to null when neither applies
     * — reported to callers as "unscheduled right now" even though the employee
     * may work other days.
     *
     * Built on ScheduleCalculator::scheduledDaysBetween() (DaysOfWeek parsing
     * lives there and only there) plus HoursCalculator::timeToSeconds() for the
     * span — never a second decoder of either.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    public static function currentShiftInstance(?array $shift, DateTimeImmutable $now, DateTimeZone $tz): ?array
    {
        if ($shift === null) {
            return null;
        }

        $startSec = HoursCalculator::timeToSeconds($shift['StartTime'] ?? null);
        $endSec   = HoursCalculator::timeToSeconds($shift['EndTime'] ?? null);
        if ($startSec === null || $endSec === null) {
            return null;
        }
        $isOvernight = $endSec <= $startSec;

        $instanceFor = static function (string $date) use ($startSec, $endSec, $tz): ?array {
            try {
                $start = (new DateTimeImmutable($date . ' 00:00:00', $tz))->modify("+{$startSec} seconds");
            } catch (Exception $e) {
                return null;
            }
            $span = $endSec <= $startSec ? ($endSec + 86400 - $startSec) : ($endSec - $startSec);
            return ['start' => $start, 'end' => $start->modify("+{$span} seconds")];
        };

        $today     = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');

        $candidates = [];
        if (ScheduleCalculator::scheduledDaysBetween($shift, $today, $today) !== []) {
            $c = $instanceFor($today);
            if ($c !== null) $candidates[] = $c;
        }
        // Only an overnight shift can still be running from yesterday into today.
        if ($isOvernight && ScheduleCalculator::scheduledDaysBetween($shift, $yesterday, $yesterday) !== []) {
            $c = $instanceFor($yesterday);
            if ($c !== null) $candidates[] = $c;
        }
        if ($candidates === []) {
            return null;
        }

        $nowTs   = $now->getTimestamp();
        $started = array_values(array_filter(
            $candidates,
            static fn(array $c): bool => $c['start']->getTimestamp() <= $nowTs
        ));
        if ($started !== []) {
            // Most recently started wins — the overnight-yesterday instance beats
            // a not-yet-relevant today instance, and vice versa once today starts.
            usort($started, static fn(array $a, array $b): int =>
                $b['start']->getTimestamp() <=> $a['start']->getTimestamp());
            return $started[0];
        }

        // Nothing has started yet as of `$now` (e.g. an early clock-in ahead of a
        // day shift) — prefer today's instance so scheduled_end is still useful.
        foreach ($candidates as $c) {
            if ($c['start']->format('Y-m-d') === $today) {
                return $c;
            }
        }
        return $candidates[0];
    }

    /** True when `$now` falls inside the shift instance's [start, end) span. */
    public static function isScheduledNow(?array $shift, DateTimeImmutable $now, DateTimeZone $tz): bool
    {
        $instance = self::currentShiftInstance($shift, $now, $tz);
        if ($instance === null) {
            return false;
        }
        $ts = $now->getTimestamp();
        return $ts >= $instance['start']->getTimestamp() && $ts < $instance['end']->getTimestamp();
    }
}
