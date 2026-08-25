<?php
/**
 * /api/external/v1/timeoff
 * Authorization: Bearer <api-key>
 *
 *   GET   Scope: timeoff:read   — the RAW time-off request feed (unchanged,
 *         see the block below this one for its full contract).
 *   POST  Scope: timeoff:write  — submit a new request on behalf of an
 *         employee. Always lands as Status='Pending': an external system can
 *         REQUEST time off, never self-approve it in the same call — approval
 *         is a deliberate separate PATCH, so a compromised key can create a
 *         pending request but cannot unilaterally grant itself paid leave.
 *   PATCH ?id=<id>&action=approve|reject   Scope: timeoff:write   — review a
 *         Pending request. Approving deducts the leave balance in the same
 *         transaction as the status update (see the PATCH section below).
 *
 * Both write verbs honor an OPTIONAL Idempotency-Key header via
 * WriteRequest::checkIdempotency()/recordIdempotency() against
 * api_write_idempotency (migrations/016_write_api_idempotency.sql) — a
 * present+reused key replays the original result instead of writing again; a
 * present+new key writes then records; an absent key writes with no dedup.
 * POST records under ResourceType='timeoff'; PATCH's review records under
 * ResourceType='timeoff_review' — different namespaces because "submit" and
 * "review" are different actions that can each be retried independently
 * against the same underlying request id.
 *
 * ---------------------------------------------------------------- GET ------
 * GET /api/external/v1/timeoff
 * Authorization: Bearer <api-key>   Scope: timeoff:read
 *
 * The RAW time-off request feed — one row per request, unfolded and unclipped.
 * This is deliberately the opposite of /api/external/v1/reports/timeoff, which
 * apportions hours across a range and folds requests up by employee: this
 * endpoint hands back the request records themselves so an external system can
 * own its own aggregation, exactly the way /api/external/v1/punches hands back
 * raw punches instead of computed hours.
 *
 * Params: employee_id, employee_email, office, status, type, tz, format, limit,
 *         offset, plus:
 *
 *   start / end     optional overlap filter (StartDate <= end AND EndDate >=
 *                    start). Independently optional — passing only one bounds
 *                    that side and leaves the other open — because the primary
 *                    sync mechanism here is `updated_since`, not a date window.
 *   updated_since    ISO 8601 WITH AN EXPLICIT OFFSET (e.g. 2026-07-20T08:00:00
 *                    -05:00 or …Z), same contract and same reason as punches.php
 *                    `since`: without a required offset the parse would silently
 *                    fall back to PHP's default timezone and a delta cursor would
 *                    quietly drift. Matches COALESCE(ReviewedAt, RequestedAt) so
 *                    a review action re-surfaces the request even if it was
 *                    already synced when first submitted.
 *
 * `start`/`end` are intentionally NOT registered with ReportParams: its own
 * start/end handling requires BOTH whenever EITHER is present, which is exactly
 * wrong for a delta-sync feed where the common case is `updated_since` alone.
 * They are parsed by hand below and folded back out of meta.ignored_params so a
 * caller who does pass them is not told (falsely) that they had no effect.
 *
 * NOTE ON include_inactive: this endpoint does not accept it. ReportParams'
 * users-table filter (ReportParams::buildUserFilter()) defaults to
 * active-employees-only with no way for a caller here to widen that, so requests
 * belonging to a since-locked-out employee are excluded by default. A future
 * revision could add the param the way every report endpoint does; until then,
 * an integrator syncing full history for terminated employees needs a different
 * endpoint or a direct export.
 *
 * No `totals` block: like punches.php, this is a raw row feed, not an aggregate.
 *
 * ---------------------------------------------------------------- POST -----
 * POST /api/external/v1/timeoff
 * Authorization: Bearer <api-key>   Scope: timeoff:write
 * Body (JSON): employee_id (int, required), start_date, end_date (YYYY-MM-DD,
 *   required, end_date >= start_date), request_type (enum, required — see
 *   ALLOWED_REQUEST_TYPES below), reason (string, optional), hours_requested
 *   (float, optional — defaults to functions/leave_balance.php's
 *   businessHoursBetween($start_date, $end_date), the same fallback the admin
 *   UI's own approval step uses).
 * 201 on create, 200 on an idempotent replay. Response is one time-off-request
 * row shaped exactly like a GET row (see $shapeTimeoffRow below), plus
 * `idempotent_replay`: bool.
 *
 * ALLOWED_REQUEST_TYPES intentionally includes 'Unpaid' alongside Vacation /
 * Sick / Personal / Other — migration 017 (already live on this install, see
 * SHOW COLUMNS) widened time_off_requests.RequestType to 5 values, and this
 * endpoint's own GET path already treats 'Unpaid' as canonical in its `type`
 * filter above. Unpaid deliberately never reaches leave_balances (see the
 * PATCH section) — it has no ledger row per migration 017's own comment.
 *
 * ---------------------------------------------------------------- PATCH ----
 * PATCH /api/external/v1/timeoff?id=<request id>&action=approve|reject
 * Authorization: Bearer <api-key>   Scope: timeoff:write
 * Body (JSON): review_notes (string, optional).
 *
 * Only a Pending request may be reviewed — 409 if it is already Approved or
 * Rejected. That check is NOT a plain read-then-write: the authoritative guard
 * is the UPDATE's own `WHERE ... AND Status = 'Pending'` clause plus an
 * affected_rows check, so two concurrent reviews of the same request can't
 * both succeed (the second one's UPDATE matches zero rows once the first has
 * committed, and reports 409). This is the same "let the atomic write detect
 * the race, don't trust an earlier read" discipline punch.php uses for
 * uk_open_per_employee — there is no unique index to lean on for a status
 * transition, so an atomic conditional UPDATE is the equivalent tool here.
 *
 * ReviewedBy is left NULL on every review — there is no admins.id for an
 * API-key actor (see the DB facts in WriteBootstrap.php's header) — with the
 * identifying actor preserved in ReviewNotes instead: the caller's own
 * review_notes when given, else 'Approved via external API (key: <name>)' /
 * 'Rejected via external API (key: <name>)'.
 *
 * On approve: the Status/ReviewedAt/ReviewNotes UPDATE and
 * functions/leave_balance.php's deductLeaveBalance() run inside one
 * mysqli transaction (begin_transaction/commit/rollback) — this is the one
 * place in this file where two related writes must succeed or fail together,
 * per the task brief. deductLeaveBalance() is SKIPPED for RequestType=Unpaid
 * (see ALLOWED_REQUEST_TYPES above) since leave_balances.LeaveType's enum
 * doesn't include 'Unpaid' at all — calling it would either throw or silently
 * misrecord a balance that was never meant to exist.
 * The hours deducted are the request's own stored HoursRequested (the value
 * fixed at submission time — an explicit POST override if one was given, the
 * businessHoursBetween() fallback otherwise), NOT recomputed at review time.
 * This deliberately differs from admin/time_off_requests.php, whose form has
 * no hours-override input and so always recomputes; recomputing here would
 * silently discard a caller's explicit hours_requested. A legacy request
 * created through a path that never set HoursRequested (the employee
 * self-service form, the mobile app) still gets the businessHoursBetween()
 * fallback computed and persisted at review time, matching the admin UI for
 * that specific case.
 *
 * On reject: Status/ReviewedAt/ReviewNotes only — no balance change on a
 * fresh Pending->Rejected transition.
 *
 * 200 on success (or on an idempotent replay of the SAME review call — see
 * the file-level Idempotency-Key note above). Response is the updated request
 * row, same shape as POST, plus `idempotent_replay`: bool.
 */

