<?php
/**
 * ODataQuery — parses and compiles the OData v4 system query options
 * ($select, $filter, $orderby, $top, $skip, $count) used by
 * /api/external/odata/v1/*.
 *
 * PURITY CONTRACT: this class touches no database and no superglobal. parse()
 * takes a raw query string plus the caller's own property whitelist/type map and
 * returns plain data; nothing here can be reasoned about only by also reading a
 * live schema. That is what makes it possible to exercise every case below
 * from the CLI with a throwaway script (no DB stub, no server needed) rather
 * than only as an integration test in disguise — the worked examples and the
 * injection-shaped cases documented below were verified that way.
 *
 * WHY A HAND-WRITTEN PARSER, NOT A REGEX SWAP-IN: the task's own warning is the
 * reason — "silently ignoring an unsupported filter would hand the client MORE
 * rows than it asked for, which for an access-scoped API is a data-leak class
 * bug, not a usability one." A regex that recognizes the constructs it knows and
 * ignores the rest is exactly that bug. This is a real tokenizer -> recursive
 * descent parser -> AST, and parse() throws ODataQueryException the moment ANY
 * token is left unconsumed or unrecognized, so a construct we don't support can
 * never be silently dropped from the WHERE clause.
 *
 * GRAMMAR SUPPORTED (deliberately a subset of OData v4 — see the task scope):
 *   filter     := orExpr
 *   orExpr     := andExpr ( "or" andExpr )*
 *   andExpr    := unary ( "and" unary )*
 *   unary      := "(" orExpr ")" | comparison | funcCall
 *   comparison := PROPERTY compOp literal
 *   compOp     := "eq" | "ne" | "gt" | "ge" | "lt" | "le"
 *   funcCall   := ("startswith"|"endswith"|"contains") "(" PROPERTY "," STRING ")"
 *   literal    := STRING | NUMBER | DATE | DATETIMEOFFSET | "true" | "false" | "null"
 * "not" and property-vs-property comparisons are NOT supported (out of task
 * scope) and are rejected with a clear 400 rather than silently mis-parsed.
 *
 * TYPE CHECKING: every comparison is checked against the property's DECLARED
 * Edm type (from $propertyTypes) at parse time. A string literal against an
 * Edm.Int32 property is a 400, not a query that quietly matches nothing.
 *
 * SQL COMPILATION: filterSql references properties as backtick-quoted bare
 * identifiers — e.g. `EmployeeID` = ?. This is safe ONLY because every property
 * name that reaches SQL was checked against $allowedProperties first (a fixed
 * list the CALLER controls, never derived from request input) and re-validated
 * against ^[A-Za-z_][A-Za-z0-9_]*$ as a second, structural guarantee. Every
 * literal — string, number, date, datetime, boolean — becomes a `?` placeholder;
 * nothing but a property name (from that whitelist) is ever concatenated into
 * the SQL text. The caller is expected to SELECT each property under exactly
 * this name (typically via a derived-table alias) so filterSql/orderBySql can be
 * appended directly to that derived table's WHERE/ORDER BY.
 *
 * IN-MEMORY EVALUATION: entities whose properties are computed in PHP (this API
 * has several — see ODataDispatch.php for which and why) cannot use filterSql,
 * because there is no SQL column to compile against. evaluateFilter() walks the
 * SAME AST against a PHP row array instead, so $filter is parsed and validated
 * in exactly one place no matter which path an entity takes.
 *
 * CASE SENSITIVITY: string eq/ne and startswith/endswith/contains are
 * case-INSENSITIVE everywhere on this surface — both compiled SQL (MySQL
 * compares utf8mb4_general_ci columns case-insensitively already, the same
 * collation offices.php's own `office` filter relies on) and evaluateFilter()
 * (which lower-cases explicitly so an in-memory-computed entity cannot disagree
 * with a SQL-backed one about whether "hq" matches "HQ").
 *
 * DateTimeOffset REPRESENTATION: every Edm.DateTimeOffset property is compared
 * as a UNIX epoch integer, never as a formatted string — see coerceLiteral()
 * and the worked example (6) below. This sidesteps MariaDB's CONVERT_TZ (which
 * silently returns NULL when the timezone tables are not loaded) entirely, and
 * matches the UNIX_TIMESTAMP(...) convention every other /api/external endpoint
 * in this codebase already uses for DATETIME columns.
 *
 * ============================================================================
 * WORKED EXAMPLES ($filter input -> generated SQL + binds)
 * ============================================================================
 * Property whitelist used below: EmployeeID:Edm.Int32, Name:Edm.String,
 * WorkedHours:Edm.Double, Office:Edm.String, Date:Edm.Date,
 * UpdatedAt:Edm.DateTimeOffset, IsActive:Edm.Boolean.
 *
 * 1) Name eq 'Smith'
 *    SQL:   `Name` = ?
 *    binds: ['Smith']            types: 's'
 *
 * 2) EmployeeID eq 42
 *    SQL:   `EmployeeID` = ?
 *    binds: [42]                 types: 'i'
 *
 * 3) Name eq 'O''Brien'                          <- escaped single quote ('')
 *    (the OData escape for a literal quote inside a string is a DOUBLED quote,
 *    exactly like a SQL string literal — the tokenizer consumes the whole
 *    quoted run as ONE token and un-escapes '' -> ' when building the value)
 *    SQL:   `Name` = ?
 *    binds: ["O'Brien"]          types: 's'
 *
 * 4) startswith(Name,'Mc')
 *    SQL:   `Name` LIKE ? ESCAPE '!'
 *    binds: ['Mc%']              types: 's'
 *    (any literal %, _ or ! in the search text is escaped with '!' first, so a
 *    search for a literal "%" can never be read back as a wildcard)
 *
 * 5) WorkedHours gt 8.5 and Office eq 'HQ'
 *    SQL:   (`WorkedHours` > ? AND `Office` = ?)
 *    binds: [8.5, 'HQ']          types: 'ds'
 *
 * 6) UpdatedAt gt 2026-08-01T00:00:00-05:00      <- datetime comparison
 *    (the literal's offset is honored during parsing, then the instant is
 *    converted to a UTC epoch integer — 2026-08-01T00:00:00-05:00 is the same
 *    instant as 2026-08-01T05:00:00Z)
 *    SQL:   `UpdatedAt` > ?
 *    binds: [<int>]  types: 'i'
 *    (the bound value is whatever
 *     (new DateTimeImmutable('2026-08-01T00:00:00-05:00'))->getTimestamp() returns —
 *     an epoch int, never a formatted string)
 *
 * 7) Date ge 2026-01-01 and Date le 2026-01-31
 *    SQL:   (`Date` >= ? AND `Date` <= ?)
 *    binds: ['2026-01-01', '2026-01-31']  types: 'ss'
 *
 * 8) contains(Office,'spring') or contains(Office,'lake')
 *    SQL:   (`Office` LIKE ? ESCAPE '!' OR `Office` LIKE ? ESCAPE '!')
 *    binds: ['%spring%', '%lake%']  types: 'ss'
 *
 * 9) IsActive eq true
 *    SQL:   `IsActive` = ?
 *    binds: [1]                  types: 'i'
 *
 * 10) Name eq null
 *     SQL:   `Name` IS NULL
 *     binds: []                  types: ''
 *
 * INJECTION-SHAPED INPUT:
 *   Name eq 'x'' OR 1=1--'
 *   The trailing text is not SQL syntax reaching a query — it is the CONTENTS
 *   of a single OData string literal, properly '' escaped. The tokenizer reads
 *   the whole `'x'' OR 1=1--'` run as ONE STRING token whose decoded value is
 *   the literal text  x' OR 1=1--  . Compiled SQL is `Name` = ? with that literal
 *   text as the single bound parameter — a normal equality comparison against a
 *   string that will simply match no row. Nothing is concatenated into SQL.
 *
 *   Name eq 'x' OR 1=1--          (quote NOT doubled — the injection actually
 *   relies on breaking OUT of the string, not escaping into it)
 *   This parses as complete comparison `Name eq 'x'`, followed by leftover
 *   tokens `OR 1 = 1 --`. `1=1` is not valid OData ("=" is not a recognized
 *   operator token — OData spins that as `eq`), so the parser throws well
 *   before reaching "trailing tokens": it rejects at the first unrecognized
 *   token. Either way this input is a 400, never a widened result set.
 */

