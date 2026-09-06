<?php

use App\Http\Controllers\MapController;
use App\Jobs\GeneratePassengerItineraries;
use App\Jobs\RecordVatsimFlights;
use App\Models\Flight;
use App\Models\FlightSession;
use App\Models\Passenger;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

Route::get('/credits', function () {
    return view('credits');
})->name('credits');

Route::get('/flights/live', [MapController::class, 'live'])->name('live');
Route::get('/flights/live/flights', [MapController::class, 'liveFlights'])->name('live.flights');
Route::get('/flights/live/airports', [MapController::class, 'liveAirports'])->name('live.airports');
Route::get('/flights/live/passengers', [MapController::class, 'livePassengers'])->name('live.passengers');
Route::get('/flights/live/most-travelled', [MapController::class, 'liveMostTravelled'])->name('live.most-travelled');
Route::get('/flights/live/passengers/{passenger}', [MapController::class, 'passengerDetail'])->name('live.passengers.show');

Route::get('/flights/fir-boundaries', [MapController::class, 'firBoundaries'])->name('fir-boundaries');

Route::get('/flights/past', [MapController::class, 'pastFlights'])->name('past-flights');
Route::get('/flights/past/airports', [MapController::class, 'pastAirports'])->name('past-flights.airports');
Route::get('/flights/past/routes', [MapController::class, 'pastRoutes'])->name('past-flights.routes');
Route::get('/flights/past/changes', [MapController::class, 'changes'])->name('past-flights.changes');
Route::post('/flights/past/changes/recalculate', [MapController::class, 'recalculateChanges'])->name('past-flights.changes.recalculate');

Route::get('/flights/recent', [MapController::class, 'recent'])->name('recent');
Route::get('/flights/recent/airports', [MapController::class, 'recentAirports'])->name('recent.airports');
Route::get('/flights/recent/routes', [MapController::class, 'recentRoutes'])->name('recent.routes');

Route::get('/flights/heatmap', [MapController::class, 'heatmap'])->name('heatmap');

// Local-only trigger to run the VATSIM ingest on demand without waiting for
// the schedule - not exposed outside local so it can't be hit in production.
Route::get('/dev/record-vatsim-flights', function () {
    abort_unless(app()->environment('local'), 404);

    $before = ['sessions' => FlightSession::count(), 'flights' => Flight::count()];

    RecordVatsimFlights::dispatchSync();

    $after = ['sessions' => FlightSession::count(), 'flights' => Flight::count()];

    return response()->json([
        'before' => $before,
        'after' => $after,
        'flights_recorded_this_run' => $after['flights'] - $before['flights'],
    ]);
});

// Local-only trigger to run the daily passenger generation on demand.
Route::get('/dev/generate-passenger-itineraries', function () {
    abort_unless(app()->environment('local'), 404);

    $before = Passenger::count();

    GeneratePassengerItineraries::dispatchSync();

    return response()->json([
        'passengers_before' => $before,
        'passengers_after' => Passenger::count(),
    ]);
});
