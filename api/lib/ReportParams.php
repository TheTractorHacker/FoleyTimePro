<?php
/**
 * ReportParams — parses and validates the canonical query-parameter contract that
 * every /api/external/v1 report endpoint shares.
 *
 * Two goals:
 *   1. An integrator gets ONE consistent, specific 400 for a bad parameter no
 *      matter which report they hit.
 *   2. Endpoints never hand-roll their own filter SQL — buildUserFilter() is the
 *      single place the employee/office/active predicate is written, so "active"
 *      can never mean one thing on /utilization and another on /timecards.
 *
 * Every validation failure exits via ApiResponse::error(..., 400) naming the
 * parameter and listing what is accepted.
 */

require_once __DIR__ . '/../middleware/ApiResponse.php';

class ReportParams
{
    /** Every endpoint honors these regardless of what it passes in $accepted. */
    private const ALWAYS_ACCEPTED = ['tz', 'format'];

    private const VALID_ROUNDING = [0, 5, 6, 10, 15, 20, 30];

    /** Bounds the cost of a single report query. */
    private const MAX_RANGE_DAYS = 400;
    private const MAX_LIMIT      = 5000;
    private const DEFAULT_LIMIT  = 500;
    /** Deep offsets are a performance trap (MySQL still walks the skipped rows). */
    private const MAX_OFFSET     = 1000000;

    private const FALLBACK_TIMEZONE = 'America/Chicago';

    private array $accepted = [];
    private array $ignored = [];

    private ?string $start = null;
    private ?string $end   = null;

    /** @var int[] */    private array $employeeIds    = [];
    /** @var string[] */ private array $employeeEmails = [];
    /** @var string[] */ private array $offices        = [];

    private bool $includeInactive = false;
    private int $rounding         = 0;
    private string $weekStart     = 'mon';
    private float $otWeekly       = 40.0;
    private float $otDaily        = 0.0;
    private float $dtDaily        = 0.0;
    private DateTimeZone $timezone;
    private string $format = 'json';
    private int $limit     = self::DEFAULT_LIMIT;
    private int $offset    = 0;

    private function __construct()
    {
        $this->timezone = new DateTimeZone(self::FALLBACK_TIMEZONE);
    }

