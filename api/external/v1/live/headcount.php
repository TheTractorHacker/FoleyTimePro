<?php
/**
 * GET /api/external/v1/live/headcount
 * Authorization: Bearer <api-key>   Scope: live:read
 *
 * Point-in-time headcount tile: one row per office, aggregating the same
 * "who is on the clock right now" answer /live/status computes per employee
 * (see api/lib/LiveStatus.php — both endpoints share it so the two can never
 * disagree). No start/end — this is "now" — and cheap enough to poll every
 * 30 seconds: the roster is small and every per-employee computation is O(1).
 *
 * Params: employee_id, employee_email, office, include_inactive, tz, format,
 * limit, offset. No rounding/week_start/ot_* — there is no hours allocation
 * here, just a live count.
 *
 * GROUPING: one row per DISTINCT users.Office value among employees matching
 * the filters; an employee with no office set groups under "Unassigned".
 * users.Office has no foreign key (see /api/external/v1/offices), so office_id
 * is looked up by exact name match against the Offices table and is null for
 * a name with no matching row — a data-quality fact, not an API error.
 *
 * SCHEDULED_NOW: an employee counts here when their ONE active assigned shift
 * (user_shifts is UNIQUE on UserID) covers THIS INSTANT — today's shift, or an
 * overnight shift still running from yesterday — via
 * LiveStatus::currentShiftInstance(). This is independent of whether they are
 * actually clocked in; it answers "should someone be here right now per the
 * schedule", which is what `variance` compares against who actually is.
 *
 * occupancy_pct and variance use ONLY `clocked_in` (status "in"), not
 * "on_lunch", per the field definitions below — an employee on lunch is
 * temporarily away from the floor even though their shift is still running.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../../lib/ApiTimeFormat.php';
require_once __DIR__ . '/../../../lib/LiveStatus.php';

/**
 * A percentage, or null when there is no denominator — same convention as
 * /reports/utilization: "no data" and "measured zero" must stay distinguishable
 * through a spreadsheet average, and only null survives that.
 */
$pct = static function (float $numerator, float $denominator): ?float {
    if ($denominator <= 0) {
        return null;
    }
    return round($numerator / $denominator * 100, 2);
};

$nullIfBlank = static fn($v) => ($v === null || $v === '') ? null : $v;

