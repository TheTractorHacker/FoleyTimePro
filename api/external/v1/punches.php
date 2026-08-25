<?php
/**
 * /api/external/v1/punches
 * Authorization: Bearer <api-key>
 *
 * GET   ?since=<ISO8601>[&after_id=<int>][&employee_email=…][&limit=<n>]
 *       Delta-sync read of raw clock punches. UNCHANGED by this revision —
 *       see handleGetPunches() below, byte-for-byte identical to the prior
 *       version of this file. Requires `punches:read`.
 *
 * POST  {employee_id, date, time_in, time_out, lunch_start?, lunch_end?, note?}
 *       Create a punch — always a HISTORICAL CORRECTION, never a live clock
 *       event. `time_out` is REQUIRED (not optional): this endpoint creates
 *       only COMPLETE, closed punches. An external system (an HR import, a
 *       payroll correction tool) has no business simulating a live
 *       "clock in and leave it open" event — that is what the mobile app /
 *       kiosk are for. Requiring a closed punch means IsOpen never becomes 1
 *       for a row this endpoint creates, which means the uk_open_per_employee
 *       race (migration 013 — "at most one open punch per employee") cannot
 *       recur on this surface BY CONSTRUCTION: there is no code path here
 *       that could ever produce a second open punch, because there is no
 *       code path that produces an open punch at all. Requires
 *       `punches:write`. 201 on create, 200 on an idempotent replay.
 *
 * PATCH ?id=<punch id>  {reason, time_in?, time_out?, lunch_start?, lunch_end?, note?}
 *       Correct specific fields of an EXISTING punch. `reason` is required —
 *       every changelog row this app writes has one, and an external
 *       correction with no stated reason is exactly the kind of edit an
 *       auditor most needs explained. Every changed field gets its OWN
 *       punch_changelog row (mirrors admin/save_punches.php's per-field
 *       pattern), and the full resulting punch is re-validated as a whole —
 *       not just the touched fields in isolation — so e.g. a PATCH that only
 *       moves `lunch_start` still gets rejected if that would put it after
 *       the (unchanged) `time_out`. Requires `punches:write`. 200.
 *
 * Both writes are TRUSTED, admin-equivalent writes: an API key with the
 * `:write` scope applies immediately (no pending_edits approval queue — that
 * queue is EMPLOYEE self-service only) and logs to punch_changelog exactly
 * like admin/save_punches.php does, attributed to `api:<key name>`.
 *
 * IDEMPOTENCY: both writes honor an OPTIONAL `Idempotency-Key` header
 * (opaque, <=64 chars, [A-Za-z0-9._~-]) against api_write_idempotency
 * (migrations/016), via WriteRequest::checkIdempotency()/recordIdempotency().
 * Unlike the mobile punch API, the header is never REQUIRED here — every
 * external write on this surface is inherently a "correction", there is no
 * live/offline distinction that would make backdating conditional on it.
 * A replay (same key's Idempotency-Key seen before, for a `punch` resource)
 * returns the ORIGINAL write's resulting row with `idempotent_replay: true`
 * and 200, never re-applying the write.
 *
 * Response shape (unchanged from the pre-write version of this file):
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
 * POST/PATCH return `data` shaped like a SINGLE item from `punches[]` above
 * (same field names) — plus `idempotent_replay: true` on a replay hit — so a
 * client parses creates, corrections and reads identically.
 *
 * On the next GET poll pass BOTH `next_since` and `next_after_id` back as
 * `since` and `after_id` to walk the cursor without duplicates or gaps.
 */

require_once __DIR__ . '/../../../auth/db.php';
require_once __DIR__ . '/../../middleware/ApiResponse.php';
require_once __DIR__ . '/../../middleware/ApiKeyAuth.php';
require_once __DIR__ . '/../../lib/WriteBootstrap.php';
require_once __DIR__ . '/../../lib/WriteRequest.php';
require_once __DIR__ . '/../../lib/HoursCalculator.php';