    /**
     * Parse $_GET for an endpoint that honors $accepted.
     *
     * $accepted lists the parameter names this endpoint understands, e.g.
     *   ['start','end','employee_id','office','include_inactive','rounding',
     *    'week_start','ot_weekly','ot_daily','dt_daily','limit','offset']
     *
     * 'start'/'end' are REQUIRED whenever they appear in $accepted — a date-ranged
     * report with an open-ended range is a table scan waiting to happen.
     * Anything present in $get but not accepted is ignored and echoed back in
     * meta.ignored_params so a typo ("employe_id") is visible instead of silently
     * returning unfiltered data.
     *
     * @param array $get      normally $_GET
     * @param array $accepted parameter names this endpoint honors
     */
    public static function fromRequest(array $get, mysqli $conn, array $accepted): self
    {
        $p = new self();
        $p->accepted = array_values(array_unique(array_merge($accepted, self::ALWAYS_ACCEPTED)));

        foreach (array_keys($get) as $name) {
            if (!in_array((string)$name, $p->accepted, true)) {
                $p->ignored[] = (string)$name;
            }
        }

        // Timezone first: meta.generated_at and any absolute formatting depend on it.
        $p->timezone = $p->parseTimezone($get, $conn);

        if ($p->honors('start') || $p->honors('end')) {
            $p->parseDateRange($get);
        }
        if ($p->honors('employee_id')) {
            $p->employeeIds = $p->parseEmployeeIds($get);
        }
        if ($p->honors('employee_email')) {
            $p->employeeEmails = $p->parseEmails($get);
        }
        if ($p->honors('office')) {
            $p->offices = $p->parseOffices($get);
        }
        if ($p->honors('include_inactive')) {
            $p->includeInactive = $p->parseBool($get, 'include_inactive', false);
        }
        if ($p->honors('rounding')) {
            $p->rounding = $p->parseRounding($get);
        }
        if ($p->honors('week_start')) {
            $p->weekStart = $p->parseWeekStart($get);
        }
        if ($p->honors('ot_weekly')) {
            $p->otWeekly = $p->parseHoursThreshold($get, 'ot_weekly', 40.0, 168.0);
        }
        if ($p->honors('ot_daily')) {
            $p->otDaily = $p->parseHoursThreshold($get, 'ot_daily', 0.0, 24.0);
        }
        if ($p->honors('dt_daily')) {
            $p->dtDaily = $p->parseHoursThreshold($get, 'dt_daily', 0.0, 24.0);
        }
        if ($p->otDaily > 0 && $p->dtDaily > 0 && $p->dtDaily < $p->otDaily) {
            ApiResponse::error(
                'Invalid parameter `dt_daily`: the double-time threshold (' . $p->dtDaily
                . ') must be greater than or equal to `ot_daily` (' . $p->otDaily . ').',
                400
            );
        }

        $p->format = $p->parseFormat($get);

        if ($p->honors('limit')) {
            $p->limit = $p->parseInt($get, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        }
        if ($p->honors('offset')) {
            $p->offset = $p->parseInt($get, 'offset', 0, 0, self::MAX_OFFSET);
        }

        return $p;
    }

    // ---------------------------------------------------------------- getters

    public function startDate(): ?string { return $this->start; }
    public function endDate(): ?string   { return $this->end; }
    /** @return int[] */    public function employeeIds(): array    { return $this->employeeIds; }
    /** @return string[] */ public function employeeEmails(): array { return $this->employeeEmails; }
    /** @return string[] */ public function offices(): array        { return $this->offices; }
    public function includeInactive(): bool     { return $this->includeInactive; }
    public function rounding(): int             { return $this->rounding; }
    public function weekStart(): string         { return $this->weekStart; }
    public function otWeekly(): float           { return $this->otWeekly; }
    public function otDaily(): float            { return $this->otDaily; }
    public function dtDaily(): float            { return $this->dtDaily; }
    public function timezone(): DateTimeZone    { return $this->timezone; }
    public function format(): string            { return $this->format; }
    public function limit(): int                { return $this->limit; }
    public function offset(): int               { return $this->offset; }
    /** @return string[] */ public function ignoredParams(): array  { return $this->ignored; }

    /** True when this endpoint declared it honors $name. */
    public function honors(string $name): bool
    {
        return in_array($name, $this->accepted, true);
    }

    // ------------------------------------------------------------- SQL filter

    /**
     * The users-table predicate for the employee/office/active filters.
     *
     * Usage:
     *   $f   = $params->buildUserFilter('u');
     *   $sql = "... WHERE tp.Date BETWEEN ? AND ?"
     *        . ($f['sql'] !== '' ? " AND {$f['sql']}" : '');
     *   $types  = 'ss' . $f['types'];
     *   $values = array_merge([$start, $end], $f['values']);
     *
     * `sql` is a bare conjunction with NO leading/trailing AND, and is '' when no
     * filter applies. Placeholder lists are built from the COUNT of validated
     * values only — the values themselves never touch the SQL string.
     *
     * @return array{sql: string, types: string, values: array}
     */
    public function buildUserFilter(string $userAlias): array
    {
        // Defensive: an alias is an identifier, never user input, but this makes
        // that guarantee structural rather than a matter of trust.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $userAlias)) {
            throw new InvalidArgumentException('Invalid SQL alias: ' . $userAlias);
        }

        $clauses = [];
        $types   = '';
        $values  = [];

        if (!$this->includeInactive) {
            // LockOut may be NULL on legacy rows; only 1 means locked out.
            $clauses[] = "($userAlias.LockOut IS NULL OR $userAlias.LockOut = 0)";
        }

        if ($this->employeeIds !== []) {
            $ph        = implode(',', array_fill(0, count($this->employeeIds), '?'));
            $clauses[] = "$userAlias.ID IN ($ph)";
            $types    .= str_repeat('i', count($this->employeeIds));
            foreach ($this->employeeIds as $v) { $values[] = $v; }
        }

        if ($this->employeeEmails !== []) {
            $ph        = implode(',', array_fill(0, count($this->employeeEmails), '?'));
            $clauses[] = "$userAlias.Email IN ($ph)";
            $types    .= str_repeat('s', count($this->employeeEmails));
            foreach ($this->employeeEmails as $v) { $values[] = $v; }
        }

        if ($this->offices !== []) {
            $ph        = implode(',', array_fill(0, count($this->offices), '?'));
            $clauses[] = "$userAlias.Office IN ($ph)";
            $types    .= str_repeat('s', count($this->offices));
            foreach ($this->offices as $v) { $values[] = $v; }
        }

        return [
            'sql'    => $clauses === [] ? '' : implode(' AND ', $clauses),
            'types'  => $types,
            'values' => $values,
        ];
    }

