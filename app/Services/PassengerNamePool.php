<?php

namespace App\Services;

use App\Models\Passenger;
use App\Models\PassengerName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes the passenger name pool's claim/release lifecycle, so
 * GeneratePassengerItineraries, PassengerBoardingEngine, and
 * ExpireStrandedPassengers never duplicate this logic. A name is claimed
 * when a passenger is generated and released once that passenger's own
 * record reaches a terminal state (completed or stranded) - never tied to
 * the 7-day expiry timer itself, which is a separate concern.
 */
class PassengerNamePool
{
    /**
     * Atomically claims up to $count available names, marking them in_use.
     * Returns fewer than $count (possibly zero) if the pool doesn't have
     * enough available - callers must handle a passenger getting no name.
     *
     * @return Collection<int, PassengerName>
     */
    public function claimBatch(int $count): Collection
    {
        if ($count <= 0) {
            return new Collection;
        }

        return DB::transaction(function () use ($count) {
            $ids = PassengerName::where('in_use', false)
                ->orderBy('id')
                ->limit($count)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return new Collection;
            }

            PassengerName::whereIn('id', $ids)->update(['in_use' => true]);

            return PassengerName::whereIn('id', $ids)->get();
        });
    }

    public function release(Passenger $passenger): void
    {
        if ($passenger->name_id === null) {
            return;
        }

        PassengerName::where('id', $passenger->name_id)->update(['in_use' => false]);
    }

    public function availableCount(): int
    {
        return PassengerName::where('in_use', false)->count();
    }

    public function totalCount(): int
    {
        return PassengerName::count();
    }
}