try {
    // WriteBootstrap::begin() replaces this file's former standalone
    // audit-hook + method-check + authenticate() block wholesale — it does
    // all of that itself (security headers, ONE shutdown audit-log hook,
    // 405+Allow before auth, then ApiKeyAuth::authenticate() for the scope
    // that matches the ACTUAL incoming method). That means there is exactly
    // ONE audit log entry per request now, same as before this revision —
    // not two — because the old scaffolding below this comment is gone, not
    // duplicated alongside WriteBootstrap's.
    $auth   = WriteBootstrap::begin('/api/external/v1/punches', [
        'GET'   => 'punches:read',
        'POST'  => 'punches:write',
        'PATCH' => 'punches:write',
    ]);
    $keyId  = $auth['id'];
    $method = $auth['method'];

    switch ($method) {
        case 'GET':
            handleGetPunches($conn);
            break;
        case 'POST':
            handleCreatePunch($conn, $keyId);
            break;
        case 'PATCH':
            handlePatchPunch($conn, $keyId);
            break;
    }
} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/punches] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}

// ============================================================================
// GET — delta-sync read. Body is UNCHANGED from the pre-write version of this
// file: every line below (down to the closing brace) is the exact same code
// that used to sit directly in the try{} block, now wrapped in a function
// only so it can be dispatched to by method. $conn is passed as a parameter
// in place of what was previously an ambient global in the same file scope.
// ============================================================================
function handleGetPunches(mysqli $conn): void
{
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
}