final class ODataQueryException extends RuntimeException
{
}

final class ODataQuery
{
    public const MAX_TOP     = 5000;
    public const DEFAULT_TOP = 500;
    public const MAX_SKIP    = 1000000;

    /**
     * Independent bounds on $filter itself, mirroring how ReportParams enforces
     * its own explicit MAX_RANGE_DAYS/MAX_LIMIT rather than trusting an upstream
     * server config. Without these, an authenticated caller (scope-gated, but
     * still just one leaked or insider key away) can send a $filter built from
     * deeply nested parentheses and force this parser to recurse thousands of
     * frames deep — measured at ~1.2GB peak memory and ~15s wall-clock for a
     * 600,000-paren string before this existed. 4KB comfortably fits any real
     * filter; 32 levels of nesting is already generous for a hand-written query.
     */
    private const MAX_FILTER_LENGTH = 4096;
    private const MAX_FILTER_DEPTH  = 32;

    /** LIKE escape character for startswith/endswith/contains — see likePattern(). */
    private const LIKE_ESCAPE = '!';

    /** Internal sentinel distinguishing a parsed `null` literal from "no literal". */
    private const NULL_LITERAL = "\0ODATA_NULL\0";

    private const EDM_BIND_TYPE = [
        'Edm.Int32'          => 'i',
        'Edm.Double'         => 'd',
        'Edm.Boolean'        => 'i',
        'Edm.String'         => 's',
        'Edm.Date'           => 's',
        'Edm.DateTimeOffset' => 'i', // always a UNIX epoch — see the class docblock
    ];

