<?php
/**
 * GET /api/external/v1/punches?since=<ISO8601>[&after_id=<int>][&employee_email=…][&limit=<n>]
 * Authorization: Bearer <api-key>
 *
 * Returns raw clock punches (in/out + lunch) so external systems own their
 * overtime math. Delta sync via a composite `(since, after_id)` cursor —
 * tie-breaking on `id` prevents cursor stalls when many rows share the same
 * UpdatedAt second (e.g. batch auto-clockout).
 *
 * Timestamp handling: UpdatedAt is read/compared as UTC epoch to avoid MySQL
 * session-timezone round-trips (which are ambiguous during DST fall-back).
 *
 * Response shape:
 *   {
 *     "success": true,
 *     "data": {
 *       "punches": [{
 *         "punch_id": "9931",
 *         "external_employee_id": "42",
 *         "employee_email": "tech@foleyit.com",
 *         "clock_in":    "2026-07-20T08:02:00-05:00",
 *         "lunch_start": "2026-07-20T12:00:00-05:00" | null,
 *         "lunch_end":   "2026-07-20T12:30:00-05:00" | null,
 *         "clock_out":   "2026-07-20T16:31:00-05:00" | null,
 *         "total_hours": 8.02 | null,
 *         "updated_at":  "2026-07-20T16:31:00-05:00"
 *       }],
 *       "next_since":    "2026-07-20T16:31:00-05:00" | null,
 *       "next_after_id": 9931 | null,
 *       "has_more":      true
 *     }
 *   }
 *
 * On the next poll pass BOTH `next_since` and `next_after_id` back as
 * `since` and `after_id` to walk the cursor without duplicates or gaps.
 */

require_once __DIR__ . '/../../../auth/db.php';
require_once __DIR__ . '/../../middleware/ApiResponse.php';
require_once __DIR__ . '/../../middleware/ApiKeyAuth.php';

$startTime   = microtime(true);
$keyId       = null;
$ip          = ApiKeyAuth::clientIp();
$queryString = $_SERVER['QUERY_STRING'] ?? '';

