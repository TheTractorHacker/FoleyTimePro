<?php
/**
 * ODataDispatch — the shared engine behind every file under
 * /api/external/odata/. One file, not nine copies, of:
 *   - the entity registry (property lists / Edm types / keys — the SINGLE
 *     source of truth $metadata and every row-shaping pass both read from, so
 *     they can never drift apart),
 *   - the two data-loading strategies (SQL push-down vs. in-memory — see
 *     "WHY TWO STRATEGIES" below),
 *   - response shaping (the OData JSON envelope, @odata.count, @odata.nextLink,
 *     the OData error shape) and the CSDL $metadata document.
 *
 * Every actual URL is still a real file at the URL's exact path (index.php,
 * metadata.php, $metadata.php, v1/<Entity>.php) — see the routing note in each
 * of those files — but each one is a thin wrapper that calls in here. This is
 * the "single dispatcher" half of the task's "per entity set, OR a single
 * dispatcher" choice; per-entity FILES still exist (nginx routes by file
 * placement, not by a router this codebase controls — see ROUTING in every
 * entry-point file), but they carry no entity-specific logic of their own.
 *
 * WHY TWO DATA-LOADING STRATEGIES (sql vs memory):
 * Employees, Offices and TimeOff are thin views over one or two tables with no
 * cross-row aggregation, so $filter/$orderby/$top/$skip compile straight to SQL
 * against a derived table (see runSqlEntity()) — real push-down, real EXPLAIN-
 * able queries, the fastest and most scalable path.
 * Punches (overnight-safe instant formatting), Shifts (tolerant DaysOfWeek
 * decode), and Hours/Overtime/Utilization/Schedule (worked-hours and schedule
 * math) all depend on logic that already lives in HoursCalculator /
 * ScheduleCalculator / punches.php-style date-shifting — logic this task's own
 * rules forbid re-deriving in SQL ("Never duplicate HoursCalculator's punch
 * math — call it"). Those five entities are computed once into a bounded PHP
 * array (see DEFAULT_WINDOW_DAYS below) and then filtered/sorted/paged with the
 * SAME parsed $filter AST via ODataQuery::evaluateFilter()/makeComparator() —
 * so $filter is parsed and validated in exactly one place regardless of which
 * strategy an entity uses (see ODataQuery.php's own docblock).
 * The live install is tiny (14 users, 41 punches — see the task's own DB
 * notes), so materializing a 400-day window for five entities is a handful of
 * milliseconds, not a scaling concern; the bound exists so that stays true as
 * the install grows, not because it is expensive today.
 *
 * WHY $filter STAYS CORRECT UNDER BOTH STRATEGIES:
 * ODataQuery::parse() is the ONLY place $filter is interpreted. An unsupported
 * construct throws there, before either strategy ever runs — so an entity can
 * never silently hand back more rows than the filter asked for regardless of
 * which loading path it takes.
 */

require_once __DIR__ . '/../../lib/ReportBootstrap.php';
require_once __DIR__ . '/../../lib/ApiTimeFormat.php';
require_once __DIR__ . '/../../lib/ODataQuery.php';
require_once __DIR__ . '/../../lib/LiveStatus.php';

final class ODataDispatch
{
    /** OData namespace for every EntityType/EntityContainer in $metadata. */
    private const NAMESPACE = 'FoleyTimePro';

    /**
     * Hours/Overtime/Utilization/Schedule have no $filter-able date-range
     * parameter the way the REST /api/external/v1/reports/* endpoints do (an
     * OData entity set's shape is fixed; there is nowhere to hang a required
     * start/end). To keep them boundedly computable regardless of install size,
     * they are limited to the trailing N days ending today (UTC — see
     * HoursCalculator's own note on why date arithmetic in this codebase is
     * done in UTC). This mirrors ReportParams::MAX_RANGE_DAYS, the same cap the
     * REST reports themselves refuse to exceed in a single request.
     */
    private const DEFAULT_WINDOW_DAYS = 400;

    /** Safety valve on the Punches base fetch — see loadPunches(). */
    private const PUNCHES_ROW_CAP = 20000;

    // ======================================================================
    // Entity registry — the single source of truth for $metadata AND for
    // every row-shaping pass. $conn is needed only to resolve the two
    // runtime-detected columns (HireDate, IsOpen); see the task's DB notes.
    // ======================================================================