    private const COMPARATORS = ['eq', 'ne', 'gt', 'ge', 'lt', 'le'];
    private const FUNCTIONS   = ['startswith', 'endswith', 'contains'];

    /**
     * Parse the raw OData query string for one request.
     *
     * @param string  $queryString       e.g. $_SERVER['QUERY_STRING']. Parsed with
     *                                   parse_str(), which — unlike PHP's automatic
     *                                   $_GET population in some SAPI configurations —
     *                                   preserves a literal leading '$' in a key, so
     *                                   $select/$filter/... are never mistaken for
     *                                   PHP-mangled names.
     * @param string[] $allowedProperties Every property this entity set declares, in
     *                                   the exact case used by $metadata. The single
     *                                   source of truth for "does this property
     *                                   exist" for $select/$filter/$orderby alike.
     * @param array<string,string> $propertyTypes Edm type per property name
     *                                   (Edm.Int32 | Edm.Double | Edm.Boolean |
     *                                   Edm.String | Edm.Date | Edm.DateTimeOffset).
     *
     * @return array{
     *   select: string[],
     *   filterSql: string,
     *   filterBinds: array,
     *   filterTypes: string,
     *   filterAst: ?array,
     *   orderBySql: string,
     *   orderBy: array<int, array{property: string, dir: string}>,
     *   top: int,
     *   skip: int,
     *   count: bool
     * }
     * @throws ODataQueryException on anything unsupported or malformed — always a
     *         client-facing 400, never a silently-ignored option.
     */
    public static function parse(string $queryString, array $allowedProperties, array $propertyTypes): array
    {
        foreach ($allowedProperties as $p) {
            if (!is_string($p) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $p)) {
                // Defensive: this is a bug in the CALLER's own entity definition,
                // not client input, but it must never reach SQL as an identifier.
                throw new ODataQueryException('Invalid property name in entity definition: ' . var_export($p, true));
            }
        }

        $get = [];
        parse_str($queryString, $get);

        $select = self::parseSelect($get['$select'] ?? null, $allowedProperties);

        // is_string() guard for the same reason parseSelect()/parseOrderBy()/
        // parseBool() all have one: ?$filter[]=a&$filter[]=b decodes to an
        // array via parse_str(), and (string)$array throws an "Array to string
        // conversion" warning before this would otherwise fail cleanly with a
        // 400 — reject it outright instead of relying on display_errors=Off to
        // keep that warning from reaching the client.
        if (isset($get['$filter']) && !is_string($get['$filter'])) {
            throw new ODataQueryException('$filter must be a single value, not a list.');
        }

        $filterAst = null;
        $filterSql = '';
        $filterBinds = [];
        $filterTypes = '';
        if (isset($get['$filter']) && trim($get['$filter']) !== '') {
            $filterAst = self::parseFilterString($get['$filter'], $allowedProperties, $propertyTypes);
            [$filterSql, $filterBinds, $filterTypes] = self::compileSql($filterAst);
        }

        [$orderBySql, $orderBy] = self::parseOrderBy($get['$orderby'] ?? null, $allowedProperties);

        $top  = self::parseNonNegativeInt($get['$top']  ?? null, '$top',  self::DEFAULT_TOP, self::MAX_TOP);
        $skip = self::parseNonNegativeInt($get['$skip'] ?? null, '$skip', 0,                 self::MAX_SKIP);
        $count = self::parseBool($get['$count'] ?? null, '$count', false);

        if (isset($get['$expand'])) {
            throw new ODataQueryException(
                '$expand is not supported by this service. Every entity set is flat; '
                . 'join by employee_id/date in your BI tool instead of expanding a navigation property.'
            );
        }

