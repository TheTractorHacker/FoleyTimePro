<?php
/**
 * API Scope Catalog — single source of truth for external API key scopes.
 *
 * Both the admin UI (admin/api_keys.php create form, admin/api-docs.php reference
 * table) and any scope-checking code read from here. Keeping the list in one place
 * is not cosmetic: admin/api_keys.php validates requested scopes against a
 * whitelist, and a scope that exists in the whitelist but nowhere in the docs —
 * or worse, a scope an endpoint requires but the whitelist rejects — produces a
 * key that silently 403s on the endpoint it was created for.
 *
 * Adding a new scope-gated endpoint means adding its scope here FIRST.
 */

final class ApiScopes
{
    /**
     * scope => [
     *   'label'       => short human name for the admin UI,
     *   'description' => what data the scope unlocks,
     *   'endpoints'   => routes gated by this scope (documentation only),
     *   'default'     => whether the create-key form pre-checks it,
     *   'write'       => true if the scope would permit mutation,
     *   'available'   => false when no endpoint honors it yet (reserved),
     * ]
     *
     * RESERVED SCOPES
     * The external API is deliberately read-only today. The write scopes below are
     * listed so a key can be provisioned ahead of the endpoints landing, but they are
     * marked available => false and the admin UI says so plainly. Granting one changes
     * nothing until write endpoints exist — and an admin who believes they have just
     * enabled write access when they have not is a worse outcome than not offering the
     * option at all, so the UI must never present these as equivalent to the read scopes.
     */
    private const CATALOG = [
        'punches:read' => [
            'label'       => 'Punches',
            'description' => 'Raw clock in/out and lunch punches, with a delta-sync cursor.',
            'endpoints'   => ['GET /api/external/v1/punches'],
            'default'     => true,
            'write'       => false,
            'available'   => true,
        ],
        'employees:read' => [
            'label'       => 'Employees',
            'description' => 'Employee roster for mapping external IDs. Never includes badge IDs or credentials.',
            'endpoints'   => ['GET /api/external/v1/employees'],
            'default'     => true,
            'write'       => false,
            'available'   => true,
        ],
        'reports:read' => [
            'label'       => 'Reports',
            'description' => 'Aggregated hours, overtime, utilization, attendance, payroll and leave reporting.',
            'endpoints'   => [
                'GET /api/external/v1/reports/hours',
                'GET /api/external/v1/reports/summary',
                'GET /api/external/v1/reports/overtime',
                'GET /api/external/v1/reports/utilization',
                'GET /api/external/v1/reports/attendance',
                'GET /api/external/v1/reports/timeoff',
                'GET /api/external/v1/reports/leave-balances',
                'GET /api/external/v1/reports/payroll',
                'GET /api/external/v1/reports/exceptions',
                'GET /api/external/v1/reports/trends',
                'GET /api/external/v1/reports/punctuality',
                'GET /api/external/v1/reports/geofence',
            ],
            'default'     => false,
            'write'       => false,
            'available'   => true,
        ],
        'shifts:read' => [
            'label'       => 'Shifts & Schedule',
            'description' => 'Shift definitions and the expanded per-employee, per-date schedule.',
            'endpoints'   => [
                'GET /api/external/v1/shifts',
                'GET /api/external/v1/schedule',
            ],
            'default'     => false,
            'write'       => false,
            'available'   => true,
        ],
        'timeoff:read' => [
            'label'       => 'Time Off',
            'description' => 'Time-off requests and their approval state.',
            'endpoints'   => ['GET /api/external/v1/timeoff'],
            'default'     => false,
            'write'       => false,
            'available'   => true,
        ],
        'offices:read' => [
            'label'       => 'Offices',
            'description' => 'Office/location list with employee counts.',
            'endpoints'   => ['GET /api/external/v1/offices'],
            'default'     => false,
            'write'       => false,
            'available'   => true,
        ],
        'live:read' => [
            'label'       => 'Live Status',
            'description' => 'Who is clocked in right now, who is on lunch, and current on-site headcount.',
            'endpoints'   => [
                'GET /api/external/v1/live/status',
                'GET /api/external/v1/live/headcount',
            ],
            'default'     => false,
            'write'       => false,
            'available'   => true,
        ],
        'audit:read' => [
            'label'       => 'Audit Trail',
            'description' => 'Timesheet edit history, sign-in records, API access log and automatic clock-outs. Sensitive — grant only to compliance tooling.',
            'endpoints'   => [
                'GET /api/external/v1/audit/punch-changes',
                'GET /api/external/v1/audit/access',
                'GET /api/external/v1/reports/auto-clockouts',
            ],
            'default'     => false,
            'write'       => false,
            'available'   => true,
        ],
        // ── Reserved: no endpoint honors these yet ──────────────────────────
        'punches:write' => [
            'label'       => 'Punches — write',
            'description' => 'Create and correct time punches. Every change would be written to punch_changelog.',
            'endpoints'   => [],
            'default'     => false,
            'write'       => true,
            'available'   => false,
        ],
        'timeoff:write' => [
            'label'       => 'Time Off — write',
            'description' => 'Submit time-off requests and approve or reject them.',
            'endpoints'   => [],
            'default'     => false,
            'write'       => true,
            'available'   => false,
        ],
        'employees:write' => [
            'label'       => 'Employees — write',
            'description' => 'Create and update employee records from an external HR system. Never covers passwords, PINs or badge IDs.',
            'endpoints'   => [],
            'default'     => false,
            'write'       => true,
            'available'   => false,
        ],
    ];

