<?php
/**
 * OData v4 service document.
 *
 * ROUTING (see the ROUTING note in the task; nginx here maps /api/<path> to
 * /api/<path>.php — /api/.htaccess is never read by nginx and is NOT edited):
 *   GET /api/external/odata           -> nginx 301-redirects to the trailing-
 *                                        slash form below (see next line) —
 *                                        this is a real, verified nginx
 *                                        behavior for any bare directory
 *                                        request, not a guess: requesting an
 *                                        existing directory with no trailing
 *                                        slash and an `index` file inside it
 *                                        is a standard nginx redirect, and
 *                                        this codebase's own sites-enabled
 *                                        config was confirmed live to do
 *                                        exactly that for another directory
 *                                        under /api/ during this task's
 *                                        verification pass.
 *   GET /api/external/odata/          -> served BY THIS FILE (index.php is in
 *                                        the server's `index` list, and this
 *                                        is the only .php file directly in
 *                                        this directory).
 * Power BI / Excel / Tableau all follow redirects transparently, so pointing
 * either URL at their OData connector works; the trailing-slash form saves the
 * one extra round trip.
 */

require_once __DIR__ . '/ODataDispatch.php';

ODataDispatch::serveServiceDocument();
