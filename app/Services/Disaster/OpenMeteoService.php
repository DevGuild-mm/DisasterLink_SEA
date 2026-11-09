<?php

namespace App\Services\Disaster;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenMeteoService
{
    private const CACHE_KEY = 'open_meteo_weather_sea';

    private const CACHE_META_KEY = 'open_meteo_meta_sea';

    private const CACHE_FRESH_KEY = 'open_meteo_fresh_lock_sea';

    private const DEFAULT_TTL_SECONDS = 900; // 15 minutes

    private const STALE_BACKUP_TTL_SECONDS = 86400; // 24 hours backup for stale fallback

    private const MIN_FORCE_REFRESH_INTERVAL = 15; // seconds

    public const CITIES = [
        [
            'country' => 'Brunei',
            'city' => 'Bandar Seri Begawan',
            'iso2' => 'BN',
            'iso3' => 'BRN',
            'lat' => 4.9031,
            'lng' => 114.9398,
        ],
        [
            'country' => 'Cambodia',
            'city' => 'Phnom Penh',
            'iso2' => 'KH',
            'iso3' => 'KHM',
            'lat' => 11.5564,
            'lng' => 104.9282,
        ],
        [
            'country' => 'Indonesia',
            'city' => 'Jakarta',
            'iso2' => 'ID',
            'iso3' => 'IDN',
            'lat' => -6.2088,
            'lng' => 106.8456,
        ],
        [
            'country' => 'Laos',
            'city' => 'Vientiane',
            'iso2' => 'LA',
            'iso3' => 'LAO',
            'lat' => 17.9757,
            'lng' => 102.6331,
        ],
        [
            'country' => 'Malaysia',
            'city' => 'Kuala Lumpur',
            'iso2' => 'MY',
            'iso3' => 'MYS',
            'lat' => 3.1390,
            'lng' => 101.6869,
        ],
        [
            'country' => 'Myanmar',
            'city' => 'Naypyidaw',
            'iso2' => 'MM',
            'iso3' => 'MMR',
            'lat' => 19.7633,
            'lng' => 96.0785,
        ],
        [
            'country' => 'Philippines',
            'city' => 'Manila',
            'iso2' => 'PH',
            'iso3' => 'PHL',
            'lat' => 14.5995,
            'lng' => 120.9842,
        ],
        [
            'country' => 'Singapore',
            'city' => 'Singapore',
            'iso2' => 'SG',
            'iso3' => 'SGP',
            'lat' => 1.3521,
            'lng' => 103.8198,
        ],
        [
            'country' => 'Thailand',
            'city' => 'Bangkok',
            'iso2' => 'TH',
            'iso3' => 'THA',
            'lat' => 13.7563,
            'lng' => 100.5018,
        ],
        [
            'country' => 'Timor-Leste',
            'city' => 'Dili',
            'iso2' => 'TL',
            'iso3' => 'TLS',
            'lat' => -8.5569,
            'lng' => 125.5788,
        ],
        [
            'country' => 'Vietnam',
            'city' => 'Hanoi',
            'iso2' => 'VN',
            'iso3' => 'VNM',
            'lat' => 21.0285,
            'lng' => 105.8542,
        ],
    ];

    /**
     * WMO Weather interpretation codes (WW)
     */
    private const WMO_CODES = [
        0 => ['condition' => 'Clear sky', 'icon' => 'sun'],
        1 => ['condition' => 'Mainly clear', 'icon' => 'sun-dim'],
        2 => ['condition' => 'Partly cloudy', 'icon' => 'cloud-sun'],
        3 => ['condition' => 'Overcast', 'icon' => 'cloud'],
        45 => ['condition' => 'Fog', 'icon' => 'cloud-fog'],
        48 => ['condition' => 'Depositing rime fog', 'icon' => 'cloud-fog'],
        51 => ['condition' => 'Light drizzle', 'icon' => 'cloud-drizzle'],
        53 => ['condition' => 'Moderate drizzle', 'icon' => 'cloud-drizzle'],
        55 => ['condition' => 'Dense drizzle', 'icon' => 'cloud-rain'],
        61 => ['condition' => 'Slight rain', 'icon' => 'cloud-rain'],
        63 => ['condition' => 'Moderate rain', 'icon' => 'cloud-rain'],
        65 => ['condition' => 'Heavy rain', 'icon' => 'cloud-heavy-rain'],
        71 => ['condition' => 'Slight snow', 'icon' => 'snowflake'],
        73 => ['condition' => 'Moderate snow', 'icon' => 'snowflake'],
        75 => ['condition' => 'Heavy snow', 'icon' => 'snowflake'],
        80 => ['condition' => 'Slight rain showers', 'icon' => 'cloud-sun-rain'],
        81 => ['condition' => 'Moderate rain showers', 'icon' => 'cloud-sun-rain'],
        82 => ['condition' => 'Violent rain showers', 'icon' => 'cloud-lightning-rain'],
        95 => ['condition' => 'Thunderstorm', 'icon' => 'cloud-lightning'],
        96 => ['condition' => 'Thunderstorm with slight hail', 'icon' => 'cloud-lightning'],
        99 => ['condition' => 'Thunderstorm with heavy hail', 'icon' => 'cloud-lightning'],
    ];

