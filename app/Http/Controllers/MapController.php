<?php

namespace App\Http\Controllers;

use App\Jobs\RecalculateFlightReroutes;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function liveFlights(): JsonResponse
    {
        $sessions = FlightSession::where('status', 'airborne')
            ->whereNotNull('lat')
            ->whereNotNull('lon')
            ->get(['callsign', 'dep', 'arr', 'aircraft_icao', 'altitude', 'groundspeed', 'heading', 'lat', 'lon']);

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
        $flights = Flight::whereNotNull('dep')->whereNotNull('arr')
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
     * using the (possibly rerouted) dep/arr, with a breakdown of any
     * originally-observed pairs that got rerouted into this one.
     */
    public function pastRoutes(): JsonResponse
    {
        $flights = Flight::whereNotNull('dep')->whereNotNull('arr')
            ->whereColumn('dep', '!=', 'arr')
            ->get(['dep', 'arr', 'original_dep', 'original_arr']);

        $routes = [];

        foreach ($flights as $flight) {
            $pair = [$flight->dep, $flight->arr];
            sort($pair);
            $key = implode('|', $pair);

            $routes[$key] ??= ['a' => $pair[0], 'b' => $pair[1], 'count' => 0, 'reroutes' => []];
            $routes[$key]['count']++;

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
                    'coordinates' => [
                        [(float) $coords[$r['a']]->lon, (float) $coords[$r['a']]->lat],
                        [(float) $coords[$r['b']]->lon, (float) $coords[$r['b']]->lat],
                    ],
                ],
                'properties' => [
                    'airport_a' => $r['a'],
                    'airport_b' => $r['b'],
                    'count' => $r['count'],
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
     * @param  \Illuminate\Support\Collection<int, string>|array<int, string>  $icaos
     * @return \Illuminate\Support\Collection<int, Airport>
     */
    private function airportsWithCoords($icaos)
    {
        // lat=0 AND lon=0 together is the "unresolved coordinates" sentinel
        // set by RecordVatsimFlights::resolveAirport() - exclude those.
        return Airport::whereIn('icao', $icaos)
            ->where(fn ($q) => $q->where('lat', '!=', 0)->orWhere('lon', '!=', 0))
            ->get(['icao', 'name', 'lat', 'lon']);
    }

    private function airportFeature(Airport $airport, array $properties): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float) $airport->lon, (float) $airport->lat],
            ],
            'properties' => array_merge(['icao' => $airport->icao, 'name' => $airport->name], $properties),
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
