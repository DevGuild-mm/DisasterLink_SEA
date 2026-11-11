<?php

use App\Models\Country;
use App\Models\CountryBoundary;
use App\Services\Disaster\Sync\CountryResolver;
use Database\Seeders\CountryBoundarySeeder;
use Database\Seeders\CountrySeeder;

/*
 * Runs the resolver against the committed Natural Earth data (database/seeders/data), not fixtures.
 */

beforeEach(function () {
    $this->seed([CountrySeeder::class, CountryBoundarySeeder::class]);
    $this->resolver = new CountryResolver;
    $this->iso3 = fn (?float $lat, ?float $lng): string => ($r = $this->resolver->resolve(null, $lat, $lng))->isResolved()
        ? Country::find($r->countryId)->iso3
        : $r->status;
});

test('the boundary file holds exactly the 11 ASEAN countries, valid and small', function () {
    $path = config('disaster_sources.country.boundaries_file');
    $data = json_decode(file_get_contents($path), true);

    expect(array_keys($data['countries']))->toEqualCanonicalizing(config('disaster_sources.asean_iso3'))
        ->and(Country::pluck('iso3')->all())->toEqualCanonicalizing(array_keys($data['countries']))
        ->and(filesize($path))->toBeLessThan(1_500_000)
        ->and($data['meta']['license'])->toBe('Public domain')
        ->and(CountryBoundary::count())->toBe(11);

    $openRings = 0;
    $shortRings = 0;
    $outOfRange = 0;

    foreach ($data['countries'] as $geometry) {
        expect($geometry['type'])->toBe('MultiPolygon');

        foreach ($geometry['coordinates'] as $polygon) {
            foreach ($polygon as $ring) {
                $openRings += $ring[0] === end($ring) ? 0 : 1;
                $shortRings += count($ring) >= 4 ? 0 : 1;

                foreach ($ring as [$lng, $lat]) {
                    $outOfRange += ($lng >= 90 && $lng <= 142 && $lat >= -12 && $lat <= 29) ? 0 : 1;
                }
            }
        }
    }

    expect([$openRings, $shortRings, $outOfRange])->toBe([0, 0, 0]);

    $indonesia = CountryBoundary::where('country_id', Country::where('iso3', 'IDN')->value('id'))->first();

    expect($indonesia->vertex_count)->toBeGreaterThan(10000)
        ->and((float) $indonesia->min_lng)->toBeBetween(94, 96)
        ->and((float) $indonesia->max_lng)->toBeBetween(140, 142);
});

test('seeding twice keeps one row per country', function () {
    $this->seed(CountryBoundarySeeder::class);

    expect(CountryBoundary::count())->toBe(11);
});

test('capitals resolve to their own country', function (string $iso3, float $lat, float $lng) {
    expect(($this->iso3)($lat, $lng))->toBe($iso3);
})->with([
    'Jakarta' => ['IDN', -6.2, 106.85],
    'Bangkok' => ['THA', 13.75, 100.5],
    'Hanoi' => ['VNM', 21.03, 105.85],
    'Phnom Penh' => ['KHM', 11.56, 104.92],
    'Kuala Lumpur' => ['MYS', 3.14, 101.69],
    'Naypyidaw' => ['MMR', 19.76, 96.08],
    'Manila' => ['PHL', 14.6, 120.98],
    'Dili' => ['TLS', -8.56, 125.57],
    'Bandar Seri Begawan' => ['BRN', 4.89, 114.94],
    'Singapore' => ['SGP', 1.35, 103.82],
]);

test('islands and multi-part territories resolve', function (string $iso3, float $lat, float $lng) {
    expect(($this->iso3)($lat, $lng))->toBe($iso3);
})->with([
    'Bali' => ['IDN', -8.4, 115.2],
    'Papua (Jayapura)' => ['IDN', -2.53, 140.7],
    'Ambon' => ['IDN', -3.7, 128.2],
    'Sumatra (Medan)' => ['IDN', 3.59, 98.67],
    'Cebu' => ['PHL', 10.3, 123.9],
    'Palawan' => ['PHL', 9.74, 118.74],
    'Mindanao (Davao)' => ['PHL', 7.07, 125.6],
    'Phuket' => ['THA', 7.9, 98.4],
    'Langkawi' => ['MYS', 6.35, 99.8],
    'Penang' => ['MYS', 5.4, 100.3],
    'Sarawak (Kuching)' => ['MYS', 1.55, 110.35],
    'Sabah interior' => ['MYS', 5.5, 116.8],
    'Labuan' => ['MYS', 5.28, 115.24],
]);

test('Indonesia and the Philippines have many separate parts', function () {
    $data = json_decode(file_get_contents(config('disaster_sources.country.boundaries_file')), true);

    expect(count($data['countries']['IDN']['coordinates']))->toBeGreaterThan(100)
        ->and(count($data['countries']['PHL']['coordinates']))->toBeGreaterThan(50);
});

test('points clear of a border resolve, points within 2 km of a border do not', function () {
    expect(($this->iso3)(6.66, 100.32))->toBe('THA')       // Padang Besar (Thailand)
        ->and(($this->iso3)(-8.95, 125.08))->toBe('TLS');  // Batugade (Timor-Leste)

    foreach ([
        'Poipet (Cambodia/Thailand)' => [13.66, 102.56],
        'Nong Khai (Laos/Thailand)' => [17.88, 102.74],
        'Mae Sai (Myanmar/Thailand)' => [20.43, 99.88],
        'Entikong (Indonesia/Malaysia)' => [0.9, 110.6],
        'Oecusse (Indonesia/Timor-Leste)' => [-9.35, 124.4],
    ] as $name => [$lat, $lng]) {
        $result = $this->resolver->resolve(null, $lat, $lng);

        expect($result->status)->toBe('unresolved', $name)
            ->and($result->countryId)->toBeNull()
            ->and($result->note)->toContain('border');
    }
});

test('places outside ASEAN are never assigned to a country', function (string $expected, float $lat, float $lng) {
    $result = $this->resolver->resolve(null, $lat, $lng);

    expect($result->status)->toBe($expected)->and($result->countryId)->toBeNull();
})->with([
    'Beijing' => ['outside_region', 39.9, 116.4],
    'Tokyo' => ['outside_region', 35.7, 139.7],
    'Taipei' => ['outside_region', 25.03, 121.56],
    'Dhaka (near Myanmar)' => ['unresolved', 23.8, 90.4],
    'Kunming (near Laos/Vietnam)' => ['unresolved', 25.04, 102.7],
    'Darwin (near Indonesia)' => ['unresolved', -12.46, 130.84],
]);

test('points in the sea are not assigned to a country', function (string $expected, float $lat, float $lng) {
    expect($this->resolver->resolve(null, $lat, $lng)->status)->toBe($expected);
})->with([
    'Java Sea' => ['unresolved', -5.0, 110.0],
    'Andaman Sea' => ['unresolved', 10.0, 95.0],
    'Pacific east of the Philippines' => ['unresolved', 8.0, 135.0],
    'Central South China Sea' => ['outside_region', 12.0, 114.0],
    'Open Indian Ocean' => ['outside_region', -15.0, 90.0],
]);

test('a coastal city can sit just outside the generalised 1:10m outline', function () {
    // Kota Kinabalu is about 2.5 km off Natural Earth's Sabah coast. Documented limit (data README, HLD §12.5).
    expect($this->resolver->resolve(null, 5.98, 116.07)->status)->toBe('unresolved')
        ->and($this->resolver->resolve('MYS', 5.98, 116.07)->countryId)->toBe(Country::where('iso3', 'MYS')->value('id'));
});
