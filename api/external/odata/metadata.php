<?php
/**
 * OData v4 CSDL metadata document — recommended URL.
 *
 * ROUTING: reachable at GET /api/external/odata/metadata (nginx maps
 * /api/<path> to /api/<path>.php by file placement — see ODataDispatch.php's
 * own header for why a literal '$metadata' segment needs special handling).
 * This file and $metadata.php are intentionally two tiny wrappers around the
 * SAME ODataDispatch::serveMetadata() so the CSDL text itself is generated in
 * exactly one place no matter which URL a client used to fetch it.
 *
 * Use THIS url in Power BI / Excel / Tableau when you have a choice — it is
 * the plain, script-friendly path. See $metadata.php for the canonical OData
 * '$metadata' URL, which also works.
 */

require_once __DIR__ . '/ODataDispatch.php';

ODataDispatch::serveMetadata();
