<?php

namespace App\Services;

use App\Jobs\CalculateAirportTiers;
use App\Models\Airport;
use App\Models\InternationalDestination;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds a directed, weighted departure -> arrival traffic graph from the
 * trailing CalculateAirportTiers::WINDOW_WEEKS of completed flights, and
 * answers "how plausible is a connection from A to B" via a maximum-
 * bottleneck ("widest path") search: the strongest available connection's
 * weakest link, not a sum or product of hops. A→B→C is only as good as its
 * worst leg, and when several connecting paths exist, the best one wins -
 * e.g. if B→D sees 20 flights/8wk and C→D sees 35, an A→D passenger should
 * be routed via whichever of B/C makes the stronger connection.
 *
 * Paths are capped at config('passengers.max_hops') edges. A destination
 * only "reachable" via a longer chain of historical connections isn't a
 * realistic itinerary - a passenger only ever plans one real hop at a time
 * (see PassengerBoardingEngine), so proving a route exists on paper via 5
 * chained connections that have never actually been flown back-to-back is
 * no evidence anyone could really make that trip. Beyond that cap, a
 * destination is simply treated as unreachable.
 *
 * A leg into or out of a curated international_destinations hub only
 * contributes an edge if its VATPAC-side airport is flagged
 * is_international_gateway (see computeEdgeWeights) - since every path to
 * an international destination necessarily ends with such a leg, this is
 * enough to guarantee any itinerary reaching one always transits a real
 * international gateway, without the widest-path search itself needing to
 * know or care about path membership.
 *
 * An airport below config('passengers.hop_min_tier') (a quieter tier -
 * numerically higher) is never used as an intermediate hop in any path,
 * though it remains perfectly valid as a path's start or end (see
 * hopEligibleIcaos). A quiet Tier 4/5 field having one or two strong edges
 * is common but not evidence it functions as a real connecting point - it's
 * evidence people fly there and back, not that a passenger could plausibly
 * transit through it to somewhere else entirely. Without this, a stray
 * data quirk (or just a small field's only two routes happening to align)
 * can make an implausible connection look "reachable", e.g. a passenger
 * routed via a remote Pacific strip purely because it happens to have
 * decent edges on both sides.
 *
 * An edge used to EXTEND a path past its first leg must itself carry at
 * least config('passengers.min_connection_flights') flights over the
 * trailing window (see extendByOneHop) - a leg only ever observed once or
 * twice isn't a connection a passenger could actually expect to catch, it's
 * a one-off. This never applies to a path's first leg (direct 1-hop edges
 * are always accepted regardless of count - a route that's ever been flown
 * at all is a real destination in its own right, not a connection anyone
 * has to plan around), only to the flight someone would need to catch
 * AFTER already arriving somewhere - e.g. YMML->YPAD->YPDN needs YPAD->YPDN
 * specifically to clear the threshold, even though YMML->YPAD does not,
 * while YMML->YPDN flown directly is accepted unconditionally.
 *

 * This is the single source of truth for route plausibility, shared by
 * GeneratePassengerItineraries (destination sampling) and
 * PassengerBoardingEngine (connection scoring) - the algorithm must never
 * be reimplemented in either place.
 */
class TrafficGraphService
{
    // Cheap enough to recompute fresh once daily (see refresh()); cached in
    // between for the boarding engine's frequent per-departure lookups.
    private const CACHE_TTL_SECONDS = 1800;

    // ICAO PANS-ATM's standard placeholder for "aerodrome not listed" in a
    // flight plan (vatSys shows this when a pilot's filed airport isn't
    // recognised) - not a real airport, so a leg touching it is dropped
    // entirely rather than letting it become a graph node passengers could
    // be routed to.
    private const UNKNOWN_AERODROME_ICAO = 'ZZZZ';

    private const EDGE_CACHE_KEY = 'traffic-graph:edge-weights:v1';

    private const MATRIX_CACHE_KEY = 'traffic-graph:widest-path:v1';

    private const HOP_MATRIX_CACHE_KEY = 'traffic-graph:widest-path-by-hop:v1';

    private const HOP_ELIGIBLE_CACHE_KEY = 'traffic-graph:hop-eligible-icaos:v1';

    /** @var array<string, array<string, int>>|null */
    private ?array $edgeWeights = null;

