<?php
/**
 * Punch API — native mobile app endpoint
 * GET  ?action=status   current status + open punch info
 * POST ?action=clockin
 * POST ?action=lunchstart
 * POST ?action=lunchend
 * POST ?action=clockout
 * GET  ?action=history  this week's punches
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../auth/db.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/ApiResponse.php';
require_once __DIR__ . '/../middleware/ClientIp.php';

date_default_timezone_set('America/Chicago');

$user  = AuthMiddleware::authenticate();
$empID = (int) $user['userId'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':     handleStatus($empID);              break;
    case 'clockin':    handlePunch($empID, 'clockin');    break;
    case 'lunchstart': handlePunch($empID, 'lunchstart'); break;
    case 'lunchend':   handlePunch($empID, 'lunchend');   break;
    case 'clockout':   handlePunch($empID, 'clockout');   break;
    case 'history':    handleHistory($empID);             break;
    default:           ApiResponse::error('Invalid action', 404);
}

function handleStatus(int $empID): void
{
    global $conn;

    $stmt = $conn->prepare("SELECT FirstName, LastName, ClockStatus FROM users WHERE ID = ?");
    $stmt->bind_param("i", $empID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) ApiResponse::error('User not found', 404);

    $status = $user['ClockStatus'] ?? 'Out';
    $name   = trim($user['FirstName'] . ' ' . $user['LastName']);

    $pStmt = $conn->prepare(
        "SELECT id, Date, TimeIN, LunchStart, LunchEnd FROM timepunches
         WHERE EmployeeID = ? AND TimeOUT IS NULL
         ORDER BY Date DESC, TimeIN DESC LIMIT 1"
    );
    $pStmt->bind_param("i", $empID);
    $pStmt->execute();
    $punch = $pStmt->get_result()->fetch_assoc();

    $clockInTime    = null;
    $lunchStartTime = null;
    $elapsedSeconds = 0;

    if ($punch) {
        $clockInTime    = $punch['TimeIN'];
        $lunchStartTime = $punch['LunchStart'] ?: null;
        $base           = $punch['Date'] . ' ';
        $inTs           = strtotime($base . $punch['TimeIN']);
        $now            = time();
        $elapsed        = $now - $inTs;

        if (!empty($punch['LunchStart']) && empty($punch['LunchEnd'])) {
            // On lunch — freeze elapsed at lunch start
            $elapsed = strtotime($base . $punch['LunchStart']) - $inTs;
        } elseif (!empty($punch['LunchStart']) && !empty($punch['LunchEnd'])) {
            // Back from lunch — subtract lunch duration
            $lsDur   = strtotime($base . $punch['LunchEnd']) - strtotime($base . $punch['LunchStart']);
            $elapsed -= max(0, $lsDur);
        }

        $elapsedSeconds = max(0, $elapsed);
    }

    ApiResponse::success([
        'name'             => $name,
        'status'           => $status,
        'clock_in_time'    => $clockInTime,
        'lunch_start_time' => $lunchStartTime,
        'elapsed_seconds'  => $elapsedSeconds,
    ]);
}

function handlePunch(int $empID, string $action): void
{
    global $conn;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ApiResponse::error('Method not allowed', 405);

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $note  = trim($input['note'] ?? '');
    $lat   = isset($input['latitude'])  ? (float)$input['latitude']  : null;
    $lon   = isset($input['longitude']) ? (float)$input['longitude'] : null;
    $ip    = getClientIp();

    // Offline queue support: the mobile app may send `captured_at` (epoch millis)
    // and `source=offline_sync` when draining queued punches. We honor both if
    // the timestamp is within a sane window (7 days back, never in the future),
    // otherwise fall back to live server time. Live web/kiosk punches ignore
    // these fields and behave exactly as before.
    $source = normalizeSource($input['source'] ?? 'live');
    [$date, $now, $capturedAtDatetime] = resolveCaptureTime($input['captured_at'] ?? null, $source);

    // Current status
    $uStmt = $conn->prepare("SELECT ClockStatus FROM users WHERE ID = ?");
    $uStmt->bind_param("i", $empID);
    $uStmt->execute();
    $uRow  = $uStmt->get_result()->fetch_assoc();
    if (!$uRow) ApiResponse::error('User not found', 404);
    $currentStatus = $uRow['ClockStatus'] ?? 'Out';

    // Open punch
    $pStmt = $conn->prepare(
        "SELECT ID, TimeIN, LunchStart, LunchEnd FROM timepunches
         WHERE EmployeeID = ? AND TimeOUT IS NULL
         ORDER BY Date DESC, TimeIN DESC LIMIT 1"
    );
    $pStmt->bind_param("i", $empID);
    $pStmt->execute();
    $punch = $pStmt->get_result()->fetch_assoc();

    switch ($action) {
        case 'clockin':
            if ($punch) {
                ApiResponse::error('Already clocked in since ' . date('g:i A', strtotime($punch['TimeIN'])), 409);
            }
            $stmt = $conn->prepare(
                "INSERT INTO timepunches
                     (EmployeeID, Date, TimeIN, Note, LatitudeIN, LongitudeIN, IPAddressIN, CapturedAt, Source)
                 VALUES (?, ?, ?, ?, ?, ?, INET_ATON(?), ?, ?)"
            );
            $stmt->bind_param(
                "isssddsss",
                $empID, $date, $now, $note, $lat, $lon, $ip, $capturedAtDatetime, $source
            );
            if (!$stmt->execute()) ApiResponse::serverError('Clock in failed');
            setClockStatus($conn, $empID, 'In');
            ApiResponse::success(['time' => date('g:i A', strtotime($now))], 'Clocked in at ' . date('g:i A', strtotime($now)));

        case 'lunchstart':
            if ($currentStatus !== 'In') ApiResponse::error('You must be clocked in to start lunch.', 400);
            if (!$punch || !empty($punch['LunchStart'])) ApiResponse::error('No active punch or lunch already started.', 400);
            $stmt = $conn->prepare(
                "UPDATE timepunches SET LunchStart = ?, LatitudeLunchStart = ?, LongitudeLunchStart = ?, IPAddressLunchStart = INET_ATON(?)
                 WHERE ID = ?"
            );
            $stmt->bind_param("sddsi", $now, $lat, $lon, $ip, $punch['ID']);
            if (!$stmt->execute()) ApiResponse::serverError('Lunch start failed');
            setClockStatus($conn, $empID, 'Lunch');
            ApiResponse::success(['time' => date('g:i A', strtotime($now))], 'Lunch started at ' . date('g:i A', strtotime($now)));

        case 'lunchend':
            if ($currentStatus !== 'Lunch') ApiResponse::error('You are not on lunch.', 400);
            if (!$punch || empty($punch['LunchStart']) || !empty($punch['LunchEnd'])) {
                ApiResponse::error('No active lunch punch found.', 400);
            }
            $stmt = $conn->prepare(
                "UPDATE timepunches SET LunchEnd = ?, LatitudeLunchEnd = ?, LongitudeLunchEnd = ?, IPAddressLunchEnd = INET_ATON(?)
                 WHERE ID = ?"
            );
            $stmt->bind_param("sddsi", $now, $lat, $lon, $ip, $punch['ID']);
            if (!$stmt->execute()) ApiResponse::serverError('Lunch end failed');
            setClockStatus($conn, $empID, 'In');
            ApiResponse::success(['time' => date('g:i A', strtotime($now))], 'Back from lunch at ' . date('g:i A', strtotime($now)));

        case 'clockout':
            if (!$punch) ApiResponse::error('You are not currently clocked in.', 400);
            $lunchEnd = $punch['LunchEnd'];
            // Auto-close forgotten open lunch
            if (!empty($punch['LunchStart']) && empty($lunchEnd)) $lunchEnd = $now;
            $total = calculateHours($punch['TimeIN'], $punch['LunchStart'], $lunchEnd, $now);
            $stmt = $conn->prepare(
                "UPDATE timepunches SET TimeOUT = ?, LunchEnd = ?, TotalHours = ?, LatitudeOut = ?, LongitudeOut = ?, IPAddressOut = INET_ATON(?)
                 WHERE ID = ?"
            );
            $stmt->bind_param("ssdddsi", $now, $lunchEnd, $total, $lat, $lon, $ip, $punch['ID']);
            if (!$stmt->execute()) ApiResponse::serverError('Clock out failed');
            setClockStatus($conn, $empID, 'Out');
            ApiResponse::success(
                ['time' => date('g:i A', strtotime($now)), 'hours_worked' => number_format($total, 2)],
                'Clocked out at ' . date('g:i A', strtotime($now)) . ' — ' . number_format($total, 2) . ' hrs worked'
            );
    }
}

function handleHistory(int $empID): void
{
    global $conn;

    $start = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
    $end   = $_GET['end']   ?? date('Y-m-d', strtotime('monday this week +6 days'));

    $stmt = $conn->prepare(
        "SELECT Date, TimeIN, LunchStart, LunchEnd, TimeOUT, TotalHours, Note, Source
         FROM timepunches WHERE EmployeeID = ? AND Date BETWEEN ? AND ?
         ORDER BY Date DESC, TimeIN DESC"
    );
    $stmt->bind_param("iss", $empID, $start, $end);
    $stmt->execute();
    $rows   = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $punches = [];
    $totalHours = 0.0;

    foreach ($rows as $row) {
        $hours = $row['TotalHours'] ? (float)$row['TotalHours'] : null;
        if ($hours) $totalHours += $hours;
        $punches[] = [
            'date'        => date('m/d/Y', strtotime($row['Date'])),
            'in'          => $row['TimeIN']    ? date('g:i A', strtotime($row['TimeIN']))    : null,
            'lunch_start' => $row['LunchStart']? date('g:i A', strtotime($row['LunchStart'])): null,
            'lunch_end'   => $row['LunchEnd']  ? date('g:i A', strtotime($row['LunchEnd']))  : null,
            'out'         => $row['TimeOUT']   ? date('g:i A', strtotime($row['TimeOUT']))   : null,
            'hours'       => $hours ? number_format($hours, 2) : null,
            'note'        => $row['Note'] ?? '',
            'source'      => $row['Source'] ?? 'live',
        ];
    }

    ApiResponse::success([
        'punches'     => $punches,
        'total_hours' => number_format($totalHours, 2),
        'start'       => $start,
        'end'         => $end,
    ]);
}

function setClockStatus(mysqli $conn, int $empID, string $status): void
{
    $stmt = $conn->prepare("UPDATE users SET ClockStatus = ? WHERE ID = ?");
    $stmt->bind_param("si", $status, $empID);
    $stmt->execute();
}

function calculateHours(?string $in, ?string $lunchStart, ?string $lunchEnd, string $out): float
{
    if (!$in) return 0.0;
    $total = strtotime($out) - strtotime($in);
    if ($lunchStart && $lunchEnd) {
        $total -= max(0, strtotime($lunchEnd) - strtotime($lunchStart));
    }
    return round(max(0, $total) / 3600, 2);
}

function getClientIp(): ?string
{
    // Delegates to trusted_client_ip() — see api/middleware/ClientIp.php.
    // Previously honored X-Forwarded-For unconditionally, so the punch
    // audit columns (IPAddressIN / IPAddressOut / IPAddressLunch*) were
    // spoofable by any caller.
    $ip = trusted_client_ip();
    return $ip !== 'unknown' ? $ip : null;
}

/**
 * Normalize the client-supplied source string to a small whitelist so callers
 * can't smuggle arbitrary strings into the audit column.
 */
