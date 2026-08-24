<?php

namespace App\Console\Commands;

use App\Jobs\SyncAirports as SyncAirportsJob;
use App\Models\Airport;
use Illuminate\Console\Command;

class SyncAirports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'airports:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync the airports table from the VATSpy data project, tagging airports within a VATPAC-jurisdiction FIR';

    public function handle(): int
    {
        $this->info('Syncing airports from VATSpy...');

        SyncAirportsJob::dispatchSync();

        $this->info(sprintf('Done. %d airports flagged is_vatpac.', Airport::where('is_vatpac', true)->count()));

        return self::SUCCESS;
    }
}
