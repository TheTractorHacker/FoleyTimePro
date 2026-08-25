<?php
/**
 * /api/external/v1/employees
 * Authorization: Bearer <api-key>
 *
 * GET   ?employee_id=&employee_email=&office=&include_inactive=&limit=&offset=&format=
 *       Employee roster read. UNCHANGED by this revision — see
 *       handleGetEmployees() below, byte-for-byte identical to the prior
 *       version of this file (the backward-compatibility notes that used to
 *       sit here now live on that function, right next to the code they
 *       describe). Requires `employees:read`.
 *
 * POST  {first_name, last_name, email?, office?, job_title?, phone_number?, lock_out?}
 *       Create an employee. Requires `employees:write`. 201 on create, 200 on
 *       an idempotent replay.
 *
 * PATCH ?id=<user id>  {first_name?, last_name?, email?, office?, job_title?, phone_number?, lock_out?}
 *       Update ONLY the fields present in the body; everything else is left
 *       alone. Requires `employees:write`. 200, or 404 if `id` doesn't exist.
 *
 * FIELD ALLOWLIST — the entire reason this endpoint is separate from
 * admin/edit_user.php (which can also set TagID, Pass, UserPIN, TwoFASecret,
 * TwoFAEnabled, TwoFARecoveryCode, RecoveryCodeHash, AdminOverride2FA and
 * ClockStatus). This external surface can NEVER touch any of those:
 *   - TagID is the sole kiosk auth credential — a write here could redirect
 *     kiosk clock-ins to a different person.
 *   - Pass / UserPIN / TwoFA* are login credentials this API must never be
 *     able to set (or read).
 *   - ClockStatus is a live runtime field owned by the clock/kiosk flow, not
 *     something an HR sync should be able to stomp on mid-shift.
 * A body containing ANY key outside {first_name, last_name, email, office,
 * job_title, phone_number, lock_out} — case-insensitively, including
 * near-spellings like "TagID", "tag_id", "pass", "password", "pin",
 * "clock_status" — fails the WHOLE request with a 400 naming every rejected
 * key; nothing is ever silently dropped. See assertAllowlistedFields() below:
 * the check is a plain, EXACT, case-sensitive string allowlist, so there is
 * no fuzzy/near-spelling special-casing anywhere that could drift out of
 * sync with the forbidden list above — anything that isn't one of those
 * seven exact keys is rejected, full stop, which trivially also catches
 * every case variant and near-spelling of every forbidden field.
 *
 * TagID, Pass and UserPIN are left NULL/unset on create — this endpoint
 * cannot provision kiosk or portal login credentials at all. That remains an
 * admin-console-only action, permanently, by design.
 *
 * EMAIL UNIQUENESS: users.Email has no UNIQUE constraint at the DB level.
 * This file enforces "no duplicate email" itself with a check-then-insert /
 * check-then-update against the `users` table (409 on a collision) — see
 * findEmailCollision() below. `users` holds only non-archived rows —
 * archiving moves a row out to the separate `user-archive` table entirely
 * (admin/archive_user.php), so this check never needs an "archived" flag;
 * every row `users` holds is live by construction. Because there is no
 * DB-level unique index backing this, the check-then-write has a narrow race
 * window (two concurrent requests creating the same brand-new email could
 * both pass the check before either INSERTs) that cannot be fully closed
 * from the application layer alone; closing it completely would need a
 * schema migration, which is outside this task's permitted scope (only
 * migrations/016, the shared write-idempotency table, belongs to this
 * change). The window is kept as small as practical: no other query runs
 * between each collision check and its write.
 *
 * IDEMPOTENCY: both writes honor an OPTIONAL `Idempotency-Key` header
 * (opaque, <=64 chars, [A-Za-z0-9._~-]) against api_write_idempotency
 * (migrations/016), via WriteRequest::checkIdempotency()/recordIdempotency().
 * A replay (the same key's Idempotency-Key seen before, for an `employee`
 * resource) returns the ORIGINAL write's resulting row with
 * `idempotent_replay: true` and 200 — the write, and the email-collision
 * check, are never re-run.
 *
 * TRUST MODEL: an API key with `employees:write` is admin-equivalent — every
 * write applies immediately, with no pending_edits approval queue (that
 * queue is EMPLOYEE self-service only).
 *
 * NOT BUILT HERE, ON PURPOSE: no delete/archive action. This task is
 * create+update only — archiving a user is a materially different operation
 * (it moves the row to `user-archive` and cascades: admin/archive_user.php)
 * that deserves its own deliberate design pass, not a bolt-on here.
 *
 * Response shape (unchanged from the pre-write version of this file):
 *   data.employees[] items (GET) and POST/PATCH's single-item `data` share
 *   every field name: id (STRING), email, first_name, last_name, office,
 *   active, job_title, phone, office_id, shift_id, shift_name, has_schedule,
 *   employment_status, clock_status. See handleGetEmployees() for the full
 *   backward-compatibility contract on data.employees / data.meta.
 */