    /**
     * Retrieve weather reports for ASEAN countries.
     *
     * @return array{weather: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getWeather(bool $forceRefresh = false): array
    {
        $ttl = (int) config('services.disaster_apis.open_meteo.cache_ttl', self::DEFAULT_TTL_SECONDS);
        $staleTtl = (int) config('services.disaster_apis.stale_ttl', self::STALE_BACKUP_TTL_SECONDS);
        $timeout = (int) config('services.disaster_apis.timeout', 8);
        $url = (string) config(
            'services.disaster_apis.open_meteo.url',
            'https://api.open-meteo.com/v1/forecast',
        );

        $cached = Cache::get(self::CACHE_KEY);
        $cachedMeta = Cache::get(self::CACHE_META_KEY);
        $isFreshLock = Cache::has(self::CACHE_FRESH_KEY);

        if ($cached !== null && $cachedMeta !== null) {
            if (! $forceRefresh && $isFreshLock) {
                return [
                    'weather' => $cached,
                    'meta' => $this->refreshMetaTimestamps($cachedMeta, $ttl, true),
                ];
            }

            if ($forceRefresh && ! empty($cachedMeta['last_successful_fetch'])) {
                try {
                    $secondsSinceFetch = abs(Carbon::now()->diffInSeconds(Carbon::parse($cachedMeta['last_successful_fetch'])));
                    if ($secondsSinceFetch < self::MIN_FORCE_REFRESH_INTERVAL && $isFreshLock) {
                        return [
                            'weather' => $cached,
                            'meta' => $this->refreshMetaTimestamps($cachedMeta, $ttl, true),
                        ];
                    }
                } catch (Throwable) {
                    // Continue with live fetch
                }
            }
        }

        $errorReason = 'Unable to contact Open-Meteo server.';
        $connectionStatus = 'Connection Failed';

        try {
            $lats = implode(',', array_column(self::CITIES, 'lat'));
            $lngs = implode(',', array_column(self::CITIES, 'lng'));

            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'DisasterLinkSEA/1.0',
                ])
                ->get($url, [
                    'latitude' => $lats,
                    'longitude' => $lngs,
                    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,weather_code,wind_speed_10m,wind_direction_10m',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum',
                    'timezone' => 'auto',
                    'forecast_days' => 1,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! is_array($data)) {
                    $connectionStatus = 'Invalid Response Format';
                    throw new \RuntimeException('Invalid JSON structure from Open-Meteo API.');
                }

                // When multiple locations are queried, Open-Meteo returns an array of location objects
                $items = isset($data[0]) ? $data : [$data];
                $weatherList = [];
                $latestObservationTime = null;

                foreach (self::CITIES as $index => $cityMeta) {
                    $cityData = $items[$index] ?? null;
                    if (! is_array($cityData) || ! isset($cityData['current']) || ! is_array($cityData['current'])) {
                        continue;
                    }

                    $current = $cityData['current'];
                    $daily = is_array($cityData['daily'] ?? null) ? $cityData['daily'] : [];
                    $code = isset($current['weather_code']) && is_numeric($current['weather_code'])
                        ? (int) $current['weather_code']
                        : 0;
                    $wmo = self::WMO_CODES[$code] ?? ['condition' => 'Unknown', 'icon' => 'cloud'];

                    $obsTime = isset($current['time']) && is_string($current['time'])
                        ? $current['time']
                        : null;

                    if ($obsTime !== null && ($latestObservationTime === null || $obsTime > $latestObservationTime)) {
                        $latestObservationTime = $obsTime;
                    }

                    $weatherList[] = [
                        'country' => $cityMeta['country'],
                        'city' => $cityMeta['city'],
                        'iso2' => $cityMeta['iso2'],
                        'iso3' => $cityMeta['iso3'],
                        'latitude' => $cityMeta['lat'],
                        'longitude' => $cityMeta['lng'],
                        'temperature_c' => isset($current['temperature_2m']) && is_numeric($current['temperature_2m'])
                            ? round((float) $current['temperature_2m'], 1)
                            : null,
                        'apparent_temperature_c' => isset($current['apparent_temperature']) && is_numeric($current['apparent_temperature'])
                            ? round((float) $current['apparent_temperature'], 1)
                            : null,
                        'humidity_pct' => isset($current['relative_humidity_2m']) && is_numeric($current['relative_humidity_2m'])
                            ? (int) $current['relative_humidity_2m']
                            : null,
                        'precipitation_mm' => isset($current['precipitation']) && is_numeric($current['precipitation'])
                            ? round((float) $current['precipitation'], 1)
                            : 0.0,
                        'wind_speed_kmh' => isset($current['wind_speed_10m']) && is_numeric($current['wind_speed_10m'])
                            ? round((float) $current['wind_speed_10m'], 1)
                            : null,
                        'wind_direction_deg' => isset($current['wind_direction_10m']) && is_numeric($current['wind_direction_10m'])
                            ? (int) $current['wind_direction_10m']
                            : null,
                        'weather_code' => $code,
                        'condition' => $wmo['condition'],
                        'icon' => $wmo['icon'],
                        'is_day' => (bool) ($current['is_day'] ?? true),
                        'forecast_max_c' => isset($daily['temperature_2m_max'][0]) && is_numeric($daily['temperature_2m_max'][0])
                            ? round((float) $daily['temperature_2m_max'][0], 1)
                            : null,
                        'forecast_min_c' => isset($daily['temperature_2m_min'][0]) && is_numeric($daily['temperature_2m_min'][0])
                            ? round((float) $daily['temperature_2m_min'][0], 1)
                            : null,
                        'forecast_precipitation_sum_mm' => isset($daily['precipitation_sum'][0]) && is_numeric($daily['precipitation_sum'][0])
                            ? round((float) $daily['precipitation_sum'][0], 1)
                            : null,
                        'observation_time' => $obsTime ?? '',
                        'data_type' => 'High-resolution atmospheric model telemetry (Open-Meteo)',
                    ];
                }

                if (empty($weatherList)) {
                    $connectionStatus = 'Empty Weather Payload';
                    throw new \RuntimeException('Open-Meteo returned 0 valid city observations.');
                }

                $nowIso = Carbon::now()->toIso8601String();
                $meta = [
                    'source' => 'Open-Meteo',
                    'name' => 'Open-Meteo Weather Forecast API',
                    'status' => 'Live',
                    'freshness_status' => 'Fresh',
                    'connection_status' => 'Connected (HTTP 200)',
                    'last_successful_fetch' => $nowIso,
                    'latest_event_time' => $latestObservationTime ?? $nowIso,
                    'cities_count' => count($weatherList),
                    'cache_ttl_seconds' => $ttl,
                    'provider_update_frequency' => 'Every 15–60 minutes (numerical weather model)',
                    'frontend_poll_interval_seconds' => 60,
                    'is_fresh' => true,
                    'is_stale' => false,
                    'error' => null,
                    'documentation_url' => 'https://open-meteo.com/en/docs',
                ];

                Cache::put(self::CACHE_FRESH_KEY, true, $ttl);
                Cache::put(self::CACHE_KEY, $weatherList, $staleTtl);
                Cache::put(self::CACHE_META_KEY, $meta, $staleTtl);

                return [
                    'weather' => $weatherList,
                    'meta' => $meta,
                ];
            }

            $status = $response->status();
            $connectionStatus = $status === 429 ? 'Rate Limited (HTTP 429)' : "Upstream HTTP {$status}";
            $errorReason = "Open-Meteo API returned HTTP {$status}.";
            Log::warning("Open-Meteo API returned non-200: {$status}");
        } catch (Throwable $e) {
            if ($connectionStatus === 'Connection Failed') {
                $connectionStatus = 'Timeout / Network Error';
            }
            $errorReason = 'Open-Meteo fetch failed: '.$e->getMessage();
            Log::error('Open-Meteo API fetch error: '.$e->getMessage());
        }

        if (is_array($cached) && is_array($cachedMeta)) {
            $staleMeta = $this->refreshMetaTimestamps($cachedMeta, $ttl, false);
            $staleMeta['status'] = 'Stale';
            $staleMeta['freshness_status'] = 'Stale';
            $staleMeta['connection_status'] = "Serving Stale Cache ({$connectionStatus})";
            $staleMeta['is_stale'] = true;
            $staleMeta['is_fresh'] = false;
            $staleMeta['error'] = $errorReason.' Serving last verified cached data.';

            return [
                'weather' => $cached,
                'meta' => $staleMeta,
            ];
        }

        return [
            'weather' => [],
            'meta' => [
                'source' => 'Open-Meteo',
                'name' => 'Open-Meteo Weather Forecast API',
                'status' => 'Unavailable',
                'freshness_status' => 'Unavailable',
                'connection_status' => $connectionStatus,
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'cities_count' => 0,
                'cache_ttl_seconds' => $ttl,
                'provider_update_frequency' => 'Every 15–60 minutes (numerical weather model)',
                'frontend_poll_interval_seconds' => 60,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => $errorReason,
                'documentation_url' => 'https://open-meteo.com/en/docs',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function refreshMetaTimestamps(array $meta, int $ttl, bool $isFresh): array
    {
        $meta['cache_ttl_seconds'] = $ttl;
        $meta['provider_update_frequency'] = 'Every 15–60 minutes (numerical weather model)';
        $meta['frontend_poll_interval_seconds'] = 60;
        $meta['is_fresh'] = $isFresh;
        $meta['is_stale'] = ! $isFresh;
        $meta['freshness_status'] = $isFresh ? 'Fresh' : 'Stale';
        $meta['status'] = $isFresh ? 'Live' : 'Stale';

        return $meta;
    }
}
