<?php

namespace App\Services\Disaster\Sync;

/**
 * Small planar geometry helpers for ADM0 lookups. Coordinates are WGS84 [longitude, latitude].
 *
 * Polygons are lists of rings (the first is the outer ring, the rest are holes).
 */
final class Geometry
{
    private const KM_PER_DEGREE = 111.195;

    /**
     * Normalise a GeoJSON geometry (or Feature) into a list of polygons.
     *
     * @param  array<string, mixed>  $geometry
     * @return array<int, array<int, array<int, array{0: float, 1: float}>>>
     */
    public static function polygons(array $geometry): array
    {
        if (($geometry['type'] ?? null) === 'Feature' && is_array($geometry['geometry'] ?? null)) {
            $geometry = $geometry['geometry'];
        }

        $coordinates = $geometry['coordinates'] ?? [];

        return match ($geometry['type'] ?? null) {
            'Polygon' => [$coordinates],
            'MultiPolygon' => $coordinates,
            default => [],
        };
    }

    /**
     * @param  array<int, array<int, array{0: float, 1: float}>>  $polygon
     */
    public static function polygonContains(array $polygon, float $lng, float $lat): bool
    {
        foreach ($polygon as $index => $ring) {
            $inside = self::ringContains($ring, $lng, $lat);

            if ($index === 0 && ! $inside) {
                return false;
            }

            if ($index > 0 && $inside) {
                return false;
            }
        }

        return $polygon !== [];
    }

    /**
     * Ray casting.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    public static function ringContains(array $ring, float $lng, float $lat): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Distance in km from a point to the nearest edge of any ring of the polygon.
     *
     * @param  array<int, array<int, array{0: float, 1: float}>>  $polygon
     */
    public static function distanceToPolygonKm(array $polygon, float $lng, float $lat): float
    {
        $best = INF;
        $cos = cos(deg2rad($lat));

        foreach ($polygon as $ring) {
            for ($i = 1, $count = count($ring); $i < $count; $i++) {
                $best = min($best, self::segmentDistanceKm($ring[$i - 1], $ring[$i], $lng, $lat, $cos));
            }
        }

        return $best;
    }

    /**
     * Distance in km from a point to a lat/lng bounding box (0 when inside).
     */
    public static function distanceToBoxKm(float $lng, float $lat, float $minLng, float $maxLng, float $minLat, float $maxLat): float
    {
        $nearestLng = min(max($lng, $minLng), $maxLng);
        $nearestLat = min(max($lat, $minLat), $maxLat);

        $dx = ($lng - $nearestLng) * cos(deg2rad($lat)) * self::KM_PER_DEGREE;
        $dy = ($lat - $nearestLat) * self::KM_PER_DEGREE;

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private static function segmentDistanceKm(array $a, array $b, float $lng, float $lat, float $cos): float
    {
        $ax = ($a[0] - $lng) * $cos;
        $ay = $a[1] - $lat;
        $bx = ($b[0] - $lng) * $cos;
        $by = $b[1] - $lat;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lengthSquared = $dx * $dx + $dy * $dy;

        $t = $lengthSquared > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $lengthSquared)) : 0.0;

        return hypot($ax + $t * $dx, $ay + $t * $dy) * self::KM_PER_DEGREE;
    }
}