require_once __DIR__ . '/../../../auth/db.php';
require_once __DIR__ . '/../../middleware/ApiResponse.php';
require_once __DIR__ . '/../../middleware/ApiKeyAuth.php';
require_once __DIR__ . '/../../lib/WriteBootstrap.php';
require_once __DIR__ . '/../../lib/WriteRequest.php';
require_once __DIR__ . '/../../lib/ReportParams.php';
require_once __DIR__ . '/../../lib/Output.php';

/**
 * The exact, case-sensitive, seven-key allowlist this endpoint accepts on
 * write. See the file header's FIELD ALLOWLIST section for why this is
 * deliberately narrower than admin/edit_user.php.
 *
 * Declared HERE, before the dispatch below, not down next to
 * assertAllowlistedFields() where it originally sat: unlike a function or
 * class, a top-level `const` is NOT hoisted — it executes in file order like
 * any other statement. Sitting after the dispatch block meant it was still
 * undefined ("Undefined constant") the moment a POST/PATCH actually reached
 * assertAllowlistedFields(), since the script had not yet reached that line.
 */
const EMPLOYEES_WRITE_ALLOWED_FIELDS = [
    'first_name', 'last_name', 'email', 'office', 'job_title', 'phone_number', 'lock_out',
];

try {
    // WriteBootstrap::begin() replaces this file's former standalone
    // audit-hook + method-check + authenticate() block wholesale — it does
    // all of that itself (security headers, ONE shutdown audit-log hook,
    // 405+Allow before auth, then ApiKeyAuth::authenticate() for the scope
    // that matches the ACTUAL incoming method). That means there is exactly
    // ONE audit log entry per request, same as before this revision — not
    // two — because the old scaffolding below this comment is gone, not
    // duplicated alongside WriteBootstrap's.
    $auth   = WriteBootstrap::begin('/api/external/v1/employees', [
        'GET'   => 'employees:read',
        'POST'  => 'employees:write',
        'PATCH' => 'employees:write',
    ]);
    $keyId  = $auth['id'];
    $method = $auth['method'];

    switch ($method) {
        case 'GET':
            handleGetEmployees($conn);
            break;
        case 'POST':
            handleCreateEmployee($conn, $keyId);
            break;
        case 'PATCH':
            handlePatchEmployee($conn, $keyId);
            break;
    }
} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/employees] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}