    /** @var array<string, array<string, int>>|null */
    private ?array $matrix = null;

    /** @var array<int, array<string, array<string, int>>>|null */
    private ?array $matrixByHop = null;

    /** @var array<string, true>|null */
    private ?array $hopEligibleIcaos = null;

    /**
     * @return array<string, array<string, int>> [fromIcao => [toIcao => flight count]] over the trailing window.
     */
    public function edgeWeights(): array
    {
        return $this->edgeWeights ??= Cache::remember(
            self::EDGE_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeEdgeWeights()
        );
    }

    /**
     * @return array<string, array<string, int>> Full all-pairs widest-path matrix. Absent/0 = unreachable.
     */
    public function widestPathMatrix(): array
    {
        return $this->matrix ??= Cache::remember(
            self::MATRIX_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeWidestPathMatrix($this->edgeWeights())
        );
    }

    public function widestPath(string $from, string $to): int
    {
        return $this->widestPathMatrix()[$from][$to] ?? 0;
    }

    /**
     * @return array<string, int> Every icao reachable from $from, valued by widest-path weight.
     */
    public function reachableDestinations(string $from): array
    {
        return $this->widestPathMatrix()[$from] ?? [];
    }

    /**
     * @return array<int, array<string, array<string, int>>> [hopCount => matrix], hopCount from 1 to config('passengers.max_hops'). Each matrix is cumulative - matrix[2] already contains every entry matrix[1] has, etc. (see computeWidestPathMatrix).
     */
    public function widestPathMatrixByHopCount(): array
    {
        return $this->matrixByHop ??= Cache::remember(
            self::HOP_MATRIX_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeWidestPathMatrixByHopCount($this->edgeWeights())
        );
    }

    /**
     * Every icao reachable from $from, bucketed by the SMALLEST number of
     * hops needed to reach it (1..config('passengers.max_hops')) - not by traffic-graph score.
     * A destination reachable via both a weak direct edge and a much
     * stronger 2-hop connection is still bucketed at 1 hop, since a real
     * single-flight option genuinely exists even though it isn't the
     * highest-scoring one. Used by GeneratePassengerItineraries to target a
     * realistic split of direct/one-stop/two-stop itineraries
     * (config('passengers.hop_distribution')) rather than letting the raw
     * traffic-graph score alone decide, which tends to favour whichever
     * hop count happens to have the strongest evidence regardless of how
     * many flights a passenger would actually need.
     *
     * @return array<int, array<string, int>> [hopCount => [icao => widest-path weight at that hop count]]
     */
    public function reachableDestinationsByHopCount(string $from): array
    {
        $byHop = [];
        $seen = [];

        foreach ($this->widestPathMatrixByHopCount() as $hop => $matrix) {
            $bucket = [];

            foreach ($matrix[$from] ?? [] as $icao => $weight) {
                if ($weight > 0 && ! isset($seen[$icao])) {
                    $bucket[$icao] = $weight;
                    $seen[$icao] = true;
                }
            }

            $byHop[$hop] = $bucket;
        }

        return $byHop;
    }

    /**
     * Same bounded widest-path search as widestPathMatrix(), but with one
     * node barred from ever being used as an intermediate hop. Used when
     * scoring a candidate flight for a passenger who is already sitting at
     * $exclude: routing back through the airport someone is already at is
     * never a real step forward, even when the graph can technically piece
     * one together, and a mega-hub's edges reappearing after any 1-hop
     * detour is exactly what makes almost anything look "reachable" from
     * one of the hub's own spokes (e.g. a passenger at YMML boarding a
     * flight to YMHB purely because YMHB<->YMML is a strong edge and YMML
     * itself reaches everywhere - a pointless round trip, not progress).
     * Uncached (the excluded node changes per boarding decision) but cheap:
     * this graph is small and the configured hop cap keeps the relaxation bounded.
     *
     * @return array<string, array<string, int>>
     */
    public function widestPathMatrixExcluding(string $exclude): array
    {
        return $this->computeWidestPathMatrix($this->edgeWeights(), $exclude);
    }

