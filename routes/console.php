<?php

use App\Jobs\CalculateAirportTiers;
use App\Jobs\RecordVatsimFlights;
use App\Jobs\SyncAirports;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dispatched on the 'sync' connection (run inline, right here) rather than
// the app's default 'database' queue - there's no queue:work process to
// consume that queue, so a job pushed there would just sit unprocessed
// forever. Every other call site in the app already runs jobs via
// dispatchSync() for the same reason (see the /dev/record-vatsim-flights
// route and MapController::recalculateChanges).
Schedule::job(new RecordVatsimFlights, connection: 'sync')->everyFifteenSeconds();
Schedule::job(new SyncAirports, connection: 'sync')->daily();

// Rolling 8-week traffic tiers, recalculated weekly - see CalculateAirportTiers.
Schedule::job(new CalculateAirportTiers, connection: 'sync')->weeklyOn(5, '00:06');
