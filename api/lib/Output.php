<?php
/**
 * Output — response emission for the /api/external/v1 report surface.
 *
 * JSON goes through ApiResponse::success() so the envelope is byte-identical to
 * the rest of the API. CSV bypasses the envelope entirely (an envelope in a CSV
 * body would break every spreadsheet import) and streams straight to php://output
 * so a 5000-row payroll export never materializes as one giant string.
 */

require_once __DIR__ . '/../middleware/ApiResponse.php';
require_once __DIR__ . '/ReportParams.php';

class Output
{
    /**
     * Send the report and terminate the request.
     *
     * $data is the full `data` object: ['rows' => [...], 'meta' => [...], 'totals' => [...]].
     * CSV mode emits ONLY $data['rows'] — meta/totals have no CSV representation, so
     * the handful of meta fields an unattended importer actually needs are mirrored
     * into X-Report-* response headers instead of being silently dropped.
     *
     * $csvColumns optionally pins the column order (and lets an empty result set
     * still emit a header row). When omitted, the flattened keys of the first row
     * define the columns.
     */
    public static function send(array $data, ReportParams $params, string $report, ?array $csvColumns = null): void
    {
        if ($params->format() !== 'csv') {
            ApiResponse::success($data); // exits
            return;
        }

        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];

        self::streamCsv($rows, $meta, $params, $report, $csvColumns); // exits
    }

    /**
     * Flatten one row to a single level using dotted keys.
     *   ['hours' => ['regular' => 8]]  ->  ['hours.regular' => 8]
     * Lists of scalars collapse to a pipe-joined string (warnings -> "a|b"); lists
     * of arrays are JSON-encoded rather than exploded into an unstable number of
     * columns, because a CSV header row has to be the same for every row.
     */
    public static function flattenRow(array $row, string $prefix = ''): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $name = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if (is_array($value)) {
                if ($value === []) {
                    $out[$name] = '';
                } elseif (array_is_list($value)) {
                    $scalarOnly = true;
                    foreach ($value as $v) {
                        if (is_array($v) || is_object($v)) { $scalarOnly = false; break; }
                    }
                    $out[$name] = $scalarOnly
                        ? implode('|', array_map(static fn($v) => self::stringify($v), $value))
                        : (json_encode($value) ?: '');
                } else {
                    $out += self::flattenRow($value, $name);
                }
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * Render one value as a CSV-safe cell.
     *
     * Formula-injection defense: a cell whose first character is = + - @ TAB or CR
     * is prefixed with a single quote so Excel/Sheets treat it as text.
     * Numeric values are exempt — a PHP int/float can never be a formula, and
     * quoting them would corrupt legitimately negative numbers (variance_hours is
     * routinely negative). Numbers reach here as int/float because report rows emit
     * hours as numbers, not strings; anything arriving as a STRING that starts with
     * '-' is treated as untrusted text and gets the quote.
     */
    public static function csvCell($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::stringify($value);
        }

        $s = self::stringify($value);
        if ($s === '') {
            return '';
        }
        $first = $s[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    // -------------------------------------------------------------- internals

    private static function streamCsv(
        array $rows,
        array $meta,
        ReportParams $params,
        string $report,
        ?array $csvColumns
    ): void {
        $columns = $csvColumns;
        if ($columns === null) {
            $first   = null;
            foreach ($rows as $r) { $first = is_array($r) ? $r : null; break; }
            $columns = $first !== null ? array_keys(self::flattenRow($first)) : [];
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . self::filename($params, $report) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        // CSV has nowhere to put meta, so mirror the fields an unattended importer
        // needs (pagination + the honesty warnings) into headers.
        self::metaHeader('X-Report-Timezone',    $params->timezone()->getName());
        self::metaHeader('X-Report-Row-Count',   (string)($meta['row_count']   ?? count($rows)));
        self::metaHeader('X-Report-Total-Count', (string)($meta['total_count'] ?? count($rows)));
        self::metaHeader('X-Report-Has-More',    !empty($meta['has_more']) ? 'true' : 'false');
        if (!empty($meta['warnings']) && is_array($meta['warnings'])) {
            self::metaHeader('X-Report-Warnings', implode(' | ', array_map('strval', $meta['warnings'])));
        }

        // Discard anything a notice/warning may have buffered; the CSV body must
        // start with the BOM or Excel mis-detects the encoding.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $out = fopen('php://output', 'w');
        if ($out === false) {
            ApiResponse::error('Internal server error', 500);
            return;
        }

        echo "\xEF\xBB\xBF";

        if ($columns !== []) {
            self::putRow($out, $columns);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $flat  = self::flattenRow($row);
            $line  = [];
            foreach ($columns as $col) {
                $line[] = self::csvCell($flat[$col] ?? null);
            }
            self::putRow($out, $line);
        }

        fclose($out);
        exit;
    }

    /**
     * fputcsv with an explicit empty escape string: PHP's default backslash escape
     * is not RFC 4180 and is deprecated as an implicit default in PHP 8.4.
     */
    private static function putRow($handle, array $fields): void
    {
        fputcsv($handle, array_map(static fn($v) => is_string($v) ? $v : self::csvCell($v), $fields), ',', '"', '');
    }

    private static function filename(ReportParams $params, string $report): string
    {
        $slug  = preg_replace('/[^A-Za-z0-9_-]/', '', $report);
        $slug  = $slug === '' ? 'report' : $slug;
        $start = $params->startDate() ?? (new DateTimeImmutable('now', $params->timezone()))->format('Y-m-d');
        $end   = $params->endDate()   ?? $start;
        return $slug . '-' . $start . '_' . $end . '.csv';
    }

    /** Header values must not carry CR/LF — this data is partly integrator-supplied. */
    private static function metaHeader(string $name, string $value): void
    {
        $value = preg_replace('/[\r\n]+/', ' ', $value) ?? '';
        if (strlen($value) > 400) {
            $value = substr($value, 0, 400);
        }
        header($name . ': ' . $value);
    }

    private static function stringify($value): string
    {
        if ($value === null)  return '';
        if (is_bool($value))  return $value ? 'true' : 'false';
        if (is_float($value)) {
            // Avoid 8.1999999999999993 in a payroll column while still allowing
            // values that legitimately need more than 2 decimals through.
            $s = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
            return $s === '' || $s === '-' ? '0' : $s;
        }
        if (is_scalar($value)) return (string)$value;
        return json_encode($value) ?: '';
    }
}
