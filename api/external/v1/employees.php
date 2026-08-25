<?php
/**
 * GET /api/external/v1/employees
 * Authorization: Bearer <api-key>
 *
 * Returns the employee roster so external systems (e.g. ITFlow) can map
 * their own employees to timeclock IDs / emails.
 *
 * BACKWARD COMPATIBILITY (read before changing anything below):
 *   - The original response keys — id (STRING), email, first_name, last_name,
 *     office, active — keep their exact names, types and meaning. `id` stays a
 *     string here even though every new report endpoint emits integer employee
 *     ids; that string-id shape is this endpoint's existing contract and an
 *     integrator may already be relying on it.
 *   - `data.employees` is unchanged in shape and stays the top-level array.
 *     `data.meta` is ADDED alongside it (not merged into it, and `rows` is never
 *     introduced) — an integrator reading `data.employees` today keeps working
 *     unmodified.
 *   - include_inactive DEFAULTS TO TRUE here, unlike every report/dimension
 *     endpoint (which defaults to false). Today this endpoint returns every
 *     employee, locked out or not, with no filter at all — flipping that default
 *     would silently drop employees out of an existing integration's sync the
 *     next time this file is deployed. Because ReportParams::fromRequest()
 *     hardcodes `false` as the default for its own include_inactive parsing (see
 *     ReportParams.php), and that default cannot be overridden by a caller of
 *     the library, `include_inactive` is NOT registered with ReportParams here —
 *     it is parsed by hand below with the correct default, and the users-table
 *     filter is built locally (mirroring ReportParams::buildUserFilter()'s
 *     shape) rather than through buildUserFilter(), which would apply the
 *     library's wrong-for-this-endpoint default.
 *   - limit/offset are new. Their defaults (500 / 0) come from ReportParams, the
 *     same defaults every other endpoint uses. A deployment with fewer than 500
 *     employees sees no behavior change; one with more now gets the first page
 *     instead of the whole roster in one response, matching how every other
 *     endpoint in this API already behaves.
 */