    /**
     * Forces a fresh recompute of both the edge weights and the widest-path
     * matrix, bypassing and repopulating the cache. Cheap at this graph's
     * size (a few hundred airports, sparsely connected) - called at the
     * start of every daily generation run so it never plans against a stale
     * (up to 30-minute-old) view of the network.
     */
    public function refresh(): void
    {
        $this->edgeWeights = $this->computeEdgeWeights();
        $this->hopEligibleIcaos = $this->computeHopEligibleIcaos();
        $this->matrix = $this->computeWidestPathMatrix($this->edgeWeights);
        $this->matrixByHop = $this->computeWidestPathMatrixByHopCount($this->edgeWeights);

        Cache::put(self::EDGE_CACHE_KEY, $this->edgeWeights, self::CACHE_TTL_SECONDS);
        Cache::put(self::HOP_ELIGIBLE_CACHE_KEY, $this->hopEligibleIcaos, self::CACHE_TTL_SECONDS);
        Cache::put(self::MATRIX_CACHE_KEY, $this->matrix, self::CACHE_TTL_SECONDS);
        Cache::put(self::HOP_MATRIX_CACHE_KEY, $this->matrixByHop, self::CACHE_TTL_SECONDS);
    }

    /**
     * @return array<string, true> Every airport at or above config('passengers.hop_min_tier') (numerically <=), keyed by icao.
     */
    private function hopEligibleIcaos(): array
    {
        return $this->hopEligibleIcaos ??= Cache::remember(
            self::HOP_ELIGIBLE_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => $this->computeHopEligibleIcaos()
        );
    }

