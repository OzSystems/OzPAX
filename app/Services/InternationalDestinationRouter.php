<?php

namespace App\Services;

use App\Models\Airport;
use App\Models\InternationalDestination;
use App\Support\GreatCircle;

/**
 * Reroutes a non-VATPAC dep/arr airport to the nearest curated
 * international_destinations entry (e.g. a small/alternate field near
 * Singapore gets rerouted to WSSS), so overseas flights collapse onto a
 * manageable set of recognised gateway airports for future passenger
 * routing. VATPAC airports are never rerouted, and an airport that's already
 * itself a curated destination is left as-is.
 *
 * Shared by RecordVatsimFlights (rerouting newly-recorded flights) and
 * RecalculateFlightReroutes (re-applying the current destination list to
 * already-recorded flights, e.g. after adding new hubs).
 */
class InternationalDestinationRouter
{
    /** @var array<string, true> */
    private array $vatpacIcaos;

    /** @var array<string, array{lat: float, lon: float}> */
    private array $destinations;

    public function __construct()
    {
        $this->vatpacIcaos = Airport::where('is_vatpac', true)->pluck('icao')->flip()->all();

        $destIcaos = InternationalDestination::pluck('icao');

        $this->destinations = Airport::whereIn('icao', $destIcaos)
            ->get(['icao', 'lat', 'lon'])
            ->keyBy('icao')
            ->map(fn (Airport $a) => ['lat' => (float) $a->lat, 'lon' => (float) $a->lon])
            ->all();
    }

    /**
     * @param  array<string, array{lat: float, lon: float}>  $airportCoords  Coordinates for the airport being checked, keyed by icao.
     */
    public function reroute(?string $icao, array $airportCoords): ?string
    {
        if ($icao === null || isset($this->vatpacIcaos[$icao]) || isset($this->destinations[$icao])) {
            return $icao;
        }

        $coords = $airportCoords[$icao] ?? null;

        if ($coords === null || $this->destinations === []) {
            return $icao;
        }

        $nearestIcao = null;
        $nearestDistance = null;

        foreach ($this->destinations as $destIcao => $destCoords) {
            $distance = GreatCircle::distanceNm($coords['lat'], $coords['lon'], $destCoords['lat'], $destCoords['lon']);

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestIcao = $destIcao;
            }
        }

        return $nearestIcao ?? $icao;
    }
}