// ============================================================================
// GET — employee roster read. Body is UNCHANGED from the pre-write version of
// this file: every line below (down to the closing brace) is the exact same
// code that used to sit directly in the top-level try{} block, now wrapped in
// a function only so it can be dispatched to by method. $conn is passed as a
// parameter in place of what was previously an ambient global in the same
// file scope.
//
// BACKWARD COMPATIBILITY (read before changing anything below):
//   - The original response keys — id (STRING), email, first_name, last_name,
//     office, active — keep their exact names, types and meaning. `id` stays a
//     string here even though every new report endpoint emits integer employee
//     ids; that string-id shape is this endpoint's existing contract and an
//     integrator may already be relying on it.
//   - `data.employees` is unchanged in shape and stays the top-level array.
//     `data.meta` is ADDED alongside it (not merged into it, and `rows` is never
//     introduced) — an integrator reading `data.employees` today keeps working
//     unmodified.
//   - include_inactive DEFAULTS TO TRUE here, unlike every report/dimension
//     endpoint (which defaults to false). Today this endpoint returns every
//     employee, locked out or not, with no filter at all — flipping that default
//     would silently drop employees out of an existing integration's sync the
//     next time this file is deployed. Because ReportParams::fromRequest()
//     hardcodes `false` as the default for its own include_inactive parsing (see
//     ReportParams.php), and that default cannot be overridden by a caller of
//     the library, `include_inactive` is NOT registered with ReportParams here —
//     it is parsed by hand below with the correct default, and the users-table
//     filter is built locally (mirroring ReportParams::buildUserFilter()'s
//     shape) rather than through buildUserFilter(), which would apply the
//     library's wrong-for-this-endpoint default.
//   - limit/offset are new. Their defaults (500 / 0) come from ReportParams, the
//     same defaults every other endpoint uses. A deployment with fewer than 500
//     employees sees no behavior change; one with more now gets the first page
//     instead of the whole roster in one response, matching how every other
//     endpoint in this API already behaves.
// ============================================================================
function handleGetEmployees(mysqli $conn): void
{
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
}

// ============================================================================
// POST — create an employee. See the file header for the field allowlist,
// email-uniqueness and idempotency contracts.
// ============================================================================
function handleCreateEmployee(mysqli $conn, int $keyId): void
{
    $body = WriteRequest::json();
    assertAllowlistedFields($body);

    $firstName   = WriteRequest::requireString($body, 'first_name', 255);
    $lastName    = WriteRequest::requireString($body, 'last_name', 255);
    $email       = extractEmailField($body);
    $office      = WriteRequest::optionalString($body, 'office', 100);
    $jobTitle    = WriteRequest::optionalString($body, 'job_title', 100);
    $phoneNumber = WriteRequest::optionalString($body, 'phone_number', 20);
    $lockOut     = optionalBoolField($body, 'lock_out') ?? false;

    // Idempotency is checked HERE — right after basic field parsing, before
    // the email-collision check below — mirroring punches.php's
    // handleCreatePunch(): a retried identical request is racing against the
    // SIDE EFFECT of its own first attempt, and that first attempt's own row
    // is exactly what a naive collision check on the retry would (falsely)
    // flag as "email already in use". Checking idempotency first means the
    // retry short-circuits to the original response before ever reaching a
    // business check that only makes sense for a genuinely NEW write.
    $idemKey    = WriteRequest::idempotencyKey();
    $existingId = WriteRequest::checkIdempotency($conn, $keyId, $idemKey, 'employee');
    if ($existingId !== null) {
        $item = fetchEmployeeItemForWrite($conn, $existingId);
        if ($item !== null) {
            $item['idempotent_replay'] = true;
            ApiResponse::success($item, 'Already recorded', 200);
        }
        // Referenced row is gone (nothing in this endpoint deletes employees;
        // an out-of-band admin archive is the only way this could happen) —
        // fall through and perform a fresh write rather than erroring on a
        // stale mapping.
    }

    // Email uniqueness is application-level only — see the file header's
    // EMAIL UNIQUENESS section for the accepted TOCTOU limitation. The window
    // is kept as small as practical: no other query runs between this check
    // and the INSERT below.
    if ($email !== null) {
        $collisionId = findEmailCollision($conn, $email, null);
        if ($collisionId !== null) {
            ApiResponse::error(
                'Conflict: email "' . safeEcho($email) . '" is already in use by employee ID ' . $collisionId . '.',
                409
            );
        }
    }

    // TagID, Pass, and UserPIN are left NULL/unset here by design — this
    // endpoint can never provision kiosk or portal login credentials (see the
    // file header). ClockStatus is likewise left NULL/unset: it is a live
    // runtime field owned by the clock/kiosk flow, not something a create
    // call should seed.
    $lockOutInt = $lockOut ? 1 : 0;
    $stmt = $conn->prepare(
        "INSERT INTO users (FirstName, LastName, Email, Office, JobTitle, PhoneNumber, LockOut)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssssssi', $firstName, $lastName, $email, $office, $jobTitle, $phoneNumber, $lockOutInt);
    // findEmailCollision() above already catches the common case; this
    // narrows the remaining TOCTOU window (two concurrent creates for the
    // same brand-new email both passing that check before either INSERTs)
    // from a generic 500 down to the same 409 the app-level check gives —
    // migration 019's UNIQUE index on users.Email is what actually makes
    // that window safe rather than merely unlikely.
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062 && str_contains($e->getMessage(), 'uk_users_email')) {
            ApiResponse::error('Conflict: email "' . safeEcho((string)$email) . '" is already in use.', 409);
        }
        throw $e;
    }
    $newId = (int)$conn->insert_id;
    $stmt->close();

    if ($idemKey !== null) {
        WriteRequest::recordIdempotency($conn, $keyId, $idemKey, 'employee', $newId);
    }

    $item = fetchEmployeeItemForWrite($conn, $newId);
    ApiResponse::success($item, null, 201);
}

