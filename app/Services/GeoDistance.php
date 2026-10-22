<?php

namespace App\Services;

/**
 * Расстояния между точками. Сейчас Haversine; готов к road routing.
 */
class GeoDistance
{
    /**
     * @param  array{lat?: mixed, lng?: mixed}|null  $a
     * @param  array{lat?: mixed, lng?: mixed}|null  $b
     */
    public function km(?array $a, ?array $b): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }
        $lat1 = $a['lat'] ?? null;
        $lng1 = $a['lng'] ?? null;
        $lat2 = $b['lat'] ?? null;
        $lng2 = $b['lng'] ?? null;
        if (! is_numeric($lat1) || ! is_numeric($lng1) || ! is_numeric($lat2) || ! is_numeric($lng2)) {
            return null;
        }

        // distance_driver=road зарезервирован; пока fallback на haversine.
        return $this->haversine((float) $lat1, (float) $lng1, (float) $lat2, (float) $lng2);
    }

    public function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($h)));
    }
}