require_once __DIR__ . '/../../lib/WriteBootstrap.php';
require_once __DIR__ . '/../../lib/WriteRequest.php';
require_once __DIR__ . '/../../lib/ReportParams.php';
require_once __DIR__ . '/../../lib/Output.php';
require_once __DIR__ . '/../../lib/ApiTimeFormat.php';
require_once __DIR__ . '/../../../functions/leave_balance.php';

/**
 * Canonical RequestType vocabulary for POST/PATCH — see the file header for
 * why 'Unpaid' is included. Kept as one named constant so POST's requireEnum()
 * call and any future validation stay in sync with each other by construction.
 */
const TIMEOFF_ALLOWED_REQUEST_TYPES = ['Vacation', 'Sick', 'Personal', 'Other', 'Unpaid'];

/** Strict Y-m-d validity check (UTC, no local-midnight ambiguity). */
$isValidDate = static function (string $date): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    return $d !== false && $d->format('Y-m-d') === $date;
};

/** Inclusive Y-m-d day count; 0 when either date is invalid or reversed. */
$inclusiveDays = static function (string $from, string $to) use ($isValidDate): int {
    if (!$isValidDate($from) || !$isValidDate($to) || $to < $from) {
        return 0;
    }
    $utc = new DateTimeZone('UTC');
    $a   = new DateTimeImmutable($from, $utc);
    $b   = new DateTimeImmutable($to, $utc);
    return (int)$a->diff($b)->days + 1;
};

/**
 * Fixed-vocabulary comma list, matched case-insensitively and returned in the
 * canonical ENUM spelling (what gets bound AND what is echoed in meta.filters).
 * Unrecognized values are a 400, not a silent empty result — "status=denied"
 * returning zero rows reads as an outage, not a typo.
 *
 * @param string[] $canonical
 * @return string[]
 */