// ============================================================================
// POST — create a punch (historical correction; always closed; see the file
// header for why `time_out` is required and what that buys us for free).
// ============================================================================
function handleCreatePunch(mysqli $conn, int $keyId): void
{
    $body = WriteRequest::json();

    $employeeId = WriteRequest::requireInt($body, 'employee_id', 1);
    $date       = WriteRequest::requireDate($body, 'date');
    $timeIn     = requireTimeField($body, 'time_in');
    $timeOut    = requireTimeField($body, 'time_out');
    $lunchStart = optionalTimeField($body, 'lunch_start');
    $lunchEnd   = optionalTimeField($body, 'lunch_end');
    $note       = WriteRequest::optionalString($body, 'note', 500);

    // A zero-length "shift" is not a real correction of worked time — reject
    // it explicitly rather than silently recording 0.00 hours. (Any other
    // time_out is either later-same-day or legitimately overnight; see
    // validatePunchTimeOrdering()/shiftedDate() below for how "overnight" is
    // decided — the SAME logic the read side already uses, so create and
    // read never disagree about what counts as an overnight shift.)
    if ($timeOut === $timeIn) {
        ApiResponse::error('Invalid field `time_out`: must differ from `time_in`.', 400);
    }

    // Everything from here through recordIdempotency() runs inside ONE
    // transaction, opened by locking this employee's `users` row FOR UPDATE
    // first (lockEmployeeForPunchWrite()). Without that lock, two concurrent
    // requests for the same employee — the canonical case being an HTTP
    // client's timeout-triggered retry firing while the original request is
    // still in flight — can both pass the idempotency check (neither has
    // recorded yet) AND both pass checkPunchOverlap() (neither has inserted
    // yet), then both INSERT: two distinct, valid timepunches rows for the
    // same shift, silently double-counting hours in any payroll export. The
    // lock forces a second concurrent request to block here until the first
    // commits, so its own checks then correctly see what the first just did.
    $conn->begin_transaction();

    // 404s immediately if employee_id doesn't exist at all — independent of
    // idempotency, since a nonexistent id can never have a legitimate prior
    // write to replay under THIS app's data model (employees are locked out
    // or archived, never hard-deleted, so an id that once existed still
    // exists; an id that never existed never will have).
    $employee = lockEmployeeForPunchWrite($conn, $employeeId);

    // Idempotency is checked HERE — immediately after acquiring the lock,
    // before any business-rule validation — and NOT after checkPunchOverlap()
    // below. That ordering matters: a retried identical request is racing
    // against the SIDE EFFECT of its own first attempt. The first attempt's
    // insert is exactly what a naive overlap check on the retry would (falsely)
    // flag as a conflict — "this employee already has an overlapping punch",
    // when that punch IS this request's own prior result. Checking idempotency
    // first means the retry short-circuits to the original response before ever
    // reaching a business check that only makes sense for a genuinely NEW write
    // — including the lockout check just below, so a replay of an
    // already-succeeded write still succeeds even if the employee was locked
    // out sometime between the original call and this retry. Mirrors
    // punch.php's own ordering: its idempotency check runs before the
    // "already clocked in" and hire-date-floor checks, for the same reason.
    $idemKey    = WriteRequest::idempotencyKey();
    $existingId = WriteRequest::checkIdempotency($conn, $keyId, $idemKey, 'punch');
    if ($existingId !== null) {
        $row = fetchPunchForResponse($conn, $existingId);
        $conn->commit();
        if ($row !== null) {
            $row['idempotent_replay'] = true;
            ApiResponse::success($row, 'Already recorded', 200);
        }
        // If the referenced row is gone (shouldn't happen — nothing in this
        // API deletes punches), fall through and perform a fresh write.
    }

    // Not a replay: NOW enforce the employee's current state. 422 (mirrors
    // punch.php's hire-date-floor precedent — a well-formed request blocked
    // by the employee's current state) if locked out.
    if ((int)$employee['LockOut'] === 1) {
        ApiResponse::error('Employee ' . $employeeId . ' is locked out and cannot receive new punches.', 422);
    }

    // date: real calendar date already guaranteed by requireDate(); not in
    // the future; not before the employee's HireDate if this install has
    // that column (mirrors punch.php's hire-date floor check exactly — same
    // query shape, same 422).
    validateDateAgainstEmployee($conn, $date, $employeeId);

    // time_out-vs-time_in overnight handling, lunch-pair completeness, and
    // lunch-within-shift bounds — re-run verbatim by PATCH against the
    // RESULTING full punch, so both entry points agree.
    validatePunchTimeOrdering($date, $timeIn, $timeOut, $lunchStart, $lunchEnd);

    // Reject if this employee already has any punch whose interval overlaps
    // the one being created — now correctly serialized by the lock above,
    // and correctly date-aware for overnight punches (see checkPunchOverlap()
    // for why raw TIME-column comparison alone is not enough).
    checkPunchOverlap($conn, $employeeId, $date, $timeIn, $timeOut, null);

    $calc = HoursCalculator::punchWorkedSeconds([
        'TimeIN' => $timeIn, 'LunchStart' => $lunchStart, 'LunchEnd' => $lunchEnd, 'TimeOut' => $timeOut,
    ]);
    $totalHours = HoursCalculator::roundHours(HoursCalculator::secondsToHours($calc['seconds']), 0);

    $ip   = ApiKeyAuth::clientIp();
    $cols = WriteRequest::timepunchColumnsAvailable($conn);

    // IsOpen (migration 013) is a VIRTUAL GENERATED column — MySQL rejects
    // any INSERT that names it explicitly. It is deliberately absent from
    // every column list below. With TimeOut always bound to a real value
    // here, IsOpen self-derives to NULL ("closed") wherever the column
    // exists; there is no branch to write for it.
    if ($cols['CapturedAt'] && $cols['Source']) {
        // CapturedAt stays NULL: that column documents when a MOBILE DEVICE
        // captured a punch (migration 011); nothing was "captured on a
        // device" here. Source = 'api_write' — a third value alongside the
        // documented 'live'/'offline_sync' (and the kiosk app's 'kiosk').
        // Source is a free VARCHAR(24) (not a SQL ENUM) and nothing in this
        // codebase branches on its value (verified: no endpoint filters
        // WHERE Source = ...), so adding a new, honestly-labeled value for
        // "created via the external write API" is safe and more useful for
        // audit than mislabeling this as 'live'.
        $sql = "INSERT INTO timepunches
                    (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, Note, TotalHours, IPAddressIN, CapturedAt, Source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, INET_ATON(?), NULL, 'api_write')";
    } else {
        $sql = "INSERT INTO timepunches
                    (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, Note, TotalHours, IPAddressIN)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, INET_ATON(?))";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'issssssds',
        $employeeId, $date, $timeIn, $lunchStart, $lunchEnd, $timeOut, $note, $totalHours, $ip
    );
    // No if(!execute()) / errno=1062 branch here (unlike punch.php's
    // clockin handler): this INSERT can never collide with
    // uk_open_per_employee (TimeOut is always non-null above, so IsOpen
    // always computes to NULL for a row this endpoint writes — see the
    // comment above). A genuinely unexpected execute() failure propagates
    // as a Throwable (this codebase's mysqli runs under PHP 8.1+'s default
    // MYSQLI_REPORT_ERROR|STRICT — confirmed for this install — under which
    // execute() THROWS rather than returning false, so an `if (!execute())`
    // check here would never fire anyway) and is caught by this file's
    // top-level try/catch, same as any other unexpected error.
    $stmt->execute();
    $newPunchId = $conn->insert_id;
    $stmt->close();

    $keyName = lookupApiKeyName($conn, $keyId);
    writePunchChangelogCreate($conn, $employeeId, $date, $keyName);

    if ($idemKey !== null) {
        WriteRequest::recordIdempotency($conn, $keyId, $idemKey, 'punch', $newPunchId);
    }

    $responseRow = fetchPunchForResponse($conn, $newPunchId);
    $conn->commit();
    ApiResponse::success($responseRow, null, 201);
}

