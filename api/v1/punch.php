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

    // Source is derived SERVER-SIDE from captured_at, never trusted from the
    // body. Prior behavior let a caller send source='offline_sync' on a live
    // request to unlock the 7-day backdate window.
    //   * no captured_at, or captured_at within +/-2min of now  -> 'live'
    //   * captured_at older than that (up to 7 days back)       -> 'offline_sync'
    //   * captured_at in the future or older than 7 days        -> 400
    [$date, $now, $capturedAtDatetime, $source] = resolveCaptureTime($input['captured_at'] ?? null);

    // Offline-sync writes require an Idempotency-Key so that a retried queue-
    // drain (mobile lost the response) doesn't insert the punch again. Live
    // writes accept a key too but don't require one.
    $idemKey = readIdempotencyKey();
    if ($source === 'offline_sync' && $idemKey === null) {
        ApiResponse::error('Idempotency-Key header required for offline_sync writes', 400);
    }
    if ($idemKey !== null) {
        $cached = idempotencyLookup($empID, $idemKey);
        if ($cached !== null) {
            // Replay hit — return a no-op success so the client stops retrying.
            ApiResponse::success(['idempotent_replay' => true, 'punch_id' => $cached], 'Already recorded');
        }
    }

    // Hire-date floor (defense in depth): if the org has recorded a hire date,
    // reject captured_at earlier than it. Skips silently when HireDate is NULL.
    if ($source === 'offline_sync' && $capturedAtDatetime !== null) {
        $hd = $conn->prepare("SELECT HireDate FROM users WHERE ID = ?");
        $hd->bind_param('i', $empID);
        $hd->execute();
        $hdRow = $hd->get_result()->fetch_assoc();
        $hd->close();
        if ($hdRow && !empty($hdRow['HireDate']) && $date < $hdRow['HireDate']) {
            ApiResponse::error('captured_at is earlier than the employee hire date', 422);
        }
    }

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

            // Overlap check for backdated inserts — refuse if the (date,time)
            // being inserted falls inside an existing punch on that Date. Live
            // punches skip this: the "already clocked in" check above catches
            // the only realistic collision.
            if ($source === 'offline_sync') {
                $ov = $conn->prepare(
                    "SELECT ID FROM timepunches
                     WHERE EmployeeID = ? AND Date = ?
                       AND TimeIN <= ?
                       AND (TimeOUT IS NULL OR TimeOUT >= ?)
                     LIMIT 1"
                );
                $ov->bind_param('isss', $empID, $date, $now, $now);
                $ov->execute();
                $ovRow = $ov->get_result()->fetch_assoc();
                $ov->close();
                if ($ovRow) {
                    ApiResponse::error('Backdated clock-in overlaps an existing punch on that date', 409);
                }
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
            if (!$stmt->execute()) {
                // The uk_open_per_employee UNIQUE index (migration 013) fires when
                // two concurrent clockin requests race past the SELECT above. That's
                // a benign concurrency collision — surface it as 409, not 500.
                if ((int)$conn->errno === 1062) {
                    ApiResponse::error('Already clocked in (concurrent request)', 409);
                }
                ApiResponse::serverError('Clock in failed');
            }
            $newPunchId = $conn->insert_id;
            setClockStatus($conn, $empID, 'In');
            if ($idemKey !== null) idempotencyRecord($empID, $idemKey, $newPunchId);
            ApiResponse::success(['time' => date('g:i A', strtotime($now)), 'punch_id' => $newPunchId], 'Clocked in at ' . date('g:i A', strtotime($now)));

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
 * Compute (date, time, capturedAtDateTime, source) for a punch, deriving
 * `source` from captured_at rather than trusting a client-supplied string.
 *
 *   * no captured_at, or captured_at within +/-2min of now  -> 'live'
 *   * captured_at in the past by >2min, still within 7 days -> 'offline_sync'
 *   * captured_at in the future >2min, or older than 7 days -> reject (400)
 *
 * The prior signature took `source` by reference from the caller. That's
 * exactly the vulnerability — a caller could send source='offline_sync' on
 * a live request to unlock the backdate window. Now the caller passes only
 * captured_at and the server decides what source it was.
 *
 * @return array{0:string,1:string,2:?string,3:string} [date Y-m-d, time H:i:s, capturedAtDateTime Y-m-d H:i:s|null, source]
 */
function resolveCaptureTime($capturedAtMillisRaw): array
{
    $nowTs    = time();
    $liveDate = date('Y-m-d', $nowTs);
    $liveTime = date('H:i:s', $nowTs);

    if ($capturedAtMillisRaw === null || $capturedAtMillisRaw === '') {
        return [$liveDate, $liveTime, null, 'live'];
    }

    $ms = is_numeric($capturedAtMillisRaw) ? (int)$capturedAtMillisRaw : 0;
    if ($ms <= 0) {
        return [$liveDate, $liveTime, null, 'live'];
    }

    $ts = intdiv($ms, 1000);
    $maxFuture = $nowTs + 120;                 // 2 min clock drift tolerance
    $maxPast   = $nowTs - (7 * 24 * 3600);     // 7 days back
    $liveWindow = 120;                          // +/- 2 min => still "live"

    if ($ts > $maxFuture || $ts < $maxPast) {
        ApiResponse::error('captured_at is outside the allowed window (must be within 7 days and not in the future)', 400);
    }

    if (abs($nowTs - $ts) <= $liveWindow) {
        return [$liveDate, $liveTime, null, 'live'];
    }

    return [
        date('Y-m-d', $ts),
        date('H:i:s', $ts),
        date('Y-m-d H:i:s', $ts),
        'offline_sync',
    ];
}

/**
 * Idempotency-Key header reader. Accepts opaque tokens up to 64 chars of
 * URL-safe base64-ish characters (the mobile client generates UUIDv4 hex).
 */
function readIdempotencyKey(): ?string
{
    $raw = null;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Idempotency-Key') === 0) { $raw = $v; break; }
        }
    }
    if ($raw === null) {
        $raw = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    }
    if ($raw === null) return null;
    $raw = trim((string)$raw);
    if ($raw === '' || strlen($raw) > 64 || !preg_match('/^[A-Za-z0-9._~-]+$/', $raw)) {
        return null;
    }
    return $raw;
}

/** Return the punch_id previously recorded under this (user, key), or null. */
function idempotencyLookup(int $empID, string $key): ?int
{
    global $conn;
    $stmt = $conn->prepare(
        "SELECT punch_id FROM punch_idempotency WHERE user_id = ? AND idem_key = ?"
    );
    $stmt->bind_param('is', $empID, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['punch_id'] : null;
}

/** Persist (user, key, punch_id) so the next replay hits the cache. */
function idempotencyRecord(int $empID, string $key, int $punchId): void
{
    global $conn;
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO punch_idempotency (user_id, idem_key, punch_id) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('isi', $empID, $key, $punchId);
    $stmt->execute();
    $stmt->close();
}