$parseEnumList = static function (array $get, string $name, array $canonical): array {
    if (!array_key_exists($name, $get)) {
        return [];
    }
    $raw = $get[$name];
    if (is_array($raw)) {
        $flat = [];
        foreach ($raw as $v) { if (is_scalar($v)) { $flat[] = (string)$v; } }
        $raw = implode(',', $flat);
    } elseif (!is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a value or comma list.', 400);
    }

    $lookup = [];
    foreach ($canonical as $c) { $lookup[strtolower($c)] = $c; }

    $out = [];
    foreach (explode(',', (string)$raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '') { continue; }
        $key = strtolower($piece);
        if (!isset($lookup[$key])) {
            $echo = htmlspecialchars(
                substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $piece) ?? '', 0, 64),
                ENT_QUOTES,
                'UTF-8'
            );
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $echo . '". Accepted values are '
                . implode(', ', $canonical) . ' (one value or a comma list).',
                400
            );
        }
        if (!in_array($lookup[$key], $out, true)) { $out[] = $lookup[$key]; }
    }
    return $out;
};

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

/**
 * Fetch one time_off_requests row by ID, joined exactly like the GET feed's
 * query (same columns, same admins join for the reviewer's username — never
 * a password or TwoFA* column). Shared by POST/PATCH so a write response is
 * built from a single fresh read of what was actually persisted, not from
 * echoing back the request body. Returns null if the id doesn't exist (a
 * stale idempotency pointer, most realistically — see the replay branches
 * below).
 */
$fetchTimeoffRow = static function (mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare(
        "SELECT tor.ID, tor.EmployeeID, tor.StartDate, tor.EndDate, tor.RequestType,
                tor.Status, tor.Reason, tor.ReviewNotes, tor.HoursRequested,
                UNIX_TIMESTAMP(tor.RequestedAt) AS RequestedAtEpoch,
                UNIX_TIMESTAMP(tor.ReviewedAt)  AS ReviewedAtEpoch,
                u.Email, u.FirstName, u.LastName,
                a.username AS ReviewedByUsername
         FROM time_off_requests tor
         JOIN users u ON u.ID = tor.EmployeeID
         LEFT JOIN admins a ON a.id = tor.ReviewedBy
         WHERE tor.ID = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

/**
 * Shape one fetched row exactly like the GET feed's per-row fields (same 15
 * keys, same derivations) — field-for-field identical to the inline shaping
 * in the GET branch below, factored out so POST/PATCH responses read like a
 * GET row instead of inventing a parallel shape. GET's own loop is left
 * untouched (not rewritten to call this) so the existing read path carries
 * zero risk of behavioral drift from this refactor.
 *
 * Write responses render requested_at/reviewed_at in UTC — there is no `tz`
 * query param on a JSON-body write the way there is on GET, and UTC is an
 * unambiguous default for a machine client to parse.
 */
$shapeTimeoffRow = static function (array $r, DateTimeZone $tz) use ($inclusiveDays, $nullIfBlank): array {
    $reqStart = ApiTimeFormat::dateOnly($r['StartDate']);
    $reqEnd   = ApiTimeFormat::dateOnly($r['EndDate']);
    $days     = ($reqStart !== null && $reqEnd !== null) ? $inclusiveDays($reqStart, $reqEnd) : 0;

    $formatEpoch = static function ($epoch) use ($tz): ?string {
        $e = ApiTimeFormat::epochOrNull($epoch);
        return ($e === null || $e <= 0) ? null : ApiTimeFormat::fromEpoch($e, $tz);
    };

    return [
        'request_id'      => (int)$r['ID'],
        'employee_id'     => (int)$r['EmployeeID'],
        'employee_email'  => $nullIfBlank($r['Email']),
        'employee_name'   => trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['LastName'] ?? '')),
        'request_type'    => (string)$r['RequestType'],
        'status'          => (string)$r['Status'],
        'start_date'      => $reqStart,
        'end_date'        => $reqEnd,
        'days'            => $days,
        'hours_requested' => $r['HoursRequested'] !== null ? (float)$r['HoursRequested'] : null,
        'reason'          => $nullIfBlank($r['Reason']),
        'requested_at'    => $formatEpoch($r['RequestedAtEpoch']),
        'reviewed_at'     => $formatEpoch($r['ReviewedAtEpoch']),
        'reviewed_by'     => $nullIfBlank($r['ReviewedByUsername']),
        'review_notes'    => $nullIfBlank($r['ReviewNotes']),
    ];
};

/**
 * POST /api/external/v1/timeoff — submit a new request. See the file header
 * for the full contract. Defined as a named top-level function (not inline in
 * the try block) so it is compile-time-hoisted and callable from the method
 * branch above regardless of textual order.
 *
 * @param callable $fetchTimeoffRow function(mysqli,int):?array
 * @param callable $shapeTimeoffRow function(array,DateTimeZone):array
 */