// ============================================================================
// PATCH ?id=<punch id> — correct specific fields of an existing punch.
// ============================================================================
function handlePatchPunch(mysqli $conn, int $keyId): void
{
    $rawId = $_GET['id'] ?? '';
    if (!is_string($rawId) && !is_int($rawId)) {
        ApiResponse::error('Missing or invalid required query parameter: `id`.', 400);
    }
    $rawId = (string)$rawId;
    if (!preg_match('/^\d+$/', trim($rawId)) || (int)$rawId <= 0) {
        ApiResponse::error('Missing or invalid required query parameter: `id`.', 400);
    }
    $punchId = (int)$rawId;

    $body   = WriteRequest::json();
    $reason = WriteRequest::requireString($body, 'reason', 500);

    $current = fetchPunchRowRaw($conn, $punchId);
    if ($current === null) {
        ApiResponse::error('Punch not found: ' . $punchId, 404);
    }

    // Same race this endpoint's POST sibling closes (see
    // lockEmployeeForPunchWrite()'s docblock): everything from here through
    // the final commit() runs inside one transaction, opened by locking this
    // punch's employee row FOR UPDATE first, so two concurrent PATCH/POST
    // requests for the same employee serialize instead of both passing the
    // idempotency check and overlap check before either commits. The
    // LockOut value this returns is intentionally unused here — unlike POST,
    // PATCH never gated on it (correcting a punch for a since-locked-out
    // employee's final timesheet is expected to keep working), and that is
    // unchanged; only the locking side effect is needed.
    $conn->begin_transaction();
    lockEmployeeForPunchWrite($conn, (int)$current['EmployeeID']);

    // Idempotency is checked (and, on a genuine write, recorded) BEFORE the
    // rest of validation — a replay must short-circuit to the original
    // result regardless, exactly like POST.
    $idemKey    = WriteRequest::idempotencyKey();
    $existingId = WriteRequest::checkIdempotency($conn, $keyId, $idemKey, 'punch');
    if ($existingId !== null) {
        $row = fetchPunchForResponse($conn, $existingId);
        $conn->commit();
        if ($row !== null) {
            $row['idempotent_replay'] = true;
            ApiResponse::success($row, 'Already recorded', 200);
        }
    }

    // Merge: only fields ACTUALLY PRESENT in the body override the stored
    // value; everything else keeps its current value. That single rule is
    // what makes "only fields present in the body AND different from
    // current get a changelog row" fall out for free below — an absent
    // field's new value always equals its old value.
    $newTimeIn     = array_key_exists('time_in', $body)     ? requireTimeField($body, 'time_in')     : $current['TimeIN'];
    $newTimeOut    = array_key_exists('time_out', $body)    ? requireTimeField($body, 'time_out')    : $current['TimeOut'];
    $newLunchStart = array_key_exists('lunch_start', $body) ? optionalTimeField($body, 'lunch_start') : $current['LunchStart'];
    $newLunchEnd   = array_key_exists('lunch_end', $body)   ? optionalTimeField($body, 'lunch_end')   : $current['LunchEnd'];
    $newNote       = array_key_exists('note', $body)        ? WriteRequest::optionalString($body, 'note', 500) : $current['Note'];

    // This endpoint only ever edits COMPLETE, closed punches (same
    // rationale as POST — see the file header). If the punch being patched
    // is currently open (TimeOut IS NULL, e.g. an in-progress mobile-app
    // punch) and the caller didn't supply a time_out to close it, there is
    // no valid "resulting full punch" to re-validate against POST's rules.
    if ($newTimeIn === null || $newTimeOut === null) {
        ApiResponse::error(
            'This punch has no recorded `time_in`/`time_out` and cannot be corrected through this endpoint '
            . '(it only edits complete, closed punches — provide `time_out` to close it, or use the mobile/kiosk clock-out flow).',
            400
        );
    }
    if ($newTimeOut === $newTimeIn) {
        ApiResponse::error('Invalid field `time_out`: must differ from `time_in`.', 400);
    }

    // Re-run the SAME time-ordering/lunch-pairing rules as POST against the
    // RESULTING full punch — not just the fields the caller touched. A
    // PATCH that only moves lunch_start must still be rejected if that
    // would put it after the (unchanged) time_out.
    validatePunchTimeOrdering($current['Date'], $newTimeIn, $newTimeOut, $newLunchStart, $newLunchEnd);

    // Same overlap re-check as POST, excluding this punch's own row.
    checkPunchOverlap($conn, (int)$current['EmployeeID'], $current['Date'], $newTimeIn, $newTimeOut, $punchId);

    $calc = HoursCalculator::punchWorkedSeconds([
        'TimeIN' => $newTimeIn, 'LunchStart' => $newLunchStart, 'LunchEnd' => $newLunchEnd, 'TimeOut' => $newTimeOut,
    ]);
    $newTotalHours = HoursCalculator::roundHours(HoursCalculator::secondsToHours($calc['seconds']), 0);

    $keyName = lookupApiKeyName($conn, $keyId);

    // One changelog row per changed field, mirroring admin/save_punches.php's
    // exact per-field pattern — never one combined row. Deliberately does
    // NOT include TotalHours as its own field: it's a derived value, not
    // something the caller sent in the body, so it isn't in this map.
    $changes = [
        'TimeIN'     => [$current['TimeIN'],     $newTimeIn],
        'LunchStart' => [$current['LunchStart'], $newLunchStart],
        'LunchEnd'   => [$current['LunchEnd'],   $newLunchEnd],
        'TimeOut'    => [$current['TimeOut'],    $newTimeOut],
        'Note'       => [$current['Note'],       $newNote],
    ];

    // NOTE: no begin_transaction() here — the transaction opened above
    // (right after fetching $current, alongside the employee-row lock) is
    // still open and covers everything below too, through commit().
    try {
        $upd = $conn->prepare(
            "UPDATE timepunches SET TimeIN = ?, LunchStart = ?, LunchEnd = ?, TimeOut = ?, Note = ?, TotalHours = ? WHERE id = ?"
        );
        $upd->bind_param('sssssdi', $newTimeIn, $newLunchStart, $newLunchEnd, $newTimeOut, $newNote, $newTotalHours, $punchId);
        $upd->execute();
        $upd->close();

        foreach ($changes as $field => $pair) {
            [$old, $new] = $pair;
            if ($old === $new) continue;
            // punch_changelog.OldValue/NewValue are varchar(10). Times
            // (HH:MM:SS, 8 chars) always fit; Note (up to 500 chars here)
            // does not — truncate rather than let the INSERT fail. The
            // full new note text is still authoritative in
            // timepunches.Note itself; this row is an audit trail of WHAT
            // changed, not the sole record of the new value.
            $oldStr = $old === null ? null : truncateChangelogValue((string)$old);
            $newStr = $new === null ? null : truncateChangelogValue((string)$new);

            $log = $conn->prepare(
                "INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $employeeIdForLog = (int)$current['EmployeeID'];
            $log->bind_param('issssss', $employeeIdForLog, $current['Date'], $keyName, $field, $oldStr, $newStr, $reason);
            $log->execute();
            $log->close();
        }

        // Recorded BEFORE commit, inside the same transaction, so a crash
        // between "punch updated" and "idempotency recorded" is impossible —
        // both land together or neither does (previously this ran after
        // commit(), as its own separate, un-atomic statement).
        if ($idemKey !== null) {
            WriteRequest::recordIdempotency($conn, $keyId, $idemKey, 'punch', $punchId);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e; // handled uniformly by this file's top-level catch
    }

    $responseRow = fetchPunchForResponse($conn, $punchId);
    ApiResponse::success($responseRow);
}

// ============================================================================
// Shared write-path helpers (POST + PATCH)
// ============================================================================

/** Required HH:MM:SS (24-hour) time-of-day string. */
function requireTimeField(array $body, string $field): string
{
    $v = WriteRequest::requireString($body, $field, 8);
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $v)) {
        ApiResponse::error('Invalid field `' . $field . '`: expected HH:MM:SS (24-hour clock).', 400);
    }
    return $v;
}

