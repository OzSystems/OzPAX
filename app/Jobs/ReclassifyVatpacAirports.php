<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Services\FirBoundaries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Airports resolved on the fly via Airlabs (see RecordVatsimFlights::resolveAirport)
 * used to always be flagged is_vatpac=false, regardless of where they actually
 * are - only airports present in VATSpy's own dataset (fir_code non-null) got a
 * real is_vatpac/fir_code. This one-off sweep re-checks every such airport's
 * coordinates against the VATPAC FIR boundaries and fixes the flag/fir_code.
 */
class ReclassifyVatpacAirports implements ShouldQueue
{
    use Queueable;

    public $timeout = 120;

    public $tries = 1;

    public function handle(FirBoundaries $firBoundaries): void
    {
        $airports = Airport::whereNull('fir_code')->get();
        $changed = 0;

        foreach ($airports as $airport) {
            // lat=0 AND lon=0 together is the "unresolved coordinates" sentinel.
            if ((float) $airport->lat === 0.0 && (float) $airport->lon === 0.0) {
                continue;
            }

            $fir = $firBoundaries->findVatpacFir((float) $airport->lat, (float) $airport->lon);
            $shouldBeVatpac = $fir !== null;

            if ($airport->is_vatpac === $shouldBeVatpac && ! $shouldBeVatpac) {
                continue;
            }

            $airport->is_vatpac = $shouldBeVatpac;
            $airport->fir_code = $fir;
            $airport->save();
            $changed++;

            Log::info('ReclassifyVatpacAirports: '.$airport->icao.' is_vatpac='.($shouldBeVatpac ? 'true' : 'false').($fir ? " ({$fir})" : ''));
        }

        Log::info(sprintf(
            'ReclassifyVatpacAirports: checked %d Airlabs-resolved airports, reclassified %d.',
            $airports->count(),
            $changed
        ));
    }
}
