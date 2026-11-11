<?php

/**
 * Post-MVP modules parked by HLD v1.3.
 *
 * These modules sit outside the MVP scope. Their code, routes and tests remain in
 * place, but every route returns 404 unless the matching flag is enabled.
 */
return [
    'intelligence' => (bool) env('MODULE_INTELLIGENCE', false),
    'coordination' => (bool) env('MODULE_COORDINATION', false),
    'countries' => (bool) env('MODULE_COUNTRIES', false),
    'reports' => (bool) env('MODULE_REPORTS', false),
];