/** Optional HH:MM:SS (24-hour) time-of-day string; absent/empty -> null. */
function optionalTimeField(array $body, string $field): ?string
{
    $v = WriteRequest::optionalString($body, $field, 8);
    if ($v === null) return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $v)) {
        ApiResponse::error('Invalid field `' . $field . '`: expected HH:MM:SS (24-hour clock).', 400);
    }
    return $v;
}

/**
 * Validate the time-ordering of a full (POST-new or PATCH-merged) punch:
 * lunch-pair completeness, lunch-within-shift bounds, and (implicitly, via
 * shiftedDate()) overnight clock-out handling — using the EXACT SAME
 * isOvernight()/shiftedDate() helpers the GET path already uses to decide
 * what counts as an overnight shift, so create/patch and read never
 * disagree. `time_out` vs `time_in` itself has no invalid case beyond
 * "must differ" (checked by the caller): any other pair is either later
 * the same day or legitimately overnight, and since both are HH:MM:SS
 * values bounded to a single day, an overnight wrap is always < 24h by
 * construction — there is no separate ">24h" case to reject.
 */
function validatePunchTimeOrdering(string $date, string $timeIn, string $timeOut, ?string $lunchStart, ?string $lunchEnd): void
{
    if (($lunchStart === null) !== ($lunchEnd === null)) {
        ApiResponse::error(
            'Invalid field `lunch_start`/`lunch_end`: both must be present together, or neither.',
            400
        );
    }
    if ($lunchStart === null) {
        return; // no lunch pair to validate
    }

    $clockOutDate = shiftedDate($date, $timeIn, $timeOut);
    $lunchEndDate = shiftedDate($date, $lunchStart, $lunchEnd);

    $clockInDT    = $date . ' ' . $timeIn;
    $clockOutDT   = $clockOutDate . ' ' . $timeOut;
    $lunchStartDT = $date . ' ' . $lunchStart;
    $lunchEndDT   = $lunchEndDate . ' ' . $lunchEnd;

    if (strcmp($lunchEndDT, $lunchStartDT) <= 0) {
        ApiResponse::error('Invalid field `lunch_end`: must be after `lunch_start`.', 400);
    }
    if (strcmp($lunchStartDT, $clockInDT) < 0) {
        ApiResponse::error('Invalid field `lunch_start`: cannot be before `time_in`.', 400);
    }
    if (strcmp($lunchEndDT, $clockOutDT) > 0) {
        ApiResponse::error('Invalid field `lunch_end`: cannot be after `time_out`.', 400);
    }
}

