<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'disaster_apis' => [
        'timeout' => (int) env('DISASTER_API_TIMEOUT', 8),
        'stale_ttl' => (int) env('DISASTER_API_STALE_TTL', 86400),
        'gdacs' => [
            'url' => env('GDACS_API_URL', 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH'),
            'cache_ttl' => (int) env('GDACS_CACHE_TTL', 300),
        ],
        'usgs' => [
            'hour_url' => env('USGS_HOUR_API_URL', 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson'),
            'week_url' => env('USGS_WEEK_API_URL', 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_week.geojson'),
            'cache_ttl' => (int) env('USGS_CACHE_TTL', 180),
        ],
        'eonet' => [
            'url' => env('NASA_EONET_API_URL', 'https://eonet.gsfc.nasa.gov/api/v3/events'),
            'cache_ttl' => (int) env('NASA_EONET_CACHE_TTL', 900),
        ],
        'open_meteo' => [
            'url' => env('OPEN_METEO_API_URL', 'https://api.open-meteo.com/v1/forecast'),
            'cache_ttl' => (int) env('OPEN_METEO_CACHE_TTL', 900),
        ],
    ],

];
