<?php

use App\Jobs\CalculateAirportTiers;
use App\Jobs\ExpireStrandedPassengers;
use App\Jobs\GeneratePassengerItineraries;
use App\Jobs\RecordVatsimFlights;
use App\Jobs\SyncAirports;
use App\Jobs\TopUpPassengerNamePool;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


// VATSIM Flight Data
Schedule::job(new RecordVatsimFlights)->everyFifteenSeconds();

// Airport Info
Schedule::job(new SyncAirports)->daily();

// Passenger Itinirary
Schedule::job(new TopUpPassengerNamePool)->hourlyAt('18');
Schedule::job(new CalculateAirportTiers)->hourlyAt('19');
Schedule::job(new ExpireStrandedPassengers)->hourlyAt('20');
Schedule::job(new GeneratePassengerItineraries)->hourlyAt('20');