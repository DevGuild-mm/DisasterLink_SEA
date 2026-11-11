<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\CountryBoundary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CountryBoundary>
 */
class CountryBoundaryFactory extends Factory
{
    /**
     * A simple square from (0,0) to (10,10).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]],
            ]),
            'min_lat' => 0,
            'max_lat' => 10,
            'min_lng' => 0,
            'max_lng' => 10,
            'source' => 'Natural Earth',
            'source_version' => 'test',
            'downloaded_at' => now()->toDateString(),
            'license' => 'Public domain',
        ];
    }
}
