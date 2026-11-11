<?php

namespace App\Services\Disaster\Sync;

use App\Models\Country;
use App\Models\CountryBoundary;

/**
 * Resolves the ASEAN country of an event (HLD §12.1), in this order:
 *
 * 1. source_code: the source's own ISO3 is one of our countries
 * 2. adm0_lookup: the point lies inside exactly one ADM0 outline and is not near another country's border
 * 3. linked_source: a linked record (a GDACS match for a USGS earthquake) supplies the country
 * 4. otherwise unresolved, or outside_region when the point is far from every ASEAN outline
 *
 * A country is never guessed from place text.
 */
class CountryResolver
{
    /**
     * @var array<string, int>|null iso3 => country id
     */
    private ?array $countryIds = null;

    /**
     * @var array<int, string>
     */
    private array $countryNames = [];

    /**
     * @var array<int, array{id: int, min_lat: float, max_lat: float, min_lng: float, max_lng: float, polygons: array<int, mixed>}>|null
     */
    private ?array $boundaries = null;

    /**
     * Forget loaded data, so a long-running worker picks up reseeded countries and boundaries.
     */
    public function flush(): void
    {
        $this->countryIds = null;
        $this->countryNames = [];
        $this->boundaries = null;
    }

    public function resolve(?string $sourceIso3, ?float $latitude, ?float $longitude, ?int $linkedCountryId = null): CountryResolution
    {
        $this->load();

        $iso3 = strtoupper(trim((string) $sourceIso3));

        if ($iso3 !== '' && isset($this->countryIds[$iso3])) {
            return CountryResolution::resolved($this->countryIds[$iso3], CountryResolution::SOURCE_CODE);
        }

        $lookup = $this->lookup($latitude, $longitude);

        if ($lookup->isResolved()) {
            return $lookup;
        }

        if ($linkedCountryId !== null) {
            return CountryResolution::resolved($linkedCountryId, CountryResolution::LINKED_SOURCE);
        }

        return $lookup;
    }

    private function lookup(?float $latitude, ?float $longitude): CountryResolution
    {
        if ($latitude === null || $longitude === null) {
            return CountryResolution::unresolved('No coordinates to look up.');
        }

        if ($this->boundaries === []) {
            return CountryResolution::unresolved('No country boundary data is loaded.');
        }

        $guardKm = (float) config('disaster_sources.country.border_guard_km', 2);
        $outsideKm = (float) config('disaster_sources.country.outside_region_km', 300);

        $containing = [];
        $nearest = INF;

        foreach ($this->boundaries as $boundary) {
            $nearest = min($nearest, Geometry::distanceToBoxKm(
                $longitude, $latitude, $boundary['min_lng'], $boundary['max_lng'], $boundary['min_lat'], $boundary['max_lat'],
            ));

            foreach ($boundary['polygons'] as $polygon) {
                if (Geometry::polygonContains($polygon, $longitude, $latitude)) {
                    $containing[$boundary['id']] = $boundary;

                    break;
                }
            }
        }

        if (count($containing) > 1) {
            return CountryResolution::unresolved('Inside more than one country outline: '.$this->names(array_keys($containing)).'.');
        }

        if (count($containing) === 1) {
            $ownId = array_key_first($containing);
            $near = $this->nearOtherCountries($ownId, $latitude, $longitude, $guardKm);

            if ($near !== []) {
                return CountryResolution::unresolved('Near the border between '.$this->names([$ownId, ...$near]).'.');
            }

            return CountryResolution::resolved($ownId, CountryResolution::ADM0_LOOKUP);
        }

        if ($nearest > $outsideKm) {
            return CountryResolution::outsideRegion('More than '.(int) $outsideKm.' km from every ASEAN country.');
        }

        return CountryResolution::unresolved('Outside ASEAN land boundaries.');
    }

    /**
     * @return array<int, int> ids of other countries whose outline is within the guard distance
     */
    private function nearOtherCountries(int $ownId, float $latitude, float $longitude, float $guardKm): array
    {
        $near = [];

        foreach ($this->boundaries ?? [] as $boundary) {
            if ($boundary['id'] === $ownId) {
                continue;
            }

            if (Geometry::distanceToBoxKm($longitude, $latitude, $boundary['min_lng'], $boundary['max_lng'], $boundary['min_lat'], $boundary['max_lat']) > $guardKm) {
                continue;
            }

            foreach ($boundary['polygons'] as $polygon) {
                if (Geometry::distanceToPolygonKm($polygon, $longitude, $latitude) <= $guardKm) {
                    $near[] = $boundary['id'];

                    break;
                }
            }
        }

        return $near;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function names(array $ids): string
    {
        return collect($ids)->map(fn (int $id): string => $this->countryNames[$id] ?? (string) $id)->sort()->implode(' and ');
    }

    private function load(): void
    {
        if ($this->countryIds !== null) {
            return;
        }

        $countries = Country::query()->where('is_active', true)->get(['id', 'iso3', 'name']);

        $this->countryIds = $countries->pluck('id', 'iso3')->map(fn ($id): int => (int) $id)->all();
        $this->countryNames = $countries->pluck('name', 'id')->all();

        $this->boundaries = CountryBoundary::query()
            ->whereIn('country_id', $countries->pluck('id'))
            ->get()
            ->map(fn (CountryBoundary $boundary): array => [
                'id' => (int) $boundary->country_id,
                'min_lat' => (float) $boundary->min_lat,
                'max_lat' => (float) $boundary->max_lat,
                'min_lng' => (float) $boundary->min_lng,
                'max_lng' => (float) $boundary->max_lng,
                'polygons' => Geometry::polygons((array) json_decode($boundary->geojson, true)),
            ])
            ->all();
    }
}
