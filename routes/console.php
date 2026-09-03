<?php

use App\Jobs\RecordVatsimFlights;
use App\Jobs\SyncAirports;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RecordVatsimFlights)->everyFifteenSeconds();
Schedule::job(new SyncAirports)->daily();
