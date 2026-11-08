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
    private const CACHE_TTL_SECONDS = 900; // 15 minutes

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
    public function getWeather(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $cachedMeta = Cache::get(self::CACHE_META_KEY);

        if ($cached !== null && $cachedMeta !== null && ($cachedMeta['is_fresh'] ?? false)) {
            return [
                'weather' => $cached,
                'meta' => $cachedMeta,
            ];
        }

        try {
            $lats = implode(',', array_column(self::CITIES, 'lat'));
            $lngs = implode(',', array_column(self::CITIES, 'lng'));

            $url = 'https://api.open-meteo.com/v1/forecast';
            $response = Http::withoutVerifying()->timeout(6)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'DisasterLinkSEA/1.0',
            ])->get($url, [
                'latitude' => $lats,
                'longitude' => $lngs,
                'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,weather_code,wind_speed_10m,wind_direction_10m',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum',
                'timezone' => 'auto',
                'forecast_days' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                // When multiple locations queried, Open-Meteo returns array of responses
                $items = isset($data[0]) ? $data : [$data];
                $weatherList = [];

                foreach (self::CITIES as $index => $cityMeta) {
                    $cityData = $items[$index] ?? null;
                    if ($cityData === null) {
                        continue;
                    }

                    $current = $cityData['current'] ?? [];
                    $daily = $cityData['daily'] ?? [];
                    $code = (int) ($current['weather_code'] ?? 0);
                    $wmo = self::WMO_CODES[$code] ?? ['condition' => 'Unknown', 'icon' => 'cloud'];

                    $weatherList[] = [
                        'country' => $cityMeta['country'],
                        'city' => $cityMeta['city'],
                        'iso2' => $cityMeta['iso2'],
                        'iso3' => $cityMeta['iso3'],
                        'latitude' => $cityMeta['lat'],
                        'longitude' => $cityMeta['lng'],
                        'temperature_c' => isset($current['temperature_2m']) ? round((float) $current['temperature_2m'], 1) : null,
                        'apparent_temperature_c' => isset($current['apparent_temperature']) ? round((float) $current['apparent_temperature'], 1) : null,
                        'humidity_pct' => isset($current['relative_humidity_2m']) ? (int) $current['relative_humidity_2m'] : null,
                        'precipitation_mm' => isset($current['precipitation']) ? round((float) $current['precipitation'], 1) : 0.0,
                        'wind_speed_kmh' => isset($current['wind_speed_10m']) ? round((float) $current['wind_speed_10m'], 1) : null,
                        'wind_direction_deg' => isset($current['wind_direction_10m']) ? (int) $current['wind_direction_10m'] : null,
                        'weather_code' => $code,
                        'condition' => $wmo['condition'],
                        'icon' => $wmo['icon'],
                        'is_day' => (bool) ($current['is_day'] ?? true),
                        'forecast_max_c' => isset($daily['temperature_2m_max'][0]) ? round((float) $daily['temperature_2m_max'][0], 1) : null,
                        'forecast_min_c' => isset($daily['temperature_2m_min'][0]) ? round((float) $daily['temperature_2m_min'][0], 1) : null,
                        'forecast_precipitation_sum_mm' => isset($daily['precipitation_sum'][0]) ? round((float) $daily['precipitation_sum'][0], 1) : null,
                        'observation_time' => $current['time'] ?? Carbon::now()->toIso8601String(),
                        'data_type' => 'High-resolution atmospheric model simulation (Open-Meteo)',
                    ];
                }

                $meta = [
                    'source' => 'Open-Meteo',
                    'name' => 'Open-Meteo Weather Forecast API',
                    'status' => 'Live',
                    'last_successful_fetch' => Carbon::now()->toIso8601String(),
                    'latest_event_time' => Carbon::now()->toIso8601String(),
                    'cities_count' => count($weatherList),
                    'is_fresh' => true,
                    'is_stale' => false,
                    'documentation_url' => 'https://open-meteo.com/en/docs',
                ];

                Cache::put(self::CACHE_KEY, $weatherList, self::CACHE_TTL_SECONDS);
                Cache::put(self::CACHE_META_KEY, $meta, self::CACHE_TTL_SECONDS);

                return [
                    'weather' => $weatherList,
                    'meta' => $meta,
                ];
            }

            Log::warning('Open-Meteo API returned non-200: ' . $response->status());
        } catch (Throwable $e) {
            Log::error('Open-Meteo API fetch error: ' . $e->getMessage());
        }

        if ($cached !== null && $cachedMeta !== null) {
            $cachedMeta['status'] = 'Stale';
            $cachedMeta['is_stale'] = true;
            $cachedMeta['is_fresh'] = false;
            $cachedMeta['error'] = 'Live refresh failed; serving cached data.';
            return [
                'weather' => $cached,
                'meta' => $cachedMeta,
            ];
        }

        return [
            'weather' => [],
            'meta' => [
                'source' => 'Open-Meteo',
                'name' => 'Open-Meteo Weather Forecast API',
                'status' => 'Unavailable',
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'cities_count' => 0,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => 'Unable to contact Open-Meteo server.',
                'documentation_url' => 'https://open-meteo.com/en/docs',
            ],
        ];
    }
}
