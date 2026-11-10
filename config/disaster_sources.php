<?php

/**
 * External disaster data sources fetched by the scheduler (HLD v1.3 §10.3).
 *
 * Page requests never call these endpoints; only `disaster:sync` does.
 */
return [

    'user_agent' => env('DISASTER_SYNC_USER_AGENT', 'DisasterLinkSEA/1.0'),

    /*
     * Generous bounding box around ASEAN. Events outside it are skipped at ingestion.
     * This is a coarse filter only; the country is resolved later by CountryResolver.
     */
    'region' => [
        'min_lat' => -11.5,
        'max_lat' => 28.5,
        'min_lng' => 92.0,
        'max_lng' => 142.0,
    ],

    'asean_iso3' => ['BRN', 'KHM', 'IDN', 'LAO', 'MYS', 'MMR', 'PHL', 'SGP', 'THA', 'TLS', 'VNM'],

    /*
     * A 429 response with a Retry-After longer than this is not waited for: the run fails and the
     * next scheduled run tries again. Shorter values are honoured exactly.
     */
    'max_retry_after_seconds' => 30,

    /*
     * Time limits of one sync (HLD v1.4 §28.9). Worst case of a single GDACS request: 3 attempts x 15 s
     * + two waits (2 s + 5 s, or up to max_retry_after_seconds each for a 429), and up to 11 such
     * requests (3 Orange/Red pages + 8 Green pages): far beyond any lock, hence the total deadline.
     *
     *   max run = deadline_seconds + timeout (one attempt in flight) + persist_allowance_seconds = 180 s
     *   max run < job_timeout_seconds (240) < lock_seconds (300) < queue retry_after (360); overlap expiry 10 min (600 s)
     *
     * - lock_seconds: the `sync:{source}` lock. It outlives the longest run, so a second worker or the
     *   scheduler can never start the same source while a live run holds it.
     * - job_timeout_seconds: for the queued admin sync job (Phase 6): `$timeout` and `queue:work --timeout`.
     * - retry_after: config/queue.php (database connection) must be larger than the job timeout, so a
     *   running job is never handed to a second worker.
     * - The scheduler uses withoutOverlapping(10) (routes/console.php): 10 minutes outlives lock_seconds.
     */
    'sync' => [
        'deadline_seconds' => 150,
        'persist_allowance_seconds' => 15,
        'lock_seconds' => 300,
        'job_timeout_seconds' => 240,
    ],

    /* Hosts (and their subdomains) allowed in a stored source_url. */
    'allowed_url_hosts' => ['gdacs.org', 'usgs.gov'],

    /*
     * Country resolution (HLD §12). Boundaries come from database/seeders/data/asean_country_boundaries.json.
     * An event within border_guard_km of another country's outline is left unresolved; one further than
     * outside_region_km from every ASEAN outline is marked outside_region.
     */
    'country' => [
        'boundaries_file' => database_path('seeders/data/asean_country_boundaries.json'),
        'border_guard_km' => 2,
        'outside_region_km' => 300,
    ],

    'sources' => [
        'gdacs' => [
            'name' => 'GDACS',
            'url' => env('GDACS_SYNC_URL', 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH'),
            'event_types' => 'EQ;TC;FL;VO;WF;DR',
            'lookback_days' => 7,
            /*
             * GDACS returns at most 100 events per page and ignores `pagesize`. Requesting several alert
             * levels together returns only the first 100 rows (Green), so levels are requested separately:
             * Orange/Red (required, small) and Green (paged, best effort, feeds escalation tracking).
             */
            'page_size' => 100,
            'max_green_pages' => 8,
            'interval_minutes' => 15,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_delays_ms' => [2000, 5000],
        ],
        'usgs' => [
            'name' => 'USGS Earthquakes',
            'url' => env('USGS_SYNC_URL', 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_day.geojson'),
            'interval_minutes' => 5,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_delays_ms' => [2000, 5000],
        ],
    ],

];