register_shutdown_function(function () use (&$keyId, $startTime, $ip, $queryString) {
    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $code = http_response_code();
    if (!is_int($code)) $code = 500;
    ApiKeyAuth::logRequest(
        $keyId,
        '/api/external/v1/punches',
        $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        $code,
        $durationMs,
        $ip,
        $queryString
    );
});

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        ApiResponse::error('Method not allowed', 405);
    }

    $key   = ApiKeyAuth::authenticate('punches:read');
    $keyId = $key['id'];

    $sinceRaw = trim($_GET['since'] ?? '');
    if ($sinceRaw === '') {
        ApiResponse::error(
            'Missing required parameter: since (ISO 8601 with explicit offset, e.g. 2026-07-20T08:02:00-05:00 or 2026-07-20T13:02:00Z)',
            400
        );
    }
    // Require explicit offset so the parse can't silently fall back to PHP's default tz.
    if (!preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $sinceRaw)) {
        ApiResponse::error(
            '`since` must include an explicit UTC offset (e.g. -05:00 or Z), got: ' . htmlspecialchars($sinceRaw),
            400
        );
    }
    try {
        $sinceDt = new DateTimeImmutable($sinceRaw);
    } catch (Exception $e) {
        ApiResponse::error('Invalid `since` — expected ISO 8601 with offset', 400);
    }
    $sinceEpoch = $sinceDt->getTimestamp();

    $afterId = (int)($_GET['after_id'] ?? 0);
    if ($afterId < 0) $afterId = 0;

    $employeeEmail = trim($_GET['employee_email'] ?? '');
    $employeeEmail = $employeeEmail === '' ? null : $employeeEmail;

    $limit = (int)($_GET['limit'] ?? 500);
    if ($limit < 1)   $limit = 500;
    if ($limit > 500) $limit = 500;
    $fetchLimit = $limit + 1;

    // Composite cursor: (UpdatedAt > since) OR (UpdatedAt = since AND id > after_id).
    // With after_id = 0 the second clause matches every real row (id AUTO_INCREMENT
    // starts at 1), so the WHOLE clause reduces to `UpdatedAt >= since` — first-call
    // inclusive. On subsequent polls after_id > 0 provides the tie-break.
    //
    // FROM_UNIXTIME(?) treats the parameter as UTC epoch and is a query-planning
    // constant, so the idx_timepunches_updated_at index is still used. UpdatedAt is
    // read as UNIX_TIMESTAMP to avoid MySQL session-tz ambiguity across DST fall-back.
    if ($employeeEmail !== null) {
        $stmt = $conn->prepare(
            "SELECT tp.id, tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart,
                    tp.LunchEnd, tp.TimeOut, tp.TotalHours,
                    UNIX_TIMESTAMP(tp.UpdatedAt) AS UpdatedAtEpoch,
                    u.Email
             FROM timepunches tp
             JOIN users u ON u.ID = tp.EmployeeID
             WHERE (tp.UpdatedAt > FROM_UNIXTIME(?)
                    OR (tp.UpdatedAt = FROM_UNIXTIME(?) AND tp.id > ?))
               AND u.Email = ?
             ORDER BY tp.UpdatedAt ASC, tp.id ASC
             LIMIT ?"
        );
        $stmt->bind_param('iiisi', $sinceEpoch, $sinceEpoch, $afterId, $employeeEmail, $fetchLimit);
    } else {
        $stmt = $conn->prepare(
            "SELECT tp.id, tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart,
                    tp.LunchEnd, tp.TimeOut, tp.TotalHours,
                    UNIX_TIMESTAMP(tp.UpdatedAt) AS UpdatedAtEpoch,
                    u.Email
             FROM timepunches tp
             JOIN users u ON u.ID = tp.EmployeeID
             WHERE (tp.UpdatedAt > FROM_UNIXTIME(?)
                    OR (tp.UpdatedAt = FROM_UNIXTIME(?) AND tp.id > ?))
             ORDER BY tp.UpdatedAt ASC, tp.id ASC
             LIMIT ?"
        );
        $stmt->bind_param('iiii', $sinceEpoch, $sinceEpoch, $afterId, $fetchLimit);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $hasMore = count($rows) > $limit;
    if ($hasMore) $rows = array_slice($rows, 0, $limit);

    $tz = new DateTimeZone('America/Chicago');

    $punches = [];
    $lastRow = null;
    foreach ($rows as $row) {
        $baseDate = $row['Date'];  // Y-m-d, punch date (clock_in day)

        $clockInTime    = $row['TimeIN'];
        $lunchStartTime = $row['LunchStart'];
        $lunchEndTime   = $row['LunchEnd'];
        $clockOutTime   = $row['TimeOut'];

        // Overnight-shift handling: clockout/lunch-end may be on the next day, but
        // the DB only stores a single Date column. Roll Date forward when the later
        // TIME is earlier than its logical predecessor.
        $lunchEndBaseTime = $lunchStartTime ?? $clockInTime;
        $lunchEndDate = shiftedDate($baseDate, $lunchEndBaseTime, $lunchEndTime);
        $clockOutDate = shiftedDate($baseDate, $clockInTime, $clockOutTime);

        $punches[] = [
            'punch_id'             => (string)$row['id'],
            'external_employee_id' => (string)$row['EmployeeID'],
            'employee_email'       => $row['Email'],
            'clock_in'             => formatDateTime($baseDate, $clockInTime, $tz),
            'lunch_start'          => formatDateTime($baseDate, $lunchStartTime, $tz),
            'lunch_end'            => formatDateTime($lunchEndDate, $lunchEndTime, $tz),
            'clock_out'            => formatDateTime($clockOutDate, $clockOutTime, $tz),
            'total_hours'          => $row['TotalHours'] !== null ? (float)$row['TotalHours'] : null,
            'updated_at'           => formatEpoch((int)$row['UpdatedAtEpoch'], $tz),
        ];
        $lastRow = $row;
    }

    $out = ['punches' => $punches];
    if ($lastRow !== null) {
        $out['next_since']    = formatEpoch((int)$lastRow['UpdatedAtEpoch'], $tz);
        $out['next_after_id'] = (int)$lastRow['id'];
    } else {
        $out['next_since']    = null;
        $out['next_after_id'] = null;
    }
    $out['has_more'] = $hasMore;

    ApiResponse::success($out);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/punches] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}

/** True if $endTime < $startTime (means the event wrapped past midnight). */
function isOvernight(?string $startTime, ?string $endTime): bool
{
    if ($startTime === null || $startTime === '') return false;
    if ($endTime   === null || $endTime   === '') return false;
    return strcmp($endTime, $startTime) < 0;
}

/** Return the base date, or +1 day if endTime wrapped past midnight relative to startTime. */
function shiftedDate(string $date, ?string $startTime, ?string $endTime): string
{
    if (!isOvernight($startTime, $endTime)) return $date;
    try {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    } catch (Exception $e) {
        return $date;
    }
}

/** Combine punch DATE + TIME into ISO 8601 in the given tz. */
function formatDateTime(?string $date, ?string $time, DateTimeZone $tz): ?string
{
    if ($date === null || $time === null || $time === '') return null;
    try {
        $dt = new DateTimeImmutable($date . ' ' . $time, $tz);
        // Spring-forward: PHP silently rolls non-existent local times forward.
        // Detect and log; still return the (shifted) value so downstream doesn't crash.
        if ($dt->format('H:i:s') !== $time) {
            error_log(
                "[external-api/punches] Non-existent local time '{$date} {$time}' shifted to "
                . $dt->format('c')
            );
        }
        return $dt->format('c');
    } catch (Exception $e) {
        return null;
    }
}

/** Convert a UTC epoch (integer) to ISO 8601 in the given tz. */
function formatEpoch(int $epoch, DateTimeZone $tz): string
{
    return (new DateTimeImmutable('@' . $epoch))
        ->setTimezone($tz)
        ->format('c');
}