    // ------------------------------------------------------------------ meta

    /**
     * The meta block for the response envelope.
     *
     * $extra typically carries row_count / total_count / warnings and any
     * report-specific keys. Keys in $extra overwrite the defaults IN PLACE, so the
     * published key order stays identical across reports.
     * has_more is derived from offset + row_count < total_count unless $extra
     * supplies it explicitly.
     */
    public function meta(string $report, array $extra = []): array
    {
        $rowCount   = isset($extra['row_count'])   ? (int)$extra['row_count']   : 0;
        $totalCount = isset($extra['total_count']) ? (int)$extra['total_count'] : $rowCount;

        $base = [
            'report'         => $report,
            'generated_at'   => (new DateTimeImmutable('now', $this->timezone))->format('c'),
            'timezone'       => $this->timezone->getName(),
            'start'          => $this->start,
            'end'            => $this->end,
            'filters'        => [
                'employee_id'      => $this->employeeIds,
                'employee_email'   => $this->employeeEmails,
                'office'           => $this->offices,
                'include_inactive' => $this->includeInactive,
            ],
            'rules'          => [
                'rounding_minutes' => $this->rounding,
                'week_start'       => $this->weekStart,
                'ot_weekly'        => $this->otWeekly,
                'ot_daily'         => $this->otDaily,
                'dt_daily'         => $this->dtDaily,
            ],
            'row_count'      => $rowCount,
            'total_count'    => $totalCount,
            'limit'          => $this->limit,
            'offset'         => $this->offset,
            'has_more'       => ($this->offset + $rowCount) < $totalCount,
            'ignored_params' => $this->ignored,
            'warnings'       => [],
        ];

        return array_merge($base, $extra);
    }

    // -------------------------------------------------------------- internals

    private function parseDateRange(array $get): void
    {
        $start = $this->scalar($get, 'start');
        $end   = $this->scalar($get, 'end');

        if ($start === null || $start === '') {
            ApiResponse::error('Missing required parameter: `start` (date, format YYYY-MM-DD).', 400);
        }
        if ($end === null || $end === '') {
            ApiResponse::error('Missing required parameter: `end` (date, format YYYY-MM-DD).', 400);
        }
        if (!$this->isValidDate($start)) {
            ApiResponse::error('Invalid parameter `start`: expected a calendar date as YYYY-MM-DD.', 400);
        }
        if (!$this->isValidDate($end)) {
            ApiResponse::error('Invalid parameter `end`: expected a calendar date as YYYY-MM-DD.', 400);
        }
        if ($end < $start) {
            ApiResponse::error('Invalid date range: `end` (' . $end . ') is before `start` (' . $start . ').', 400);
        }

        $days = $this->inclusiveDays($start, $end);
        if ($days > self::MAX_RANGE_DAYS) {
            ApiResponse::error(
                'Invalid date range: ' . $days . ' days requested, maximum is '
                . self::MAX_RANGE_DAYS . '. Split the range into smaller requests.',
                400
            );
        }

        $this->start = $start;
        $this->end   = $end;
    }

