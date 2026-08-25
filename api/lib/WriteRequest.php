<?php
/**
 * WriteRequest — shared mechanics for the /api/external/v1 WRITE endpoints
 * (punches.php POST/PATCH, timeoff.php POST/PATCH, employees.php POST/PATCH).
 *
 * Four responsibilities, none of which any endpoint should reimplement:
 *
 *   1. JSON body parsing (json()) — the read API takes its input from the
 *      query string; writes take a JSON body, matching REST convention and
 *      what an integration client (Power BI, an HR system, a Zapier-style
 *      connector) actually sends.
 *
 *   2. Idempotency-Key handling (idempotencyKey() / checkIdempotency() /
 *      recordIdempotency()) against the api_write_idempotency table
 *      (migrations/016_write_api_idempotency.sql). This is a NEW table, not
 *      punch_idempotency — see that migration's header for why: this API's
 *      actor is the API key, which can write on behalf of many employees, so
 *      the natural key is (KeyID, IdemKey) rather than (user_id, idem_key).
 *      The header is ALWAYS OPTIONAL here (unlike punch.php, where it is
 *      mandatory for offline_sync writes) — there is no live/offline
 *      distinction on this surface, every external write is inherently a
 *      "correction API" write, so the contract is uniformly:
 *        present + reused key -> return the ORIGINAL response's resource id
 *                                  with a replay flag, do not write again
 *        present + new key    -> do the write, then record it
 *        absent                -> do the write, no dedup, exactly like a
 *                                  client that never asked for the safety net
 *
 *   3. Typed field extraction from the decoded JSON body (requireString /
 *      optionalString / requireInt / optionalFloat / requireDate /
 *      optionalDate / requireEnum), erroring with the same "Invalid field
 *      `x`: ..." / "Missing required field: `x`" message shape ReportParams.php
 *      uses for query parameters, so a write-endpoint 400 reads exactly like a
 *      read-endpoint 400.
 *
 *   4. Runtime detection of the live-only timepunches columns
 *      (timepunchColumnsAvailable()) — IsOpen (migration 013), CapturedAt/
 *      Source (migration 011), GeofencePass* (a later, not-yet-committed
 *      migration) — queried once via SHOW COLUMNS and cached in a static, so
 *      punches.php's write branch (and any future write endpoint touching
 *      timepunches) doesn't hand-roll its own detection differently than
 *      GeofenceSupport::geofencePassColumns() / ODataDispatch::columnExists()
 *      already do on the read side.
 */

require_once __DIR__ . '/../middleware/ApiResponse.php';

class WriteRequest
{
    // ------------------------------------------------------------ JSON body