        // Everything else starting with '$' is a system query option we do not
        // recognize (or explicitly do not implement, e.g. $search/$apply/$compute).
        // Rejecting it — rather than ignoring it — is the same "never hand back
        // more than was asked for" rule the task calls out for $filter: an
        // unsupported $search that we silently drop would look, from the
        // client's side, exactly like "matched everything".
        foreach (array_keys($get) as $key) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            if ($key[0] !== '$') {
                continue; // non-system params are none of this parser's business
            }
            if ($key === '$format') {
                continue; // harmless; many OData clients always send it — honored implicitly (JSON is the only format)
            }
            if (in_array($key, ['$select', '$filter', '$orderby', '$top', '$skip', '$count', '$expand'], true)) {
                continue;
            }
            throw new ODataQueryException(
                'Unsupported query option `' . self::echoSafe($key) . '`. This service supports '
                . '$select, $filter, $orderby, $top, $skip, $count and $format only ($expand is '
                . 'explicitly rejected, not ignored).'
            );
        }

        return [
            'select'      => $select,
            'filterSql'   => $filterSql,
            'filterBinds' => $filterBinds,
            'filterTypes' => $filterTypes,
            'filterAst'   => $filterAst,
            'orderBySql'  => $orderBySql,
            'orderBy'     => $orderBy,
            'top'         => $top,
            'skip'        => $skip,
            'count'       => $count,
        ];
    }

    /**
     * Evaluate a parsed $filter AST against one PHP row (property name => scalar
     * value, in the same representation the entity emits in its JSON output —
     * i.e. Edm.DateTimeOffset properties are ISO 8601 strings here, NOT epoch
     * ints; this method converts on the fly). Used by entities whose data is
     * computed in PHP (via HoursCalculator/ScheduleCalculator) rather than
     * queried straight out of a table — see the class docblock.
     *
     * A null AST (no $filter was supplied) matches every row.
     */
    public static function evaluateFilter(?array $node, array $row): bool
    {
        if ($node === null) {
            return true;
        }
        switch ($node['type']) {
            case 'and':
                return self::evaluateFilter($node['left'], $row) && self::evaluateFilter($node['right'], $row);
            case 'or':
                return self::evaluateFilter($node['left'], $row) || self::evaluateFilter($node['right'], $row);
            case 'cmp':
                return self::evaluateCmp($node, $row);
            case 'func':
                return self::evaluateFunc($node, $row);
            default:
                // Unreachable: every node this parser produces is one of the above.
                throw new ODataQueryException('Internal error: unknown filter node type.');
        }
    }

    /**
     * A usort() comparator for the structured `orderBy` list, operating on rows
     * in the same "wire representation" convention as evaluateFilter().
     * $propertyTypes is the SAME map passed to parse(), needed here so a
     * DateTimeOffset property sorts chronologically rather than lexically.
     */
    public static function makeComparator(array $orderBy, array $propertyTypes): \Closure
    {
        return static function (array $a, array $b) use ($orderBy, $propertyTypes): int {
            foreach ($orderBy as $ord) {
                $prop = $ord['property'];
                $type = $propertyTypes[$prop] ?? 'Edm.String';
                $av   = $a[$prop] ?? null;
                $bv   = $b[$prop] ?? null;
                $cmp  = self::compareValues($av, $bv, $type);
                if ($cmp !== 0) {
                    return $ord['dir'] === 'desc' ? -$cmp : $cmp;
                }
            }
            return 0;
        };
    }

    // ======================================================================
    // $select
    // ======================================================================

    /** @return string[] */
    private static function parseSelect($raw, array $allowed): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_string($raw)) {
            throw new ODataQueryException('$select must be a single comma-separated value.');
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $name = trim($piece);
            if ($name === '') {
                continue;
            }
            if (!in_array($name, $allowed, true)) {
                throw new ODataQueryException(
                    'Unknown property `' . self::echoSafe($name) . '` in $select. Valid properties: '
                    . implode(', ', $allowed) . '.'
                );
            }
            if (!in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    // ======================================================================
    // $orderby
    // ======================================================================

    /** @return array{0: string, 1: array<int, array{property: string, dir: string}>} */
    private static function parseOrderBy($raw, array $allowed): array
    {
        if ($raw === null) {
            return ['', []];
        }
        if (!is_string($raw)) {
            throw new ODataQueryException('$orderby must be a single comma-separated value.');
        }
        $raw = trim($raw);
        if ($raw === '') {
            return ['', []];
        }

        $sqlParts = [];
        $structured = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            // Collapse internal whitespace so "Name   desc" parses the same as "Name desc".
            $tokens = preg_split('/\s+/', $piece) ?: [];
            if (count($tokens) === 1) {
                [$prop, $dir] = [$tokens[0], 'asc'];
            } elseif (count($tokens) === 2) {
                [$prop, $dirRaw] = $tokens;
                $dir = strtolower($dirRaw);
                if ($dir !== 'asc' && $dir !== 'desc') {
                    throw new ODataQueryException(
                        'Invalid $orderby direction `' . self::echoSafe($dirRaw) . '` for property `'
                        . self::echoSafe($prop) . '`. Expected "asc" or "desc".'
                    );
                }
            } else {
                throw new ODataQueryException(
                    'Invalid $orderby segment `' . self::echoSafe($piece)
                    . '`. Expected "Property" or "Property asc|desc".'
                );
            }
            if (!in_array($prop, $allowed, true)) {
                throw new ODataQueryException(
                    'Unknown property `' . self::echoSafe($prop) . '` in $orderby. Valid properties: '
                    . implode(', ', $allowed) . '.'
                );
            }
            $sqlParts[]   = '`' . $prop . '` ' . strtoupper($dir);
            $structured[] = ['property' => $prop, 'dir' => $dir];
        }
        return [implode(', ', $sqlParts), $structured];
    }

    // ======================================================================
    // $top / $skip / $count
    // ======================================================================

    private static function parseNonNegativeInt($raw, string $name, int $default, int $max): int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_string($raw) || !preg_match('/^\d+$/', trim($raw))) {
            throw new ODataQueryException(
                'Invalid `' . $name . '`: expected a non-negative integer, got `' . self::echoSafe((string)$raw) . '`.'
            );
        }
        $v = (int)trim($raw);
        if ($v > $max) {
            throw new ODataQueryException('`' . $name . '` of ' . $v . ' exceeds the maximum of ' . $max . '.');
        }
        return $v;
    }

    private static function parseBool($raw, string $name, bool $default): bool
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        // Matches the is_string() guard parseSelect()/parseOrderBy() already use
        // for the same reason: parse_str() turns a repeated query param
        // (?$count[]=a&$count[]=b) into an array, and casting an array with
        // (string) throws an "Array to string conversion" warning rather than
        // failing cleanly — this file should reject that itself, not depend on
        // php.ini's display_errors being off to keep the warning from a client.
        if (!is_string($raw)) {
            throw new ODataQueryException('`' . $name . '` must be a single value, not a list.');
        }
        $v = strtolower(trim($raw));
        if ($v === 'true')  return true;
        if ($v === 'false') return false;
        throw new ODataQueryException(
            'Invalid `' . $name . '`: "' . self::echoSafe((string)$raw) . '". Expected "true" or "false".'
        );
    }

    // ======================================================================
    // $filter — tokenizer
    // ======================================================================

    /**
     * @return array<int, array{type: string, value: string, pos: int}>
     */
    private static function tokenize(string $s): array
    {
        $tokens = [];
        $len    = strlen($s);
        $pos    = 0;

        while ($pos < $len) {
            if (preg_match('/\G\s+/', $s, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                continue;
            }
            // Order matters: DATETIME before DATE before NUMBER (all can start
            // with a digit), STRING is unambiguous ('), IDENT covers keywords AND
            // property/function names alike (classified in the parser).
            if (preg_match('/\G\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})/', $s, $m, 0, $pos)) {
                $tokens[] = ['type' => 'DATETIME', 'value' => $m[0], 'pos' => $pos];
                $pos += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G\d{4}-\d{2}-\d{2}/', $s, $m, 0, $pos)) {
                $tokens[] = ['type' => 'DATE', 'value' => $m[0], 'pos' => $pos];
                $pos += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G-?\d+(?:\.\d+)?/', $s, $m, 0, $pos)) {
                $tokens[] = ['type' => 'NUMBER', 'value' => $m[0], 'pos' => $pos];
                $pos += strlen($m[0]);
                continue;
            }
            if (preg_match("/\\G'(?:[^']|'')*'/", $s, $m, 0, $pos)) {
                $inner = substr($m[0], 1, -1);
                $tokens[] = ['type' => 'STRING', 'value' => str_replace("''", "'", $inner), 'pos' => $pos];
                $pos += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $s, $m, 0, $pos)) {
                $tokens[] = ['type' => 'IDENT', 'value' => $m[0], 'pos' => $pos];
                $pos += strlen($m[0]);
                continue;
            }
            if ($s[$pos] === '(') { $tokens[] = ['type' => 'LPAREN', 'value' => '(', 'pos' => $pos]; $pos++; continue; }
            if ($s[$pos] === ')') { $tokens[] = ['type' => 'RPAREN', 'value' => ')', 'pos' => $pos]; $pos++; continue; }
            if ($s[$pos] === ',') { $tokens[] = ['type' => 'COMMA',  'value' => ',', 'pos' => $pos]; $pos++; continue; }

            throw new ODataQueryException(
                'Unrecognized character `' . self::echoSafe($s[$pos]) . '` in $filter at position ' . $pos . '.'
            );
        }
        return $tokens;
    }

    // ======================================================================
    // $filter — recursive-descent parser
    // ======================================================================

    private static function parseFilterString(string $filter, array $allowed, array $types): array
    {
        if (strlen($filter) > self::MAX_FILTER_LENGTH) {
            throw new ODataQueryException(
                '$filter is too long (' . strlen($filter) . ' bytes, max ' . self::MAX_FILTER_LENGTH . ').'
            );
        }

        $tokens = self::tokenize($filter);
        $state  = ['tokens' => $tokens, 'pos' => 0, 'allowed' => $allowed, 'types' => $types, 'depth' => 0];

        $node = self::parseOr($state);

        if ($state['pos'] < count($state['tokens'])) {
            $t = $state['tokens'][$state['pos']];
            throw new ODataQueryException(
                'Unexpected trailing input in $filter starting with `' . self::echoSafe($t['value'])
                . '` at position ' . $t['pos'] . '. (Every token after a complete expression must be a '
                . 'connecting "and"/"or" — this parser never silently truncates the rest of a filter.)'
            );
        }
        return $node;
    }

    private static function parseOr(array &$state): array
    {
        $node = self::parseAnd($state);
        while (self::peekIsKeyword($state, 'or')) {
            self::advance($state);
            $right = self::parseAnd($state);
            $node  = ['type' => 'or', 'left' => $node, 'right' => $right];
        }
        return $node;
    }

    private static function parseAnd(array &$state): array
    {
        $node = self::parseUnary($state);
        while (self::peekIsKeyword($state, 'and')) {
            self::advance($state);
            $right = self::parseUnary($state);
            $node  = ['type' => 'and', 'left' => $node, 'right' => $right];
        }
        return $node;
    }

    private static function parseUnary(array &$state): array
    {
        $tok = self::peek($state);
        if ($tok === null) {
            throw new ODataQueryException('Unexpected end of $filter expression.');
        }

        if ($tok['type'] === 'LPAREN') {
            $state['depth']++;
            if ($state['depth'] > self::MAX_FILTER_DEPTH) {
                throw new ODataQueryException(
                    '$filter has more than ' . self::MAX_FILTER_DEPTH . ' levels of nested parentheses.'
                );
            }
            self::advance($state);
            $node = self::parseOr($state);
            self::expect($state, 'RPAREN', ')');
            $state['depth']--;
            return $node;
        }

        if ($tok['type'] === 'IDENT' && in_array(strtolower($tok['value']), self::FUNCTIONS, true)) {
            return self::parseFuncCall($state);
        }

        if ($tok['type'] === 'IDENT' && strtolower($tok['value']) === 'not') {
            throw new ODataQueryException(
                '`not` is not supported by this $filter implementation. Supported operators: '
                . implode(', ', self::COMPARATORS) . ', and/or, and startswith/endswith/contains.'
            );
        }

        return self::parseComparison($state);
    }

    private static function parseComparison(array &$state): array
    {
        $propTok = self::expect($state, 'IDENT', 'a property name');
        $prop    = $propTok['value'];
        if (in_array(strtolower($prop), self::COMPARATORS, true) || in_array(strtolower($prop), ['and', 'or'], true)) {
            throw new ODataQueryException(
                'Expected a property name in $filter but found the keyword `' . $prop . '` at position '
                . $propTok['pos'] . '.'
            );
        }
        if (!in_array($prop, $state['allowed'], true)) {
            throw new ODataQueryException(
                'Unknown property `' . self::echoSafe($prop) . '` in $filter. Valid properties: '
                . implode(', ', $state['allowed']) . '.'
            );
        }

        $opTok = self::expect($state, 'IDENT', 'a comparison operator (eq, ne, gt, ge, lt, le)');
        $op    = strtolower($opTok['value']);
        if (!in_array($op, self::COMPARATORS, true)) {
            throw new ODataQueryException(
                'Unsupported operator `' . self::echoSafe($opTok['value']) . '` in $filter at position '
                . $opTok['pos'] . '. Supported: ' . implode(', ', self::COMPARATORS) . '.'
            );
        }

        $edmType = $state['types'][$prop] ?? 'Edm.String';
        $litTok  = self::advance($state);
        if ($litTok === null) {
            throw new ODataQueryException('Expected a literal after `' . $prop . ' ' . $op . '` in $filter.');
        }
        $value = self::coerceLiteral($litTok, $edmType, $prop);

        if ($value === self::NULL_LITERAL && !in_array($op, ['eq', 'ne'], true)) {
            throw new ODataQueryException(
                'Only `eq`/`ne` may compare `' . $prop . '` against null (got `' . $op . '`).'
            );
        }
        if ($edmType === 'Edm.Boolean' && !in_array($op, ['eq', 'ne'], true)) {
            throw new ODataQueryException(
                'Property `' . $prop . '` is Edm.Boolean; only `eq`/`ne` apply (got `' . $op . '`).'
            );
        }

        return ['type' => 'cmp', 'property' => $prop, 'op' => $op, 'value' => $value, 'edmType' => $edmType];
    }

    private static function parseFuncCall(array &$state): array
    {
        $funcTok = self::advance($state); // already confirmed to be a known function name
        $func    = strtolower($funcTok['value']);

        self::expect($state, 'LPAREN', '(');
        $propTok = self::expect($state, 'IDENT', 'a property name');
        $prop    = $propTok['value'];
        if (!in_array($prop, $state['allowed'], true)) {
            throw new ODataQueryException(
                'Unknown property `' . self::echoSafe($prop) . '` in $filter. Valid properties: '
                . implode(', ', $state['allowed']) . '.'
            );
        }
        $edmType = $state['types'][$prop] ?? 'Edm.String';
        if ($edmType !== 'Edm.String') {
            throw new ODataQueryException(
                'Function `' . $func . '()` only applies to Edm.String properties; `' . $prop
                . '` is ' . $edmType . '.'
            );
        }
        self::expect($state, 'COMMA', ',');
        $litTok = self::expect($state, 'STRING', 'a quoted string literal');
        self::expect($state, 'RPAREN', ')');

        return ['type' => 'func', 'func' => $func, 'property' => $prop, 'value' => $litTok['value']];
    }

    // ------------------------------------------------------- literal coercion

    /** @return int|float|string true value, or self::NULL_LITERAL */
    private static function coerceLiteral(array $tok, string $edmType, string $prop)
    {
        if ($tok['type'] === 'IDENT' && strtolower($tok['value']) === 'null') {
            return self::NULL_LITERAL;
        }
        if ($tok['type'] === 'IDENT' && in_array(strtolower($tok['value']), ['true', 'false'], true)) {
            if ($edmType !== 'Edm.Boolean') {
                throw new ODataQueryException(
                    'Property `' . $prop . '` is ' . $edmType . '; `' . $tok['value'] . '` is a boolean literal.'
                );
            }
            return strtolower($tok['value']) === 'true' ? 1 : 0;
        }

        switch ($edmType) {
            case 'Edm.String':
                if ($tok['type'] !== 'STRING') {
                    throw new ODataQueryException(
                        'Property `' . $prop . '` is Edm.String; expected a quoted string literal but found `'
                        . self::echoSafe($tok['value']) . '` at position ' . $tok['pos'] . '.'
                    );
                }
                return $tok['value'];

            case 'Edm.Int32':
                if ($tok['type'] !== 'NUMBER' || str_contains($tok['value'], '.')) {
                    throw new ODataQueryException(
                        'Property `' . $prop . '` is Edm.Int32; expected a whole-number literal but found `'
                        . self::echoSafe($tok['value']) . '` at position ' . $tok['pos'] . '.'
                    );
                }
                return (int)$tok['value'];

            case 'Edm.Double':
                if ($tok['type'] !== 'NUMBER') {
                    throw new ODataQueryException(
                        'Property `' . $prop . '` is Edm.Double; expected a numeric literal but found `'
                        . self::echoSafe($tok['value']) . '` at position ' . $tok['pos'] . '.'
                    );
                }
                return (float)$tok['value'];

            case 'Edm.Boolean':
                throw new ODataQueryException(
                    'Property `' . $prop . '` is Edm.Boolean; expected `true` or `false` but found `'
                    . self::echoSafe($tok['value']) . '` at position ' . $tok['pos'] . '.'
                );

            case 'Edm.Date':
                if ($tok['type'] !== 'DATE') {
                    throw new ODataQueryException(
                        'Property `' . $prop . '` is Edm.Date; expected a YYYY-MM-DD literal but found `'
                        . self::echoSafe($tok['value']) . '` at position ' . $tok['pos'] . '.'
                    );
                }
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $tok['value'], new DateTimeZone('UTC'));
                if ($d === false || $d->format('Y-m-d') !== $tok['value']) {
                    throw new ODataQueryException(
                        'Property `' . $prop . '` — `' . $tok['value'] . '` is not a real calendar date.'
                    );
                }
                return $tok['value'];

            case 'Edm.DateTimeOffset':
                if ($tok['type'] !== 'DATETIME') {
                    throw new ODataQueryException(
                        'Property `' . $prop . '` is Edm.DateTimeOffset; expected an ISO 8601 literal WITH an '
                        . 'explicit offset (e.g. 2026-08-01T00:00:00-05:00 or ...Z) but found `'
                        . self::echoSafe($tok['value']) . '` at position ' . $tok['pos'] . '.'
                    );
                }
                try {
                    $dt = new DateTimeImmutable($tok['value']);
                } catch (Exception $e) {
                    throw new ODataQueryException(
                        'Property `' . $prop . '` — `' . $tok['value'] . '` is not a valid ISO 8601 datetime.'
                    );
                }
                return $dt->getTimestamp(); // always compared as a UNIX epoch — see class docblock

            default:
                throw new ODataQueryException('Internal error: unknown Edm type `' . $edmType . '` for `' . $prop . '`.');
        }
    }

    // ------------------------------------------------------------ token helpers

    private static function peek(array $state): ?array
    {
        return $state['tokens'][$state['pos']] ?? null;
    }

    private static function peekIsKeyword(array $state, string $keyword): bool
    {
        $t = self::peek($state);
        return $t !== null && $t['type'] === 'IDENT' && strtolower($t['value']) === $keyword;
    }

    private static function advance(array &$state): ?array
    {
        $t = self::peek($state);
        if ($t !== null) {
            $state['pos']++;
        }
        return $t;
    }

    private static function expect(array &$state, string $type, string $describe): array
    {
        $t = self::peek($state);
        if ($t === null) {
            throw new ODataQueryException('Expected ' . $describe . ' in $filter but the expression ended.');
        }
        if ($t['type'] !== $type) {
            throw new ODataQueryException(
                'Expected ' . $describe . ' in $filter at position ' . $t['pos'] . ' but found `'
                . self::echoSafe($t['value']) . '`.'
            );
        }
        $state['pos']++;
        return $t;
    }

    // ======================================================================
    // SQL compilation
    // ======================================================================

    /** @return array{0: string, 1: array, 2: string} [sql, binds, types] */
    private static function compileSql(array $node): array
    {
        switch ($node['type']) {
            case 'and':
            case 'or':
                [$lSql, $lBinds, $lTypes] = self::compileSql($node['left']);
                [$rSql, $rBinds, $rTypes] = self::compileSql($node['right']);
                $joiner = $node['type'] === 'and' ? ' AND ' : ' OR ';
                return ['(' . $lSql . $joiner . $rSql . ')', array_merge($lBinds, $rBinds), $lTypes . $rTypes];

            case 'cmp':
                $col = self::sqlIdentifier($node['property']);
                if ($node['value'] === self::NULL_LITERAL) {
                    $sql = $col . ($node['op'] === 'eq' ? ' IS NULL' : ' IS NOT NULL');
                    return [$sql, [], ''];
                }
                $opSql = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'ge' => '>=', 'lt' => '<', 'le' => '<='][$node['op']];
                $bindType = self::EDM_BIND_TYPE[$node['edmType']] ?? 's';
                return [$col . ' ' . $opSql . ' ?', [$node['value']], $bindType];

            case 'func':
                $col     = self::sqlIdentifier($node['property']);
                $pattern = self::likePattern($node['func'], (string)$node['value']);
                return [$col . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$pattern], 's'];

            default:
                throw new ODataQueryException('Internal error: unknown filter node type in SQL compilation.');
        }
    }

    /** Backtick-quoted identifier. Safe ONLY given the whitelist check in parse(). */
    private static function sqlIdentifier(string $prop): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prop)) {
            // Unreachable given parse()'s upfront validation; kept as a hard
            // stop so a future refactor can never turn this into an injection.
            throw new ODataQueryException('Internal error: unsafe property identifier `' . $prop . '`.');
        }
        return '`' . $prop . '`';
    }

    /**
     * Build the LIKE pattern for startswith/endswith/contains with the search
     * text's own %, _ and the escape character itself neutralized first — so a
     * client searching for a literal "50%" can never have that "%" reinterpreted
     * as a wildcard.
     */
    private static function likePattern(string $func, string $needle): string
    {
        $esc = self::LIKE_ESCAPE;
        $escaped = str_replace(
            [$esc, '%', '_'],
            [$esc . $esc, $esc . '%', $esc . '_'],
            $needle
        );
        return match ($func) {
            'startswith' => $escaped . '%',
            'endswith'   => '%' . $escaped,
            'contains'   => '%' . $escaped . '%',
            default      => throw new ODataQueryException('Internal error: unknown function `' . $func . '`.'),
        };
    }

    // ======================================================================
    // In-memory evaluation
    // ======================================================================

    private static function evaluateCmp(array $node, array $row): bool
    {
        $rowVal = array_key_exists($node['property'], $row) ? $row[$node['property']] : null;

        if ($node['value'] === self::NULL_LITERAL) {
            $isNull = ($rowVal === null);
            return $node['op'] === 'eq' ? $isNull : !$isNull;
        }
        if ($rowVal === null) {
            // SQL three-valued logic: NULL compared to a concrete literal is never
            // true for eq/ne/gt/ge/lt/le. Matches how the compiled SQL path behaves.
            return false;
        }

        $cmp = self::compareValues($rowVal, $node['value'], $node['edmType']);
        return match ($node['op']) {
            'eq' => $cmp === 0,
            'ne' => $cmp !== 0,
            'gt' => $cmp > 0,
            'ge' => $cmp >= 0,
            'lt' => $cmp < 0,
            'le' => $cmp <= 0,
        };
    }

    private static function evaluateFunc(array $node, array $row): bool
    {
        $rowVal = array_key_exists($node['property'], $row) ? $row[$node['property']] : null;
        if ($rowVal === null || !is_string($rowVal)) {
            return false;
        }
        // Case-insensitive throughout this surface — see the class docblock.
        $hay = mb_strtolower($rowVal);
        $needle = mb_strtolower((string)$node['value']);
        return match ($node['func']) {
            'startswith' => str_starts_with($hay, $needle),
            'endswith'   => str_ends_with($hay, $needle),
            'contains'   => str_contains($hay, $needle),
            default      => false,
        };
    }

    /**
     * Three-way compare for both evaluateFilter() and makeComparator().
     * $b is either a raw row value (sort) or an already-coerced filter literal
     * (evaluateCmp) — both arrive in the same wire representation per type.
     */
    private static function compareValues($a, $b, string $edmType): int
    {
        if ($a === null && $b === null) return 0;
        if ($a === null) return -1;
        if ($b === null) return 1;

        switch ($edmType) {
            case 'Edm.Int32':
                return (int)$a <=> (int)$b;
            case 'Edm.Double':
                return (float)$a <=> (float)$b;
            case 'Edm.Boolean':
                return (int)((bool)$a) <=> (int)((bool)$b);
            case 'Edm.Date':
                return strcmp((string)$a, (string)$b);
            case 'Edm.DateTimeOffset':
                // $b may already be a UNIX epoch int (from a parsed filter literal)
                // or an ISO string (from another row, during a sort) — normalize both.
                return self::toEpoch($a) <=> self::toEpoch($b);
            case 'Edm.String':
            default:
                return strcasecmp((string)$a, (string)$b);
        }
    }

    private static function toEpoch($v): int
    {
        if (is_int($v)) {
            return $v;
        }
        try {
            return (new DateTimeImmutable((string)$v))->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    // ======================================================================
    // misc
    // ======================================================================

    /** Strip control characters and cap length before a value is echoed into an error message. */
    private static function echoSafe(string $v): string
    {
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
        return strlen($v) > 80 ? substr($v, 0, 80) . '…' : $v;
    }
}