    /**
     * @return array<string, array{
     *   scope: string,
     *   entityType: string,
     *   keys: string[],
     *   properties: array<int, array{name: string, type: string, nullable: bool}>,
     *   strategy: 'sql'|'memory'
     * }>
     */
    private static function entityDefs(mysqli $conn): array
    {
        $hasHireDate = self::columnExists($conn, 'users', 'HireDate');

        $employeeProps = [
            ['name' => 'EmployeeID',  'type' => 'Edm.Int32',   'nullable' => false],
            ['name' => 'Email',       'type' => 'Edm.String',  'nullable' => true],
            ['name' => 'FirstName',   'type' => 'Edm.String',  'nullable' => true],
            ['name' => 'LastName',    'type' => 'Edm.String',  'nullable' => true],
            ['name' => 'Office',      'type' => 'Edm.String',  'nullable' => true],
            ['name' => 'JobTitle',    'type' => 'Edm.String',  'nullable' => true],
            ['name' => 'PhoneNumber', 'type' => 'Edm.String',  'nullable' => true],
            ['name' => 'IsActive',    'type' => 'Edm.Boolean', 'nullable' => false],
            ['name' => 'ClockStatus', 'type' => 'Edm.String',  'nullable' => true],
        ];
        if ($hasHireDate) {
            // Live-only column (see the task's DB notes) — appended only when
            // present so $metadata never advertises a property this install's
            // schema cannot back, and Employees.php never SELECTs a column that
            // does not exist.
            $employeeProps[] = ['name' => 'HireDate', 'type' => 'Edm.Date', 'nullable' => true];
        }

        return [
            'Employees' => [
                'scope'      => 'employees:read',
                'entityType' => 'EmployeeItem',
                'keys'       => ['EmployeeID'],
                'properties' => $employeeProps,
                'strategy'   => 'sql',
            ],
            'Punches' => [
                'scope'      => 'punches:read',
                'entityType' => 'PunchItem',
                'keys'       => ['PunchID'],
                'properties' => [
                    ['name' => 'PunchID',          'type' => 'Edm.Int32',          'nullable' => false],
                    ['name' => 'EmployeeID',       'type' => 'Edm.Int32',          'nullable' => false],
                    ['name' => 'EmployeeEmail',    'type' => 'Edm.String',         'nullable' => true],
                    ['name' => 'Date',             'type' => 'Edm.Date',           'nullable' => false],
                    ['name' => 'ClockIn',          'type' => 'Edm.DateTimeOffset', 'nullable' => true],
                    ['name' => 'LunchStart',       'type' => 'Edm.DateTimeOffset', 'nullable' => true],
                    ['name' => 'LunchEnd',         'type' => 'Edm.DateTimeOffset', 'nullable' => true],
                    ['name' => 'ClockOut',         'type' => 'Edm.DateTimeOffset', 'nullable' => true],
                    ['name' => 'TotalHoursStored', 'type' => 'Edm.Double',         'nullable' => true],
                    ['name' => 'IsOpen',           'type' => 'Edm.Boolean',        'nullable' => false],
                    ['name' => 'UpdatedAt',        'type' => 'Edm.DateTimeOffset', 'nullable' => false],
                ],
                'strategy' => 'memory',
            ],
            'Hours' => [
                'scope'      => 'reports:read',
                'entityType' => 'HoursItem',
                'keys'       => ['EmployeeID', 'Date'],
                'properties' => [
                    ['name' => 'EmployeeID',       'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'Date',              'type' => 'Edm.Date',    'nullable' => false],
                    ['name' => 'EmployeeEmail',     'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'EmployeeName',      'type' => 'Edm.String',  'nullable' => false],
                    ['name' => 'Office',            'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'JobTitle',          'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'DayOfWeek',         'type' => 'Edm.String',  'nullable' => false],
                    ['name' => 'FirstIn',           'type' => 'Edm.TimeOfDay', 'nullable' => true],
                    ['name' => 'LastOut',           'type' => 'Edm.TimeOfDay', 'nullable' => true],
                    ['name' => 'PunchCount',        'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'WorkedHours',       'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'LunchHours',        'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'SpanHours',         'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'StoredTotalHours',  'type' => 'Edm.Double',  'nullable' => true],
                    ['name' => 'HasOpenPunch',      'type' => 'Edm.Boolean', 'nullable' => false],
                    ['name' => 'Warnings',          'type' => 'Edm.String',  'nullable' => false],
                ],
                'strategy' => 'memory',
            ],
            'Overtime' => [
                'scope'      => 'reports:read',
                'entityType' => 'OvertimeItem',
                'keys'       => ['EmployeeID', 'WeekStart'],
                'properties' => [
                    ['name' => 'EmployeeID',           'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'WeekStart',             'type' => 'Edm.Date',    'nullable' => false],
                    ['name' => 'WeekEnd',               'type' => 'Edm.Date',    'nullable' => false],
                    ['name' => 'EmployeeEmail',         'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'EmployeeName',          'type' => 'Edm.String',  'nullable' => false],
                    ['name' => 'Office',                'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'IsPartialWeek',          'type' => 'Edm.Boolean', 'nullable' => false],
                    ['name' => 'DaysWorked',             'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'WorkedHours',            'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'RegularHours',           'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'OvertimeHours',          'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'DoubleTimeHours',        'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'DailyOvertimeHours',     'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'WeeklyOvertimeHours',    'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'OtThresholdWeekly',      'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'OtThresholdDaily',       'type' => 'Edm.Double',  'nullable' => false],
                ],
                'strategy' => 'memory',
            ],
            'Utilization' => [
                'scope'      => 'reports:read',
                'entityType' => 'UtilizationItem',
                'keys'       => ['EmployeeID'],
                'properties' => [
                    ['name' => 'EmployeeID',      'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'EmployeeEmail',   'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'EmployeeName',    'type' => 'Edm.String',  'nullable' => false],
                    ['name' => 'Office',          'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'JobTitle',        'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'ShiftName',       'type' => 'Edm.String',  'nullable' => true],
                    ['name' => 'HasSchedule',     'type' => 'Edm.Boolean', 'nullable' => false],
                    ['name' => 'ScheduledDays',   'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'ScheduledHours',  'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'DaysWorked',      'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'WorkedHours',     'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'RegularHours',    'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'OvertimeHours',   'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'DoubleTimeHours', 'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'TimeOffDays',     'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'TimeOffHours',    'type' => 'Edm.Double',  'nullable' => false],
                    ['name' => 'AbsentDays',      'type' => 'Edm.Int32',   'nullable' => false],
                    ['name' => 'UtilizationPct',  'type' => 'Edm.Double',  'nullable' => true],
                    ['name' => 'OvertimePct',     'type' => 'Edm.Double',  'nullable' => true],
                    ['name' => 'AttendanceRate',  'type' => 'Edm.Double',  'nullable' => true],
                    ['name' => 'VarianceHours',   'type' => 'Edm.Double',  'nullable' => false],
                ],
                'strategy' => 'memory',
            ],
            'Shifts' => [
                'scope'      => 'shifts:read',
                'entityType' => 'ShiftItem',
                'keys'       => ['ShiftID'],
                'properties' => [
                    ['name' => 'ShiftID',                      'type' => 'Edm.Int32',    'nullable' => false],
                    ['name' => 'ShiftName',                    'type' => 'Edm.String',   'nullable' => false],
                    ['name' => 'StartTime',                    'type' => 'Edm.TimeOfDay','nullable' => true],
                    ['name' => 'EndTime',                      'type' => 'Edm.TimeOfDay','nullable' => true],
                    ['name' => 'DaysOfWeek',                   'type' => 'Edm.String',   'nullable' => false],
                    ['name' => 'ScheduledHoursPerDay',         'type' => 'Edm.Double',   'nullable' => false],
                    ['name' => 'LunchDurationMinutes',         'type' => 'Edm.Int32',    'nullable' => false],
                    ['name' => 'LunchStartTime',               'type' => 'Edm.TimeOfDay','nullable' => true],
                    ['name' => 'GracePeriodMinutes',           'type' => 'Edm.Int32',    'nullable' => false],
                    ['name' => 'AutoClockOut',                 'type' => 'Edm.Boolean',  'nullable' => false],
                    ['name' => 'IsActive',                     'type' => 'Edm.Boolean',  'nullable' => false],
                    ['name' => 'AssignedEmployeeCount',        'type' => 'Edm.Int32',    'nullable' => false],
                    ['name' => 'ActiveAssignedEmployeeCount',  'type' => 'Edm.Int32',    'nullable' => false],
                ],
                'strategy' => 'memory',
            ],
            'Schedule' => [
                'scope'      => 'shifts:read',
                'entityType' => 'ScheduleItem',
                'keys'       => ['EmployeeID', 'Date'],
                'properties' => [
                    ['name' => 'EmployeeID',      'type' => 'Edm.Int32',    'nullable' => false],
                    ['name' => 'Date',             'type' => 'Edm.Date',     'nullable' => false],
                    ['name' => 'EmployeeEmail',    'type' => 'Edm.String',   'nullable' => true],
                    ['name' => 'EmployeeName',     'type' => 'Edm.String',   'nullable' => false],
                    ['name' => 'Office',           'type' => 'Edm.String',   'nullable' => true],
                    ['name' => 'ShiftID',          'type' => 'Edm.Int32',    'nullable' => false],
                    ['name' => 'ShiftName',        'type' => 'Edm.String',   'nullable' => false],
                    ['name' => 'DayOfWeek',        'type' => 'Edm.String',   'nullable' => false],
                    ['name' => 'ScheduledStart',   'type' => 'Edm.TimeOfDay','nullable' => true],
                    ['name' => 'ScheduledEnd',     'type' => 'Edm.TimeOfDay','nullable' => true],
                    ['name' => 'ScheduledHours',   'type' => 'Edm.Double',   'nullable' => false],
                    ['name' => 'IsOvernight',      'type' => 'Edm.Boolean',  'nullable' => false],
                    ['name' => 'TimeOffType',      'type' => 'Edm.String',   'nullable' => true],
                    ['name' => 'IsWorkingDay',     'type' => 'Edm.Boolean',  'nullable' => false],
                ],
                'strategy' => 'memory',
            ],
            'TimeOff' => [
                'scope'      => 'timeoff:read',
                'entityType' => 'TimeOffItem',
                'keys'       => ['RequestID'],
                'properties' => [
                    ['name' => 'RequestID',       'type' => 'Edm.Int32',          'nullable' => false],
                    ['name' => 'EmployeeID',      'type' => 'Edm.Int32',          'nullable' => false],
                    ['name' => 'EmployeeEmail',   'type' => 'Edm.String',         'nullable' => true],
                    ['name' => 'RequestType',     'type' => 'Edm.String',         'nullable' => false],
                    ['name' => 'Status',          'type' => 'Edm.String',         'nullable' => false],
                    ['name' => 'StartDate',       'type' => 'Edm.Date',           'nullable' => false],
                    ['name' => 'EndDate',         'type' => 'Edm.Date',           'nullable' => false],
                    ['name' => 'HoursRequested',  'type' => 'Edm.Double',         'nullable' => true],
                    ['name' => 'Reason',          'type' => 'Edm.String',         'nullable' => true],
                    ['name' => 'RequestedAt',     'type' => 'Edm.DateTimeOffset', 'nullable' => true],
                    ['name' => 'ReviewedAt',      'type' => 'Edm.DateTimeOffset', 'nullable' => true],
                    ['name' => 'ReviewedBy',      'type' => 'Edm.String',         'nullable' => true],
                ],
                'strategy' => 'sql',
            ],
            'Offices' => [
                'scope'      => 'offices:read',
                'entityType' => 'OfficeItem',
                'keys'       => ['OfficeID'],
                'properties' => [
                    ['name' => 'OfficeID',             'type' => 'Edm.Int32',  'nullable' => false],
                    ['name' => 'OfficeName',           'type' => 'Edm.String', 'nullable' => false],
                    ['name' => 'EmployeeCount',        'type' => 'Edm.Int32',  'nullable' => false],
                    ['name' => 'ActiveEmployeeCount',  'type' => 'Edm.Int32',  'nullable' => false],
                ],
                'strategy' => 'sql',
            ],
        ];
    }

    /** Every entity-set name this service exposes, in service-document order. */
    public static function entityNames(): array
    {
        return ['Employees', 'Punches', 'Hours', 'Overtime', 'Utilization', 'Shifts', 'Schedule', 'TimeOff', 'Offices'];
    }

    /**
     * Scope per entity set, WITHOUT touching the database — deliberately
     * duplicated from entityDefs()'s 'scope' values (this duplication is
     * checked for drift by a standalone CLI script during development/CI, not
     * at request time — a per-request equality check here would just be
     * ANOTHER thing that must never disagree with itself) so serve() can
     * enforce the scope BEFORE running entityDefs()'s HireDate-detection
     * query. An unauthenticated or wrongly-scoped request should cost this
     * service nothing beyond ApiKeyAuth's own work — the same "reject cheap
     * things first" principle ReportBootstrap.php itself documents for its
     * method-allowlist-before-auth ordering.
     */
    private const SCOPES = [
        'Employees'   => 'employees:read',
        'Punches'     => 'punches:read',
        'Hours'       => 'reports:read',
        'Overtime'    => 'reports:read',
        'Utilization' => 'reports:read',
        'Shifts'      => 'shifts:read',
        'Schedule'    => 'shifts:read',
        'TimeOff'     => 'timeoff:read',
        'Offices'     => 'offices:read',
    ];

    // ======================================================================
    // Entry points
    // ======================================================================

    /**
     * Serve one entity set. Called by v1/<Entity>.php with the exact name it
     * was matched under (which is also the file's own basename, since nginx
     * routes by file placement — see that file's ROUTING comment).
     */
    public static function serve(string $entityName): void
    {
        global $conn;

        try {
            self::serveInner($entityName, $conn);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[external-api/odata] %s: %s at %s:%d',
                get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
            ));
            self::sendError(500, 'InternalServerError', 'Internal server error');
        }
    }

    /**
     * The actual body of serve(), factored out so serve() itself is just the
     * try/catch wrapper every other endpoint in this API has — added after an
     * audit found this whole surface had none. With mysqli's default error
     * mode (MYSQLI_REPORT_ERROR|STRICT, unmodified anywhere in this codebase),
     * any prepare()/execute() throws mysqli_sql_exception on a DB error rather
     * than returning false; with display_errors=Off and no fastcgi_intercept_errors
     * in the nginx vhost, an uncaught one here used to reach the client as a
     * bare, empty 500 — not the OData {"error":{...}} shape a client can parse.
     */
    private static function serveInner(string $entityName, mysqli $conn): void
    {
        if (!isset(self::SCOPES[$entityName])) {
            // Unreachable via the shipped v1/*.php files (each hardcodes its own
            // name); kept as a hard stop in case this method is ever called with
            // a bad value some other way.
            self::sendError(404, 'NotFound', 'Unknown entity set.');
        }

        // Method allowlist + shutdown audit hook + Bearer auth + scope check —
        // identical machinery to every REST endpoint on this API. The scope
        // required is EXACTLY the scope the underlying REST data already
        // requires (see the task's per-entity scope table); there is no
        // separate "odata" scope, so a key scoped only for employees:read gets
        // the same 403 on /Punches here that it would get on
        // /api/external/v1/punches. Checked here — from the static SCOPES map,
        // no DB round trip — BEFORE entityDefs()'s HireDate-detection query
        // below, so a request that never authenticates never costs one.
        ReportBootstrap::begin('/api/external/odata/v1/' . $entityName, self::SCOPES[$entityName]);

        $def = self::cachedEntityDefs($conn)[$entityName];

        $propNames = array_column($def['properties'], 'name');
        $propTypes = array_column($def['properties'], 'type', 'name');

        try {
            $parsed = ODataQuery::parse($_SERVER['QUERY_STRING'] ?? '', $propNames, $propTypes);
        } catch (ODataQueryException $e) {
            self::sendError(400, 'BadRequest', $e->getMessage());
        }

        // Deterministic paging requires a deterministic order. A client that
        // never sends $orderby still gets stable paging by defaulting to the
        // entity's own key(s) — see the class docblock on why an unordered
        // derived table would make @odata.nextLink silently drop or repeat rows.
        if ($parsed['orderBySql'] === '' && $parsed['orderBy'] === []) {
            [$parsed['orderBySql'], $parsed['orderBy']] = self::compileDefaultOrder($def['keys']);
        }

        [$rows, $totalCount] = $def['strategy'] === 'sql'
            ? self::runSqlEntity($conn, $entityName, $def, $parsed)
            : self::runMemoryEntity($conn, $entityName, $def, $parsed);

        // $select projection happens LAST, after filter/sort/page/count, exactly
        // like a real SELECT list never changes which rows a WHERE/ORDER
        // BY/LIMIT chose.
        if ($parsed['select'] !== []) {
            $rows = array_map(static function (array $r) use ($parsed): array {
                $out = [];
                foreach ($parsed['select'] as $p) {
                    $out[$p] = $r[$p] ?? null;
                }
                return $out;
            }, $rows);
        }

        self::sendEntitySet($entityName, $rows, $totalCount, $parsed);
    }

    public static function serveServiceDocument(): void
    {
        try {
            self::beginDiscovery('/api/external/odata');

            $value = [];
            foreach (self::entityNames() as $name) {
                $value[] = ['name' => $name, 'kind' => 'EntitySet', 'url' => self::entitySetUrl($name)];
            }

            self::sendJson([
                '@odata.context' => self::metadataUrl(),
                'value'          => $value,
            ]);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[external-api/odata] %s: %s at %s:%d',
                get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
            ));
            self::sendError(500, 'InternalServerError', 'Internal server error');
        }
    }

    public static function serveMetadata(): void
    {
        global $conn;
        try {
            self::beginDiscovery('/api/external/odata/$metadata');

            $defs = self::cachedEntityDefs($conn);
            $xml  = self::buildCsdl($defs);

            header('Content-Type: application/xml');
            header('OData-Version: 4.0');
            echo $xml;
            exit;
        } catch (Throwable $e) {
            error_log(sprintf(
                '[external-api/odata] %s: %s at %s:%d',
                get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
            ));
            self::sendError(500, 'InternalServerError', 'Internal server error');
        }
    }

    // ======================================================================
    // Auth (discovery endpoints — no specific scope, mirrors
    // /api/external/v1/meta.php's documented rationale exactly: begin()'s
    // $requiredScope is non-nullable, and these two documents are schema, not
    // scoped row data, so ApiKeyAuth::authenticate(null) is called directly)
    // ======================================================================

    private static function beginDiscovery(string $endpointPath): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');

        $startTime   = microtime(true);
        $ip          = ApiKeyAuth::clientIp();
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $method      = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

        $keyId = null;
        register_shutdown_function(function () use (&$keyId, $startTime, $ip, $queryString, $endpointPath, $method) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $code = http_response_code();
            if (!is_int($code)) $code = 500;
            ApiKeyAuth::logRequest($keyId, $endpointPath, $method, $code, $durationMs, $ip, $queryString);
        });