    /**
     * Parse the JSON request body.
     *
     *   - Empty body (no bytes, or only whitespace)      -> []
     *   - Malformed JSON                                  -> 400, naming the
     *                                                         json_last_error_msg()
     *   - Valid JSON that decodes to a non-array (a bare
     *     string, number, bool, or literal null)           -> 400
     *   - Valid JSON object or list                        -> the decoded array
     *
     * Always reads php://input fresh — do not call this more than once per
     * request (the stream is not seekable in every SAPI configuration).
     *
     * @return array<mixed>
     */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error(
                'Malformed JSON request body: ' . json_last_error_msg() . '.',
                400
            );
        }
        if (!is_array($decoded)) {
            ApiResponse::error(
                'Invalid request body: expected a JSON object, got ' . self::describeType($decoded) . '.',
                400
            );
        }
        return $decoded;
    }

    // ------------------------------------------------------------ idempotency

    /**
     * Idempotency-Key header reader. Same validation as punch.php's
     * readIdempotencyKey(): an opaque token, <=64 chars, [A-Za-z0-9._~-].
     * A present-but-malformed header degrades to null exactly like a missing
     * one — since the header is always-optional on this surface, there is no
     * "header required" 400 to raise the way punch.php does for offline_sync;
     * a malformed key just means this write gets no dedup safety net.
     */
    public static function idempotencyKey(): ?string
    {
        $raw = null;
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Idempotency-Key') === 0) { $raw = $v; break; }
            }
        }
        if ($raw === null) {
            $raw = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        }
        if ($raw === null) return null;

        $raw = trim((string)$raw);
        if ($raw === '' || strlen($raw) > 64 || !preg_match('/^[A-Za-z0-9._~-]+$/', $raw)) {
            return null;
        }
        return $raw;
    }

    /**
     * Look up a prior write recorded under (KeyID, IdemKey, ResourceType).
     * Returns the previously-created resource's id on a replay hit, or null
     * on a miss (including when $idemKey is null — "no dedup requested").
     *
     * ResourceType is part of the lookup even though the table's UNIQUE key
     * (KeyID, IdemKey) is not scoped by it. That is deliberate: if a caller
     * ever reuses one Idempotency-Key across two different resource types —
     * a client bug, but not this class's job to prevent — the SECOND write's
     * recordIdempotency() call silently no-ops (INSERT IGNORE hits the
     * unique key) rather than overwriting the first mapping. Without the
     * ResourceType filter here, that second call's checkIdempotency() would
     * then find the FIRST write's row and hand back its id as if it were a
     * resource of the second type — e.g. a punch id returned as though it
     * were a newly-created time-off request id. Filtering on ResourceType
     * turns that scenario into "no dedup for this call" (a fresh write
     * proceeds normally) instead of a cross-resource-type id collision.
     */
    public static function checkIdempotency(mysqli $conn, int $keyId, ?string $idemKey, string $resourceType): ?int
    {
        if ($idemKey === null) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT ResourceId FROM api_write_idempotency
             WHERE KeyID = ? AND IdemKey = ? AND ResourceType = ?"
        );
        $stmt->bind_param('iss', $keyId, $idemKey, $resourceType);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['ResourceId'] : null;
    }

    /**
     * Persist (KeyID, IdemKey, ResourceType, ResourceId) so the next replay
     * hits the cache. INSERT IGNORE, mirroring idempotencyRecord()'s IGNORE
     * semantics in punch.php: a benign race between two identical retries
     * landing at once (both pass checkIdempotency() before either records)
     * must not throw — the uk_write_idem unique key silently drops the loser,
     * and both requests already computed/return the same resource id from
     * their own insert, so no data is lost.
     */
    public static function recordIdempotency(mysqli $conn, int $keyId, string $idemKey, string $resourceType, int $resourceId): void
    {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO api_write_idempotency (KeyID, IdemKey, ResourceType, ResourceId)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('issi', $keyId, $idemKey, $resourceType, $resourceId);
        $stmt->execute();
        $stmt->close();
    }

    // ------------------------------------------------------------ typed fields
    //
    // All seven pull from the array json() already decoded. Error messages
    // deliberately echo ReportParams.php's shape: "Missing required field:
    // `x`." for an absent required value, "Invalid field `x`: ..." for a
    // present-but-wrong-shape one — so an integrator debugging a write 400
    // and a read 400 side by side sees one consistent voice, not two APIs
    // stitched together.

    /** Required, trimmed, non-empty, length-capped string. */
    public static function requireString(array $body, string $field, int $maxLen = 255): string
    {
        $v = self::optionalString($body, $field, $maxLen);
        if ($v === null) {
            ApiResponse::error('Missing required field: `' . $field . '`.', 400);
        }
        return $v;
    }

    /**
     * Optional string. Absent, null, or (after trimming) empty all return
     * null — a write endpoint treats "the field was left out" and "the field
     * was sent as an empty string" the same way, which matches how
     * ReportParams' scalar()/csvList() already treat blank query values.
     */
    public static function optionalString(array $body, string $field, int $maxLen = 255): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        $raw = $body[$field];
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            ApiResponse::error('Invalid field `' . $field . '`: expected a string.', 400);
        }
        $v = trim((string)$raw);
        if ($v === '') {
            return null;
        }
        if (strlen($v) > $maxLen) {
            ApiResponse::error(
                'Invalid field `' . $field . '`: exceeds the maximum length of ' . $maxLen . ' characters.',
                400
            );
        }
        return $v;
    }

    /** Required integer, with optional inclusive [min, max] bounds. */
    public static function requireInt(array $body, string $field, ?int $min = null, ?int $max = null): int
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            ApiResponse::error('Missing required field: `' . $field . '`.', 400);
        }
        return self::validateInt($body[$field], $field, $min, $max);
    }

    /** Optional integer; absent/null/empty-string returns null. */
    public static function optionalInt(array $body, string $field, ?int $min = null, ?int $max = null): ?int
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        return self::validateInt($body[$field], $field, $min, $max);
    }

    /**
     * Optional float (e.g. latitude/longitude), with optional inclusive
     * [min, max] bounds. Absent/null/empty-string returns null — there is no
     * requireFloat because every current write-endpoint float field
     * (latitude, longitude, hours overrides) is optional by nature.
     */
    public static function optionalFloat(array $body, string $field, ?float $min = null, ?float $max = null): ?float
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        $raw = $body[$field];
        if (is_bool($raw) || (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric(trim($raw))))) {
            ApiResponse::error('Invalid field `' . $field . '`: expected a number.', 400);
        }
        $f = (float)$raw;
        if ($min !== null && $f < $min) {
            ApiResponse::error('Invalid field `' . $field . '`: ' . $f . ' is below the minimum of ' . $min . '.', 400);
        }
        if ($max !== null && $f > $max) {
            ApiResponse::error('Invalid field `' . $field . '`: ' . $f . ' exceeds the maximum of ' . $max . '.', 400);
        }
        return $f;
    }

    /** Required calendar date, strict Y-m-d (no '0000-00-00', no partial dates). */
    public static function requireDate(array $body, string $field): string
    {
        $v = self::optionalDate($body, $field);
        if ($v === null) {
            ApiResponse::error('Missing required field: `' . $field . '`.', 400);
        }
        return $v;
    }

    /** Optional calendar date, strict Y-m-d. Absent/null/empty returns null. */
    public static function optionalDate(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        $raw = $body[$field];
        if (!is_string($raw)) {
            ApiResponse::error('Invalid field `' . $field . '`: expected a date string (YYYY-MM-DD).', 400);
        }
        $v = trim($raw);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $v) {
            ApiResponse::error(
                'Invalid field `' . $field . '`: "' . self::safe($v) . '" is not a calendar date in YYYY-MM-DD format.',
                400
            );
        }
        return $v;
    }

    /**
     * Required value from a fixed vocabulary, matched case-insensitively and
     * returned in its canonical spelling from $allowed — mirrors timeoff.php's
     * inline $parseEnumList so a write endpoint's enum fields (Source,
     * RequestType, action, Status, ...) behave exactly like the read side's
     * status/type query filters. Unrecognized values are a 400 naming every
     * accepted value, never a silent fallback.
     *
     * @param string[] $allowed canonical spellings, e.g. ['Vacation','Sick','Personal','Other']
     */
    public static function requireEnum(array $body, string $field, array $allowed): string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            ApiResponse::error('Missing required field: `' . $field . '`.', 400);
        }
        $raw = $body[$field];
        if (!is_string($raw) && !is_int($raw)) {
            ApiResponse::error('Invalid field `' . $field . '`: expected a string.', 400);
        }
        $v = trim((string)$raw);

        $lookup = [];
        foreach ($allowed as $c) { $lookup[strtolower($c)] = $c; }

        $key = strtolower($v);
        if (!isset($lookup[$key])) {
            ApiResponse::error(
                'Invalid field `' . $field . '`: "' . self::safe($v) . '". Accepted values are '
                . implode(', ', $allowed) . '.',
                400
            );
        }
        return $lookup[$key];
    }

    private static function validateInt($raw, string $field, ?int $min, ?int $max): int
    {
        if (is_bool($raw) || (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/', trim($raw))))) {
            ApiResponse::error('Invalid field `' . $field . '`: expected an integer.', 400);
        }
        $i = (int)$raw;
        if ($min !== null && $i < $min) {
            ApiResponse::error('Invalid field `' . $field . '`: ' . $i . ' is below the minimum of ' . $min . '.', 400);
        }
        if ($max !== null && $i > $max) {
            ApiResponse::error('Invalid field `' . $field . '`: ' . $i . ' exceeds the maximum of ' . $max . '.', 400);
        }
        return $i;
    }

    // -------------------------------------------------------- schema detection

    /** @var array<string,bool>|null */
    private static ?array $timepunchCols = null;

    /**
     * Runtime-detected live-only timepunches columns, queried once via SHOW
     * COLUMNS and cached in a static for the life of the request — the write
     * side's equivalent of GeofenceSupport::geofencePassColumns() /
     * ODataDispatch::columnExists() on the read side. Callers must not assume
     * any of these exist, and must not hardcode false if a column happens to
     * already exist on this install — always read through this method.
     *
     *   IsOpen                   migration 013 — generated column backing
     *                            uk_open_per_employee; if false, this install
     *                            has no open-punch race protection at the DB
     *                            level and a write endpoint creating a punch
     *                            must fall back to its own (weaker,
     *                            check-then-insert) guard rather than relying
     *                            on a 1062 from an index that isn't there.
     *   CapturedAt, Source       migration 011 — offline-sync provenance
     *   GeofencePassIN
     *   GeofencePassOut
     *   GeofencePassLunchStart
     *   GeofencePassLunchEnd     a later migration, not yet in the committed
     *                            schema on every install
     *
     * @return array{IsOpen:bool, CapturedAt:bool, Source:bool,
     *               GeofencePassIN:bool, GeofencePassOut:bool,
     *               GeofencePassLunchStart:bool, GeofencePassLunchEnd:bool}
     */
    public static function timepunchColumnsAvailable(mysqli $conn): array
    {
        if (self::$timepunchCols !== null) {
            return self::$timepunchCols;
        }

        $cols = [
            'IsOpen'                 => false,
            'CapturedAt'             => false,
            'Source'                 => false,
            'GeofencePassIN'         => false,
            'GeofencePassOut'        => false,
            'GeofencePassLunchStart' => false,
            'GeofencePassLunchEnd'   => false,
        ];

        $res = $conn->query("SHOW COLUMNS FROM timepunches");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (array_key_exists($r['Field'], $cols)) {
                    $cols[$r['Field']] = true;
                }
            }
            $res->free();
        }

        return self::$timepunchCols = $cols;
    }

    // -------------------------------------------------------------- internals

    /** Human-readable type name for the "expected a JSON object, got X" message. */
    private static function describeType($value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return 'a boolean';
        if (is_int($value) || is_float($value)) return 'a number';
        if (is_string($value)) return 'a string';
        return gettype($value);
    }

    /**
     * Echo an offending value back to the integrator without letting it
     * become markup or a control-character injection in a log/console.
     * Mirrors ReportParams::safe() exactly.
     */
    private static function safe(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if (strlen($value) > 64) {
            $value = substr($value, 0, 64) . '…';
        }
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
