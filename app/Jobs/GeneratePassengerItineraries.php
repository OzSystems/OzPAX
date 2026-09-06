<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\InternationalDestination;
use App\Models\Passenger;
use App\Services\PassengerNamePool;
use App\Services\TrafficGraphService;
use App\Support\GreatCircle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily batch generation of new passenger itineraries - origin/destination
 * pairs only, never a precomputed route (see PassengerBoardingEngine for
 * how the actual journey is discovered live, one hop at a time). Runs once
 * a day at 18:00 UTC (4am AEST, see routes/console.php).
 *
 * Destinations are sampled per origin, weighted by TrafficGraphService's
 * widest-path score adjusted by config('passengers.destination_tier_weight')
 * - a route that's only plausible via a weak connection is much less likely
 * to be generated than one the network strongly supports, and the tier
 * multiplier lets a busy hub's raw traffic weight be dialled back so
 * generation doesn't pile almost every itinerary onto the same few busiest
 * airports. Volume is controlled by a combination of a hard global cap and
 * a per-aerodrome ceiling (config('passengers.tier_caps'), keyed by the
 * airport's own tier) on how many passengers may simultaneously be waiting
 * at that ONE airport - not shared across a tier's airports, so a busy
 * Tier 1 hub's own demand never eats into a quieter Tier 1 airport's
 * separate allowance. See below for how an itinerary's origin itself gets
 * chosen.
 *
 * `international_destinations` airports never originate itineraries -
 * VATSIM-Australia traffic only ever records one leg of an international
 * journey, so an itinerary starting there would almost never see a matching
 * outbound flight to actually board. They can still be sampled as a
 * destination like any other reachable node.
 *
 * Each of the `daily_generation_target` itineraries is generated one at a
 * time, independently drawing an origin (weighted by 8-week traffic ×
 * config('passengers.origin_tier_chance') for that origin's tier × recent-
 * stranding dampening) before drawing that itinerary's destination. Tier
 * preference is therefore applied PER ITINERARY, not as an up-front gate on
 * which airports even get to participate - an earlier version rolled one
 * coin flip per origin per run and gave every surviving origin its entire
 * deterministic quota, which meant a single small airport "winning" its
 * flip could dump an enormous, unrealistic quota onto one quiet field while
 * a major hub that "lost" its flip contributed nothing at all that run.
 * Per-itinerary weighted sampling is what actually behaves like traffic:
 * with 4000 independent draws, each origin's realised share converges on
 * its true relative weight (law of large numbers) instead of being an
 * all-or-nothing coin flip with only a handful of Tier 1 airports to spread
 * the risk across. Tier 5 is still the vast majority of airports on the
 * network and still gets a proportionally tiny weight per tier config, but
 * that weight is now spread thinly across every Tier 5 airport on every
 * run rather than concentrated onto whichever handful happen to win a
 * binary roll. This is independent of destination_tier_weight (which only
 * affects destination selection within a draw, not origin selection) and
 * of tier_caps (which limits how many may be waiting, not how draws are
 * weighted).
 *
 * Each generated passenger's destination is drawn from a hop-count bucket
 * (1/2/3 flights - see TrafficGraphService::reachableDestinationsByHopCount)
 * chosen per config('passengers.hop_distribution') before the usual
 * traffic-weighted pick happens within that bucket. Without this, most
 * itineraries would need only a single flight anyway (direct edges are
 * usually the strongest evidence), but leaving it entirely to raw score
 * gives no way to deliberately shape how many connections passengers
 * typically need - this makes "mostly direct trips, a meaningful minority
 * needing one connection, a rare few needing two" an explicit, tunable
 * target instead of an accident of the traffic data.
 */
class GeneratePassengerItineraries implements ShouldQueue
{
    use Queueable;

    public $timeout = 300;

    public $tries = 1;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('generate-passenger-itineraries'))->dontRelease()->expireAfter(600)];
    }

    public function handle(TrafficGraphService $graph, PassengerNamePool $namePool): void
    {
        $graph->refresh();

        $now = Carbon::now();
        $expiryDays = config('passengers.expiry_days');

        $airports = Airport::whereNotNull('icao')->get(['icao', 'name', 'tier', 'movements_8w', 'is_vatpac', 'lat', 'lon'])->keyBy('icao');
        $internationalIcaos = InternationalDestination::pluck('icao')->flip()->all();

        $headroomByIcao = $this->computeAirportHeadroom($airports);
        $globalHeadroom = config('passengers.global_cap') - Passenger::whereNotIn('status', ['completed', 'stranded'])->count();

        if ($globalHeadroom <= 0) {
            Log::info('GeneratePassengerItineraries: global cap already reached, skipping this run.');

            return;
        }

        // Only real VATPAC airspace airports ever originate itineraries -
        // see class docblock. A non-VATPAC airport (whether a curated
        // international_destinations hub or just some other airport that
        // picked up incidental movements_8w from a stray recorded flight,
        // e.g. a pilot filing a real-world overseas route that happens to
        // touch VATPAC airspace) is never a legitimate jumping-off point;
        // it remains eligible only as a sampled destination. is_vatpac
        // alone already excludes every curated international destination
        // (none of them are flagged VATPAC), but the explicit check is kept
        // for clarity and as a safety net. Heliports (and similar - see
        // isExcludedAerodromeType) never originate or receive an itinerary
        // at all, in either direction.
        $eligibleAirports = $airports->filter(fn (Airport $a) => ! $this->isExcludedAerodromeType($a));

        $origins = $eligibleAirports
            ->filter(fn (Airport $a) => $a->is_vatpac && $a->movements_8w > 0 && ! isset($internationalIcaos[$a->icao]));

        if ($origins->isEmpty()) {
            Log::info('GeneratePassengerItineraries: no eligible origins, nothing to generate.');

            return;
        }

        $dampeningWindow = config('passengers.stranding_dampening_window_days');
        $originTierChance = config('passengers.origin_tier_chance');

        // Every eligible origin's per-draw sampling weight (8-week traffic x
        // tier preference x recent-stranding dampening) and its hop-bucketed,
        // tier-weighted destination pool, computed once up front and reused
        // across however many of the target's independent draws happen to
        // land on that origin (see class docblock for why sampling replaces
        // the old per-origin coin flip + deterministic quota).
        $originWeights = [];
        $originDestinations = [];
        $unreachableOrigins = 0;

        foreach ($origins as $origin) {
            $destinationsByHop = array_map(
                fn (array $bucket) => $this->weightDestinationsByTier($bucket, $eligibleAirports),
                $this->excludeAerodromeTypesAndShortHops($graph->reachableDestinationsByHopCount($origin->icao), $origin, $airports)
            );

            if (array_sum(array_map('count', $destinationsByHop)) === 0) {
                $unreachableOrigins++;

                continue;
            }

            $strandedRecent = Passenger::where('current_icao', $origin->icao)
                ->where('status', 'stranded')
                ->where('updated_at', '>=', $now->copy()->subDays($dampeningWindow))
                ->count();

            $dampening = max(0.1, 1 / (1 + $strandedRecent));
            $tierChance = $originTierChance[$origin->tier] ?? 1.0;
            $weight = (int) round($origin->movements_8w * $tierChance * $dampening * 1000);

            if ($weight <= 0) {
                continue;
            }

            $originWeights[$origin->icao] = $weight;
            $originDestinations[$origin->icao] = $destinationsByHop;
        }

        if ($originWeights === []) {
            Log::info(sprintf(
                'GeneratePassengerItineraries: no origin had any usable destination (%d origins checked), nothing to generate.',
                $unreachableOrigins
            ));

            return;
        }

        $target = config('passengers.daily_generation_target');

        $generatedByTier = array_fill(1, 5, 0);
        $totalGenerated = 0;
        $attempts = 0;
        $maxAttempts = $target * 5;

        // Draw itineraries one at a time - each draw independently samples
        // an origin from $originWeights, so tier/traffic preference shapes
        // every single itinerary rather than an up-front pass/fail per
        // origin. An origin that turns out to have no headroom or no
        // destination left is dropped from the pool permanently (never
        // re-added), which bounds the loop by the number of origins even
        // though a "wasted" draw doesn't count toward $totalGenerated.
        while ($totalGenerated < $target && $globalHeadroom > 0 && $originWeights !== [] && $attempts < $maxAttempts) {
            $attempts++;

            $originIcao = $this->weightedRandomKey($originWeights);

            if ($originIcao === null) {
                break;
            }

            if (($headroomByIcao[$originIcao] ?? 0) <= 0) {
                unset($originWeights[$originIcao]);

                continue;
            }

            $hop = $this->pickHopBucket($originDestinations[$originIcao]);
            $destination = $hop !== null ? $this->weightedRandomKey($originDestinations[$originIcao][$hop]) : null;

            if ($destination === null) {
                unset($originWeights[$originIcao]);

                continue;
            }

            $name = $namePool->claimBatch(1)->first();

            Passenger::create([
                'name_id' => $name?->id,
                'origin_icao' => $originIcao,
                'destination_icao' => $destination,
                'current_icao' => $originIcao,
                'status' => 'waiting',
                'generated_at' => $now,
                'last_movement_at' => $now,
                'expires_at' => $now->copy()->addDays($expiryDays),
            ]);

            $headroomByIcao[$originIcao]--;
            $generatedByTier[$eligibleAirports[$originIcao]->tier]++;
            $globalHeadroom--;
            $totalGenerated++;
        }

        if ($namePool->availableCount() < config('passengers.name_pool.low_water_mark')) {
            TopUpPassengerNamePool::dispatch();
        }

        Log::info(sprintf(
            'GeneratePassengerItineraries: generated %d passengers (tier breakdown %s), %d origins had no usable destination, global active now %d.',
            $totalGenerated,
            json_encode($generatedByTier),
            $unreachableOrigins,
            Passenger::whereNotIn('status', ['completed', 'stranded'])->count()
        ));
    }

    /**
     * True if this airport's name marks it as a type never eligible for a
     * passenger itinerary (config('passengers.excluded_aerodrome_name_patterns')
     * - heliports and similar aren't part of the fixed-wing network this
     * simulates), regardless of its tier, traffic, or is_vatpac status.
     */
    private function isExcludedAerodromeType(Airport $airport): bool
    {
        $name = mb_strtolower((string) $airport->name);

        foreach (config('passengers.excluded_aerodrome_name_patterns') as $pattern) {
            if (str_contains($name, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drops, from every hop bucket, any destination that's an excluded
     * aerodrome type (see isExcludedAerodromeType) or closer than
     * config('passengers.min_itinerary_distance_nm') to $origin - a handful
     * of nautical miles isn't a realistic air-travel itinerary, it's two
     * nearby fields both happening to touch the traffic graph. A
     * destination $airports has no record for (e.g. a curated
     * international hub not loaded into this particular collection) is
     * kept rather than dropped, since "unknown" isn't evidence it should be
     * excluded.
     *
     * @param  array<int, array<string, int>>  $destinationsByHop
     * @param  \Illuminate\Support\Collection<string, Airport>  $airports
     * @return array<int, array<string, int>>
     */
    private function excludeAerodromeTypesAndShortHops(array $destinationsByHop, Airport $origin, $airports): array
    {
        $minDistance = config('passengers.min_itinerary_distance_nm');
        $originLat = (float) $origin->lat;
        $originLon = (float) $origin->lon;

        foreach ($destinationsByHop as $hop => $bucket) {
            $destinationsByHop[$hop] = array_filter($bucket, function ($weight, $icao) use ($airports, $minDistance, $originLat, $originLon) {
                $destination = $airports->get($icao);

                if ($destination === null) {
                    return true;
                }

                if ($this->isExcludedAerodromeType($destination)) {
                    return false;
                }

                $distance = GreatCircle::distanceNm($originLat, $originLon, (float) $destination->lat, (float) $destination->lon);

                return $distance >= $minDistance;
            }, ARRAY_FILTER_USE_BOTH);
        }

        return $destinationsByHop;
    }

    /**
     * Each airport's own allowance (config('passengers.tier_caps'), keyed by
     * that airport's tier) minus however many passengers are already
     * waiting there right now, PLUS however many are currently boarded with
     * that airport as their origin - a per-aerodrome ceiling, not a pool
     * shared across every airport of that tier, so one busy hub's demand
     * can never starve a quieter same-tier airport of its own separate
     * capacity.
     *
     * The boarded-at-origin reservation matters because
     * PassengerBoardingEngine::handleDisconnect sends a boarded passenger
     * straight back to origin_icao as 'waiting' the moment their flight
     * disconnects, at any time, with no warning. Counting only passengers
     * who happen to be 'waiting' at the exact moment a run starts
     * undercounts an airport's true claimed population - this job runs
     * hourly, so generation could otherwise keep topping an airport back up
     * to its cap every run while a growing number of that airport's
     * previously-boarded passengers sit in the air with nowhere reserved
     * for them, then all land back on 'waiting' (via disconnects) on top of
     * what generation already added, pushing the airport well past its
     * cap over successive runs. This is deliberately conservative - most
     * boarded passengers will actually complete their leg and move on
     * rather than disconnect, so it reserves more room than usually turns
     * out to be needed, but a firm cap that's occasionally slightly
     * under-utilised is the point, not an accident.
     *
     * @param  \Illuminate\Support\Collection<string, Airport>  $airports
     * @return array<string, int> Remaining capacity per airport icao, floored at 0.
     */
    private function computeAirportHeadroom($airports): array
    {
        $waiting = Passenger::query()
            ->where('status', 'waiting')
            ->select('current_icao', DB::raw('count(*) as cnt'))
            ->groupBy('current_icao')
            ->pluck('cnt', 'current_icao');

        $boardedByOrigin = Passenger::query()
            ->where('status', 'boarded')
            ->select('origin_icao', DB::raw('count(*) as cnt'))
            ->groupBy('origin_icao')
            ->pluck('cnt', 'origin_icao');

        $caps = config('passengers.tier_caps');
        $headroom = [];

        foreach ($airports as $icao => $airport) {
            $cap = $caps[$airport->tier] ?? end($caps);
            $occupied = (int) ($waiting[$icao] ?? 0) + (int) ($boardedByOrigin[$icao] ?? 0);
            $headroom[$icao] = max(0, $cap - $occupied);
        }

        return $headroom;
    }

    /**
     * Raises each candidate's raw traffic-graph weight to
     * config('passengers.destination_score_exponent') before multiplying by
     * its airport's configured tier weight (config('passengers.destination_tier_weight'))
     * and random selection. The exponent compresses the gap between a
     * dominant route and its runners-up while preserving their rank order -
     * e.g. at 0.25, a 6x raw-traffic edge (YSSY->YMML's 375 vs YSSY->YBBN's
     * 59) becomes only a ~1.6x weight edge, instead of carrying the full 6x
     * straight through into destination probability. Without it, a single
     * dominant real route linearly monopolises an origin's destination mix
     * (YMML alone was pulling ~64% of every YSSY-origin passenger, because
     * "6x more raw traffic" became "6x more likely" one-for-one) - realistic
     * ranking, but far too monotonous a simulation. A destination whose
     * airport record is missing (shouldn't happen - every node in the graph
     * comes from a recorded flight's dep/arr) falls back to tier 5's weight.
     *
     * Scaled by 1000 before rounding to an int (weightedRandomKey needs
     * integer weights) - after exponent dampening, scores cluster into a
     * narrow range (often single digits), and multiplying by a fractional
     * tier weight like 0.09 or 0.01 without this scaling rounds almost
     * everything down to 0, collapsing a hundred-candidate bucket down to
     * whichever one or two entries barely survived the rounding threshold -
     * which then get picked with effectively 100% probability, not
     * "weighted down." That's precisely what once let a single Tier 4
     * airport become the overwhelming top destination network-wide: it
     * wasn't outscoring anything, it was the sole survivor in its hop
     * bucket after rounding wiped out the other ~113 candidates. Weights
     * that still round down to zero even with the scaling (a true
     * zero-evidence entry) are dropped.
     *
     * @param  array<string, int>  $destinations
     * @param  \Illuminate\Support\Collection<string, Airport>  $airports
     * @return array<string, int>
     */
    private function weightDestinationsByTier(array $destinations, $airports): array
    {
        $tierWeights = config('passengers.destination_tier_weight');
        $exponent = config('passengers.destination_score_exponent');
        $weighted = [];

        foreach ($destinations as $icao => $weight) {
            $tier = $airports->get($icao)?->tier ?? 5;
            $dampened = $weight ** $exponent;
            $adjusted = (int) round($dampened * ($tierWeights[$tier] ?? 1.0) * 1000);

            if ($adjusted > 0) {
                $weighted[$icao] = $adjusted;
            }
        }

        return $weighted;
    }

    /**
     * Picks which hop-count bucket (1..config('passengers.max_hops')) this passenger's
     * destination should come from, per config('passengers.hop_distribution'),
     * restricted to buckets that actually have at least one destination for
     * this origin. A bucket with nothing in it is excluded rather than
     * wasting the roll on an empty result - the remaining buckets' relative
     * weights are preserved as-is (e.g. if the 2-hop bucket is empty, a
     * configured 60/30/10 split behaves as a 60/10 split between 1 and 3
     * hops, not as "try 2 hops, get nothing, stop").
     *
     * @param  array<int, array<string, int>>  $destinationsByHop
     */
    private function pickHopBucket(array $destinationsByHop): ?int
    {
        $weights = config('passengers.hop_distribution');
        $total = 0.0;

        foreach ($weights as $hop => $weight) {
            if (($destinationsByHop[$hop] ?? []) !== []) {
                $total += $weight;
            }
        }

        if ($total <= 0) {
            return null;
        }

        $r = mt_rand() / mt_getrandmax() * $total;
        $cumulative = 0.0;

        foreach ($weights as $hop => $weight) {
            if (($destinationsByHop[$hop] ?? []) === []) {
                continue;
            }

            $cumulative += $weight;

            if ($r <= $cumulative) {
                return $hop;
            }
        }

        // Floating-point rounding can leave $r a hair above every
        // cumulative sum - fall back to the last available bucket rather
        // than reporting "nothing to pick" when something clearly qualified.
        foreach (array_reverse(array_keys($weights)) as $hop) {
            if (($destinationsByHop[$hop] ?? []) !== []) {
                return $hop;
            }
        }

        return null;
    }

    /**
     * Picks a key from a [key => weight] map, weighted by its integer
     * weight. Returns null only if every weight is non-positive.
     *
     * @param  array<string, int>  $weights
     */
    private function weightedRandomKey(array $weights): ?string
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return null;
        }

        $r = mt_rand(1, $total);
        $cumulative = 0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;

            if ($r <= $cumulative) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
