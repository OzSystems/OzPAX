<?php

namespace App\Jobs;

use App\Models\Airport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sorts every airport into one of 5 traffic tiers by its total departures +
 * arrivals over the trailing 8-week window, so passenger itinerary
 * generation can weight airport choice toward where the network actually
 * flies. Tier is fully recalculated (not incrementally adjusted) each run -
 * scheduled weekly (see routes/console.php for the exact day/time).
 */
class CalculateAirportTiers implements ShouldQueue
{
    use Queueable;

    public $timeout = 120;

    public $tries = 1;

    // Tier 1 is reserved for Australia's four primary international
    // gateways, regardless of their movement count.
    private const TIER_1_ICAOS = ['YMML', 'YSSY', 'YBBN', 'YPPH'];

    // The trailing window, in weeks, that movements are summed over. Public
    // so other places needing the same "recent" window (e.g. the Recent map)
    // can stay in sync with this without duplicating the number.
    public const WINDOW_WEEKS = 8;

    // Minimum 8-week movement count (departures + arrivals) to qualify for
    // each tier - edit these numbers to retune the tiers. Checked top to
    // bottom; an airport gets the first tier whose minimum it meets or
    // exceeds. Tier 1 isn't listed here - it's the reserved airports above,
    // regardless of movement count. Anything below TIER_5 is impossible
    // (0 is the floor), so tier 5 is simply "didn't qualify for 2-4".
    private const TIER_2_MIN_MOVEMENTS = 500;

    private const TIER_3_MIN_MOVEMENTS = 200;

    private const TIER_4_MIN_MOVEMENTS = 20;

    public function handle(): void
    {
        $since = Carbon::now()->subWeeks(self::WINDOW_WEEKS);

        $depCounts = DB::table('flights')
            ->where('landed_at', '>=', $since)
            ->whereNotNull('dep')
            ->select('dep', DB::raw('count(*) as cnt'))
            ->groupBy('dep')
            ->pluck('cnt', 'dep');

        $arrCounts = DB::table('flights')
            ->where('landed_at', '>=', $since)
            ->whereNotNull('arr')
            ->select('arr', DB::raw('count(*) as cnt'))
            ->groupBy('arr')
            ->pluck('cnt', 'arr');

        // Always include the reserved Tier 1 airports, even if they had zero
        // movements in the window - they're structurally Tier 1 regardless.
        $icaos = $depCounts->keys()->merge($arrCounts->keys())->merge(self::TIER_1_ICAOS)->unique();

        $now = Carbon::now();
        $tierCounts = array_fill(1, 5, 0);

        foreach (Airport::whereIn('icao', $icaos)->get() as $airport) {
            $movements = ($depCounts[$airport->icao] ?? 0) + ($arrCounts[$airport->icao] ?? 0);
            $tier = $this->tierFor($airport->icao, $movements);

            $airport->update([
                'movements_8w' => $movements,
                'tier' => $tier,
                'tier_calculated_at' => $now,
            ]);

            $tierCounts[$tier]++;
        }

        // Everything else (no movements at all in the window) resets to the
        // bottom tier, rather than keeping a stale tier from a busier
        // 8-week-plus-old window.
        $stale = Airport::whereNotIn('icao', $icaos)
            ->where(fn ($q) => $q->where('movements_8w', '!=', 0)->orWhere('tier', '!=', 5));
        $staleCount = $stale->count();
        $stale->update(['movements_8w' => 0, 'tier' => 5, 'tier_calculated_at' => $now]);
        $tierCounts[5] += $staleCount;

        Log::info(sprintf(
            'CalculateAirportTiers: %d airports had movements in the trailing %d weeks, %d reset to tier 5 - tier counts %s.',
            $icaos->count(),
            self::WINDOW_WEEKS,
            $staleCount,
            json_encode($tierCounts)
        ));
    }

    private function tierFor(string $icao, int $movements): int
    {
        if (in_array($icao, self::TIER_1_ICAOS, true)) {
            return 1;
        }

        return match (true) {
            $movements >= self::TIER_2_MIN_MOVEMENTS => 2,
            $movements >= self::TIER_3_MIN_MOVEMENTS => 3,
            $movements >= self::TIER_4_MIN_MOVEMENTS => 4,
            default => 5,
        };
    }
}
