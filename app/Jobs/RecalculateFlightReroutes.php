<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\Flight;
use App\Services\InternationalDestinationRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-applies the current international_destinations list to every already
 * recorded flight - run this after adding/removing curated hubs so existing
 * flights reflect the new options, not just newly-recorded ones. Always
 * reroutes from each flight's true original_dep/original_arr (never from an
 * already-rerouted value), and backfills original_dep/original_arr for old
 * flights recorded before that tracking existed.
 */
class RecalculateFlightReroutes implements ShouldQueue
{
    use Queueable;

    public $timeout = 120;

    public $tries = 1;

    public function handle(): void
    {
        $router = new InternationalDestinationRouter;

        $airportCoords = Airport::all(['icao', 'lat', 'lon'])
            ->keyBy('icao')
            ->map(fn (Airport $a) => ['lat' => (float) $a->lat, 'lon' => (float) $a->lon])
            ->all();

        $updated = 0;

        Flight::query()->chunkById(500, function ($flights) use ($router, $airportCoords, &$updated) {
            foreach ($flights as $flight) {
                $baseDep = $flight->original_dep ?? $flight->dep;
                $baseArr = $flight->original_arr ?? $flight->arr;

                $newDep = $router->reroute($baseDep, $airportCoords);
                $newArr = $router->reroute($baseArr, $airportCoords);

                $changed = $newDep !== $flight->dep
                    || $newArr !== $flight->arr
                    || $flight->original_dep === null
                    || $flight->original_arr === null;

                if ($changed) {
                    $flight->dep = $newDep;
                    $flight->arr = $newArr;
                    $flight->original_dep = $baseDep;
                    $flight->original_arr = $baseArr;
                    $flight->save();
                    $updated++;
                }
            }
        });

        Log::info("RecalculateFlightReroutes: recalculated reroutes for {$updated} flight(s).");
    }
}