    /** @return int[] */
    private function parseEmployeeIds(array $get): array
    {
        $out = [];
        foreach ($this->csvList($get, 'employee_id') as $raw) {
            if (!preg_match('/^\d+$/', $raw) || (int)$raw <= 0) {
                ApiResponse::error(
                    'Invalid parameter `employee_id`: "' . $this->safe($raw)
                    . '" is not a positive integer. Accepts one id or a comma list, e.g. 3,7,11.',
                    400
                );
            }
            $id = (int)$raw;
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return string[] */
    private function parseEmails(array $get): array
    {
        $out = [];
        foreach ($this->csvList($get, 'employee_email') as $raw) {
            if (strlen($raw) > 255 || !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                ApiResponse::error(
                    'Invalid parameter `employee_email`: "' . $this->safe($raw)
                    . '" is not a valid email address. Accepts one address or a comma list.',
                    400
                );
            }
            if (!in_array($raw, $out, true)) {
                $out[] = $raw;
            }
        }
        return $out;
    }

    /** @return string[] */
    private function parseOffices(array $get): array
    {
        $out = [];
        foreach ($this->csvList($get, 'office') as $raw) {
            if (strlen($raw) > 100) {
                ApiResponse::error(
                    'Invalid parameter `office`: values are limited to 100 characters '
                    . 'and must match users.Office / Offices.OfficeName exactly.',
                    400
                );
            }
            if (!in_array($raw, $out, true)) {
                $out[] = $raw;
            }
        }
        return $out;
    }

    private function parseRounding(array $get): int
    {
        $raw = $this->scalar($get, 'rounding');
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!preg_match('/^\d+$/', $raw) || !in_array((int)$raw, self::VALID_ROUNDING, true)) {
            ApiResponse::error(
                'Invalid parameter `rounding`: "' . $this->safe($raw) . '". Accepted values are '
                . implode(', ', self::VALID_ROUNDING) . ' (minutes; 0 disables rounding).',
                400
            );
        }
        return (int)$raw;
    }

    private function parseWeekStart(array $get): string
    {
        $raw = $this->scalar($get, 'week_start');
        if ($raw === null || $raw === '') {
            return 'mon';
        }
        $v = strtolower(trim($raw));
        if ($v !== 'mon' && $v !== 'sun') {
            ApiResponse::error(
                'Invalid parameter `week_start`: "' . $this->safe($raw) . '". Accepted values are "mon" or "sun".',
                400
            );
        }
        return $v;
    }

    private function parseHoursThreshold(array $get, string $name, float $default, float $max): float
    {
        $raw = $this->scalar($get, $name);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $v = trim($raw);
        if (!preg_match('/^\d+(\.\d+)?$/', $v)) {
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $this->safe($raw)
                . '". Expected a non-negative number of hours (0 disables this threshold).',
                400
            );
        }
        $f = (float)$v;
        if ($f > $max) {
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: ' . $f . ' exceeds the maximum of ' . $max . ' hours.',
                400
            );
        }
        return $f;
    }

    private function parseFormat(array $get): string
    {
        $raw = $this->scalar($get, 'format');
        if ($raw === null || $raw === '') {
            return 'json';
        }
        $v = strtolower(trim($raw));
        if ($v !== 'json' && $v !== 'csv') {
            ApiResponse::error(
                'Invalid parameter `format`: "' . $this->safe($raw) . '". Accepted values are "json" or "csv".',
                400
            );
        }
        return $v;
    }

    private function parseInt(array $get, string $name, int $default, int $min, int $max): int
    {
        $raw = $this->scalar($get, $name);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $v = trim($raw);
        if (!preg_match('/^\d+$/', $v)) {
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: "' . $this->safe($raw)
                . '". Expected a non-negative integer between ' . $min . ' and ' . $max . '.',
                400
            );
        }
        $i = (int)$v;
        if ($i < $min || $i > $max) {
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: ' . $i . ' is out of range ('
                . $min . '-' . $max . ').',
                400
            );
        }
        return $i;
    }

    private function parseBool(array $get, string $name, bool $default): bool
    {
        $raw = $this->scalar($get, $name);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $v = strtolower(trim($raw));
        if (in_array($v, ['1', 'true', 'yes'], true))  return true;
        if (in_array($v, ['0', 'false', 'no'], true))  return false;

        ApiResponse::error(
            'Invalid parameter `' . $name . '`: "' . $this->safe($raw)
            . '". Accepted values are 1/0, true/false, yes/no.',
            400
        );
    }

    /**
     * Report timezone: ?tz= override, else branding.Timezone, else DB_TIMEZONE,
     * else America/Chicago. Every candidate is checked against
     * timezone_identifiers_list() before use so a bad branding row can't throw.
     */
    private function parseTimezone(array $get, mysqli $conn): DateTimeZone
    {
        $raw = $this->scalar($get, 'tz');
        if ($raw !== null && $raw !== '') {
            $tz = trim($raw);
            if (!in_array($tz, timezone_identifiers_list(), true)) {
                ApiResponse::error(
                    'Invalid parameter `tz`: "' . $this->safe($raw)
                    . '" is not a recognized IANA timezone (e.g. America/Chicago, UTC).',
                    400
                );
            }
            return new DateTimeZone($tz);
        }

        foreach ([self::brandingTimezone($conn), $_ENV['DB_TIMEZONE'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== ''
                && in_array($candidate, timezone_identifiers_list(), true)) {
                return new DateTimeZone($candidate);
            }
        }
        return new DateTimeZone(self::FALLBACK_TIMEZONE);
    }

    /** Cached per request: several endpoints build more than one ReportParams. */
    private static ?string $brandingTz = null;
    private static bool $brandingTzLoaded = false;

    private static function brandingTimezone(mysqli $conn): ?string
    {
        if (self::$brandingTzLoaded) {
            return self::$brandingTz;
        }
        self::$brandingTzLoaded = true;
        try {
            $res = $conn->query("SELECT Timezone FROM branding ORDER BY ID LIMIT 1");
            if ($res) {
                $row = $res->fetch_assoc();
                $res->free();
                if ($row && is_string($row['Timezone']) && $row['Timezone'] !== '') {
                    self::$brandingTz = $row['Timezone'];
                }
            }
        } catch (Throwable $e) {
            // A missing/unreadable branding row must degrade to the fallback tz,
            // not 500 a payroll export.
            error_log('[report-lib/ReportParams] branding.Timezone lookup failed: ' . $e->getMessage());
        }
        return self::$brandingTz;
    }

    /**
     * A comma list, also tolerating repeated `name[]=` array form. Empty segments
     * are dropped so "3,,7" and a trailing comma are not errors.
     *
     * @return string[]
     */
    private function csvList(array $get, string $name): array
    {
        if (!array_key_exists($name, $get)) {
            return [];
        }
        $raw = $get[$name];
        if (is_array($raw)) {
            $flat = [];
            foreach ($raw as $v) {
                if (is_scalar($v)) { $flat[] = (string)$v; }
            }
            $raw = implode(',', $flat);
        } elseif (!is_scalar($raw)) {
            ApiResponse::error('Invalid parameter `' . $name . '`: expected a value or comma list.', 400);
        }

        $out = [];
        foreach (explode(',', (string)$raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $out[] = $piece;
            }
        }
        return $out;
    }

    /** A single scalar query value, or null. Arrays are rejected with a clear 400. */
    private function scalar(array $get, string $name): ?string
    {
        if (!array_key_exists($name, $get)) {
            return null;
        }
        $v = $get[$name];
        if (is_array($v)) {
            ApiResponse::error(
                'Invalid parameter `' . $name . '`: expected a single value, got a list.',
                400
            );
        }
        if (!is_scalar($v)) {
            ApiResponse::error('Invalid parameter `' . $name . '`: expected a single value.', 400);
        }
        return trim((string)$v);
    }

    private function isValidDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /** Inclusive day count. UTC so a DST transition inside the range can't lose a day. */
    private function inclusiveDays(string $start, string $end): int
    {
        $utc = new DateTimeZone('UTC');
        $a   = new DateTimeImmutable($start . ' 00:00:00', $utc);
        $b   = new DateTimeImmutable($end . ' 00:00:00', $utc);
        return (int)$a->diff($b)->days + 1;
    }

    /**
     * Echo an offending value back to the integrator without letting it become
     * markup or a control-character injection in a log/console.
     */
    private function safe(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if (strlen($value) > 64) {
            $value = substr($value, 0, 64) . '…';
        }
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
