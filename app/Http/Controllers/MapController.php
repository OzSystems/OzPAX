<?php

namespace App\Http\Controllers;

use App\Jobs\CalculateAirportTiers;
use App\Jobs\RecalculateFlightReroutes;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSession;
use App\Services\FirBoundaries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MapController extends Controller
{
    public function live()
    {
        return view('live');
    }

    public function pastFlights()
    {
        return view('past-flights');
    }

    /**
     * Same shape as the All History map, but windowed to the trailing
     * 8 weeks (the same window CalculateAirportTiers uses) rather than
     * all-time, and with tier-styled airport icons matching the live map.
     */
    public function recent()
    {
        return view('recent');
    }

    public function heatmap()
    {
        return view('heatmap');
    }

    /**
     * VATSpy's FIR/sector boundary polygons, filtered down to the VATPAC
     * division only - the FIRs actually shown on our maps - rather than the
     * full ~2MB worldwide dataset (see FirBoundaries for the fetch/cache).
     */
    public function firBoundaries(FirBoundaries $firBoundaries): JsonResponse
    {
        $features = array_values(array_filter(
            $firBoundaries->geoJson()['features'],
            fn (array $feature) => ($feature['properties']['division'] ?? null) === 'VATPAC'
        ));

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    public function liveFlights(): JsonResponse
    {
        $sessions = FlightSession::where('status', 'airborne')
            ->whereNotNull('lat')
            ->whereNotNull('lon')
            ->get(['callsign', 'dep', 'arr', 'aircraft_icao', 'altitude', 'groundspeed', 'heading', 'lat', 'lon', 'connected_on_ground']);

        $features = $sessions->map(fn (FlightSession $session) => [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float) $session->lon, (float) $session->lat],
            ],
            'properties' => [
                'callsign' => $session->callsign,
                'dep' => $session->dep,
                'arr' => $session->arr,
                'aircraft' => $session->aircraft_icao,
                'altitude' => $session->altitude,
                'groundspeed' => $session->groundspeed,
                'heading' => (float) ($session->heading ?? 0),
                'connected_on_ground' => (bool) $session->connected_on_ground,
            ],
        ])->values();

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    /**
     * Per-airport counts of currently in-progress sessions (on the ground
     * awaiting departure, or airborne) - how much live traffic is
     * going/coming from each airport right now.
     */
    public function liveAirports(): JsonResponse
    {
        $depCounts = FlightSession::whereNotNull('dep')->select('dep', DB::raw('count(*) as cnt'))->groupBy('dep')->pluck('cnt', 'dep');
        $arrCounts = FlightSession::whereNotNull('arr')->select('arr', DB::raw('count(*) as cnt'))->groupBy('arr')->pluck('cnt', 'arr');

        return $this->airportFeatureCollection($depCounts, $arrCounts);
    }

    /**
     * Per-airport counts of completed, historically-recorded flights, using
     * the (possibly rerouted) dep/arr - not the originally-observed values.
     */
    public function pastAirports(): JsonResponse
    {
        return $this->airportStats(null);
    }

    /**
     * Same as pastAirports, windowed to the trailing 8 weeks - see recent().
     */
    public function recentAirports(): JsonResponse
    {
        return $this->airportStats($this->recentSince());
    }

    private function airportStats(?Carbon $since): JsonResponse
    {
        $flights = Flight::whereNotNull('dep')->whereNotNull('arr')
            ->when($since, fn ($q) => $q->where('landed_at', '>=', $since))
            ->get(['dep', 'arr', 'original_dep', 'original_arr']);

        $stats = [];

        foreach ($flights as $flight) {
            $stats[$flight->dep] ??= ['dep' => 0, 'arr' => 0, 'depRerouted' => 0, 'arrRerouted' => 0];
            $stats[$flight->dep]['dep']++;

            if ($flight->original_dep !== null && $flight->original_dep !== $flight->dep) {
                $stats[$flight->dep]['depRerouted']++;
            }

            $stats[$flight->arr] ??= ['dep' => 0, 'arr' => 0, 'depRerouted' => 0, 'arrRerouted' => 0];
            $stats[$flight->arr]['arr']++;

            if ($flight->original_arr !== null && $flight->original_arr !== $flight->arr) {
                $stats[$flight->arr]['arrRerouted']++;
            }
        }

        $airports = $this->airportsWithCoords(array_keys($stats));

        $features = $airports->map(function (Airport $airport) use ($stats) {
            $s = $stats[$airport->icao];

            return $this->airportFeature($airport, [
                'departures' => $s['dep'],
                'arrivals' => $s['arr'],
                'total' => $s['dep'] + $s['arr'],
                'departuresRerouted' => $s['depRerouted'],
                'arrivalsRerouted' => $s['arrRerouted'],
            ]);
        })->values();

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    /**
     * One LineString per unique airport pair (both directions combined),
     * using the (possibly rerouted) dep/arr, with a directional breakdown
     * (a->b vs b->a) and a breakdown of any originally-observed pairs that
     * got rerouted into this one.
     */
    public function pastRoutes(): JsonResponse
    {
        return $this->routeStats(null);
    }

    /**
     * Same as pastRoutes, windowed to the trailing 8 weeks - see recent().
     */
    public function recentRoutes(): JsonResponse
    {
        return $this->routeStats($this->recentSince());
    }

    private function routeStats(?Carbon $since): JsonResponse
    {
        $flights = Flight::whereNotNull('dep')->whereNotNull('arr')
            ->whereColumn('dep', '!=', 'arr')
            ->when($since, fn ($q) => $q->where('landed_at', '>=', $since))
            ->get(['dep', 'arr', 'original_dep', 'original_arr']);

        $routes = [];

        foreach ($flights as $flight) {
            $pair = [$flight->dep, $flight->arr];
            sort($pair);
            $key = implode('|', $pair);

            $routes[$key] ??= ['a' => $pair[0], 'b' => $pair[1], 'count' => 0, 'aToB' => 0, 'bToA' => 0, 'reroutes' => []];
            $routes[$key]['count']++;
            $routes[$key][$flight->dep === $routes[$key]['a'] ? 'aToB' : 'bToA']++;

            $originalDep = $flight->original_dep ?? $flight->dep;
            $originalArr = $flight->original_arr ?? $flight->arr;

            if ($originalDep !== $flight->dep || $originalArr !== $flight->arr) {
                $origPair = [$originalDep, $originalArr];
                sort($origPair);
                $origKey = implode('|', $origPair);

                if ($origKey !== $key) {
                    $routes[$key]['reroutes'][$origKey] ??= ['a' => $origPair[0], 'b' => $origPair[1], 'count' => 0];
                    $routes[$key]['reroutes'][$origKey]['count']++;
                }
            }
        }

        $icaos = collect($routes)->flatMap(fn ($r) => [$r['a'], $r['b']])->unique();
        $coords = $this->airportsWithCoords($icaos)->keyBy('icao');

        $features = collect($routes)
            ->filter(fn ($r) => $coords->has($r['a']) && $coords->has($r['b']))
            ->map(fn ($r) => [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => $this->antimeridianSafeLine(
                        (float) $coords[$r['a']]->lon,
                        (float) $coords[$r['a']]->lat,
                        (float) $coords[$r['b']]->lon,
                        (float) $coords[$r['b']]->lat,
                    ),
                ],
                'properties' => [
                    'airport_a' => $r['a'],
                    'airport_b' => $r['b'],
                    'count' => $r['count'],
                    'a_to_b' => $r['aToB'],
                    'b_to_a' => $r['bToA'],
                    'reroutes' => array_values($r['reroutes']),
                ],
            ])
            ->values();

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    /**
     * Monitoring page: how many flights have had their dep/arr rerouted,
     * grouped by (original icao -> current icao).
     */
    public function changes()
    {
        $flights = Flight::where(function ($q) {
            $q->whereColumn('original_dep', '!=', 'dep')->orWhereColumn('original_arr', '!=', 'arr');
        })->get(['dep', 'arr', 'original_dep', 'original_arr']);

        $changes = [];

        foreach ($flights as $flight) {
            if ($flight->original_dep !== null && $flight->original_dep !== $flight->dep) {
                $key = "{$flight->original_dep}|{$flight->dep}";
                $changes[$key] ??= ['from' => $flight->original_dep, 'to' => $flight->dep, 'count' => 0];
                $changes[$key]['count']++;
            }

            if ($flight->original_arr !== null && $flight->original_arr !== $flight->arr) {
                $key = "{$flight->original_arr}|{$flight->arr}";
                $changes[$key] ??= ['from' => $flight->original_arr, 'to' => $flight->arr, 'count' => 0];
                $changes[$key]['count']++;
            }
        }

        $changes = collect($changes)->sortByDesc('count')->values();

        return view('past-flights-changes', ['changes' => $changes]);
    }

    /**
     * Re-applies the current international_destinations list to every
     * already-recorded flight, so adding/removing curated hubs takes effect
     * on historical data too, not just newly-recorded flights.
     */
    public function recalculateChanges(): RedirectResponse
    {
        RecalculateFlightReroutes::dispatchSync();

        return redirect()->route('past-flights.changes')->with('status', 'Reroutes recalculated against the current international destinations list.');
    }

    /**
     * The cutoff used by the Recent map - kept in lockstep with
     * CalculateAirportTiers's own window rather than a separately
     * hardcoded number, so "recent" always means the same thing as a
     * airport's tier.
     */
    private function recentSince(): Carbon
    {
        return Carbon::now()->subWeeks(CalculateAirportTiers::WINDOW_WEEKS);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>|array<int, string>  $icaos
     * @return \Illuminate\Support\Collection<int, Airport>
     */
    private function airportsWithCoords($icaos)
    {
        // lat=0 AND lon=0 together is the "unresolved coordinates" sentinel
        // set by RecordVatsimFlights::resolveAirport() - exclude those.
        return Airport::whereIn('icao', $icaos)
            ->where(fn ($q) => $q->where('lat', '!=', 0)->orWhere('lon', '!=', 0))
            ->get(['icao', 'name', 'lat', 'lon', 'tier', 'movements_8w']);
    }

    /**
     * A straight two-point line, with the second point's longitude shifted
     * by a multiple of 360 so it's within 180deg of the first. Mapbox GL
     * draws LineStrings using the raw longitudes given, so a route between
     * e.g. lon 179 and lon -179 (a short hop across the antimeridian) would
     * otherwise be drawn the "long way" - stretching across the entire map.
     */
    private function antimeridianSafeLine(float $lonA, float $latA, float $lonB, float $latB): array
    {
        $lonB -= round(($lonB - $lonA) / 360) * 360;

        return [[$lonA, $latA], [$lonB, $latB]];
    }

    private function airportFeature(Airport $airport, array $properties): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float) $airport->lon, (float) $airport->lat],
            ],
            'properties' => array_merge([
                'icao' => $airport->icao,
                'name' => $airport->name,
                'tier' => $airport->tier,
                'movements_8w' => $airport->movements_8w,
            ], $properties),
        ];
    }

    private function airportFeatureCollection($depCounts, $arrCounts): JsonResponse
    {
        $icaos = $depCounts->keys()->merge($arrCounts->keys())->unique();
        $airports = $this->airportsWithCoords($icaos);

        $features = $airports->map(function (Airport $airport) use ($depCounts, $arrCounts) {
            $dep = $depCounts[$airport->icao] ?? 0;
            $arr = $arrCounts[$airport->icao] ?? 0;

            return $this->airportFeature($airport, [
                'departures' => $dep,
                'arrivals' => $arr,
                'total' => $dep + $arr,
            ]);
        })->values();

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }
}
