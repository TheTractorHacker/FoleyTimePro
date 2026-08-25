<?php
/**
 * GET /api/external/v1/reports/geofence
 * Authorization: Bearer <api-key>   Scope: reports:read
 *
 * GPS compliance: punches recorded outside their geofence.
 *
 * Params: start, end (required), employee_id, employee_email, office,
 *         include_inactive, tz, format, limit, offset, plus:
 *
 *   only_violations     bool  (default true)  — when false, every checked event
 *                        is returned, compliant ones included, so an integrator
 *                        can build their own compliance-rate chart.
 *   min_meters_outside   float (default 0)    — see VIOLATION DEFINITION below.
 *   max_accuracy         float (default 0 = no filter) — drop readings whose
 *                        reported accuracy is worse (numerically larger) than
 *                        this many meters. A fix reported accurate to only 500m
 *                        "failing" a 200m-radius geofence proves nothing — the
 *                        true position could easily have been inside it — so
 *                        that reading is excluded from consideration entirely
 *                        rather than counted either way. A NULL accuracy (never
 *                        recorded) is NOT excluded: there is no evidence it was
 *                        bad, only no evidence at all.
 *
 * SOURCE OF TRUTH — verified against THIS database before writing a line of
 * logic here, not assumed from the schema doc: geofences currently has 0 rows,
 * and every GeofencePass* value on every punch is NULL. Both states are handled
 * as first-class, not edge cases:
 *   - No active geofence at all -> the whole report is meaningless (there is
 *     nothing to be outside OF), so it short-circuits to an empty row set with
 *     a meta warning, NOT a 200-with-misleading-zeros and NOT an error. This
 *     happens before touching timepunches at all.
 *   - GeofencePass* columns are LIVE-ONLY (not in Install/timeclock-schema.sql)
 *     and are currently all NULL on live data. Their presence is detected at
 *     runtime via SHOW COLUMNS (cached in a static — once per request, never
 *     per row) and a NULL value on a row that DOES have the column is treated
 *     exactly like a missing column: fall through to computing it ourselves.
 *     Every row states which path it took in `source` ("stored" | "computed")
 *     so a consumer never mistakes a value this endpoint computed for one the
 *     app itself recorded at punch time.
 *
 * DISTANCE: Haversine, computed in PHP (GeofenceMath::distanceMeters) against
 * every ACTIVE geofence, keeping the NEAREST — not the first row returned by
 * the query and not the first geofence in ID order. An employee who works near
 * two sites must be judged against the one they are actually closest to; judging
 * them against an arbitrary geofence would manufacture false violations.
 *
 * VIOLATION DEFINITION: distance > radius of the nearest active geofence (when
 * source=computed), or `!GeofencePass*` (when source=stored). min_meters_outside
 * then acts as a severity floor on TOP of that boolean — but only for
 * source=computed, where the floor is exactly the number we just measured. A
 * source=stored violation always qualifies regardless of min_meters_outside: it
 * is a bare pass/fail with no magnitude attached, and this endpoint's own
 * nearest-active-geofence distance can legitimately disagree with the app's
 * verdict (different geofence set, a since-moved fence), so using our distance
 * to discard THEIR verdict could hide a real, app-confirmed violation behind a
 * coincidental disagreement. Either way, min_meters_outside never hides a
 * COMPLIANT event: only_violations=false always returns every checked
 * event regardless of min_meters_outside, so nothing needed for a compliance-
 * rate chart is silently dropped by a threshold meant to reduce violation noise.
 *
 * ONE ROW PER EVENT, not per punch: a single timepunches row can carry up to
 * four independently-captured coordinate pairs (clock_in, lunch_start,
 * lunch_end, clock_out), each its own GPS fix with its own accuracy and its own
 * compliance verdict. Folding them into one row per punch would force an
 * arbitrary "worst of four" choice this endpoint has no business making.
 */

require_once __DIR__ . '/../../../lib/ReportBootstrap.php';

