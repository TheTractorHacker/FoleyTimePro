#!/usr/bin/env php
<?php
/**
 * Convert api/docs/openapi.yaml -> api/docs/openapi.json
 *
 * WHY THIS EXISTS
 * The YAML spec is the human-editable source of truth, but nothing in this stack can
 * read YAML at runtime: PHP here has no ext-yaml, and composer.json deliberately carries
 * no YAML library. Rather than add a dependency or shell out from a web request (a web
 * page executing python3 is a liability, not a feature), the conversion happens ONCE
 * here, offline, and the committed JSON is what the docs UI and API tooling consume.
 *
 * Run after any edit to openapi.yaml:
 *     php scripts/openapi-to-json.php
 *
 * Optional explicit paths (used by tests and by CI against a candidate spec):
 *     php scripts/openapi-to-json.php <input.yaml> [output.json]
 *
 * Conversion backends, tried in order:
 *   1. ext-yaml (yaml_parse_file)  — if the host happens to have it
 *   2. python3 + PyYAML            — near-universal on Linux hosts
 * If neither is available the script explains how to install one and exits non-zero.
 *
 * CLI ONLY. Refuses to run over HTTP: it writes into the webroot and invokes a
 * subprocess, neither of which should ever be reachable from a browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}

$root    = dirname(__DIR__);
$yamlIn  = $argv[1] ?? ($root . '/api/docs/openapi.yaml');
$jsonOut = $argv[2] ?? (
    // Default the output next to the input so an explicit input path does not
    // silently overwrite the production artifact.
    isset($argv[1])
        ? preg_replace('/\.ya?ml$/i', '', $argv[1]) . '.json'
        : $root . '/api/docs/openapi.json'
);

if (!is_readable($yamlIn)) {
    fwrite(STDERR, "ERROR: spec not found or unreadable: {$yamlIn}\n");
    exit(1);
}

$spec = parseYaml($yamlIn);

if (!is_array($spec)) {
    fwrite(STDERR, "ERROR: spec did not parse to a mapping — check the YAML is well formed.\n");
    exit(1);
}

// Structural sanity. A spec that parses but is missing these would render as an empty
// docs page, which looks like a styling bug rather than a bad spec — fail loudly instead.
foreach (['openapi', 'info', 'paths'] as $required) {
    if (!isset($spec[$required])) {
        fwrite(STDERR, "ERROR: spec is missing the top-level '{$required}' key.\n");
        exit(1);
    }
}

$pathCount = count($spec['paths']);
$opCount   = 0;
foreach ($spec['paths'] as $ops) {
    if (!is_array($ops)) continue;
    foreach (array_keys($ops) as $method) {
        if (in_array(strtolower($method), ['get','post','put','patch','delete','head','options'], true)) {
            $opCount++;
        }
    }
}
$schemaCount = count($spec['components']['schemas'] ?? []);

// Stamp provenance so the docs page can show when the JSON last tracked the YAML,
// and so a stale artifact is visibly stale rather than silently wrong.
$spec['x-generated'] = [
    'from'         => 'openapi.yaml',
    'at'           => date('c'),
    'generator'    => 'scripts/openapi-to-json.php',
    'source_sha256'=> hash_file('sha256', $yamlIn),
];

$json = json_encode(
    $spec,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false) {
    fwrite(STDERR, 'ERROR: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}

if (file_put_contents($jsonOut, $json . "\n") === false) {
    fwrite(STDERR, "ERROR: could not write {$jsonOut}\n");
    exit(1);
}

printf(
    "OK  %s -> %s\n    %d paths, %d operations, %d schemas, %s\n",
    basename($yamlIn),
    basename($jsonOut),
    $pathCount,
    $opCount,
    $schemaCount,
    formatBytes(strlen($json))
);

/**
 * Parse a YAML file via whichever backend is available.
 */
function parseYaml(string $file): mixed
{
    if (function_exists('yaml_parse_file')) {
        $parsed = @yaml_parse_file($file);
        if ($parsed !== false) return $parsed;
        fwrite(STDERR, "WARN: ext-yaml failed to parse; falling back to python3.\n");
    }

    $python = findPython();
    if ($python === null) {
        fwrite(STDERR,
            "ERROR: no YAML parser available.\n" .
            "  Install one of:\n" .
            "    apt install php-yaml        (then: php -m | grep yaml)\n" .
            "    apt install python3-yaml    (or: pip3 install pyyaml)\n"
        );
        exit(1);
    }

    // Round-trip through JSON on the python side: it is the only format both
    // languages agree on without ambiguity (YAML's implicit typing of things like
    // "on", "no" and sexagesimals differs between parsers).
    $script = 'import sys, yaml, json; json.dump(yaml.safe_load(open(sys.argv[1])), sys.stdout)';
    $cmd    = escapeshellcmd($python) . ' -c ' . escapeshellarg($script) . ' ' . escapeshellarg($file) . ' 2>&1';

    $out    = shell_exec($cmd);
    if ($out === null || $out === '') {
        fwrite(STDERR, "ERROR: python YAML conversion produced no output.\n");
        exit(1);
    }

    $decoded = json_decode($out, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        // $out holds python's traceback in this case — surfacing it verbatim is the
        // fastest route to a fix (it names the offending line and column in the YAML).
        fwrite(STDERR, "ERROR: YAML parse failed:\n" . $out . "\n");
        exit(1);
    }

    return $decoded;
}

function findPython(): ?string
{
    foreach (['python3', 'python'] as $bin) {
        $which = trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        if ($which !== '') {
            // Confirm PyYAML is importable, not just that the interpreter exists.
            $check = shell_exec(escapeshellcmd($which) . ' -c ' . escapeshellarg('import yaml') . ' 2>&1');
            if (trim((string)$check) === '') return $which;
        }
    }
    return null;
}

function formatBytes(int $n): string
{
    return $n < 1024 ? $n . ' B' : round($n / 1024, 1) . ' KB';
}