/**
 * 409 if this employee already has a punch on $date whose [TimeIN, TimeOut]
 * interval overlaps [$timeIn, $timeOut]. Mirrors punch.php's offline_sync
 * overlap-check query shape (same EmployeeID+Date scope, same NULL-TimeOut
 * = "still open, treat as unbounded" handling) generalized from a single
 * instant to a full interval. $excludePunchId excludes the row itself
 * (PATCH re-validating against its own now-changed values).
 */
/**
 * Real overlap test — was a raw TIME-column comparison (TimeIN <= ? AND
 * TimeOut >= ?), which is only correct for two same-day, non-wrapping
 * intervals. The moment either interval is overnight (TimeOut < TimeIN on
 * the same Date — exactly what isOvernight()/shiftedDate() exist elsewhere
 * in this file to handle), that raw comparison silently misses real
 * overlaps: e.g. an existing 20:00-23:30 punch and a new 23:00-06:00 punch
 * on the same Date genuinely overlap 23:00-23:30, but '20:00:00' <=
 * '06:00:00' is FALSE as a bare TIME/string comparison, so the existing row
 * never enters the candidate set and the conflict is never seen — both
 * punches persist and that half hour gets paid twice. Fixed by resolving
 * every candidate (and the new punch itself) to real date-aware instants via
 * shiftedDate() before comparing, instead of comparing TIME columns raw.
 *
 * Also now the lock target for the transaction wrapping create/patch (see
 * lockEmployeeForPunchWrite()) — this function must be called only AFTER
 * that lock is held, or two concurrent requests can both pass it before
 * either commits (the original TOCTOU gap; see handleCreatePunch()).
 */
