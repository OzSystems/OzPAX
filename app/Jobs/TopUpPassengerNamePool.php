<?php

namespace App\Jobs;

use App\Models\PassengerName;
use App\Services\PassengerNamePool;
use App\Services\RandomUserNameProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Tops the passenger_names pool up toward config('passengers.name_pool.target')
 * whenever the available (not in_use) count drops below the low-water mark.
 * Runs as a daily safety net (see routes/console.php) in addition to being
 * dispatched opportunistically from GeneratePassengerItineraries. Fails
 * soft - if randomuser.me is unreachable, this just logs and does nothing,
 * since generation must never block waiting on the name API.
 */
class TopUpPassengerNamePool implements ShouldQueue
{
    use Queueable;

    public $timeout = 60;

    public $tries = 1;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('top-up-passenger-name-pool'))->dontRelease()->expireAfter(60)];
    }

    public function handle(RandomUserNameProvider $provider, PassengerNamePool $pool): void
    {
        $available = $pool->availableCount();
        $total = $pool->totalCount();
        $lowWaterMark = config('passengers.name_pool.low_water_mark');
        $target = config('passengers.name_pool.target');

        if ($available >= $lowWaterMark || $total >= $target) {
            Log::info(sprintf(
                'TopUpPassengerNamePool: no top-up needed - pool at %d/%d (%d available).',
                $total,
                $target,
                $available
            ));

            return;
        }

        $want = min(config('passengers.name_pool.batch_size'), $target - $total);
        $names = $provider->fetchNames($want);

        if ($names === []) {
            Log::warning('TopUpPassengerNamePool: fetchNames returned nothing (API unreachable?) - pool left unchanged.');

            return;
        }

        $now = Carbon::now();

        foreach (array_chunk($names, 500) as $chunk) {
            PassengerName::insert(array_map(fn (array $name) => [
                'full_name' => $name['full_name'],
                'gender' => $name['gender'],
                'nationality' => $name['nationality'],
                'source' => 'randomuser.me',
                'in_use' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        Log::info(sprintf(
            'TopUpPassengerNamePool: pool now %d/%d (%d available), added %d.',
            $pool->totalCount(),
            $target,
            $pool->availableCount(),
            count($names)
        ));
    }
}