function normalizeSource(?string $raw): string
{
    $allowed = ['live', 'offline_sync', 'kiosk'];
    $s = strtolower(trim((string)($raw ?? '')));
    return in_array($s, $allowed, true) ? $s : 'live';
}

/**
 * Compute the effective (date, time, datetime-for-CapturedAt) for a punch.
 *
 * When the app sends `captured_at` (epoch millis) AND source=='offline_sync',
 * we use that timestamp as the punch time — subject to sanity limits:
 *   - not in the future (allow +2 minutes of clock drift),
 *   - not more than 7 days in the past.
 * Anything outside that window is clamped back to server-now and logged in
 * `Source` as 'live' so the record isn't silently backdated.
 *
 * For every other source (live/kiosk), `captured_at` is ignored and we use
 * server-now — preserving the pre-migration behavior exactly.
 *
 * @return array [string $date (Y-m-d), string $time (H:i:s), ?string $capturedAtDateTime (Y-m-d H:i:s)]
 */
function resolveCaptureTime($capturedAtMillisRaw, string &$source): array
{
    $nowTs = time();
    $liveDate = date('Y-m-d', $nowTs);
    $liveTime = date('H:i:s', $nowTs);

    if ($source !== 'offline_sync' || $capturedAtMillisRaw === null) {
        return [$liveDate, $liveTime, null];
    }

    $ms = is_numeric($capturedAtMillisRaw) ? (int)$capturedAtMillisRaw : 0;
    if ($ms <= 0) {
        $source = 'live';
        return [$liveDate, $liveTime, null];
    }

    $ts = intdiv($ms, 1000);
    $maxFuture = $nowTs + 120;                 // 2 min clock drift tolerance
    $maxPast   = $nowTs - (7 * 24 * 3600);     // 7 days back

    if ($ts > $maxFuture || $ts < $maxPast) {
        // Out of range — record as live, don't retroactively rewrite history.
        $source = 'live';
        return [$liveDate, $liveTime, null];
    }

    return [
        date('Y-m-d', $ts),
        date('H:i:s', $ts),
        date('Y-m-d H:i:s', $ts),
    ];
}