function timeoffHandlePost(mysqli $conn, int $keyId, callable $fetchTimeoffRow, callable $shapeTimeoffRow): void
{
    $body = WriteRequest::json();

    $employeeId  = WriteRequest::requireInt($body, 'employee_id', 1);
    $startDate   = WriteRequest::requireDate($body, 'start_date');
    $endDate     = WriteRequest::requireDate($body, 'end_date');
    if ($endDate < $startDate) {
        ApiResponse::error(
            'Invalid field `end_date`: "' . $endDate . '" is before `start_date` ("' . $startDate . '").',
            400
        );
    }
    $requestType = WriteRequest::requireEnum($body, 'request_type', TIMEOFF_ALLOWED_REQUEST_TYPES);
    $reason      = WriteRequest::optionalString($body, 'reason', 2000);
    $hours       = WriteRequest::optionalFloat($body, 'hours_requested', 0.0, 999.99);
    if ($hours === null) {
        // Same fallback the admin UI's own approval step uses (see
        // functions/leave_balance.php::businessHoursBetween) — Mon-Fri,
        // 8h/day, inclusive of both endpoints.
        $hours = businessHoursBetween($startDate, $endDate);
    }

    // Everything from here through recordIdempotency() runs inside one
    // transaction, opened by locking this employee's `users` row FOR UPDATE
    // first — same reasoning and same shape as punches.php's
    // lockEmployeeForPunchWrite(): without it, two concurrent submissions
    // for the same employee (an HTTP retry being the canonical case) can
    // both pass the idempotency check AND both pass the overlap check below
    // before either commits, creating two Pending requests for the same
    // range. Also resolves employee existence + lockout in the same query.
    $conn->begin_transaction();

    $empStmt = $conn->prepare("SELECT ID, LockOut FROM users WHERE ID = ? FOR UPDATE");
    $empStmt->bind_param('i', $employeeId);
    $empStmt->execute();
    $empRow = $empStmt->get_result()->fetch_assoc();
    $empStmt->close();
    if (!$empRow) {
        ApiResponse::error('Employee not found: id ' . $employeeId . '.', 404);
    }

    // Idempotency is checked HERE — after the lock, before any other
    // validation — so a retry short-circuits to the original result even if
    // the employee was locked out or the request-overlap state changed
    // between the original call and this retry. Mirrors punches.php's
    // reasoning exactly.
    $idemKey  = WriteRequest::idempotencyKey();
    $existing = WriteRequest::checkIdempotency($conn, $keyId, $idemKey, 'timeoff');
    if ($existing !== null) {
        $row = $fetchTimeoffRow($conn, $existing);
        $conn->commit();
        if ($row !== null) {
            $data = $shapeTimeoffRow($row, new DateTimeZone('UTC'));
            $data['idempotent_replay'] = true;
            ApiResponse::success($data, 'Time-off request already submitted (replay)', 200);
        }
        // Stale pointer (the row it names no longer exists — nothing in this
        // API deletes time_off_requests, so this should not happen in
        // practice): fall through and perform a fresh write rather than
        // failing a legitimate retry.
    }

    // Not a replay: NOW enforce the employee's current state. A locked out
    // account cannot have a NEW request created on its behalf — this is a
    // state conflict (409), not a missing-resource error, and is distinct
    // from LockOut's effect on approve/reject below (a review is allowed to
    // proceed even for a since-locked-out employee, e.g. as part of
    // offboarding a departing employee's already-pending request).
    if ((int)($empRow['LockOut'] ?? 0) === 1) {
        ApiResponse::error(
            'Employee ' . $employeeId . ' is locked out and cannot have a time-off request submitted on their behalf.',
            409
        );
    }

    // Duplicate/overlapping-request guard — this endpoint had none at all
    // despite being the admin-equivalent-trust write surface for the same
    // table the self-service form already protects. Mirrored exactly from
    // user/time_off.php's own overlap check (same EmployeeID/Status/date-
    // range logic), now also correctly serialized by the employee lock
    // above rather than racing a concurrent submission the way a bare
    // check-then-insert would.
    $ovStmt = $conn->prepare(
        "SELECT ID FROM time_off_requests
         WHERE EmployeeID = ?
         AND Status IN ('Pending', 'Approved')
         AND (
             (StartDate <= ? AND EndDate >= ?)
             OR (StartDate <= ? AND EndDate >= ?)
             OR (StartDate >= ? AND EndDate <= ?)
         )"
    );
    $ovStmt->bind_param(
        'issssss',
        $employeeId, $endDate, $startDate, $endDate, $startDate, $startDate, $endDate
    );
    $ovStmt->execute();
    $ovRow = $ovStmt->get_result()->fetch_assoc();
    $ovStmt->close();
    if ($ovRow) {
        ApiResponse::error(
            'Conflict: employee ' . $employeeId . ' already has a Pending or Approved time-off request '
            . 'overlapping ' . $startDate . ' to ' . $endDate . '.',
            409
        );
    }

    // Status is intentionally absent from the column list — the schema
    // default ('Pending') applies. See the file header: this endpoint can
    // never create an already-decided request.
    $insStmt = $conn->prepare(
        "INSERT INTO time_off_requests (EmployeeID, StartDate, EndDate, RequestType, Reason, HoursRequested, RequestedAt)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $insStmt->bind_param('issssd', $employeeId, $startDate, $endDate, $requestType, $reason, $hours);
    $insStmt->execute();
    $newId = $conn->insert_id;
    $insStmt->close();

    if ($idemKey !== null) {
        WriteRequest::recordIdempotency($conn, $keyId, $idemKey, 'timeoff', $newId);
    }

    $row = $fetchTimeoffRow($conn, $newId);
    $data = $shapeTimeoffRow($row, new DateTimeZone('UTC'));
    $data['idempotent_replay'] = false;
    $conn->commit();
    ApiResponse::success($data, 'Time-off request submitted', 201);
}

/**
 * PATCH /api/external/v1/timeoff?id=<id>&action=approve|reject — review a
 * Pending request. See the file header for the full contract (race handling,
 * the transaction around approve, the Unpaid balance exclusion).
 *
 * @param callable $fetchTimeoffRow function(mysqli,int):?array
 * @param callable $shapeTimeoffRow function(array,DateTimeZone):array
 */
function timeoffHandlePatch(mysqli $conn, int $keyId, callable $fetchTimeoffRow, callable $shapeTimeoffRow): void
{
    $idRaw = $_GET['id'] ?? null;
    if (!is_scalar($idRaw) || !preg_match('/^[1-9]\d*$/', trim((string)$idRaw))) {
        ApiResponse::error('Invalid or missing parameter `id`: expected a positive integer time-off request id.', 400);
    }
    $requestId = (int)trim((string)$idRaw);

    $actionRaw = isset($_GET['action']) && is_scalar($_GET['action'])
        ? strtolower(trim((string)$_GET['action'])) : '';
    if (!in_array($actionRaw, ['approve', 'reject'], true)) {
        ApiResponse::error('Invalid or missing parameter `action`: expected "approve" or "reject".', 400);
    }

    $body             = WriteRequest::json();
    $reviewNotesInput = WriteRequest::optionalString($body, 'review_notes', 2000);

    $idemKey  = WriteRequest::idempotencyKey();
    $existing = WriteRequest::checkIdempotency($conn, $keyId, $idemKey, 'timeoff_review');
    if ($existing !== null) {
        $row = $fetchTimeoffRow($conn, $existing);
        if ($row !== null) {
            $data = $shapeTimeoffRow($row, new DateTimeZone('UTC'));
            $data['idempotent_replay'] = true;
            ApiResponse::success($data, 'Time-off review already recorded (replay)', 200);
        }
        // Stale pointer — fall through to a fresh review attempt.
    }

    $reqStmt = $conn->prepare(
        "SELECT ID, EmployeeID, RequestType, Status, StartDate, EndDate, HoursRequested
         FROM time_off_requests WHERE ID = ?"
    );
    $reqStmt->bind_param('i', $requestId);
    $reqStmt->execute();
    $existingRow = $reqStmt->get_result()->fetch_assoc();
    $reqStmt->close();

    if (!$existingRow) {
        ApiResponse::error('Time-off request not found: id ' . $requestId . '.', 404);
    }
    // Fast-path rejection from this read — NOT the authoritative race guard.
    // The authoritative guard is the UPDATE's own WHERE Status='Pending' +
    // affected_rows check below, which closes the TOCTOU gap between this
    // SELECT and the write (see the file header).
    if ($existingRow['Status'] !== 'Pending') {
        ApiResponse::error(
            'This request has already been ' . strtolower((string)$existingRow['Status'])
            . ' and cannot be reviewed again.',
            409
        );
    }

    $employeeId  = (int)$existingRow['EmployeeID'];
    $requestType = (string)$existingRow['RequestType'];
    // The hours to deduct are the request's OWN stored value (an explicit
    // POST override if one was given, or the businessHoursBetween() fallback
    // computed at submission time) — never recomputed here. See the file
    // header for why this deliberately differs from
    // admin/time_off_requests.php. A legacy request that never had
    // HoursRequested set (created outside this API) still gets the same
    // fallback computed now.
    $hoursRequested = $existingRow['HoursRequested'] !== null
        ? (float)$existingRow['HoursRequested']
        : businessHoursBetween($existingRow['StartDate'], $existingRow['EndDate']);

    // Resolve the key's display name for the ReviewNotes fallback text —
    // ReviewedBy itself stays NULL (no admins.id for an API-key actor).
    $keyNameStmt = $conn->prepare("SELECT Name FROM api_keys WHERE ID = ?");
    $keyNameStmt->bind_param('i', $keyId);
    $keyNameStmt->execute();
    $keyNameRow = $keyNameStmt->get_result()->fetch_assoc();
    $keyNameStmt->close();
    $keyName = $keyNameRow['Name'] ?? ('key#' . $keyId);

    if ($actionRaw === 'approve') {
        $reviewNotes = $reviewNotesInput ?? ('Approved via external API (key: ' . $keyName . ')');

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare(
                "UPDATE time_off_requests
                 SET Status = 'Approved', ReviewedAt = NOW(), ReviewedBy = NULL,
                     ReviewNotes = ?, HoursRequested = ?
                 WHERE ID = ? AND Status = 'Pending'"
            );
            $upd->bind_param('sdi', $reviewNotes, $hoursRequested, $requestId);
            $upd->execute();
            if ($upd->affected_rows !== 1) {
                // Reviewed concurrently between the SELECT above and this
                // UPDATE — the atomic guard caught what the earlier read
                // could not. Roll back (nothing to undo yet, but keeps the
                // transaction lifecycle symmetric) and report the race as a
                // state conflict, not a silent no-op.
                $conn->rollback();
                ApiResponse::error('This request was reviewed concurrently and can no longer be approved.', 409);
            }

            // Unpaid time off has no ledger row: leave_balances.LeaveType's
            // enum does not include 'Unpaid' at all (migration 017's own
            // comment is explicit about this), so it is never passed to
            // deductLeaveBalance().
            //
            // $year is explicit — deductLeaveBalance() defaults to the
            // CURRENT calendar year when omitted, which is the year this
            // PATCH happens to run in, not the year leave was actually
            // requested for. A request spanning a year boundary (or simply
            // approved a few days into the year after it was submitted)
            // would otherwise post its whole deduction against the WRONG
            // year's ledger, silently understating one year and overstating
            // the other. admin/time_off_requests.php's own approve handler
            // has this identical omission (a real, pre-existing bug there
            // too) — deliberately not reproduced here.
            if ($requestType !== 'Unpaid') {
                $balanceYear = (int)date('Y', strtotime((string)$existingRow['StartDate']));
                deductLeaveBalance($conn, $employeeId, $requestType, $hoursRequested, $balanceYear);
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    } else {
        $reviewNotes = $reviewNotesInput ?? ('Rejected via external API (key: ' . $keyName . ')');

        $upd = $conn->prepare(
            "UPDATE time_off_requests
             SET Status = 'Rejected', ReviewedAt = NOW(), ReviewedBy = NULL, ReviewNotes = ?
             WHERE ID = ? AND Status = 'Pending'"
        );
        $upd->bind_param('si', $reviewNotes, $requestId);
        $upd->execute();
        if ($upd->affected_rows !== 1) {
            ApiResponse::error('This request was reviewed concurrently and can no longer be rejected.', 409);
        }
    }

    if ($idemKey !== null) {
        WriteRequest::recordIdempotency($conn, $keyId, $idemKey, 'timeoff_review', $requestId);
    }

    $row = $fetchTimeoffRow($conn, $requestId);
    $data = $shapeTimeoffRow($row, new DateTimeZone('UTC'));
    $data['idempotent_replay'] = false;
    ApiResponse::success($data, 'Time-off request ' . ($actionRaw === 'approve' ? 'approved' : 'rejected'), 200);
}

try {
    $auth   = WriteBootstrap::begin('/api/external/v1/timeoff', [
        'GET'   => 'timeoff:read',
        'POST'  => 'timeoff:write',
        'PATCH' => 'timeoff:write',
    ]);
    $method = $auth['method'];
    $keyId  = $auth['id'];

    if ($method === 'POST') {
        timeoffHandlePost($conn, $keyId, $fetchTimeoffRow, $shapeTimeoffRow);
    } elseif ($method === 'PATCH') {
        timeoffHandlePatch($conn, $keyId, $fetchTimeoffRow, $shapeTimeoffRow);
    }

    // GET falls through to the existing read path below, byte-for-byte
    // unchanged except that WriteBootstrap::begin() above already performed
    // the method-allowlist + scope check that ReportBootstrap::begin() used
    // to do inline here.

    // 'start'/'end' deliberately excluded — see the file header. They are still
    // parsed (by hand, below) and are filtered back out of ignored_params so
    // passing them is never falsely reported as having no effect.
    $params = ReportParams::fromRequest($_GET, $conn, [
        'employee_id', 'employee_email', 'office',
        'status', 'type', 'updated_since',
        'limit', 'offset',
    ]);

    $tz = $params->timezone();

    // ------------------------------------------------------------- start/end
    $startRaw = isset($_GET['start']) && is_scalar($_GET['start']) ? trim((string)$_GET['start']) : '';
    $endRaw   = isset($_GET['end'])   && is_scalar($_GET['end'])   ? trim((string)$_GET['end'])   : '';
    $startDate = null;
    $endDate   = null;
    if ($startRaw !== '') {
        if (!$isValidDate($startRaw)) {
            ApiResponse::error('Invalid parameter `start`: expected a calendar date as YYYY-MM-DD.', 400);
        }
        $startDate = $startRaw;
    }
    if ($endRaw !== '') {
        if (!$isValidDate($endRaw)) {
            ApiResponse::error('Invalid parameter `end`: expected a calendar date as YYYY-MM-DD.', 400);
        }
        $endDate = $endRaw;
    }
    if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
        ApiResponse::error(
            'Invalid date range: `end` (' . $endDate . ') is before `start` (' . $startDate . ').',
            400
        );
    }

    // --------------------------------------------------------- updated_since
    $updatedSinceRaw = isset($_GET['updated_since']) && is_scalar($_GET['updated_since'])
        ? trim((string)$_GET['updated_since']) : '';
    $updatedSinceEpoch = null;
    if ($updatedSinceRaw !== '') {
        if (!preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $updatedSinceRaw)) {
            ApiResponse::error(
                '`updated_since` must include an explicit UTC offset (e.g. -05:00 or Z), got: '
                . htmlspecialchars($updatedSinceRaw, ENT_QUOTES, 'UTF-8'),
                400
            );
        }
        $updatedSinceEpoch = ApiTimeFormat::parseIso8601ToEpoch($updatedSinceRaw);
        if ($updatedSinceEpoch === null) {
            ApiResponse::error('Invalid `updated_since` — expected ISO 8601 with an explicit offset.', 400);
        }
    }

    $statusFilter = $parseEnumList($_GET, 'status', ['Pending', 'Approved', 'Rejected']);
    $typeFilter   = $parseEnumList($_GET, 'type',   ['Vacation', 'Sick', 'Personal', 'Other', 'Unpaid']);

    // A caller passing start/end is genuinely filtering, not being ignored.
    $ignoredParams = array_values(array_diff($params->ignoredParams(), ['start', 'end']));

    // ------------------------------------------------------------- filtering
    // Every bound value travels through bind_param; placeholder lists are sized
    // from the COUNT of already-validated values, never from the values.
    $filter = $params->buildUserFilter('u');

    $conds  = [];
    $types  = '';
    $values = [];

    if ($filter['sql'] !== '') {
        $conds[] = $filter['sql'];
        $types  .= $filter['types'];
        $values  = array_merge($values, $filter['values']);
    }
    if ($startDate !== null) {
        $conds[] = 'tor.EndDate >= ?';
        $types  .= 's';
        $values[] = $startDate;
    }
    if ($endDate !== null) {
        $conds[] = 'tor.StartDate <= ?';
        $types  .= 's';
        $values[] = $endDate;
    }
    if ($statusFilter !== []) {
        $conds[] = 'tor.Status IN (' . implode(',', array_fill(0, count($statusFilter), '?')) . ')';
        $types  .= str_repeat('s', count($statusFilter));
        $values  = array_merge($values, $statusFilter);
    }
    if ($typeFilter !== []) {
        $conds[] = 'tor.RequestType IN (' . implode(',', array_fill(0, count($typeFilter), '?')) . ')';
        $types  .= str_repeat('s', count($typeFilter));
        $values  = array_merge($values, $typeFilter);
    }
    if ($updatedSinceEpoch !== null) {
        $conds[]  = 'UNIX_TIMESTAMP(COALESCE(tor.ReviewedAt, tor.RequestedAt)) > ?';
        $types   .= 'i';
        $values[] = $updatedSinceEpoch;
    }

    $where = $conds === [] ? '1=1' : implode(' AND ', $conds);

    // --------------------------------------------------------------- counting
    $countSql = "SELECT COUNT(*) AS c
                 FROM time_off_requests tor
                 JOIN users u ON u.ID = tor.EmployeeID
                 WHERE $where";
    $stmt = $conn->prepare($countSql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $totalCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // ------------------------------------------------------------------ rows
    // Ordered by (COALESCE(ReviewedAt, RequestedAt), ID): the same field
    // updated_since filters on, so a caller paging forward with rising
    // updated_since values walks the feed in the order it was actually updated,
    // with ID as a tie-break for two requests updated in the same second.
    // admins is joined only for the reviewer's username — never a password or
    // TwoFA* column.
    $limit  = $params->limit();
    $offset = $params->offset();

    $sql = "SELECT tor.ID, tor.EmployeeID, tor.StartDate, tor.EndDate, tor.RequestType,
                   tor.Status, tor.Reason, tor.ReviewNotes, tor.HoursRequested,
                   UNIX_TIMESTAMP(tor.RequestedAt) AS RequestedAtEpoch,
                   UNIX_TIMESTAMP(tor.ReviewedAt)  AS ReviewedAtEpoch,
                   u.Email, u.FirstName, u.LastName,
                   a.username AS ReviewedByUsername
            FROM time_off_requests tor
            JOIN users u ON u.ID = tor.EmployeeID
            LEFT JOIN admins a ON a.id = tor.ReviewedBy
            WHERE $where
            ORDER BY COALESCE(tor.ReviewedAt, tor.RequestedAt) ASC, tor.ID ASC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types . 'ii', ...array_merge($values, [$limit, $offset]));
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /**
     * A DATETIME read back as a UTC epoch, rendered in the report timezone.
     * The <= 0 guard covers MariaDB handing back 0 for a legacy
     * '0000-00-00 00:00:00' sentinel, which must not become 1970-01-01.
     */
    $formatEpoch = static function ($epoch) use ($tz): ?string {
        $e = ApiTimeFormat::epochOrNull($epoch);
        return ($e === null || $e <= 0) ? null : ApiTimeFormat::fromEpoch($e, $tz);
    };

    $rows            = [];
    $malformedDates  = 0;

    foreach ($requests as $r) {
        $reqStart = ApiTimeFormat::dateOnly($r['StartDate']);
        $reqEnd   = ApiTimeFormat::dateOnly($r['EndDate']);
        if ($reqStart === null || $reqEnd === null) {
            $malformedDates++;
        }
        $days = ($reqStart !== null && $reqEnd !== null) ? $inclusiveDays($reqStart, $reqEnd) : 0;

        $rows[] = [
            'request_id'      => (int)$r['ID'],
            'employee_id'     => (int)$r['EmployeeID'],
            'employee_email'  => $nullIfBlank($r['Email']),
            'employee_name'   => trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['LastName'] ?? '')),
            'request_type'    => (string)$r['RequestType'],
            'status'          => (string)$r['Status'],
            'start_date'      => $reqStart,
            'end_date'        => $reqEnd,
            'days'            => $days,
            'hours_requested' => $r['HoursRequested'] !== null ? (float)$r['HoursRequested'] : null,
            'reason'          => $nullIfBlank($r['Reason']),
            'requested_at'    => $formatEpoch($r['RequestedAtEpoch']),
            'reviewed_at'     => $formatEpoch($r['ReviewedAtEpoch']),
            // null when never reviewed, or when the reviewing admin account has
            // since been deleted (FK is ON DELETE SET NULL).
            'reviewed_by'     => $nullIfBlank($r['ReviewedByUsername']),
            'review_notes'    => $nullIfBlank($r['ReviewNotes']),
        ];
    }

    // -------------------------------------------------------------- warnings
    $warnings = [];
    if ($malformedDates > 0) {
        $warnings[] = $malformedDates . ' request(s) on this page have an unusable StartDate/EndDate '
            . '(e.g. a legacy 0000-00-00 sentinel) and report start_date/end_date/days as null/0.';
    }

    // ------------------------------------------------------------------ meta
    $filtersBlock = [
        'employee_id'      => $params->employeeIds(),
        'employee_email'   => $params->employeeEmails(),
        'office'           => $params->offices(),
        // No override on this endpoint (see the file header) — always false.
        'include_inactive' => false,
        'status'           => $statusFilter,
        'type'             => $typeFilter,
        'start'            => $startDate,
        'end'              => $endDate,
        'updated_since'    => $updatedSinceRaw !== '' ? $updatedSinceRaw : null,
    ];

    $meta = $params->meta('timeoff', [
        'filters'        => $filtersBlock,
        'rules'          => (object)[],
        'row_count'      => count($rows),
        'total_count'    => $totalCount,
        'warnings'       => $warnings,
        'ignored_params' => $ignoredParams,
    ]);

    $data = ['rows' => $rows, 'meta' => $meta];

    Output::send($data, $params, 'timeoff', [
        'request_id', 'employee_id', 'employee_email', 'employee_name',
        'request_type', 'status', 'start_date', 'end_date', 'days',
        'hours_requested', 'reason', 'requested_at', 'reviewed_at',
        'reviewed_by', 'review_notes',
    ]);

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/timeoff] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