/** Same closure-per-file convention as utilization.php / trends.php. */
$parseBoolParam = static function (array $get, string $name, bool $default): bool {
    if (!array_key_exists($name, $get)) {
        return $default;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = strtolower(trim((string)$raw));
    if ($v === '') {
        return $default;
    }
    if (in_array($v, ['1', 'true', 'yes'], true))  return true;
    if (in_array($v, ['0', 'false', 'no'], true))  return false;
    ApiResponse::error(
        'Invalid parameter `' . $name . '`: "' . htmlspecialchars(substr($v, 0, 64), ENT_QUOTES, 'UTF-8')
        . '". Accepted values are 1/0, true/false, yes/no.',
        400
    );
};

$parseNonNegativeFloat = static function (array $get, string $name, float $default): float {
    if (!array_key_exists($name, $get)) {
        return $default;
    }
    $raw = $get[$name];
    if (is_array($raw) || !is_scalar($raw)) {
        ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
    }
    $v = trim((string)$raw);
    if ($v === '') {
        return $default;
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $v)) {
        ApiResponse::error(
            'Invalid parameter `' . $name . '`: "' . htmlspecialchars(substr($v, 0, 64), ENT_QUOTES, 'UTF-8')
            . '". Expected a non-negative number.',
            400
        );
    }
    return (float)$v;
};

/** A percentage-free helper file needs none of ReportParams' rounding/OT machinery — this report has no hours math. */
final class GeofenceMath
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    /** Great-circle distance between two lat/lon points, in meters. */
    public static function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1    = deg2rad($lat1);
        $phi2    = deg2rad($lat2);
        $dPhi    = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }
}

/**
 * Runtime detection of the live-only GeofencePass* columns, and the DATE+TIME ->
 * ISO 8601 combination this endpoint needs. A class, not global functions, so
 * this file can never collide with punches.php's global formatDateTime() etc.
 * if the two are ever loaded in the same process (a combined lint/test run).
 */
final class GeofenceSupport
{
    private static ?array $geofencePassCols = null;

    /**
     * @return array{GeofencePassIN:bool, GeofencePassOut:bool,
     *               GeofencePassLunchStart:bool, GeofencePassLunchEnd:bool}
     */
    public static function geofencePassColumns(mysqli $conn): array
    {
        if (self::$geofencePassCols !== null) {
            return self::$geofencePassCols;
        }
        $cols = [
            'GeofencePassIN' => false, 'GeofencePassOut' => false,
            'GeofencePassLunchStart' => false, 'GeofencePassLunchEnd' => false,
        ];
        $res = $conn->query("SHOW COLUMNS FROM timepunches LIKE 'GeofencePass%'");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (array_key_exists($r['Field'], $cols)) {
                    $cols[$r['Field']] = true;
                }
            }
            $res->free();
        }
        return self::$geofencePassCols = $cols;
    }

    /** Combine a punch DATE + TIME into ISO 8601 in $tz, or null if either is missing/unparseable. */
    public static function combine(?string $date, ?string $time, DateTimeZone $tz): ?string
    {
        if ($date === null || $time === null || $time === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($date . ' ' . $time, $tz))->format('c');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The calendar date an event's TIME belongs to, given the punch's base Date
     * and the time of the event immediately before it in the clock_in ->
     * lunch_start -> lunch_end -> clock_out chain. Rolls the date forward
     * whenever a time is earlier than its predecessor, exactly the overnight
     * rule punches.php uses for lunch_end/clock_out, generalized to every step
     * of the chain (an overnight shift can just as easily start its lunch after
     * midnight as end one there).
     */
    public static function chainDate(string $currentDate, ?string $prevTime, ?string $time): string
    {
        if ($prevTime === null || $time === null || $time === '') {
            return $currentDate;
        }
        if (strcmp($time, $prevTime) >= 0) {
            return $currentDate;
        }
        try {
            return (new DateTimeImmutable($currentDate))->modify('+1 day')->format('Y-m-d');
        } catch (Exception $e) {
            return $currentDate;
        }
    }
}