function checkPunchOverlap(mysqli $conn, int $employeeId, string $date, string $timeIn, string $timeOut, ?int $excludePunchId): void
{
    // Widened window: an existing punch dated the day before can be an
    // overnight shift whose shifted end lands ON $date, and if the NEW
    // punch is itself overnight its own shifted end can land on the day
    // AFTER $date — either direction can produce a real overlap that a
    // same-Date-only query would never even fetch as a candidate.
    $prevDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    $nextDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');

    if ($excludePunchId !== null) {
        $stmt = $conn->prepare(
            "SELECT id, Date, TimeIN, TimeOut FROM timepunches
             WHERE EmployeeID = ? AND Date BETWEEN ? AND ? AND id <> ?"
        );
        $stmt->bind_param('issi', $employeeId, $prevDate, $nextDate, $excludePunchId);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, Date, TimeIN, TimeOut FROM timepunches
             WHERE EmployeeID = ? AND Date BETWEEN ? AND ?"
        );
        $stmt->bind_param('iss', $employeeId, $prevDate, $nextDate);
    }
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($candidates === []) return;

    $newStart = new DateTimeImmutable($date . ' ' . $timeIn);
    $newEnd   = new DateTimeImmutable(shiftedDate($date, $timeIn, $timeOut) . ' ' . $timeOut);

    foreach ($candidates as $c) {
        $cDate = (string)$c['Date'];
        $cIn   = $c['TimeIN'];
        $cOut  = $c['TimeOut'];
        if ($cIn === null) continue;   // no start recorded — nothing to compare

        $existingStart = new DateTimeImmutable($cDate . ' ' . $cIn);
        // An open existing row (TimeOut NULL) has no defined end yet — treat
        // it as unbounded/still running rather than skipping it, so a new
        // closed punch can't be inserted into a window the employee is
        // already actively clocked through. '+50 years' is simply "far
        // enough" to always compare as later than any real newEnd.
        $existingEnd = $cOut === null
            ? $existingStart->modify('+50 years')
            : new DateTimeImmutable(shiftedDate($cDate, $cIn, $cOut) . ' ' . $cOut);

        // Inclusive-boundary overlap test (existingStart <= newEnd AND
        // newStart <= existingEnd), matching the original query's <=/>=
        // semantics — two punches that exactly touch end-to-end still count
        // as a conflict, unchanged from the prior behavior for the cases it
        // did handle correctly.
        if ($existingStart <= $newEnd && $newStart <= $existingEnd) {
            ApiResponse::error('Conflict: this employee already has an overlapping punch on ' . $cDate . '.', 409);
        }
    }
}

/** 404s if $employeeId doesn't exist. Returns ['ID'=>int,'LockOut'=>0|1]. */
function fetchEmployeeForWrite(mysqli $conn, int $employeeId): array
{
    $stmt = $conn->prepare("SELECT ID, LockOut FROM users WHERE ID = ?");
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        ApiResponse::error('Employee not found: ' . $employeeId, 404);
    }
    return $row;
}

/**
 * Same as fetchEmployeeForWrite() but takes a row lock (FOR UPDATE) — MUST be
 * called inside an open transaction, and MUST be the first statement in that
 * transaction, before the idempotency check or checkPunchOverlap().
 *
 * WHY: without this, two concurrent requests for the same employee (the
 * canonical case being an HTTP client's timeout-triggered retry firing while
 * the original request is still in flight) can both pass checkIdempotency()
 * (neither has recorded yet) and both pass checkPunchOverlap() (neither has
 * inserted yet), then both INSERT — two distinct, fully valid timepunches
 * rows for the same shift, each contributing its own TotalHours to any
 * payroll export. Locking this employee's `users` row first forces a second
 * concurrent request to BLOCK here until the first request's transaction
 * commits, at which point its own idempotency check and overlap check
 * correctly observe what the first request just committed. Scoped to the
 * EMPLOYEE (not the whole table) so concurrent writes for two DIFFERENT
 * employees never block each other.
 */
function lockEmployeeForPunchWrite(mysqli $conn, int $employeeId): array
{
    $stmt = $conn->prepare("SELECT ID, LockOut FROM users WHERE ID = ? FOR UPDATE");
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        ApiResponse::error('Employee not found: ' . $employeeId, 404);
    }
    return $row;
}

/**
 * date: not in the future (400), and not before the employee's HireDate if
 * this install has that column (422 — mirrors punch.php's hire-date floor
 * check exactly, including its query shape; skips silently when HireDate
 * is NULL or the column doesn't exist on this install).
 */
