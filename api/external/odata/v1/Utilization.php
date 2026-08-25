<?php
/**
 * OData v4 entity set: Utilization
 * Scope required: reports:read (identical to the REST scope the underlying data
 * already requires — see the task's per-entity scope table; there is no
 * separate "odata" scope).
 *
 * One row per employee: scheduled vs. worked capacity for the default window.
 *
 * ROUTING: nginx maps /api/<path> to /api/<path>.php by file placement (see
 * the top-level ROUTING note) — this file's own path is exactly why
 * GET /api/external/odata/v1/Utilization works with no routing change.
 *
 * All actual logic — the property registry, $select/$filter/$orderby/$top/
 * $skip/$count handling, data loading, and response shaping — lives in
 * ODataDispatch.php, shared by every entity set (see that file's own header
 * for why). This file exists only because nginx routes by file placement, not
 * because it has anything of its own to say.
 */

require_once __DIR__ . '/../ODataDispatch.php';

ODataDispatch::serve('Utilization');
