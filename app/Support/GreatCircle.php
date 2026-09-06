<?php

namespace App\Support;

/**
 * Haversine great-circle distance in nautical miles - shared by every
 * service that reasons about airport-to-airport range (proximity checks,
 * nearest-hub lookups, "is this actually closer" comparisons), so the
 * formula only ever needs to be correct in one place.
 */
class GreatCircle
{
    private const EARTH_RADIUS_NM = 3440.065;

    public static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_NM * $c;
    }
}