try {
    ReportBootstrap::begin('/api/external/v1/live/headcount', 'live:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'employee_id', 'employee_email', 'office', 'include_inactive', 'limit', 'offset',
    ]);

    $tz  = $params->timezone();
    $now = new DateTimeImmutable('now', $tz);

    // ------------------------------------------------------------------ roster
    // office_id is a correlated scalar subquery (MIN ID on a name match) rather
    // than a LEFT JOIN, because Offices has no unique key on OfficeName — a plain
    // join would duplicate an employee row once per matching Offices row.
    $filter = $params->buildUserFilter('u');
    $rosterWhere = $filter['sql'] !== '' ? $filter['sql'] : '1=1';
    $stmt = $conn->prepare(
        "SELECT u.ID, u.Office,
                (SELECT MIN(o.ID) FROM Offices o WHERE o.OfficeName = u.Office) AS OfficeID
         FROM users u
         WHERE $rosterWhere"
    );
    if ($filter['types'] !== '') {
        $stmt->bind_param($filter['types'], ...$filter['values']);
    }
    $stmt->execute();
    $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rosterIds = array_map(static fn($u) => (int)$u['ID'], $roster);

    $shifts      = $rosterIds === [] ? [] : ScheduleCalculator::loadShiftsForUsers($conn, $rosterIds);
    $openPunches = $rosterIds === [] ? [] : LiveStatus::loadOpenPunches($conn, $rosterIds);

    // Offices.OfficeName has no unique key; a duplicated name means office_id
    // above was picked arbitrarily (MIN ID) among two-or-more lookup rows for
    // the same office. Flagged in meta.warnings below only if it actually
    // touches a group this response returns.
    $dupOfficeNames = [];
    $dRes = $conn->query(
        "SELECT OfficeName FROM Offices GROUP BY OfficeName HAVING COUNT(*) > 1"
    );
    if ($dRes) {
        while ($d = $dRes->fetch_assoc()) { $dupOfficeNames[] = (string)$d['OfficeName']; }
        $dRes->free();
    }

    // --------------------------------------------------------------- aggregate
    $officeAgg = [];
    $ensure = static function (string $key, ?int $officeId) use (&$officeAgg): void {
        if (isset($officeAgg[$key])) {
            return;
        }
        $officeAgg[$key] = [
            'office'          => $key,
            'office_id'       => $officeId,
            'clocked_in'      => 0,
            'on_lunch'        => 0,
            'out'             => 0,
            'total_employees' => 0,
            'scheduled_now'   => 0,
        ];
    };

    foreach ($roster as $u) {
        $eid   = (int)$u['ID'];
        $shift = $shifts[$eid] ?? null;
        $punch = $openPunches[$eid] ?? null;

        $status = $punch !== null ? LiveStatus::statusForOpenPunch($punch) : 'out';

        $officeName = $nullIfBlank($u['Office']);
        $key        = $officeName ?? 'Unassigned';
        $officeId   = $u['OfficeID'] !== null ? (int)$u['OfficeID'] : null;
        $ensure($key, $officeId);

        $officeAgg[$key]['total_employees']++;
        if ($status === 'in')            { $officeAgg[$key]['clocked_in']++; }
        elseif ($status === 'on_lunch')  { $officeAgg[$key]['on_lunch']++; }
        else                              { $officeAgg[$key]['out']++; }

        if (LiveStatus::isScheduledNow($shift, $now, $tz)) {
            $officeAgg[$key]['scheduled_now']++;
        }
    }

    ksort($officeAgg); // stable alphabetical order; "Unassigned" sorts with the rest

    $rows = [];
    $touchedDupName = false;
    foreach ($officeAgg as $key => $o) {
        if (in_array($key, $dupOfficeNames, true)) {
            $touchedDupName = true;
        }
        $rows[] = [
            'office'          => $o['office'],
            'office_id'       => $o['office_id'],
            'clocked_in'      => $o['clocked_in'],
            'on_lunch'        => $o['on_lunch'],
            'out'             => $o['out'],
            'total_employees' => $o['total_employees'],
            'occupancy_pct'   => $pct((float)$o['clocked_in'], (float)$o['total_employees']),
            'scheduled_now'   => $o['scheduled_now'],
            'variance'        => $o['clocked_in'] - $o['scheduled_now'],
        ];
    }

    // ------------------------------------------------------------- pagination
    // Totals below cover every office row regardless of paging (same convention
    // as /reports/utilization) — a dashboard tile totaling only the current page
    // would shrink every time the page size changed.
    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    // ------------------------------------------------------------------ totals
    $totClockedIn = 0; $totOnLunch = 0; $totOut = 0; $totEmployees = 0; $totScheduled = 0;
    foreach ($rows as $r) {
        $totClockedIn += $r['clocked_in'];
        $totOnLunch   += $r['on_lunch'];
        $totOut       += $r['out'];
        $totEmployees += $r['total_employees'];
        $totScheduled += $r['scheduled_now'];
    }
    $totals = [
        'clocked_in'      => $totClockedIn,
        'on_lunch'        => $totOnLunch,
        'out'             => $totOut,
        'total_employees' => $totEmployees,
        // Weighted, not an average of the per-office percentages — same reasoning
        // as /reports/utilization's totals.utilization_pct: an office of 2 must
        // not move the company number as much as an office of 40.
        'occupancy_pct'   => $pct((float)$totClockedIn, (float)$totEmployees),
        'scheduled_now'   => $totScheduled,
        'variance'        => $totClockedIn - $totScheduled,
        'as_of'           => $now->format('c'),
    ];

    // ----------------------------------------------------------------- meta
    $warnings = [];
    if ($touchedDupName) {
        $warnings[] = 'One or more returned office names have more than one matching row in the Offices '
            . 'lookup table; office_id reflects the lowest matching ID and may not be the intended one. '
            . 'Deduplicate the Offices table to make this unambiguous.';
    }
    if (!LiveStatus::isOpenColumnExists($conn)) {
        $warnings[] = 'This install has no timepunches.IsOpen column; open-punch detection fell back to '
            . 'TimeOut IS NULL, which cannot distinguish more than one concurrently open punch per employee.';
    }

    $meta = $params->meta('live_headcount', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
        // No date range or hours-allocation policy applies to a live headcount
        // tile — publishing the shared rounding/OT defaults would describe
        // behavior this endpoint does not have (same reasoning as /offices).
        'rules'       => (object)[],
    ]);
    $meta['server_time'] = ApiTimeFormat::now($tz);

    $csvColumns = [
        'office', 'office_id', 'clocked_in', 'on_lunch', 'out', 'total_employees',
        'occupancy_pct', 'scheduled_now', 'variance',
    ];

    Output::send(
        ['rows' => $page, 'meta' => $meta, 'totals' => $totals],
        $params,
        'live_headcount',
        $csvColumns
    );

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/live/headcount] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