    /** @return string[] Every grantable scope name. */
    public static function all(): array
    {
        return array_keys(self::CATALOG);
    }

    /** @return array<string, array> The full catalog, for rendering reference tables. */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    /** @return string[] Scopes the create-key form pre-selects. */
    public static function defaults(): array
    {
        return array_keys(array_filter(self::CATALOG, static fn($m) => $m['default']));
    }

    public static function exists(string $scope): bool
    {
        return isset(self::CATALOG[$scope]);
    }

    /**
     * Split a submitted scope string into [valid, unknown]. Callers reject the
     * request when $unknown is non-empty rather than silently dropping typos —
     * a key quietly created without the scope its integration needs is far
     * harder to diagnose than an upfront validation error.
     *
     * @return array{0: string[], 1: string[]}
     */
    public static function partition(string $csv): array
    {
        $requested = array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)))));
        $valid     = array_values(array_filter($requested, [self::class, 'exists']));
        $unknown   = array_values(array_diff($requested, $valid));
        return [$valid, $unknown];
    }

    public static function describe(string $scope): ?string
    {
        return self::CATALOG[$scope]['description'] ?? null;
    }

    /**
     * Validate scopes submitted as an array (checkbox form) rather than a CSV
     * string. Returns [valid, unknown] exactly like partition().
     *
     * @param  mixed $input Raw $_POST value — untrusted, may be any type.
     * @return array{0: string[], 1: string[]}
     */
    public static function partitionArray($input): array
    {
        if (!is_array($input)) return [[], []];

        $requested = [];
        foreach ($input as $value) {
            // A checkbox array can carry nested arrays if the field name is
            // manipulated; ignore anything that is not a plain scalar.
            if (!is_string($value) && !is_int($value)) continue;
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $requested, true)) $requested[] = $value;
        }

        $valid   = array_values(array_filter($requested, [self::class, 'exists']));
        $unknown = array_values(array_diff($requested, $valid));
        return [$valid, $unknown];
    }

    /** True when the scope is honored by at least one shipped endpoint. */
    public static function isAvailable(string $scope): bool
    {
        return !empty(self::CATALOG[$scope]['available']);
    }

    /** True when granting the scope would permit mutation. */
    public static function isWrite(string $scope): bool
    {
        return !empty(self::CATALOG[$scope]['write']);
    }

    /**
     * Catalog split for the admin create-key form: live read scopes first, then
     * reserved write scopes, so the UI can render them under separate headings
     * with different affordances.
     *
     * @return array{read: array<string,array>, write: array<string,array>}
     */
    public static function grouped(): array
    {
        $read = $write = [];
        foreach (self::CATALOG as $name => $meta) {
            if (!empty($meta['write'])) $write[$name] = $meta;
            else                        $read[$name]  = $meta;
        }
        return ['read' => $read, 'write' => $write];
    }
}