        if (strtoupper($method) !== 'GET') {
            header('Allow: GET');
            self::sendError(405, 'MethodNotAllowed', 'This endpoint accepts GET only.');
        }

        $key   = ApiKeyAuth::authenticate(null);
        $keyId = (int)$key['id'];
    }

    // ======================================================================
    // SQL push-down strategy — Employees, Offices, TimeOff
    // ======================================================================

    /** @return array{0: array, 1: int} [page rows (raw internal representation), totalCount] */
    private static function runSqlEntity(mysqli $conn, string $name, array $def, array $parsed): array
    {
        $base = self::baseSqlFor($conn, $name);

        $where = $parsed['filterSql'] !== '' ? ' WHERE ' . $parsed['filterSql'] : '';
        $order = $parsed['orderBySql'] !== '' ? ' ORDER BY ' . $parsed['orderBySql'] : '';

        // COUNT ignores $top/$skip by construction — it is the TRUE unpaginated
        // count the task requires @odata.count to carry, not the page size.
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM ({$base['sql']}) e" . $where);
        if ($base['types'] !== '' || $parsed['filterTypes'] !== '') {
            $countStmt->bind_param($base['types'] . $parsed['filterTypes'], ...array_merge($base['values'], $parsed['filterBinds']));
        }
        $countStmt->execute();
        $totalCount = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();

        // Fetch exactly $top rows (nextLink is decided from totalCount vs.
        // skip+count($rows) in sendEntitySet(), so no +1 probe row is needed).
        $fetchTop = $parsed['top'];
        $sql = "SELECT * FROM ({$base['sql']}) e" . $where . $order . ' LIMIT ? OFFSET ?';
        $types  = $base['types'] . $parsed['filterTypes'] . 'ii';
        $values = array_merge($base['values'], $parsed['filterBinds'], [$fetchTop, $parsed['skip']]);

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $shaped = array_map(static fn($r) => self::shapeRowForOutput($name, $r), $rows);
        return [$shaped, $totalCount];
    }

    /**
     * The base derived-table SELECT for one SQL-strategy entity: every declared
     * property, aliased to EXACTLY its property name, so $filter/$orderby (which
     * compile to bare `PropertyName` identifiers) resolve against the OUTER
     * query rather than the inner one — MySQL does not allow a WHERE clause to
     * reference a SELECT-list alias, which is exactly why this is wrapped as
     * `SELECT * FROM (...) e WHERE ...` rather than filtered inline.
     *
     * @return array{sql: string, types: string, values: array}
     */
    private static function baseSqlFor(mysqli $conn, string $name): array
    {
        switch ($name) {
            case 'Employees':
                $hasHireDate = self::columnExists($conn, 'users', 'HireDate');
                $hireDateCol = $hasHireDate ? ', u.HireDate AS HireDate' : '';
                return [
                    'sql' => "SELECT u.ID AS EmployeeID,
                                     NULLIF(u.Email,'') AS Email,
                                     NULLIF(u.FirstName,'') AS FirstName,
                                     NULLIF(u.LastName,'') AS LastName,
                                     NULLIF(u.Office,'') AS Office,
                                     NULLIF(u.JobTitle,'') AS JobTitle,
                                     NULLIF(u.PhoneNumber,'') AS PhoneNumber,
                                     CASE WHEN u.LockOut IS NULL OR u.LockOut = 0 THEN 1 ELSE 0 END AS IsActive,
                                     CASE WHEN u.ClockStatus IN ('In','Lunch') THEN 'In'
                                          WHEN u.ClockStatus = 'Out' THEN 'Out'
                                          ELSE NULL END AS ClockStatus
                                     $hireDateCol
                              FROM users u",
                    'types' => '', 'values' => [],
                ];

            case 'Offices':
                // Orphan users.Office values with no Offices row (see offices.php's
                // own extensive comment on this) are deliberately OMITTED here,
                // not represented with a null key: OData requires every entity's
                // key property to be non-null, and there is no non-null key to
                // give a row that has no Offices.ID. The REST /api/external/v1/offices
                // endpoint remains the complete picture, orphans included.
                return [
                    'sql' => "SELECT o.ID AS OfficeID, o.OfficeName AS OfficeName,
                                     COUNT(u.ID) AS EmployeeCount,
                                     COALESCE(SUM(CASE WHEN u.ID IS NOT NULL
                                                        AND (u.LockOut IS NULL OR u.LockOut = 0)
                                                   THEN 1 ELSE 0 END), 0) AS ActiveEmployeeCount
                              FROM Offices o
                              LEFT JOIN users u ON u.Office = o.OfficeName
                              GROUP BY o.ID, o.OfficeName",
                    'types' => '', 'values' => [],
                ];

            case 'TimeOff':
                return [
                    'sql' => "SELECT tor.ID AS RequestID, tor.EmployeeID AS EmployeeID,
                                     NULLIF(u.Email,'') AS EmployeeEmail,
                                     tor.RequestType AS RequestType, tor.Status AS Status,
                                     tor.StartDate AS StartDate, tor.EndDate AS EndDate,
                                     tor.HoursRequested AS HoursRequested,
                                     NULLIF(tor.Reason,'') AS Reason,
                                     UNIX_TIMESTAMP(tor.RequestedAt) AS RequestedAt,
                                     UNIX_TIMESTAMP(tor.ReviewedAt) AS ReviewedAt,
                                     NULLIF(a.username,'') AS ReviewedBy
                              FROM time_off_requests tor
                              JOIN users u ON u.ID = tor.EmployeeID
                              LEFT JOIN admins a ON a.id = tor.ReviewedBy",
                    'types' => '', 'values' => [],
                ];

            default:
                throw new RuntimeException('No SQL base query defined for entity `' . $name . '`.');
        }
    }

    /**
     * Convert one row from its INTERNAL comparison representation (as read
     * straight off the derived table — e.g. DateTimeOffset columns are still
     * UNIX epoch integers there, see ODataQuery's own DateTimeOffset note) into
     * its FINAL JSON wire representation. Applied only to the page actually
     * being returned (at most `top` rows), never to the full matched set.
     */
    private static function shapeRowForOutput(string $entityName, array $row): array
    {
        global $conn;
        $defs = self::cachedEntityDefs($conn);
        $props = $defs[$entityName]['properties'] ?? [];
        $out = [];
        foreach ($props as $p) {
            $name = $p['name'];
            $val  = array_key_exists($name, $row) ? $row[$name] : null;
            $out[$name] = self::coerceForType($val, $p['type']);
        }
        return $out;
    }

    /** Final scalar coercion by declared Edm type, applied uniformly to any row (SQL or memory) before it is emitted. */
    private static function coerceForType($val, string $edmType)
    {
        if ($val === null) {
            return null;
        }
        switch ($edmType) {
            case 'Edm.Int32':
                return (int)$val;
            case 'Edm.Double':
                return (float)$val;
            case 'Edm.Boolean':
                return (bool)((int)$val);
            case 'Edm.DateTimeOffset':
                // Internal representation is always a UNIX epoch int at this point
                // (SQL entities read it that way off the derived table; in-memory
                // entities that build genuine-instant properties — TimeOff has none
                // of its own here, but the convention is shared — also hand epoch
                // ints through this same path). Rendered in UTC: this generic OData
                // surface has no per-request tz concept the way ?tz= gives the REST
                // reports one (see ODataDispatch's class docblock). Goes through
                // ApiTimeFormat::epochOrNull() first rather than trusting is_int()
                // on the raw value — every other epoch-reading call site in this
                // codebase does the same, because a value that came from
                // UNIX_TIMESTAMP() over mysqli is not guaranteed to already be a
                // native PHP int (a fractional-seconds source column would surface
                // it as a numeric string instead), and is_int() silently falling
                // through to (string)$val on a real epoch would emit a raw numeric
                // string where $metadata promises Edm.DateTimeOffset.
                $epoch = ApiTimeFormat::epochOrNull($val);
                return $epoch !== null ? ApiTimeFormat::fromEpoch($epoch, new DateTimeZone('UTC')) : (string)$val;
            case 'Edm.Date':
                return ApiTimeFormat::dateOnly((string)$val);
            case 'Edm.TimeOfDay':
                return ApiTimeFormat::timeOnly((string)$val);
            case 'Edm.String':
            default:
                return (string)$val;
        }
    }

    /** Per-request memo so repeated callers (shapeRowForOutput() per row, serve(), serveMetadata()) never re-run entityDefs()'s column-detection query more than once. */
    private static ?array $entityDefsMemo = null;
    private static function cachedEntityDefs(mysqli $conn): array
    {
        if (self::$entityDefsMemo === null) {
            self::$entityDefsMemo = self::entityDefs($conn);
        }
        return self::$entityDefsMemo;
    }

    // ======================================================================
    // In-memory strategy — Punches, Shifts, Hours, Overtime, Utilization, Schedule
    // ======================================================================

    /** @return array{0: array, 1: int} [page rows, totalCount] */
    private static function runMemoryEntity(mysqli $conn, string $name, array $def, array $parsed): array
    {
        $all = match ($name) {
            'Punches'     => self::loadPunches($conn),
            'Shifts'      => self::loadShifts($conn),
            'Hours'       => self::loadHours($conn),
            'Overtime'    => self::loadOvertime($conn),
            'Utilization' => self::loadUtilization($conn),
            'Schedule'    => self::loadSchedule($conn),
            default       => throw new RuntimeException('No in-memory loader for `' . $name . '`.'),
        };

        $propTypes = array_column($def['properties'], 'type', 'name');

        $matched = $parsed['filterAst'] === null
            ? $all
            : array_values(array_filter($all, static fn($r) => ODataQuery::evaluateFilter($parsed['filterAst'], $r)));

        if ($parsed['orderBy'] !== []) {
            usort($matched, ODataQuery::makeComparator($parsed['orderBy'], $propTypes));
        }

        $totalCount = count($matched);
        $page = array_slice($matched, $parsed['skip'], $parsed['top']);

        return [$page, $totalCount];
    }

    /**
     * Raw punches -> Punches rows, bounded to the same trailing DEFAULT_WINDOW_DAYS
     * window every other memory-strategy entity uses (Hours/Overtime/Utilization/
     * Schedule) — this file originally had NO date filter here at all, just
     * ORDER BY id ASC LIMIT PUNCHES_ROW_CAP, which meant the query returned the
     * SAME oldest 20,000 rows forever once the table passed that count: every
     * punch inserted after row 20,000 became permanently invisible through this
     * entity set with no warning. PUNCHES_ROW_CAP now really is headroom (the
     * date window keeps any realistic install far under it) rather than the
     * thing silently doing the limiting; ORDER BY id DESC means that IF the cap
     * is ever the binding constraint, it drops the oldest rows in the window,
     * not the newest, which is the direction a client would actually want.
     * IsOpen prefers the live-only column via LiveStatus::isOpenColumnExists()
     * — the SAME helper /live/status and /live/headcount already share —
     * falling back to TimeOut IS NULL so this entity works unchanged on an
     * install running the committed schema.
     */
    private static function loadPunches(mysqli $conn): array
    {
        [$start, $end] = self::defaultWindow();

        $hasIsOpen = LiveStatus::isOpenColumnExists($conn);
        $isOpenCol = $hasIsOpen ? ', tp.IsOpen AS IsOpenRaw' : '';

        $stmt = $conn->prepare(
            "SELECT tp.id AS PunchID, tp.EmployeeID AS EmployeeID, u.Email AS EmployeeEmailRaw,
                    tp.Date AS Date, tp.TimeIN AS TimeIN, tp.LunchStart AS LunchStartT,
                    tp.LunchEnd AS LunchEndT, tp.TimeOut AS TimeOut, tp.TotalHours AS TotalHours,
                    UNIX_TIMESTAMP(tp.UpdatedAt) AS UpdatedAtEpoch
                    $isOpenCol
             FROM timepunches tp
             JOIN users u ON u.ID = tp.EmployeeID
             WHERE tp.Date BETWEEN ? AND ?
             ORDER BY tp.id DESC
             LIMIT " . self::PUNCHES_ROW_CAP
        );
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();

        // Fixed timezone, matching /api/external/v1/punches.php's OWN convention
        // exactly (see that file). timepunches stores a bare DATE + TIME with no
        // recorded offset (HoursCalculator's DST note explains why), so these are
        // not real UTC instants to convert — they are local wall-clock times, and
        // "UTC" would just be a second, equally-arbitrary interpretation. Matching
        // the REST endpoint's already-shipped rendering is more useful than a
        // second, disagreeing one.
        $tz = new DateTimeZone('America/Chicago');

        $rows = [];
        while ($p = $res->fetch_assoc()) {
            $baseDate = (string)$p['Date'];
            $clockOutDate   = self::shiftedDate($baseDate, $p['TimeIN'], $p['TimeOut']);
            $lunchEndBase   = $p['LunchStartT'] ?? $p['TimeIN'];
            $lunchEndDate   = self::shiftedDate($baseDate, $lunchEndBase, $p['LunchEndT']);

            $isOpen = $hasIsOpen
                ? ((int)($p['IsOpenRaw'] ?? 0) === 1)
                : ($p['TimeOut'] === null);

            $rows[] = [
                'PunchID'          => (int)$p['PunchID'],
                'EmployeeID'       => (int)$p['EmployeeID'],
                'EmployeeEmail'    => self::nullIfBlank($p['EmployeeEmailRaw']),
                'Date'             => $baseDate,
                'ClockIn'          => self::combineDateTime($baseDate, $p['TimeIN'], $tz),
                'LunchStart'       => self::combineDateTime($baseDate, $p['LunchStartT'], $tz),
                'LunchEnd'         => self::combineDateTime($lunchEndDate, $p['LunchEndT'], $tz),
                'ClockOut'         => self::combineDateTime($clockOutDate, $p['TimeOut'], $tz),
                'TotalHoursStored' => $p['TotalHours'] !== null ? (float)$p['TotalHours'] : null,
                'IsOpen'           => $isOpen,
                'UpdatedAt'        => ApiTimeFormat::fromEpoch(
                    ApiTimeFormat::epochOrNull($p['UpdatedAtEpoch'] ?? null), new DateTimeZone('UTC')
                ) ?? ApiTimeFormat::now(new DateTimeZone('UTC')),
            ];
        }
        $res->free();
        $stmt->close();
        return $rows;
    }

    /**
     * Shifts -> Shifts rows. DaysOfWeek decode mirrors shifts.php's own tolerant
     * decode (malformed/unknown tokens drop silently to an empty or partial
     * list rather than throwing — this is dimension data, not a request to
     * reject); ScheduledHoursPerDay reuses the exact
     * ScheduleCalculator::scheduledHoursForDate() trick shifts.php uses (a
     * shift forced to run every day of a fixed reference Monday), so this can
     * never disagree with /api/external/v1/shifts or the utilization denominator.
     */
    private static function loadShifts(mysqli $conn): array
    {
        $stmt = $conn->prepare(
            "SELECT s.ShiftID, s.ShiftName, s.StartTime, s.EndTime, s.DaysOfWeek,
                    s.LunchDuration, s.LunchStartTime, s.IsActive,
                    s.GracePeriodMinutes, s.AutoClockOut,
                    COUNT(us.ID) AS AssignedCount,
                    COALESCE(SUM(CASE WHEN us.ID IS NOT NULL
                                       AND (u.LockOut IS NULL OR u.LockOut = 0)
                                  THEN 1 ELSE 0 END), 0) AS ActiveAssignedCount
             FROM shifts s
             LEFT JOIN user_shifts us ON us.ShiftID = s.ShiftID
             LEFT JOIN users u        ON u.ID       = us.UserID
             GROUP BY s.ShiftID, s.ShiftName, s.StartTime, s.EndTime, s.DaysOfWeek,
                      s.LunchDuration, s.LunchStartTime, s.IsActive,
                      s.GracePeriodMinutes, s.AutoClockOut"
        );
        $stmt->execute();
        $shiftRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $canonicalDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        $rows = [];
        foreach ($shiftRows as $r) {
            $decoded = json_decode((string)($r['DaysOfWeek'] ?? ''), true);
            $found = [];
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    if (!is_string($d)) continue;
                    $code = strtolower(substr(trim($d), 0, 3));
                    foreach ($canonicalDays as $day) {
                        if (strtolower($day) === $code && !in_array($day, $found, true)) {
                            $found[] = $day;
                        }
                    }
                }
            } else {
                error_log('[odata/Shifts] Malformed shifts.DaysOfWeek JSON for ShiftID ' . (string)$r['ShiftID']);
            }
            $orderedDays = array_values(array_filter($canonicalDays, static fn($d) => in_array($d, $found, true)));

            $spanShift = $r;
            $spanShift['DaysOfWeek'] = '["Mon","Tue","Wed","Thu","Fri","Sat","Sun"]';
            $hours = round(ScheduleCalculator::scheduledHoursForDate($spanShift, '2024-01-01'), 2);

            $rows[] = [
                'ShiftID'                     => (int)$r['ShiftID'],
                'ShiftName'                   => (string)$r['ShiftName'],
                'StartTime'                   => ApiTimeFormat::timeOnly($r['StartTime'] ?? null),
                'EndTime'                     => ApiTimeFormat::timeOnly($r['EndTime'] ?? null),
                'DaysOfWeek'                  => implode(',', $orderedDays),
                'ScheduledHoursPerDay'        => $hours,
                'LunchDurationMinutes'        => (int)($r['LunchDuration'] ?? 0),
                'LunchStartTime'              => ApiTimeFormat::timeOnly($r['LunchStartTime'] ?? null),
                'GracePeriodMinutes'          => (int)($r['GracePeriodMinutes'] ?? 0),
                'AutoClockOut'                => (int)($r['AutoClockOut'] ?? 0) === 1,
                'IsActive'                    => (int)($r['IsActive'] ?? 0) === 1,
                'AssignedEmployeeCount'       => (int)$r['AssignedCount'],
                'ActiveAssignedEmployeeCount' => (int)$r['ActiveAssignedCount'],
            ];
        }
        return $rows;
    }

    /**
     * Fact table: one row per employee per day with punch activity in the
     * default window (see DEFAULT_WINDOW_DAYS) — mirrors
     * /api/external/v1/reports/hours' row shape exactly, minus the cursor-page
     * plumbing that endpoint needs and this one does not (paging here happens
     * AFTER this full in-memory pass, per runMemoryEntity()).
     */
    private static function loadHours(mysqli $conn): array
    {
        [$start, $end] = self::defaultWindow();

        $stmt = $conn->prepare(
            "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut, tp.TotalHours,
                    u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
             FROM timepunches tp
             JOIN users u ON u.ID = tp.EmployeeID
             WHERE tp.Date BETWEEN ? AND ?
             ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.id ASC"
        );
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();

        // Group by (EmployeeID, Date); also remember one user-info row and the
        // raw punch rows for each date so first_in/last_out/lunch/punch_count
        // can be derived exactly like reports/hours.php derives them.
        $byEmpDate = [];
        $userInfo  = [];
        while ($p = $res->fetch_assoc()) {
            $eid = (int)$p['EmployeeID'];
            $userInfo[$eid] = $p;
            $byEmpDate[$eid][$p['Date']][] = $p;
        }
        $res->free();
        $stmt->close();

        $rows = [];
        foreach ($byEmpDate as $eid => $byDate) {
            $flat = [];
            foreach ($byDate as $dayRows) { foreach ($dayRows as $r) { $flat[] = $r; } }
            $daily = HoursCalculator::dailyHoursFromPunches($flat, 0);
            $u = $userInfo[$eid];

            foreach ($byDate as $date => $punchRows) {
                $day = $daily['days'][$date] ?? null;

                $firstInSec = null; $firstIn = null;
                $lastOutSec = null; $lastOut = null;
                $lunchSec   = 0;
                $storedSum  = null;

                foreach ($punchRows as $p) {
                    $in  = HoursCalculator::timeToSeconds($p['TimeIN'] ?? null);
                    $out = HoursCalculator::timeToSeconds($p['TimeOut'] ?? null);
                    if ($in !== null && ($firstInSec === null || $in < $firstInSec)) {
                        $firstInSec = $in; $firstIn = (string)$p['TimeIN'];
                    }
                    if ($out !== null) {
                        $anchored = ($in !== null && $out < $in) ? $out + 86400 : $out;
                        if ($lastOutSec === null || $anchored > $lastOutSec) {
                            $lastOutSec = $anchored; $lastOut = (string)$p['TimeOut'];
                        }
                    }
                    $lunchSec += self::lunchSecondsFor($p);
                    if ($p['TotalHours'] !== null) {
                        $storedSum = ($storedSum ?? 0.0) + (float)$p['TotalHours'];
                    }
                }

                $spanHours = ($firstInSec !== null && $lastOutSec !== null)
                    ? round(max(0, $lastOutSec - $firstInSec) / 3600, 2) : 0.0;
                $workedHours = $day !== null ? (float)$day['hours'] : 0.0;
                $warnings    = $day !== null ? $day['warnings'] : [];

                $rows[] = [
                    'EmployeeID'      => $eid,
                    'Date'            => (string)$date,
                    'EmployeeEmail'   => self::nullIfBlank($u['Email']),
                    'EmployeeName'    => trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? '')),
                    'Office'          => self::nullIfBlank($u['Office']),
                    'JobTitle'        => self::nullIfBlank($u['JobTitle']),
                    'DayOfWeek'       => ApiTimeFormat::dayOfWeek((string)$date),
                    'FirstIn'         => ApiTimeFormat::timeOnly($firstIn),
                    'LastOut'         => ApiTimeFormat::timeOnly($lastOut),
                    'PunchCount'      => count($punchRows),
                    'WorkedHours'     => $workedHours,
                    'LunchHours'      => round($lunchSec / 3600, 2),
                    'SpanHours'       => $spanHours,
                    'StoredTotalHours' => $storedSum !== null ? round($storedSum, 2) : null,
                    'HasOpenPunch'    => ($day['open_punch_count'] ?? 0) > 0,
                    'Warnings'        => implode('|', $warnings),
                ];
            }
        }
        return $rows;
    }

    /** One row per employee per ISO week — mirrors /reports/overtime with fixed default thresholds (ot_weekly=40, ot_daily=0, dt_daily=0, week_start=mon; OData has no request-scoped parameter channel for these — see the class docblock). */
    private static function loadOvertime(mysqli $conn): array
    {
        [$start, $end] = self::defaultWindow();
        $otWeekly = 40.0; $otDaily = 0.0; $dtDaily = 0.0; $weekStart = 'mon';

        $stmt = $conn->prepare(
            "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut,
                    u.Email, u.FirstName, u.LastName, u.Office
             FROM timepunches tp
             JOIN users u ON u.ID = tp.EmployeeID
             WHERE tp.Date BETWEEN ? AND ?
             ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.id ASC"
        );
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();

        $byEmp = []; $userInfo = [];
        while ($p = $res->fetch_assoc()) {
            $eid = (int)$p['EmployeeID'];
            $userInfo[$eid] = $p;
            $byEmp[$eid][] = $p;
        }
        $res->free();
        $stmt->close();

        $rows = [];
        foreach ($byEmp as $eid => $punches) {
            $u = $userInfo[$eid];
            $daily = HoursCalculator::dailyHoursFromPunches($punches, 0);
            $hoursByDate = [];
            foreach ($daily['days'] as $date => $d) { $hoursByDate[$date] = $d['hours']; }

            $split = HoursCalculator::splitOvertime($hoursByDate, $otDaily, $dtDaily, $otWeekly, $weekStart);

            $daysWorkedByWeek = [];
            foreach ($split['days'] as $d) {
                if ($d['hours'] > 0) {
                    $daysWorkedByWeek[$d['week_start']] = ($daysWorkedByWeek[$d['week_start']] ?? 0) + 1;
                }
            }

            foreach ($split['weeks'] as $wkKey => $w) {
                $dailyOt  = (float)$w['daily_overtime'];
                $weeklyOt = (float)$w['weekly_overtime'];
                $overtime = round($dailyOt + $weeklyOt, 2);
                $double   = (float)$w['double_time'];
                $worked   = (float)$w['hours'];
                $regular  = max(0.0, round($worked - $overtime - $double, 2));

                $rows[] = [
                    'EmployeeID'          => $eid,
                    'WeekStart'           => $w['week_start'],
                    'WeekEnd'             => $w['week_end'],
                    'EmployeeEmail'       => self::nullIfBlank($u['Email']),
                    'EmployeeName'        => trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? '')),
                    'Office'              => self::nullIfBlank($u['Office']),
                    'IsPartialWeek'       => HoursCalculator::isPartialWeek($w['week_start'], $w['week_end'], $start, $end),
                    'DaysWorked'          => (int)($daysWorkedByWeek[$wkKey] ?? 0),
                    'WorkedHours'         => $worked,
                    'RegularHours'        => $regular,
                    'OvertimeHours'       => $overtime,
                    'DoubleTimeHours'     => $double,
                    'DailyOvertimeHours'  => $dailyOt,
                    'WeeklyOvertimeHours' => $weeklyOt,
                    'OtThresholdWeekly'   => $otWeekly,
                    'OtThresholdDaily'    => $otDaily,
                ];
            }
        }
        return $rows;
    }

    /** One row per employee for the whole default window — mirrors /reports/utilization's group_by=employee shape. */
    private static function loadUtilization(mysqli $conn): array
    {
        [$start, $end] = self::defaultWindow();

        $stmt = $conn->prepare(
            "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office, u.JobTitle
             FROM users u
             WHERE u.LockOut IS NULL OR u.LockOut = 0
             ORDER BY u.LastName ASC, u.FirstName ASC, u.ID ASC"
        );
        $stmt->execute();
        $roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $rosterIds = array_map(static fn($u) => (int)$u['ID'], $roster);
        $shifts  = $rosterIds === [] ? [] : ScheduleCalculator::loadShiftsForUsers($conn, $rosterIds);
        $timeOff = $rosterIds === [] ? [] : ScheduleCalculator::loadApprovedTimeOff($conn, $rosterIds, $start, $end);

        $stmt = $conn->prepare(
            "SELECT tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut
             FROM timepunches tp
             WHERE tp.Date BETWEEN ? AND ?
               AND tp.EmployeeID IN (" . ($rosterIds === [] ? '0' : implode(',', array_fill(0, count($rosterIds), '?'))) . ")
             ORDER BY tp.EmployeeID ASC, tp.Date ASC, tp.id ASC"
        );
        $types  = 'ss' . str_repeat('i', count($rosterIds));
        $values = array_merge([$start, $end], $rosterIds);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $res = $stmt->get_result();
        $punchesByEmp = [];
        while ($p = $res->fetch_assoc()) {
            $punchesByEmp[(int)$p['EmployeeID']][] = $p;
        }
        $res->free();
        $stmt->close();

        $rows = [];
        foreach ($roster as $u) {
            $eid = (int)$u['ID'];
            $shift = $shifts[$eid] ?? null;
            $hasSchedule = $shift !== null;

            $daily = HoursCalculator::dailyHoursFromPunches($punchesByEmp[$eid] ?? [], 0);
            $hoursByDate = [];
            foreach ($daily['days'] as $date => $d) { $hoursByDate[$date] = $d['hours']; }
            $split = HoursCalculator::splitOvertime($hoursByDate, 0.0, 0.0, 40.0, 'mon');

            $regular = (float)$split['totals']['regular'];
            $ot      = (float)$split['totals']['overtime'];
            $dt      = (float)$split['totals']['double_time'];
            $worked  = round($regular + $ot + $dt, 2);

            $daysWorked = 0;
            foreach ($split['days'] as $d) { if ($d['hours'] > 0) $daysWorked++; }

            $scheduledDays = 0; $scheduledHours = 0.0; $absentDays = 0;
            $empTimeOff = $timeOff[$eid] ?? [];
            foreach (ScheduleCalculator::scheduledDaysBetween($shift, $start, $end) as $date) {
                if (isset($empTimeOff[$date])) continue;
                $scheduledDays++;
                $scheduledHours += ScheduleCalculator::scheduledHoursForDate($shift, $date);
                if (!isset($daily['days'][$date])) $absentDays++;
            }
            $scheduledHours = round($scheduledHours, 2);

            $timeOffDays = 0; $timeOffHours = 0.0;
            foreach ($empTimeOff as $date => $t) {
                $timeOffDays++;
                $hours = $t['hours'] ?? ScheduleCalculator::scheduledHoursForDate($shift, (string)$date);
                $timeOffHours += (float)$hours;
            }
            $timeOffHours = round($timeOffHours, 2);

            $pct = static function (float $n, float $d): ?float {
                return $d <= 0 ? null : round($n / $d * 100, 2);
            };

            $rows[] = [
                'EmployeeID'      => $eid,
                'EmployeeEmail'   => self::nullIfBlank($u['Email']),
                'EmployeeName'    => trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? '')),
                'Office'          => self::nullIfBlank($u['Office']),
                'JobTitle'        => self::nullIfBlank($u['JobTitle']),
                'ShiftName'       => $hasSchedule ? self::nullIfBlank($shift['ShiftName'] ?? null) : null,
                'HasSchedule'     => $hasSchedule,
                'ScheduledDays'   => $scheduledDays,
                'ScheduledHours'  => $scheduledHours,
                'DaysWorked'      => $daysWorked,
                'WorkedHours'     => $worked,
                'RegularHours'    => round($regular, 2),
                'OvertimeHours'   => round($ot, 2),
                'DoubleTimeHours' => round($dt, 2),
                'TimeOffDays'     => $timeOffDays,
                'TimeOffHours'    => $timeOffHours,
                'AbsentDays'      => $absentDays,
                'UtilizationPct'  => $pct($worked, $scheduledHours),
                'OvertimePct'     => $pct($ot, $worked),
                'AttendanceRate'  => $pct((float)$daysWorked, (float)$scheduledDays),
                'VarianceHours'   => round($worked - $scheduledHours, 2),
            ];
        }
        return $rows;
    }

    /** Expanded per-employee-per-date schedule for the default window — mirrors /api/external/v1/schedule row-for-row. */
    private static function loadSchedule(mysqli $conn): array
    {
        [$start, $end] = self::defaultWindow();

        $stmt = $conn->prepare(
            "SELECT u.ID, u.Email, u.FirstName, u.LastName, u.Office
             FROM users u
             WHERE u.LockOut IS NULL OR u.LockOut = 0
             ORDER BY u.FirstName, u.LastName, u.ID"
        );
        $stmt->execute();
        $userRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $userIds = array_map(static fn($u) => (int)$u['ID'], $userRows);
        $shifts  = ScheduleCalculator::loadShiftsForUsers($conn, $userIds);
        $timeOff = ScheduleCalculator::loadApprovedTimeOff($conn, $userIds, $start, $end);

        $rows = [];
        foreach ($userRows as $u) {
            $uid = (int)$u['ID'];
            $shift = $shifts[$uid] ?? null;
            if ($shift === null) continue;

            $dates = ScheduleCalculator::scheduledDaysBetween($shift, $start, $end);
            if ($dates === []) continue;

            $startSec = HoursCalculator::timeToSeconds($shift['StartTime'] ?? null);
            $endSec   = HoursCalculator::timeToSeconds($shift['EndTime'] ?? null);
            $isOvernight = ($startSec !== null && $endSec !== null) && ($endSec <= $startSec);

            $scheduledStart = ApiTimeFormat::timeOnly($shift['StartTime'] ?? null);
            $scheduledEnd   = ApiTimeFormat::timeOnly($shift['EndTime'] ?? null);
            $shiftId   = (int)$shift['ShiftID'];
            $shiftName = (string)$shift['ShiftName'];
            $name   = trim(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? ''));
            $email  = self::nullIfBlank($u['Email']);
            $office = self::nullIfBlank($u['Office']);

            foreach ($dates as $date) {
                $off = $timeOff[$uid][$date] ?? null;
                $rows[] = [
                    'EmployeeID'     => $uid,
                    'Date'           => $date,
                    'EmployeeEmail'  => $email,
                    'EmployeeName'   => $name,
                    'Office'         => $office,
                    'ShiftID'        => $shiftId,
                    'ShiftName'      => $shiftName,
                    'DayOfWeek'      => ApiTimeFormat::dayOfWeek($date),
                    'ScheduledStart' => $scheduledStart,
                    'ScheduledEnd'   => $scheduledEnd,
                    'ScheduledHours' => round(ScheduleCalculator::scheduledHoursForDate($shift, $date), 2),
                    'IsOvernight'    => $isOvernight,
                    'TimeOffType'    => $off['type'] ?? null,
                    'IsWorkingDay'   => $off === null,
                ];
            }
        }
        return $rows;
    }

    // ---------------------------------------------------- shared small helpers

    /** [start, end] Y-m-d, trailing DEFAULT_WINDOW_DAYS ending today — see the class docblock. */
    private static function defaultWindow(): array
    {
        $utc = new DateTimeZone('UTC');
        $end = (new DateTimeImmutable('now', $utc))->format('Y-m-d');
        $start = (new DateTimeImmutable($end, $utc))->modify('-' . (self::DEFAULT_WINDOW_DAYS - 1) . ' days')->format('Y-m-d');
        return [$start, $end];
    }

    private static function nullIfBlank($v)
    {
        return ($v === null || $v === '') ? null : $v;
    }

    /**
     * Lunch seconds actually deducted from worked time — mirrors
     * reports/hours.php's own local closure of the same name/purpose exactly
     * (anchoring + clamping identically to HoursCalculator::punchWorkedSeconds()
     * so this number can never disagree with the worked-hours figure on the
     * same row). Reimplemented here as a private static method, not copied as a
     * global function, for the same reason ApiTimeFormat.php gives for not
     * reusing punches.php's globals: two files declaring the same global
     * function is a fatal redeclaration the moment both load in one process.
     */
    private static function lunchSecondsFor(array $p): int
    {
        $in  = HoursCalculator::timeToSeconds($p['TimeIN'] ?? null);
        $out = HoursCalculator::timeToSeconds($p['TimeOut'] ?? null);
        if ($in === null || $out === null) return 0;
        $ls = HoursCalculator::timeToSeconds($p['LunchStart'] ?? null);
        $le = HoursCalculator::timeToSeconds($p['LunchEnd'] ?? null);
        if ($ls === null || $le === null) return 0;
        if ($out < $in) $out += 86400;
        if ($ls  < $in) $ls  += 86400;
        if ($le  < $ls) $le  += 86400;
        return (int)min(max(0, $le - $ls), $out - $in);
    }

    /**
     * True if $endTime < $startTime (the event wrapped past midnight).
     * Reimplemented as a private method for the same redeclaration reason as
     * lunchSecondsFor() — this is punches.php's own isOvernight(), which lives
     * at GLOBAL scope there and so cannot be require_once'd from here.
     */
    private static function isOvernightTime(?string $startTime, ?string $endTime): bool
    {
        if ($startTime === null || $startTime === '') return false;
        if ($endTime === null || $endTime === '')     return false;
        return strcmp($endTime, $startTime) < 0;
    }

    /** Base date, or +1 day if $endTime wrapped past midnight relative to $startTime. Mirrors punches.php's shiftedDate(). */
    private static function shiftedDate(string $date, ?string $startTime, ?string $endTime): string
    {
        if (!self::isOvernightTime($startTime, $endTime)) return $date;
        try {
            return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        } catch (Exception $e) {
            return $date;
        }
    }

    /** Combine a punch DATE + TIME into an ISO 8601 instant in $tz, or null. Mirrors punches.php's formatDateTime(). */
    private static function combineDateTime(?string $date, ?string $time, DateTimeZone $tz): ?string
    {
        if ($date === null || $time === null || $time === '') return null;
        try {
            return (new DateTimeImmutable($date . ' ' . $time, $tz))->format('c');
        } catch (Exception $e) {
            return null;
        }
    }

    /** [key asc, key asc, ...] as both compiled SQL and structured orderBy, for the deterministic-paging default. */
    private static function compileDefaultOrder(array $keys): array
    {
        $sqlParts = [];
        $structured = [];
        foreach ($keys as $k) {
            $sqlParts[] = '`' . $k . '` ASC';
            $structured[] = ['property' => $k, 'dir' => 'asc'];
        }
        return [implode(', ', $sqlParts), $structured];
    }

    /** SHOW COLUMNS existence check, cached in a static per (table,column) for the life of the request — never per row. */
    private static array $columnExistsCache = [];
    private static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnExistsCache)) {
            return self::$columnExistsCache[$key];
        }
        // $table/$column are always literal constants from THIS file, never
        // request input, but they are still validated structurally before
        // being concatenated into SHOW COLUMNS (which does not support
        // placeholders for identifiers) as a second, defensive guarantee.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new RuntimeException('Invalid identifier passed to columnExists().');
        }
        $exists = false;
        // Safe by construction: the regex guard above already restricts $table/
        // $column to [A-Za-z0-9_], so neither can contain a quote or backslash —
        // wrapping the (already-validated) column name in real_escape_string()
        // would escape the surrounding quote DELIMITERS themselves, which is the
        // wrong operation here (it escapes literal content, not a value the
        // caller already quoted). The regex is the actual injection guarantee;
        // '_'/'%' are additionally escaped below purely for LIKE-pattern
        // correctness (every call site today passes a literal with neither, but
        // an underscore is a legal identifier character and IS a LIKE wildcard —
        // this keeps an exact match exact if that ever changes).
        $likeSafeColumn = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $likeSafeColumn . "'");
        if ($res) {
            $exists = $res->num_rows > 0;
            $res->free();
        }
        return self::$columnExistsCache[$key] = $exists;
    }

    // ======================================================================
    // Response shaping
    // ======================================================================

    private static function sendEntitySet(string $name, array $rows, int $totalCount, array $parsed): void
    {
        $body = [
            '@odata.context' => self::metadataUrl() . '#' . $name,
        ];
        if ($parsed['count']) {
            $body['@odata.count'] = $totalCount;
        }
        $body['value'] = $rows;

        // Server-driven paging: a nextLink is emitted whenever the page fetched
        // did not reach the end of the matched set, REGARDLESS of whether the
        // client passed $top explicitly. Power BI's OData connector follows
        // nextLink automatically and stops when it is absent — a nextLink that
        // is missing (when more rows exist) or wrong (skip not advanced by
        // exactly this page's size) both look, to Power BI, identical to "that
        // is all the data" and silently truncate the dataset the user thinks
        // they imported. skip is advanced by count($rows) — the ACTUAL number
        // of rows just returned — not by $parsed['top'], so a final short page
        // can never produce a link that skips past the end or repeats rows.
        $consumed = $parsed['skip'] + count($rows);
        if ($consumed < $totalCount) {
            $body['@odata.nextLink'] = self::nextLink($name, $parsed, $consumed);
        }

        self::sendJson($body);
    }

    private static function sendJson(array $body): void
    {
        header('Content-Type: application/json;odata.metadata=minimal');
        header('OData-Version: 4.0');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * OData error shape — {"error":{"code":"...","message":"..."}} — NOT the
     * ApiResponse envelope ({"success":false,"error":"..."}) every REST
     * endpoint on this API uses. An OData client (Power BI's connector
     * included) parses the error body to decide how to report a failed
     * request to the user; handing it the REST envelope's different shape
     * would leave it with no error message to show at all. This divergence is
     * deliberate and confined to this directory — nothing under
     * /api/external/v1 changes.
     * NOTE: auth/scope/rate-limit failures (401/403/429/500) happen INSIDE
     * ApiKeyAuth::authenticate(), which every entity file reaches through
     * ReportBootstrap::begin() (a hard requirement — see the task rules on
     * never skipping the scope check or audit hook) and which calls
     * ApiResponse::error() directly. Those specific failure modes are
     * therefore still returned in the REST envelope shape, not this one; only
     * errors raised from code in this file (bad $filter, unknown entity, etc.)
     * use the OData shape. A future revision could special-case this by
     * wrapping authenticate() were the inconsistency worth the coupling risk;
     * today an OData client gets a correctly-coded 401/403/429 either way, and
     * every response is JSON either way, which is the part that actually
     * matters for a client that fails the request and moves on.
     */
    private static function sendError(int $status, string $code, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('OData-Version: 4.0');
        echo json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ------------------------------------------------------------ URL helpers

    private static function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Strip anything unexpected out of Host before it goes into a response
        // body — this only affects the convenience nextLink/context URLs, never
        // a security decision, but a response should not echo raw attacker input.
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host) ?? 'localhost';
        return $scheme . '://' . $host . '/api/external/odata';
    }

    private static function metadataUrl(): string
    {
        return self::baseUrl() . '/$metadata';
    }

    private static function entitySetUrl(string $name): string
    {
        return self::baseUrl() . '/v1/' . $name;
    }

    /**
     * Absolute nextLink carrying every original query option forward, with
     * $skip replaced by $newSkip. Rebuilt from the RAW query string (not from
     * $parsed's normalized values) so an option this service does not
     * interpret itself (only $format survives that today) still round-trips
     * unchanged, and so re-serialization can never subtly reformat $filter/
     * $orderby text in a way that changes its meaning on the next hop.
     */
    private static function nextLink(string $name, array $parsed, int $newSkip): string
    {
        $get = [];
        parse_str($_SERVER['QUERY_STRING'] ?? '', $get);
        $get['$skip'] = (string)$newSkip;
        $get['$top']  = (string)$parsed['top']; // explicit, so a re-fetch can't silently pick up a new default
        return self::entitySetUrl($name) . '?' . http_build_query($get, '', '&', PHP_QUERY_RFC3986);
    }

    // ======================================================================
    // $metadata (CSDL)
    // ======================================================================

    private static function buildCsdl(array $defs): string
    {
        $edm  = static fn($t) => htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        // Heredoc interpolation only expands `$variables`/`{$expr}`, never a
        // bare `self::CONST` — pulled into a local var first so the namespace
        // is never (silently) emitted as the literal text "self::NAMESPACE".
        $ns = self::NAMESPACE;

        $entityTypes = '';
        $entitySets  = '';
        foreach ($defs as $setName => $def) {
            $entityTypes .= '      <EntityType Name="' . $edm($def['entityType']) . "\">\n";
            $entityTypes .= '        <Key>' . implode('', array_map(
                static fn($k) => '<PropertyRef Name="' . $edm($k) . '"/>', $def['keys']
            )) . "</Key>\n";
            foreach ($def['properties'] as $p) {
                $nullable = $p['nullable'] ? 'true' : 'false';
                $entityTypes .= '        <Property Name="' . $edm($p['name']) . '" Type="' . $edm($p['type'])
                    . '" Nullable="' . $nullable . "\"/>\n";
            }
            $entityTypes .= "      </EntityType>\n";

            $entitySets .= '        <EntitySet Name="' . $edm($setName) . '" EntityType="'
                . $ns . '.' . $edm($def['entityType']) . "\"/>\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx" Version="4.0">
  <edmx:DataServices>
    <Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="{$ns}">
{$entityTypes}      <EntityContainer Name="{$ns}Container">
{$entitySets}      </EntityContainer>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>

XML;
    }
}
