<?php
/**
 * ApiTimeFormat — ISO 8601 rendering shared by the /api/external/v1 endpoints.
 *
 * punches.php declares formatDateTime() / formatEpoch() / shiftedDate() /
 * isOvernight() at GLOBAL scope. Copying any of them into a second endpoint would
 * be a fatal redeclaration the moment two of those files are included in one
 * process, so the same logic lives here as static methods. punches.php is
 * deliberately left untouched — its response contract is in production — and new
 * endpoints use this class instead.
 *
 * Everything is emitted with an explicit UTC offset. The caller is a machine in an
 * unknown timezone, and an offsetless "2026-08-24T09:15:00" is only meaningful to
 * whoever wrote it.
 */

final class ApiTimeFormat
{
    /**
     * A UTC epoch — as produced by MySQL's UNIX_TIMESTAMP(col) — as ISO 8601 in $tz.
     *
     * DATETIME columns in this schema are naive wall-clock values in the DB session
     * timezone (auth/db.php sets it from DB_TIMEZONE). Reading them as an epoch and
     * converting here is what makes the result unambiguous across a DST fall-back,
     * where the same local string occurs twice; it is the same reason punches.php
     * reads UpdatedAt as UNIX_TIMESTAMP rather than as a string.
     */
    public static function fromEpoch(?int $epoch, DateTimeZone $tz): ?string
    {
        if ($epoch === null) {
            return null;
        }
        return (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->format('c');
    }

    /** Convenience for a fetched row: null-safe cast of a UNIX_TIMESTAMP() column. */
    public static function epochOrNull($value): ?int
    {
        return ($value === null || $value === '') ? null : (int)$value;
    }

    /** Current time in $tz, ISO 8601 with offset. */
    public static function now(DateTimeZone $tz): string
    {
        return (new DateTimeImmutable('now', $tz))->format('c');
    }

    /**
     * A DATE column as a plain Y-m-d, or null.
     *
     * MariaDB hands back '0000-00-00' for a zero date, which is not a calendar date
     * and would poison a BI date dimension; it is reported as null instead.
     */
    public static function dateOnly(?string $date): ?string
    {
        if ($date === null || $date === '' || $date === '0000-00-00') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return ($d !== false && $d->format('Y-m-d') === $date) ? $date : null;
    }

    /**
     * A TIME column normalized to HH:MM:SS, or null.
     *
     * MySQL's TIME range is -838:59:59..838:59:59, so a corrupt row can hold
     * something no clock can display. Such a value returns null rather than being
     * passed through, so a consumer never has to guess whether "838:00:00" is a
     * time of day.
     */
    public static function timeOnly(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }
        $t = trim($time);
        if ($t === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/', $t, $m)) {
            return null;
        }
        $h = (int)$m[1];
        if ($h > 23) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', $h, (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
    }

    /**
     * Three-letter day name for a Y-m-d date ('Mon'..'Sun'), or '' when unparseable.
     * UTC on purpose: a local midnight does not exist on every spring-forward date.
     * The vocabulary deliberately matches shifts.DaysOfWeek.
     */
    public static function dayOfWeek(string $date): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $d === false ? '' : $d->format('D');
    }

    /**
     * Parse an ISO 8601 instant supplied by an integrator into a UTC epoch.
     *
     * An explicit offset is REQUIRED — without one the parse silently falls back to
     * PHP's default timezone and a delta-sync cursor quietly shifts by hours. Same
     * rule (and same regex) as /api/external/v1/punches' `since`.
     *
     * @return int|null null when the value is unparseable or carries no offset;
     *                  the caller decides what error to raise.
     */
    public static function parseIso8601ToEpoch(string $value): ?int
    {
        $v = trim($value);
        if ($v === '' || !preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $v)) {
            return null;
        }
        try {
            return (new DateTimeImmutable($v))->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }
}