// ============================================================================
// PATCH ?id=<user id> — update allowlisted fields only. Only fields present
// in the body are changed; everything else is left alone.
// ============================================================================
function handlePatchEmployee(mysqli $conn, int $keyId): void
{
    $rawId = $_GET['id'] ?? null;
    if (!is_scalar($rawId) || !preg_match('/^[1-9]\d*$/', trim((string)$rawId))) {
        ApiResponse::error('Missing or invalid required query parameter: `id`.', 400);
    }
    $targetId = (int)trim((string)$rawId);

    $body = WriteRequest::json();
    assertAllowlistedFields($body);

    if (!fetchEmployeeExists($conn, $targetId)) {
        ApiResponse::error('Employee not found: ' . $targetId, 404);
    }

    // Idempotency is checked (and, on a genuine write, recorded) before any
    // field is touched — same reasoning as handleCreateEmployee() and
    // punches.php's PATCH handler: a replay must short-circuit to the
    // original result regardless of what the current row looks like now.
    $idemKey    = WriteRequest::idempotencyKey();
    $existingId = WriteRequest::checkIdempotency($conn, $keyId, $idemKey, 'employee');
    if ($existingId !== null) {
        $item = fetchEmployeeItemForWrite($conn, $existingId);
        if ($item !== null) {
            $item['idempotent_replay'] = true;
            ApiResponse::success($item, 'Already recorded', 200);
        }
    }

    // Only fields ACTUALLY PRESENT in the body are touched — everything else
    // is left alone, gated by array_key_exists() rather than "is the parsed
    // value non-null". first_name/last_name reuse requireString() even
    // though they are optional AT THE PATCH LEVEL, because the underlying
    // columns are NOT NULL: a present-but-blank value must be rejected (400),
    // never silently coerced into clearing a required column. email / office
    // / job_title / phone_number are nullable columns, so a present blank
    // value there legitimately means "clear this field" — optionalString()'s
    // documented blank-collapses-to-null behavior is exactly that semantic,
    // which is why those four are safe to call unconditionally once gated by
    // array_key_exists(), while lock_out (a NOT NULL boolean column) treats
    // an explicit JSON null the same as "absent" — see below.
    $fields = []; // ColumnName => [mysqli bind type, value]

    if (array_key_exists('first_name', $body)) {
        $fields['FirstName'] = ['s', WriteRequest::requireString($body, 'first_name', 255)];
    }
    if (array_key_exists('last_name', $body)) {
        $fields['LastName'] = ['s', WriteRequest::requireString($body, 'last_name', 255)];
    }
    $emailProvided = array_key_exists('email', $body);
    $newEmail = null;
    if ($emailProvided) {
        $newEmail = extractEmailField($body); // null here means "clear it"
        $fields['Email'] = ['s', $newEmail];
    }
    if (array_key_exists('office', $body)) {
        $fields['Office'] = ['s', WriteRequest::optionalString($body, 'office', 100)];
    }
    if (array_key_exists('job_title', $body)) {
        $fields['JobTitle'] = ['s', WriteRequest::optionalString($body, 'job_title', 100)];
    }
    if (array_key_exists('phone_number', $body)) {
        $fields['PhoneNumber'] = ['s', WriteRequest::optionalString($body, 'phone_number', 20)];
    }
    // lock_out: optionalBoolField() 400s on anything but a real JSON boolean
    // or null. A present-but-null lock_out is treated as "no-op" (consistent
    // with how an absent key behaves), never as an attempt to NULL out a
    // NOT NULL column and never as a silently-guessed default.
    $lockOut = optionalBoolField($body, 'lock_out');
    if ($lockOut !== null) {
        $fields['LockOut'] = ['i', $lockOut ? 1 : 0];
    }

    if ($fields !== []) {
        // Same TOCTOU acknowledgment as handleCreateEmployee() — see the file
        // header's EMAIL UNIQUENESS section. Excludes the row being patched.
        if ($emailProvided && $newEmail !== null) {
            $collisionId = findEmailCollision($conn, $newEmail, $targetId);
            if ($collisionId !== null) {
                ApiResponse::error(
                    'Conflict: email "' . safeEcho($newEmail) . '" is already in use by employee ID ' . $collisionId . '.',
                    409
                );
            }
        }

        // Column names in $setParts come ONLY from the fixed literal keys
        // this function itself assigned into $fields above (FirstName,
        // LastName, Email, Office, JobTitle, PhoneNumber, LockOut) — never
        // from a client-supplied string — so this interpolation cannot be
        // used to inject arbitrary SQL structure. Every VALUE is still bound
        // as a parameter, never interpolated.
        $setParts = [];
        $types    = '';
        $values   = [];
        foreach ($fields as $col => [$bindType, $val]) {
            $setParts[] = "`$col` = ?";
            $types     .= $bindType;
            $values[]   = $val;
        }
        $types   .= 'i';
        $values[] = $targetId;

        $sql  = "UPDATE users SET " . implode(', ', $setParts) . " WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        // Same TOCTOU narrowing as handleCreateEmployee(): findEmailCollision()
        // above catches the common case, migration 019's UNIQUE index is the
        // real backstop for the remaining race window.
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062 && str_contains($e->getMessage(), 'uk_users_email')) {
                $conflictEmail = $emailProvided && $newEmail !== null ? $newEmail : '(unknown)';
                ApiResponse::error('Conflict: email "' . safeEcho($conflictEmail) . '" is already in use.', 409);
            }
            throw $e;
        }
        $stmt->close();
    }

    if ($idemKey !== null) {
        WriteRequest::recordIdempotency($conn, $keyId, $idemKey, 'employee', $targetId);
    }

    $item = fetchEmployeeItemForWrite($conn, $targetId);
    ApiResponse::success($item, null, 200);
}