require_once __DIR__ . '/../../../auth/db.php';
require_once __DIR__ . '/../../middleware/ApiResponse.php';
require_once __DIR__ . '/../../middleware/ApiKeyAuth.php';
require_once __DIR__ . '/../../lib/ReportParams.php';
require_once __DIR__ . '/../../lib/Output.php';

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
        '/api/external/v1/employees',
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

    $key   = ApiKeyAuth::authenticate('employees:read');
    $keyId = $key['id'];

    // employee_id/employee_email/office/format/limit/offset go through
    // ReportParams for the same validation + 400 messages every other endpoint
    // gives an integrator. include_inactive is deliberately NOT in this list —
    // see the file header — and is parsed separately below.
    $params = ReportParams::fromRequest($_GET, $conn, [
        'employee_id', 'employee_email', 'office', 'limit', 'offset',
    ]);

    $includeInactive = true; // this endpoint's default, not ReportParams'
    if (array_key_exists('include_inactive', $_GET)) {
        $raw = $_GET['include_inactive'];
        if (is_array($raw) || !is_scalar($raw)) {
            ApiResponse::error('Invalid parameter `include_inactive`: expected a single value.', 400);
        }
        $v = strtolower(trim((string)$raw));
        if (in_array($v, ['1', 'true', 'yes'], true)) {
            $includeInactive = true;
        } elseif (in_array($v, ['0', 'false', 'no'], true)) {
            $includeInactive = false;
        } else {
            ApiResponse::error(
                'Invalid parameter `include_inactive`: "' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8')
                . '". Accepted values are 1/0, true/false, yes/no.',
                400
            );
        }
    }

    // Filter built by hand (mirrors ReportParams::buildUserFilter()'s shape) so
    // this endpoint's include_inactive default (true) is honored instead of the
    // library's hardcoded default (false) — see the file header.
    $conds  = [];
    $types  = '';
    $values = [];

    if (!$includeInactive) {
        // LockOut may be NULL on legacy rows; only 1 means locked out.
        $conds[] = '(u.LockOut IS NULL OR u.LockOut = 0)';
    }
    $empIds = $params->employeeIds();
    if ($empIds !== []) {
        $conds[]  = 'u.ID IN (' . implode(',', array_fill(0, count($empIds), '?')) . ')';
        $types   .= str_repeat('i', count($empIds));
        $values   = array_merge($values, $empIds);
    }
    $empEmails = $params->employeeEmails();
    if ($empEmails !== []) {
        $conds[]  = 'u.Email IN (' . implode(',', array_fill(0, count($empEmails), '?')) . ')';
        $types   .= str_repeat('s', count($empEmails));
        $values   = array_merge($values, $empEmails);
    }
    $offices = $params->offices();
    if ($offices !== []) {
        $conds[]  = 'u.Office IN (' . implode(',', array_fill(0, count($offices), '?')) . ')';
        $types   .= str_repeat('s', count($offices));
        $values   = array_merge($values, $offices);
    }
    $where = $conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds);

    // ----------------------------------------------------------------- count
    $countSql = "SELECT COUNT(*) AS c FROM users u" . $where;
    $stmt = $conn->prepare($countSql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $totalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // ------------------------------------------------------------------ rows
    // TagID is deliberately omitted: it is the sole kiosk auth credential,
    // and exposing it here would let any external-API integrator clock any
    // employee in/out at the kiosk. Integrations that need to reconcile a
    // badge should ask for a fingerprint API (last-4 or SHA-256(TagID))
    // rather than the raw value.
    //
    // has_schedule / shift_id / shift_name reflect only an ACTIVE shift
    // assignment (s.IsActive = 1 in the JOIN), matching
    // ScheduleCalculator::loadShiftsForUsers() exactly, so this field can never
    // disagree with /api/external/v1/schedule or /reports/utilization about
    // whether an employee currently has a schedule.
    //
    // office_id is a scalar subquery, NOT a JOIN: Offices has no unique key on
    // OfficeName (see offices.php's own warnings about this), so a plain
    // `LEFT JOIN Offices o ON o.OfficeName = u.Office` would silently duplicate
    // an employee row — once per matching Offices row — the moment two offices
    // share a name. The subquery instead deterministically picks the
    // lowest-ID match (same "lowest id wins" tie-break ScheduleCalculator uses
    // for overlapping time-off requests), which keeps this query's grain at
    // exactly one row per employee no matter what the Offices table contains.
    $limit  = $params->limit();
    $offset = $params->offset();
    $sql = "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.LockOut,
                   u.JobTitle, u.PhoneNumber, u.ClockStatus,
                   (SELECT o.ID FROM Offices o
                    WHERE o.OfficeName = u.Office
                    ORDER BY o.ID ASC LIMIT 1) AS OfficeID,
                   s.ShiftID, s.ShiftName
            FROM users u
            LEFT JOIN user_shifts us ON us.UserID = u.ID
            LEFT JOIN shifts s ON s.ShiftID = us.ShiftID AND s.IsActive = 1"
        . $where
        . " ORDER BY u.LastName, u.FirstName, u.ID
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types . 'ii', ...array_merge($values, [$limit, $offset]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $employees = [];
    foreach ($rows as $r) {
        // ClockStatus has three real values in this schema: In, Lunch, Out (see
        // functions/clock_handler.php). This endpoint's contract is the 2-value
        // In/Out/null the rest of the external API expects, so "Lunch" — still
        // clocked in, just on a break — folds into "In" rather than being
        // dropped or invented as a third API value. NULL (never punched) stays
        // null rather than defaulting to "Out" the way some internal admin
        // pages do, because "unknown" and "clocked out" are different facts.
        $rawStatus = $r['ClockStatus'];
        if ($rawStatus === 'In' || $rawStatus === 'Lunch') {
            $clockStatus = 'In';
        } elseif ($rawStatus === 'Out') {
            $clockStatus = 'Out';
        } else {
            $clockStatus = null;
        }

        $shiftId = $r['ShiftID'] !== null ? (int)$r['ShiftID'] : null;

        $employees[] = [
            'id'                 => (string)$r['ID'],
            'email'              => $r['Email'],
            'first_name'         => $r['FirstName'],
            'last_name'          => $r['LastName'],
            'office'             => $r['Office'] !== null && $r['Office'] !== '' ? $r['Office'] : null,
            'active'             => empty($r['LockOut']),
            'job_title'          => $r['JobTitle'] !== null && $r['JobTitle'] !== '' ? $r['JobTitle'] : null,
            'phone'              => $r['PhoneNumber'] !== null && $r['PhoneNumber'] !== '' ? $r['PhoneNumber'] : null,
            'office_id'          => $r['OfficeID'] !== null ? (int)$r['OfficeID'] : null,
            'shift_id'           => $shiftId,
            'shift_name'         => $r['ShiftName'] !== null ? (string)$r['ShiftName'] : null,
            'has_schedule'       => $shiftId !== null,
            'employment_status'  => empty($r['LockOut']) ? 'active' : 'locked_out',
            'clock_status'       => $clockStatus,
        ];
    }

    $meta = $params->meta('employees', [
        'filters' => [
            'employee_id'      => $params->employeeIds(),
            'employee_email'   => $params->employeeEmails(),
            'office'           => $params->offices(),
            'include_inactive' => $includeInactive,
        ],
        'rules'       => (object)[],
        'row_count'   => count($employees),
        'total_count' => $totalCount,
        'warnings'    => [],
    ]);

    // Output::send() operates on data['rows'] for its CSV path; the JSON
    // contract must keep data.employees (see the file header), so JSON and CSV
    // take separate final calls rather than sharing one $data shape.
    if ($params->format() === 'csv') {
        Output::send(['rows' => $employees, 'meta' => $meta], $params, 'employees', [
            'id', 'email', 'first_name', 'last_name', 'office', 'active',
            'job_title', 'phone', 'office_id', 'shift_id', 'shift_name',
            'has_schedule', 'employment_status', 'clock_status',
        ]);
    } else {
        ApiResponse::success(['employees' => $employees, 'meta' => $meta]);
    }

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/employees] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
