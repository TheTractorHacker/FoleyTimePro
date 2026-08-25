<?php
/**
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
 */

require_once __DIR__ . '/../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../lib/ApiTimeFormat.php';

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

try {
    ReportBootstrap::begin('/api/external/v1/timeoff', 'timeoff:read');

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