// ============================================================================
// Shared write-side helpers
// ============================================================================

/**
 * Rejects the WHOLE request (400) if the body contains any key outside
 * EMPLOYEES_WRITE_ALLOWED_FIELDS, naming every rejected key explicitly.
 * Deliberately a plain exact-string allowlist — see the file header for why
 * that alone is sufficient to catch every case variant and near-spelling of
 * every forbidden field without needing to enumerate them here too.
 */
function assertAllowlistedFields(array $body): void
{
    $unknown = array_values(array_diff(array_map('strval', array_keys($body)), EMPLOYEES_WRITE_ALLOWED_FIELDS));
    if ($unknown !== []) {
        ApiResponse::error(
            'Unrecognized field(s): ' . implode(', ', array_map(static fn($f) => '`' . $f . '`', $unknown))
            . '. This endpoint accepts only: ' . implode(', ', EMPLOYEES_WRITE_ALLOWED_FIELDS) . '. '
            . 'Kiosk badge ID, login password, PIN, 2FA settings, and live clock status can only be '
            . 'changed from the admin console.',
            400
        );
    }
}

/**
 * lock_out is the only boolean field this endpoint accepts. WriteRequest's
 * shared typed-field helpers cover string/int/float/date/enum (no bool) —
 * this is intentionally local and tiny rather than a call-site-of-one
 * addition to that shared class. Absent or explicit JSON null both return
 * null ("no opinion"); anything present that isn't a real JSON boolean 400s.
 */
