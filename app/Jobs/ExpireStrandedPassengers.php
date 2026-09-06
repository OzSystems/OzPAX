<?php

namespace App\Jobs;

use App\Models\Passenger;
use App\Services\PassengerNamePool;
use App\Services\TrafficGraphService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Marks a waiting passenger stranded for either of two reasons: (1)
 * config('passengers.expiry_days') has passed with no movement
 * (last_movement_at never reset by a boarding or a landing - see
 * PassengerBoardingEngine), or (2) their destination has become
 * unreachable from wherever they currently are, under today's traffic-graph
 * rules (see TrafficGraphService).
 *
 * (2) exists because an itinerary that was genuinely reachable at
 * generation time can be retroactively invalidated by a later config
 * change (e.g. raising min_connection_flights or hop_min_tier tightens
 * what counts as a plausible connection) - GeneratePassengerItineraries
 * only ever checks reachability once, at creation, and never revisits it.
 * PassengerBoardingEngine already correctly refuses to ever board such a
 * passenger onto anything real (see discardDeadEndCandidates and
 * discardCandidatesFartherFromDestination), so leaving them "waiting"
 * for the full expiry window is pointless - if the graph proves the trip
 * can never happen at all, there's no reason to wait for the timer too.
 *
 * Stranding is what feeds the dampening signal GeneratePassengerItineraries
 * reads when sizing future demand at an airport that keeps failing to
 * move its passengers on.
 */
class ExpireStrandedPassengers implements ShouldQueue
{
    use Queueable;

    public $timeout = 60;

    public $tries = 1;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('expire-stranded-passengers'))->dontRelease()->expireAfter(60)];
    }

    public function handle(PassengerNamePool $namePool, TrafficGraphService $graph): void
    {
        $graph->refresh();

        $now = Carbon::now();
        $timedOut = 0;
        $unreachable = 0;

        foreach (Passenger::where('status', 'waiting')->get() as $passenger) {
            $isTimedOut = $passenger->expires_at !== null && $passenger->expires_at <= $now;
            $isUnreachable = $passenger->current_icao !== $passenger->destination_icao
                && $graph->widestPath($passenger->current_icao, $passenger->destination_icao) <= 0;

            if (! $isTimedOut && ! $isUnreachable) {
                continue;
            }

            $passenger->update(['status' => 'stranded']);
            $namePool->release($passenger);

            $isTimedOut ? $timedOut++ : $unreachable++;
        }

        Log::info(sprintf(
            'ExpireStrandedPassengers: stranded %d passengers (%d timed out, %d had an unreachable destination).',
            $timedOut + $unreachable,
            $timedOut,
            $unreachable
        ));
    }
}