function validateDateAgainstEmployee(mysqli $conn, string $date, int $employeeId): void
{
    $tz    = new DateTimeZone('America/Chicago');
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    if ($date > $today) {
        ApiResponse::error('Invalid field `date`: cannot be in the future.', 400);
    }

    if (!usersHireDateColumnExists($conn)) {
        return;
    }
    $stmt = $conn->prepare("SELECT HireDate FROM users WHERE ID = ?");
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && !empty($row['HireDate']) && $date < $row['HireDate']) {
        ApiResponse::error('Invalid field `date`: earlier than the employee hire date.', 422);
    }
}

/** Runtime-detected, cached for the life of the request. users.HireDate is live-only. */
function usersHireDateColumnExists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'HireDate'");
        $exists = ($res && $res->num_rows > 0);
    }
    return $exists;
}

/** 'api:<key name>', truncated to fit punch_changelog.ChangedBy (varchar(100)). */
function lookupApiKeyName(mysqli $conn, int $keyId): string
{
    $stmt = $conn->prepare("SELECT Name FROM api_keys WHERE ID = ?");
    $stmt->bind_param('i', $keyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = ($row && $row['Name'] !== null && $row['Name'] !== '') ? $row['Name'] : ('key#' . $keyId);
    return substr('api:' . $name, 0, 100);
}

/** Raw current row for PATCH's merge-and-diff. Null if the punch id doesn't exist. */
function fetchPunchRowRaw(mysqli $conn, int $punchId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, Note, TotalHours
         FROM timepunches WHERE id = ?"
    );
    $stmt->bind_param('i', $punchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Shape a punch for a POST/PATCH response body — same field names as one
 * item of GET's `punches[]` array (see fetchPunchForResponse()'s SELECT and
 * the transform below, which intentionally mirror handleGetPunches()'s own
 * per-row logic line-for-line rather than sharing a helper with it, so the
 * GET path above stays untouched and trivially provable as unchanged).
 */
function fetchPunchForResponse(mysqli $conn, int $punchId): ?array
{
    $stmt = $conn->prepare(
        "SELECT tp.id, tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart,
                tp.LunchEnd, tp.TimeOut, tp.TotalHours,
                UNIX_TIMESTAMP(tp.UpdatedAt) AS UpdatedAtEpoch,
                u.Email
         FROM timepunches tp
         JOIN users u ON u.ID = tp.EmployeeID
         WHERE tp.id = ?"
    );
    $stmt->bind_param('i', $punchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $tz = new DateTimeZone('America/Chicago');

    $baseDate       = $row['Date'];
    $clockInTime    = $row['TimeIN'];
    $lunchStartTime = $row['LunchStart'];
    $lunchEndTime   = $row['LunchEnd'];
    $clockOutTime   = $row['TimeOut'];

    $lunchEndBaseTime = $lunchStartTime ?? $clockInTime;
    $lunchEndDate = shiftedDate($baseDate, $lunchEndBaseTime, $lunchEndTime);
    $clockOutDate = shiftedDate($baseDate, $clockInTime, $clockOutTime);

    return [
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
}

/** Hard-truncate to fit punch_changelog.OldValue/NewValue (varchar(10)). */
function truncateChangelogValue(string $v): string
{
    return strlen($v) > 10 ? substr($v, 0, 10) : $v;
}

/**
 * The single audit row for a POST create. FieldChanged='Created' (per this
 * endpoint's spec) with OldValue=NULL. NewValue is documented as "a summary"
 * in this endpoint's spec, but OldValue/NewValue are varchar(10) — nowhere
 * near enough room for a real summary of a punch. 'CREATED' (mirrors the
 * literal constant admin/save_punches.php's own ADD PUNCH handler writes to
 * this same column for the same event) fits the column and keeps this
 * endpoint's changelog vocabulary consistent with the admin UI's, which
 * matters more here than cramming a lossy summary into 10 characters.
 */
function writePunchChangelogCreate(mysqli $conn, int $employeeId, string $date, string $changedBy): void
{
    $stmt = $conn->prepare(
        "INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
         VALUES (?, ?, ?, 'Created', NULL, 'CREATED', ?)"
    );
    $reason = 'External API create';
    $stmt->bind_param('isss', $employeeId, $date, $changedBy, $reason);
    $stmt->execute();
    $stmt->close();
}

// ============================================================================
// Original shared date/time helpers — UNCHANGED.
// ============================================================================

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