try {
    ReportBootstrap::begin('/api/external/v1/reports/geofence', 'reports:read');

    $params = ReportParams::fromRequest($_GET, $conn, [
        'start', 'end', 'employee_id', 'employee_email', 'office', 'include_inactive',
        'limit', 'offset', 'only_violations', 'min_meters_outside', 'max_accuracy',
    ]);

    // Declared in $accepted above so they are not reported as ignored params, but
    // parsed here (same convention as utilization.php/trends.php) because they
    // are specific to this report and ReportParams does not own them.
    $onlyViolations   = $parseBoolParam($_GET, 'only_violations', true);
    $minMetersOutside = $parseNonNegativeFloat($_GET, 'min_meters_outside', 0.0);
    $maxAccuracy      = $parseNonNegativeFloat($_GET, 'max_accuracy', 0.0);

    $start = (string)$params->startDate();
    $end   = (string)$params->endDate();
    $tz    = $params->timezone();

    // --------------------------------------------------------- active geofences
    // Loaded once, in full — geofences is an admin-maintained lookup table, not
    // something that ever needs its own pagination or date filter.
    $geofences = [];
    $gRes = $conn->query(
        "SELECT ID, Name, Latitude, Longitude, RadiusMeters, IsRequired FROM geofences WHERE IsActive = 1"
    );
    if ($gRes) {
        while ($g = $gRes->fetch_assoc()) {
            $geofences[] = [
                'id'        => (int)$g['ID'],
                'name'      => (string)$g['Name'],
                'lat'       => (float)$g['Latitude'],
                'lon'       => (float)$g['Longitude'],
                'radius'    => (float)$g['RadiusMeters'],
                'required'  => (bool)$g['IsRequired'],
            ];
        }
        $gRes->free();
    }

    if ($geofences === []) {
        // Nothing to be outside OF. Returning violations here would either be a
        // false "clean" 0 (if we invented a rule) or an error for a perfectly
        // valid state (a site that hasn't set up geofencing yet) — neither is
        // right, so this is an empty, successful report with an explicit reason.
        $meta = $params->meta('geofence', [
            'row_count'   => 0,
            'total_count' => 0,
            'warnings'    => ['Geofencing is not configured: no active geofence exists. No punches were checked.'],
        ]);
        $meta['filters']['only_violations']    = $onlyViolations;
        $meta['filters']['min_meters_outside'] = $minMetersOutside;
        $meta['filters']['max_accuracy']       = $maxAccuracy;

        Output::send(
            [
                'rows' => [],
                'meta' => $meta,
                'totals' => [
                    'violations' => 0, 'employees_affected' => 0,
                    'punches_checked' => 0, 'punches_without_coordinates' => 0,
                ],
            ],
            $params,
            'geofence',
            ['punch_id', 'employee_id', 'employee_email', 'employee_name', 'office', 'date', 'event',
             'event_time', 'latitude', 'longitude', 'accuracy_meters', 'nearest_geofence',
             'geofence_distance_meters', 'geofence_radius_meters', 'meters_outside', 'is_required', 'source']
        );
    }

    $gCols = GeofenceSupport::geofencePassColumns($conn);
    // Real column when present, a literal NULL under the same alias otherwise —
    // one code path downstream regardless of which install this runs on.
    $selIN         = ($gCols['GeofencePassIN'] ? 'tp.GeofencePassIN' : 'NULL') . ' AS GeofencePassIN';
    $selOut        = ($gCols['GeofencePassOut'] ? 'tp.GeofencePassOut' : 'NULL') . ' AS GeofencePassOut';
    $selLunchStart = ($gCols['GeofencePassLunchStart'] ? 'tp.GeofencePassLunchStart' : 'NULL') . ' AS GeofencePassLunchStart';
    $selLunchEnd   = ($gCols['GeofencePassLunchEnd'] ? 'tp.GeofencePassLunchEnd' : 'NULL') . ' AS GeofencePassLunchEnd';

    $filter = $params->buildUserFilter('u');
    $sql = "SELECT tp.id AS PunchID, tp.EmployeeID, tp.Date,
                   tp.TimeIN, tp.LatitudeIN, tp.LongitudeIN, tp.AccuracyIN, $selIN,
                   tp.LunchStart, tp.LatitudeLunchStart, tp.LongitudeLunchStart, tp.AccuracyLunchStart, $selLunchStart,
                   tp.LunchEnd, tp.LatitudeLunchEnd, tp.LongitudeLunchEnd, tp.AccuracyLunchEnd, $selLunchEnd,
                   tp.TimeOut, tp.LatitudeOut, tp.LongitudeOut, tp.AccuracyOut, $selOut,
                   u.Email, u.FirstName, u.LastName, u.Office
            FROM timepunches tp
            JOIN users u ON u.ID = tp.EmployeeID
            WHERE tp.Date BETWEEN ? AND ?"
        . ($filter['sql'] !== '' ? " AND {$filter['sql']}" : '')
        . " ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.id ASC";

    $types  = 'ss' . $filter['types'];
    $values = array_merge([$start, $end], $filter['values']);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $res = $stmt->get_result();

    // event key => [time column, lat column, lon column, accuracy column, geofencepass column]
    $eventDefs = [
        'clock_in'    => ['TimeIN',     'LatitudeIN',        'LongitudeIN',        'AccuracyIN',        'GeofencePassIN'],
        'lunch_start' => ['LunchStart', 'LatitudeLunchStart','LongitudeLunchStart','AccuracyLunchStart','GeofencePassLunchStart'],
        'lunch_end'   => ['LunchEnd',   'LatitudeLunchEnd',  'LongitudeLunchEnd',  'AccuracyLunchEnd',  'GeofencePassLunchEnd'],
        'clock_out'   => ['TimeOut',    'LatitudeOut',       'LongitudeOut',       'AccuracyOut',       'GeofencePassOut'],
    ];

    $rows                     = [];
    $punchesChecked           = 0;
    $punchesWithoutCoords     = 0;
    $qualifyingViolations     = 0;
    $employeesAffected        = [];
    $excludedByAccuracy       = 0;

    while ($p = $res->fetch_assoc()) {
        $punchesChecked++;
        $eid      = (int)$p['EmployeeID'];
        $baseDate = (string)$p['Date'];
        $email    = ($p['Email'] ?? '') !== '' ? $p['Email'] : null;
        $name     = trim((string)$p['FirstName'] . ' ' . (string)$p['LastName']);
        $office   = ($p['Office'] ?? '') !== '' ? $p['Office'] : null;

        $anyCoords = false;
        $curDate   = $baseDate;
        $prevTime  = null;

        foreach ($eventDefs as $eventName => [$timeCol, $latCol, $lonCol, $accCol, $gpCol]) {
            $time = $p[$timeCol] !== null && $p[$timeCol] !== '' ? (string)$p[$timeCol] : null;
            $lat  = $p[$latCol] !== null && $p[$latCol] !== '' ? (float)$p[$latCol] : null;
            $lon  = $p[$lonCol] !== null && $p[$lonCol] !== '' ? (float)$p[$lonCol] : null;
            $acc  = $p[$accCol] !== null && $p[$accCol] !== '' ? (float)$p[$accCol] : null;

            // Advance the overnight-safe date chain using this event's time
            // whenever we have one, even if it turns out to lack coordinates —
            // a punch with no GPS on lunch_end must not throw off clock_out's date.
            if ($time !== null) {
                $curDate  = GeofenceSupport::chainDate($curDate, $prevTime, $time);
                $prevTime = $time;
            }

            if ($lat === null || $lon === null) {
                continue; // "one row per event that HAS coordinates"
            }
            $anyCoords = true;

            if ($time === null) {
                // Coordinates with no recorded time is a data anomaly, not a
                // representable event — skip rather than emit a fabricated event_time.
                continue;
            }

            if ($maxAccuracy > 0 && $acc !== null && $acc > $maxAccuracy) {
                $excludedByAccuracy++;
                continue; // too imprecise to judge either way — excluded, not counted as compliant
            }

            // Nearest ACTIVE geofence by Haversine distance, not the first one.
            $nearest = null;
            $nearestDist = null;
            foreach ($geofences as $g) {
                $d = GeofenceMath::distanceMeters($lat, $lon, $g['lat'], $g['lon']);
                if ($nearestDist === null || $d < $nearestDist) {
                    $nearestDist = $d;
                    $nearest     = $g;
                }
            }
            // $geofences is never empty here (checked and short-circuited above).

            $storedVal = $p[$gpCol];
            $hasStored = $gCols[$gpCol] && $storedVal !== null;
            $source    = $hasStored ? 'stored' : 'computed';
            $isViolation = $hasStored
                ? !((bool)$storedVal)
                : ($nearestDist > $nearest['radius']);

            $metersOutside = round($nearestDist - $nearest['radius'], 2);
            // min_meters_outside is a severity floor on a MAGNITUDE, and only the
            // computed path has one we can stand behind: it is literally the
            // distance we just measured against the nearest active geofence. A
            // stored verdict is a bare pass/fail from the app with no distance
            // attached, and — as this endpoint's own nearest-active-geofence
            // distance can legitimately disagree with it (different geofence set
            // or a since-moved fence) — using OUR distance to decide whether to
            // discard THEIR verdict would let a coincidental disagreement hide a
            // real, app-confirmed violation. So a stored violation always
            // qualifies; the floor applies only where we computed the number.
            $qualifies = $isViolation && ($source === 'computed' ? $metersOutside >= $minMetersOutside : true);

            if ($qualifies) {
                $qualifyingViolations++;
                $employeesAffected[$eid] = true;
            }

            $include = $onlyViolations ? $qualifies : true;
            if (!$include) {
                continue;
            }

            $rows[] = [
                'punch_id'                 => (string)$p['PunchID'],
                'employee_id'              => $eid,
                'employee_email'           => $email,
                'employee_name'            => $name,
                'office'                   => $office,
                'date'                     => $baseDate,
                'event'                    => $eventName,
                'event_time'               => GeofenceSupport::combine($curDate, $time, $tz),
                'latitude'                 => $lat,
                'longitude'                => $lon,
                'accuracy_meters'          => $acc,
                'nearest_geofence'         => $nearest['name'],
                'geofence_distance_meters' => round($nearestDist, 2),
                'geofence_radius_meters'   => $nearest['radius'],
                'meters_outside'           => $metersOutside,
                'is_required'              => $nearest['required'],
                'source'                   => $source,
            ];
        }

        if (!$anyCoords) {
            $punchesWithoutCoords++;
        }
    }
    $res->free();
    $stmt->close();

    // Deterministic, readable ordering: worst violations first within an
    // otherwise-preserved employee/date/event order, which is what a compliance
    // reviewer opens this report to see first.
    usort($rows, static function (array $a, array $b): int {
        return $b['meters_outside'] <=> $a['meters_outside'];
    });

    // --------------------------------------------------------------- pagination
    $totalCount = count($rows);
    $page       = array_slice($rows, $params->offset(), $params->limit());

    $totals = [
        'violations'                  => $qualifyingViolations,
        'employees_affected'          => count($employeesAffected),
        'punches_checked'             => $punchesChecked,
        'punches_without_coordinates' => $punchesWithoutCoords,
    ];

    // ------------------------------------------------------------------ warnings
    $warnings = [];
    if ($punchesWithoutCoords > 0) {
        $warnings[] = $punchesWithoutCoords . ' of ' . $punchesChecked . ' punch(es) in this range have no '
            . 'GPS coordinates recorded on any event and could not be checked against a geofence at all.';
    }
    if ($excludedByAccuracy > 0) {
        $warnings[] = $excludedByAccuracy . ' reading(s) were excluded because their reported accuracy was '
            . 'worse than max_accuracy=' . $maxAccuracy . 'm — too imprecise to say whether they were '
            . 'actually inside or outside the geofence.';
    }
    $hasStoredAny = $gCols['GeofencePassIN'] || $gCols['GeofencePassOut']
        || $gCols['GeofencePassLunchStart'] || $gCols['GeofencePassLunchEnd'];
    if (!$hasStoredAny) {
        $warnings[] = 'This install has no GeofencePass* columns; every row was computed here via Haversine '
            . 'distance rather than read from a stored app verdict (source=computed on every row).';
    }

    $meta = $params->meta('geofence', [
        'row_count'   => count($page),
        'total_count' => $totalCount,
        'warnings'    => $warnings,
    ]);
    $meta['filters']['only_violations']    = $onlyViolations;
    $meta['filters']['min_meters_outside'] = $minMetersOutside;
    $meta['filters']['max_accuracy']       = $maxAccuracy;

    $csvColumns = ['punch_id', 'employee_id', 'employee_email', 'employee_name', 'office', 'date', 'event',
        'event_time', 'latitude', 'longitude', 'accuracy_meters', 'nearest_geofence',
        'geofence_distance_meters', 'geofence_radius_meters', 'meters_outside', 'is_required', 'source'];

    Output::send(
        ['rows' => $page, 'meta' => $meta, 'totals' => $totals],
        $params,
        'geofence',
        $csvColumns
    );

} catch (Throwable $e) {
    error_log(sprintf(
        '[external-api/reports/geofence] %s: %s at %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    ApiResponse::error('Internal server error', 500);
}