    /**
     * @return array<string, true>
     */
    private function computeHopEligibleIcaos(): array
    {
        return Airport::whereNotNull('tier')
            ->where('tier', '<=', config('passengers.hop_min_tier'))
            ->pluck('icao')
            ->flip()
            ->all();
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function computeEdgeWeights(): array
    {
        $since = Carbon::now()->subWeeks(CalculateAirportTiers::WINDOW_WEEKS);

        // dep/arr are already post-InternationalDestinationRouter values, so
        // the graph automatically only contains VATPAC airports plus curated
        // international hubs - no separate node-universe filtering needed
        // for domestic traffic. A leg touching an international destination
        // is filtered separately below.
        $rows = DB::table('flights')
            ->where('landed_at', '>=', $since)
            ->whereNotNull('dep')
            ->whereNotNull('arr')
            ->whereColumn('dep', '!=', 'arr')
            ->where('dep', '!=', self::UNKNOWN_AERODROME_ICAO)
            ->where('arr', '!=', self::UNKNOWN_AERODROME_ICAO)
            ->select('dep', 'arr', DB::raw('count(*) as cnt'))
            ->groupBy('dep', 'arr')
            ->get();

        $internationalIcaos = InternationalDestination::pluck('icao')->flip()->all();
        $gatewayIcaos = Airport::where('is_international_gateway', true)->pluck('icao')->flip()->all();

        $weights = [];

        foreach ($rows as $row) {
            $cnt = (int) $row->cnt;

            // A leg touching a curated international destination only
            // enters the graph if its VATPAC side is a flagged
            // international-gateway airport - VATSIM lets anyone file an
            // "international" flight from any strip, which isn't realistic
            // and would otherwise let a tiny regional airport look like a
            // legitimate jumping-off point for an overseas itinerary. A
            // leg between two international destinations (no VATPAC side
            // at all) is untouched by this rule.
            $depIsIntl = isset($internationalIcaos[$row->dep]);
            $arrIsIntl = isset($internationalIcaos[$row->arr]);

            if ($depIsIntl !== $arrIsIntl) {
                $vatpacSide = $depIsIntl ? $row->arr : $row->dep;

                if (! isset($gatewayIcaos[$vatpacSide])) {
                    continue;
                }
            }

            // Real-world routes are normally flown both ways even though
            // VATSIM's random traffic can easily record only one direction
            // for a long time (e.g. YSSY->YBBN observed, YBBN->YSSY not
            // yet) - an observed A->B leg counts as equal evidence that
            // B->A is just as viable a connection, so a quiet airport isn't
            // wrongly treated as a dead end just because nobody's happened
            // to fly out of it yet. Both directions accumulate independently
            // observed counts rather than one overwriting the other.
            $weights[$row->dep][$row->arr] = ($weights[$row->dep][$row->arr] ?? 0) + $cnt;
            $weights[$row->arr][$row->dep] = ($weights[$row->arr][$row->dep] ?? 0) + $cnt;
        }

        return $weights;
    }

    /**
     * Bounded-relaxation widest path: rather than summing edge weights and
     * keeping the minimum-cost path, this keeps the maximum, over every path
     * of at most config('passengers.max_hops') edges, of the minimum edge weight along that path.
     * A direct edge is itself a 1-hop path. Built by repeatedly calling
     * extendByOneHop - see its docblock for how a single relaxation step
     * works.
     *
     * $excludeMid, when given, is never used as an intermediate hop (see
     * widestPathMatrixExcluding) - it can still appear as a path's starting
     * node or as a destination, just never as a waypoint in between.
     *
     * @param  array<string, array<string, int>>  $edges
     * @return array<string, array<string, int>>
     */
    private function computeWidestPathMatrix(array $edges, ?string $excludeMid = null): array
    {
        $dist = $edges;
        $hopEligible = $this->hopEligibleIcaos();

        for ($hop = 2; $hop <= config('passengers.max_hops'); $hop++) {
            $dist = $this->extendByOneHop($dist, $edges, $hopEligible, $excludeMid);
        }

        return $dist;
    }

    /**
     * Same bounded-relaxation search as computeWidestPathMatrix, but
     * returning every intermediate step instead of only the final one - see
     * reachableDestinationsByHopCount for why GeneratePassengerItineraries
     * needs the by-hop-count breakdown rather than just the merged result.
     *
     * @param  array<string, array<string, int>>  $edges
     * @return array<int, array<string, array<string, int>>>
     */
    private function computeWidestPathMatrixByHopCount(array $edges): array
    {
        $dist = $edges;
        $hopEligible = $this->hopEligibleIcaos();
        $byHop = [1 => $dist];

        for ($hop = 2; $hop <= config('passengers.max_hops'); $hop++) {
            $dist = $this->extendByOneHop($dist, $edges, $hopEligible);
            $byHop[$hop] = $dist;
        }

        return $byHop;
    }

    /**
     * One relaxation step of the bounded widest-path search: extends every
     * path in $dist (built from at most N-1 edges) by exactly one more
     * edge, returning the widest-path matrix for at most N edges - carrying
     * forward shorter paths unchanged (extending is never forced) is what
     * keeps a direct or short connection from being displaced by a longer
     * one that happens to still qualify.
     *
     * $hopEligible restricts which nodes may ever serve as the extending
     * pivot ($mid) - an airport below config('passengers.hop_min_tier')
     * never counts as a real connecting point (see class docblock), even
     * though it remains valid as $dist's own starting node or as a final
     * destination.
     *
     * $midToJ (the edge actually being added to extend the path) must also
     * clear config('passengers.min_connection_flights') - see class
     * docblock. This is checked only on the newly-extending edge, never on
     * $viaMid (the path so far): $viaMid is either a raw 1-hop edge (exempt,
     * it's the path's first/direct leg) or was itself already required to
     * clear the threshold in a previous call to this method, so a single
     * check here is sufficient to enforce it across the whole path by
     * induction - every edge except the very first is guaranteed to have
     * passed through this exact check at the hop where it was added.
     *
     * @param  array<string, array<string, int>>  $dist
     * @param  array<string, array<string, int>>  $edges
     * @param  array<string, true>  $hopEligible
     * @return array<string, array<string, int>>
     */
    private function extendByOneHop(array $dist, array $edges, array $hopEligible, ?string $excludeMid = null): array
    {
        $next = $dist;
        $minConnectionFlights = config('passengers.min_connection_flights');

        foreach ($edges as $mid => $outEdges) {
            if ($mid === $excludeMid || ! isset($hopEligible[$mid])) {
                continue;
            }

            foreach ($dist as $i => $viaMidRow) {
                if ($i === $mid) {
                    continue;
                }

                $viaMid = $viaMidRow[$mid] ?? 0;

                if ($viaMid <= 0) {
                    continue;
                }

                foreach ($outEdges as $j => $midToJ) {
                    if ($j === $i || $j === $mid || $midToJ < $minConnectionFlights) {
                        continue;
                    }

                    $candidate = min($viaMid, $midToJ);

                    if ($candidate > ($next[$i][$j] ?? 0)) {
                        $next[$i][$j] = $candidate;
                    }
                }
            }
        }

        return $next;
    }
}
