<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\CountryBoundary;
use App\Services\Disaster\Sync\Geometry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountryBoundarySeeder extends Seeder
{
    /**
     * Load ADM0 outlines from `config('disaster_sources.country.boundaries_file')`. Idempotent (upserts by country).
     *
     * Expected file shape:
     * {"meta": {"source", "version", "downloaded_at", "license", "attribution", "simplify_tolerance"},
     *  "countries": {"IDN": <GeoJSON Polygon|MultiPolygon>, ...}}
     *
     * Without the file nothing is seeded and country lookups stay unresolved.
     */
    public function run(): void
    {
        $path = (string) config('disaster_sources.country.boundaries_file');

        if (! is_file($path)) {
            $this->command?->warn("Country boundary file not found ({$path}); skipping. Events will use source country codes only.");

            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $meta = (array) ($data['meta'] ?? []);
        $countries = Country::query()->pluck('id', 'iso3');

        DB::transaction(function () use ($data, $meta, $countries): void {
            foreach ((array) ($data['countries'] ?? []) as $iso3 => $geometry) {
                $countryId = $countries[strtoupper((string) $iso3)] ?? null;
                $polygons = Geometry::polygons((array) $geometry);

                if ($countryId === null || $polygons === []) {
                    continue;
                }

                $points = collect($polygons)->flatten(2);
                $lngs = $points->map(fn ($p) => (float) $p[0]);
                $lats = $points->map(fn ($p) => (float) $p[1]);

                CountryBoundary::query()->updateOrCreate(['country_id' => $countryId], [
                    'geojson' => json_encode($geometry),
                    'min_lat' => $lats->min(),
                    'max_lat' => $lats->max(),
                    'min_lng' => $lngs->min(),
                    'max_lng' => $lngs->max(),
                    'vertex_count' => $points->count(),
                    'source' => (string) ($meta['source'] ?? 'unknown'),
                    'source_version' => $meta['version'] ?? null,
                    'downloaded_at' => $meta['downloaded_at'] ?? null,
                    'license' => $meta['license'] ?? null,
                    'attribution' => $meta['attribution'] ?? null,
                    'simplify_tolerance' => $meta['simplify_tolerance'] ?? null,
                    'imported_at' => now(),
                ]);
            }
        });
    }
}