function optionalBoolField(array $body, string $field): ?bool
{
    if (!array_key_exists($field, $body) || $body[$field] === null) {
        return null;
    }
    $v = $body[$field];
    if (!is_bool($v)) {
        ApiResponse::error('Invalid field `' . $field . '`: expected a boolean (true/false).', 400);
    }
    return $v;
}

/**
 * Extracts and format-validates the `email` field. Reuses
 * WriteRequest::optionalString() for the presence/blank/length handling
 * (so "" collapses to null exactly like every other optional string field),
 * then layers a FILTER_VALIDATE_EMAIL check mirroring
 * ReportParams::parseEmails()'s validation of the `employee_email` query
 * parameter, so a malformed email is rejected the same way on both the read
 * and write sides of this API.
 */
function extractEmailField(array $body, string $field = 'email'): ?string
{
    $email = WriteRequest::optionalString($body, $field, 255);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ApiResponse::error(
            'Invalid field `' . $field . '`: "' . safeEcho($email) . '" is not a valid email address.',
            400
        );
    }
    return $email;
}

/**
 * Returns the ID of a DIFFERENT user already holding $email, or null if
 * $email is free. $excludeId (the row being updated, for PATCH) is never
 * treated as its own collision. See the file header's EMAIL UNIQUENESS
 * section for the TOCTOU caveat. MySQL's utf8mb4_general_ci collation on
 * users.Email makes this comparison case-insensitive already — no LOWER()
 * needed.
 */
function findEmailCollision(mysqli $conn, string $email, ?int $excludeId): ?int
{
    if ($excludeId !== null) {
        $stmt = $conn->prepare("SELECT ID FROM users WHERE Email = ? AND ID != ? LIMIT 1");
        $stmt->bind_param('si', $email, $excludeId);
    } else {
        $stmt = $conn->prepare("SELECT ID FROM users WHERE Email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['ID'] : null;
}

/** True if a `users` row with this ID exists. */
function fetchEmployeeExists(mysqli $conn, int $id): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE ID = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Builds the POST/PATCH response body: exactly the same shape as one item of
 * GET's data.employees[] array (same field names, same derivations —
 * ClockStatus folding, null-if-empty, the office_id scalar-subquery
 * tie-break, the IsActive-shift JOIN). Deliberately a SEPARATE query/mapping
 * from handleGetEmployees() rather than a shared helper the GET loop also
 * calls: the task requires GET's existing code to stay byte-for-byte
 * untouched, and duplicating this single-row shape here means there is zero
 * risk of a refactor accidentally changing GET's behavior. Keep this in sync
 * with handleGetEmployees()'s per-row mapping by hand if that ever changes.
 */
function fetchEmployeeItemForWrite(mysqli $conn, int $id): ?array
{
    $sql = "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.LockOut,
                   u.JobTitle, u.PhoneNumber, u.ClockStatus,
                   (SELECT o.ID FROM Offices o
                    WHERE o.OfficeName = u.Office
                    ORDER BY o.ID ASC LIMIT 1) AS OfficeID,
                   s.ShiftID, s.ShiftName
            FROM users u
            LEFT JOIN user_shifts us ON us.UserID = u.ID
            LEFT JOIN shifts s ON s.ShiftID = us.ShiftID AND s.IsActive = 1
            WHERE u.ID = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) {
        return null;
    }

    $rawStatus = $r['ClockStatus'];
    if ($rawStatus === 'In' || $rawStatus === 'Lunch') {
        $clockStatus = 'In';
    } elseif ($rawStatus === 'Out') {
        $clockStatus = 'Out';
    } else {
        $clockStatus = null;
    }

    $shiftId = $r['ShiftID'] !== null ? (int)$r['ShiftID'] : null;

    return [
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

/**
 * Echo an offending value back to the integrator without letting it become
 * markup or a control-character injection in a log/console. Mirrors
 * ReportParams::safe() / WriteRequest::safe() exactly.
 */
function safeEcho(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    if (strlen($value) > 64) {
        $value = substr($value, 0, 64) . '…';
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
