<?php

use App\Models\Country;
use App\Models\CountryBoundary;
use App\Services\Disaster\Sync\CountryResolution;
use App\Services\Disaster\Sync\CountryResolver;
use Database\Seeders\CountryBoundarySeeder;

/**
 * @param  array<string, mixed>  $geometry
 */
function countryWithBoundary(string $iso3, array $geometry, array $box): Country
{
    $country = Country::factory()->create(['iso3' => $iso3, 'name' => "Country {$iso3}"]);

    CountryBoundary::factory()->create([
        'country_id' => $country->id,
        'geojson' => json_encode($geometry),
        'min_lng' => $box[0], 'max_lng' => $box[1], 'min_lat' => $box[2], 'max_lat' => $box[3],
    ]);

    return $country;
}

/**
 * @return array<string, mixed>
 */
function square(float $minLng, float $maxLng, float $minLat, float $maxLat): array
{
    return ['type' => 'Polygon', 'coordinates' => [[[$minLng, $minLat], [$maxLng, $minLat], [$maxLng, $maxLat], [$minLng, $maxLat], [$minLng, $minLat]]]];
}

beforeEach(function () {
    // A: lng 0..10, B: lng 10.5..20 (about 55 km apart), both lat 0..10.
    $this->a = countryWithBoundary('AAA', square(0, 10, 0, 10), [0, 10, 0, 10]);
    $this->b = countryWithBoundary('BBB', square(10.5, 20, 0, 10), [10.5, 20, 0, 10]);
    $this->resolver = new CountryResolver;
});

test('a valid source ISO3 wins without looking at coordinates', function () {
    $result = $this->resolver->resolve('bbb', 5.0, 5.0);

    expect($result->status)->toBe('resolved')
        ->and($result->method)->toBe('source_code')
        ->and($result->countryId)->toBe($this->b->id);
});

test('an unknown source ISO3 falls through to the coordinate lookup', function () {
    $result = $this->resolver->resolve('CHN', 5.0, 5.0);

    expect($result->method)->toBe('adm0_lookup')
        ->and($result->countryId)->toBe($this->a->id);
});

test('a point inside exactly one outline resolves by adm0_lookup (longitude, latitude order)', function () {
    // lat 2, lng 15 is inside B. Swapped axes (lat 15, lng 2) would be outside both.
    $result = $this->resolver->resolve(null, 2.0, 15.0);

    expect($result->countryId)->toBe($this->b->id)
        ->and($result->method)->toBe('adm0_lookup');
});

test('a point near another country border is unresolved and names both countries', function () {
    // 0.2 deg (about 22 km) apart is fine; shrink the gap to 1 km for this case.
    CountryBoundary::query()->where('country_id', $this->b->id)->update([
        'geojson' => json_encode(square(10.005, 20, 0, 10)),
        'min_lng' => 10.005,
    ]);

    $result = (new CountryResolver)->resolve(null, 5.0, 9.999);

    expect($result->status)->toBe('unresolved')
        ->and($result->countryId)->toBeNull()
        ->and($result->note)->toContain('Country AAA')->toContain('Country BBB')->toContain('border');
});

test('a point more than the guard distance from the other country still resolves', function () {
    expect($this->resolver->resolve(null, 5.0, 9.0)->countryId)->toBe($this->a->id);
});

test('a point inside two overlapping outlines is unresolved', function () {
    CountryBoundary::query()->where('country_id', $this->b->id)->update([
        'geojson' => json_encode(square(9, 20, 0, 10)),
        'min_lng' => 9,
    ]);

    $result = (new CountryResolver)->resolve(null, 5.0, 9.5);

    expect($result->status)->toBe('unresolved')->and($result->note)->toContain('more than one');
});

test('holes are excluded and multipolygons are searched', function () {
    $donut = ['type' => 'MultiPolygon', 'coordinates' => [
        [
            [[30, 30], [40, 30], [40, 40], [30, 40], [30, 30]],
            [[33, 33], [37, 33], [37, 37], [33, 37], [33, 33]],
        ],
        [[[50, 30], [55, 30], [55, 35], [50, 35], [50, 30]]],
    ]];
    $c = countryWithBoundary('CCC', $donut, [30, 55, 30, 40]);
    $resolver = new CountryResolver;

    expect($resolver->resolve(null, 31.0, 31.0)->countryId)->toBe($c->id)
        ->and($resolver->resolve(null, 32.0, 52.0)->countryId)->toBe($c->id)
        ->and($resolver->resolve(null, 35.0, 35.0)->countryId)->toBeNull();
});

test('a point near the ASEAN outlines but inside none is unresolved, a far one is outside_region', function () {
    $near = $this->resolver->resolve(null, 5.0, 22.0);
    $far = $this->resolver->resolve(null, 5.0, 100.0);

    expect($near->status)->toBe('unresolved')->and($near->note)->toContain('Outside ASEAN land')
        ->and($far->status)->toBe('outside_region');
});

test('a linked source country is used only when the coordinate lookup fails', function () {
    $offshore = $this->resolver->resolve(null, 5.0, 22.0, $this->b->id);
    $inside = $this->resolver->resolve(null, 5.0, 5.0, $this->b->id);

    expect($offshore->method)->toBe('linked_source')->and($offshore->countryId)->toBe($this->b->id)
        ->and($inside->method)->toBe('adm0_lookup')->and($inside->countryId)->toBe($this->a->id);
});

test('missing coordinates, or no boundary data, never guess a country', function () {
    CountryBoundary::query()->delete();

    $resolver = new CountryResolver;

    expect($resolver->resolve(null, null, null)->note)->toContain('No coordinates')
        ->and($resolver->resolve(null, 5.0, 5.0)->note)->toContain('No country boundary data')
        ->and($resolver->resolve(null, 5.0, 5.0)->status)->toBe(CountryResolution::UNRESOLVED);
});

test('the boundary seeder imports a file, computes the box and is idempotent', function () {
    CountryBoundary::query()->delete();

    $path = tempnam(sys_get_temp_dir(), 'adm0');
    file_put_contents($path, json_encode([
        'meta' => ['source' => 'Test source', 'version' => '1', 'downloaded_at' => '2026-11-10', 'license' => 'Public domain', 'simplify_tolerance' => 0.01],
        'countries' => ['AAA' => square(1, 9, 2, 8), 'ZZZ' => square(0, 1, 0, 1)],
    ]));
    config(['disaster_sources.country.boundaries_file' => $path]);

    $this->seed(CountryBoundarySeeder::class);
    $this->seed(CountryBoundarySeeder::class);

    $boundary = CountryBoundary::first();

    expect(CountryBoundary::count())->toBe(1)
        ->and($boundary->country_id)->toBe($this->a->id)
        ->and((float) $boundary->min_lng)->toBe(1.0)
        ->and((float) $boundary->max_lat)->toBe(8.0)
        ->and($boundary->vertex_count)->toBe(5)
        ->and($boundary->source)->toBe('Test source')
        ->and($boundary->imported_at)->not->toBeNull();

    unlink($path);
});

test('the boundary seeder skips quietly when the file is absent', function () {
    CountryBoundary::query()->delete();
    config(['disaster_sources.country.boundaries_file' => '/nonexistent/adm0.json']);

    $this->seed(CountryBoundarySeeder::class);

    expect(CountryBoundary::count())->toBe(0);
});
