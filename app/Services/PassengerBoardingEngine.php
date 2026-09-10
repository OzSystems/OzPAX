<?php

namespace App\Services;

use App\Models\AircraftType;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSession;
use App\Models\InternationalDestination;
use App\Models\Passenger;
use App\Models\PassengerFlightHistory;
use App\Support\GreatCircle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides, live, which waiting passengers board which real VATSIM flights -
 * called from three exact points in RecordVatsimFlights where it already
 * observes the relevant session state transitions. A passenger's itinerary
 * is only ever an origin/destination pair (see GeneratePassengerItineraries)
 * - the actual route is discovered here, one hop at a time: every time a
 * flight's manifest is decided, the decision only ever looks at flights
 * currently on the ground *right now* at that one airport, never planning
 * further ahead than the immediate next leg.
 *
 * Boarding lock timing: a session's manifest is decided once, at whichever
 * of two triggers fires first - the aircraft starting to move on the ground,
 * or the current time coming within config('passengers.boarding_lock_minutes_before_deptime')
 * minutes of its filed departure time - never at the moment liftoff is
 * witnessed (by then the aircraft may have been taxiing for minutes, too
 * late for a realistic boarding cutoff). See maybeLockBoarding().
 */
class PassengerBoardingEngine
{
    public function __construct(
        private readonly TrafficGraphService $trafficGraph,
        private readonly PassengerNamePool $namePool,
    ) {}

    /**
     * Checks whether this session's boarding should lock on this poll, and
     * if so, decides and commits its manifest. Returns whether a lock
     * happened (the caller is responsible for persisting boarding_locked_at
     * on the session - this method never saves the session itself).
     *
     * @param  array<string, array{lat: float, lon: float, altitude: ?int, altitude_checked_at: ?Carbon}>  $airportRecords
     */
    public function maybeLockBoarding(
        FlightSession $session,
        ?object $plan,
        int $groundspeed,
        string $reroutedDep,
        string $reroutedArr,
        InternationalDestinationRouter $router,
        array $airportRecords,
        Carbon $now
    ): bool {
        $moving = $groundspeed >= config('passengers.taxi_groundspeed_kt');
        $nearDeptime = $this->withinDeptimeWindow($plan->deptime ?? null, $now);

        if (! $moving && ! $nearDeptime) {
            return false;
        }

        $this->handleBoardingLock($session, $reroutedDep, $reroutedArr, $router, $airportRecords);

        return true;
    }

    /**
     * @param  array<string, array{lat: float, lon: float, altitude: ?int, altitude_checked_at: ?Carbon}>  $airportRecords
     */
    private function handleBoardingLock(
        FlightSession $session,
        string $reroutedDep,
        string $reroutedArr,
        InternationalDestinationRouter $router,
        array $airportRecords
    ): void {
        $waiting = Passenger::where('status', 'waiting')->where('current_icao', $reroutedDep)->get();

        if ($waiting->isEmpty()) {
            return;
        }

        $candidates = $this->buildCandidatePool($session, $reroutedDep, $reroutedArr, $router, $airportRecords);

        // Every waiting passenger here shares the same current_icao
        // ($reroutedDep), so this is computed once for the whole batch
        // rather than per passenger - see resolvePreferredCandidate.
        $graphExcludingOrigin = $this->trafficGraph->widestPathMatrixExcluding($reroutedDep);
        $internationalIcaos = InternationalDestination::pluck('icao')->flip()->all();
        $airportTiers = Airport::pluck('tier', 'icao');

        // How many real flights each waiting passenger has already taken on
        // their CURRENT itinerary - see resolvePreferredCandidate's
        // max_hops cap. A fresh batched count rather than a stored counter:
        // passenger_flight_history is the only durable record of this (see
        // handleLegCompleted's origin_icao docblock), and a passenger row is
        // never reused across itineraries, so a plain per-passenger count is
        // always exactly the current itinerary's leg count.
        $legsTakenByPassenger = PassengerFlightHistory::whereIn('passenger_id', $waiting->pluck('id'))
            ->select('passenger_id', DB::raw('count(*) as legs'))
            ->groupBy('passenger_id')
            ->pluck('legs', 'passenger_id');

        $terminusMatches = new Collection;
        $connections = new Collection;

        foreach ($waiting as $passenger) {
            $pick = $this->resolvePreferredCandidate($passenger, $candidates, $graphExcludingOrigin, $internationalIcaos, $airportTiers, $legsTakenByPassenger);

            if ($pick === null || $pick['session_id'] !== $session->id) {
                continue;
            }

            $pick['is_terminus'] ? $terminusMatches->push($passenger) : $connections->push($passenger);
        }

        if ($terminusMatches->isEmpty() && $connections->isEmpty()) {
            return;
        }

        $maxPax = AircraftType::where('icao_type', $session->aircraft_icao)->value('max_pax')
            ?? AircraftType::FALLBACK_MAX_PAX;

        $selected = $this->fillSeats($terminusMatches, $maxPax);
        $remainingSeats = $maxPax - $selected->count();

        if ($remainingSeats > 0) {
            $selected = $selected->merge($this->fillSeats($connections, $remainingSeats));
        }

        if ($selected->isEmpty()) {
            return;
        }

        $now = Carbon::now();

        Passenger::whereIn('id', $selected->pluck('id'))->update([
            'status' => 'boarded',
            'boarded_flight_session_id' => $session->id,
            'last_movement_at' => $now,
            'expires_at' => $now->copy()->addDays(config('passengers.expiry_days')),
        ]);
    }

    /**
     * Every other on-ground session departing from the same (rerouted)
     * airport, evaluated fresh on every call - no persisted preference
     * state, so this always reflects exactly what's on the ground right
     * now. Self-loops (a candidate arriving back at its own departure
     * airport) are excluded - never a useful connection.
     *
     * @param  array<string, array{lat: float, lon: float, altitude: ?int, altitude_checked_at: ?Carbon}>  $airportRecords
     * @return array<int, array{session_id: int, arr: string}>
     */
    private function buildCandidatePool(
        FlightSession $session,
        string $reroutedDep,
        string $reroutedArr,
        InternationalDestinationRouter $router,
        array $airportRecords
    ): array {
        $pool = [];

        if ($reroutedArr !== $reroutedDep) {
            $pool[] = ['session_id' => $session->id, 'arr' => $reroutedArr];
        }

        $siblings = FlightSession::where('status', 'on_ground')
            ->whereNotNull('dep')
            ->whereNotNull('arr')
            ->where('id', '!=', $session->id)
            ->get(['id', 'dep', 'arr']);

        foreach ($siblings as $sibling) {
            $siblingDep = $router->reroute($sibling->dep, $airportRecords);

            if ($siblingDep !== $reroutedDep) {
                continue;
            }

            $siblingArr = $router->reroute($sibling->arr, $airportRecords);

            if ($siblingArr !== null && $siblingArr !== $reroutedDep) {
                $pool[] = ['session_id' => $sibling->id, 'arr' => $siblingArr];
            }
        }

        return $pool;
    }

    /**
     * Rule 1: any candidate whose arrival is the passenger's final
     * destination wins unconditionally, no further comparison. Rules 2/3:
     * otherwise, score every candidate by widest-path from its arrival
     * toward the passenger's destination (the strongest connection's
     * weakest link) and take the highest; ties break on the lowest airport
     * tier among the tied candidates.
     *
     * Scoring uses $graphExcludingOrigin (widest path with the passenger's
     * own current airport barred from being an intermediate hop) rather
     * than the plain graph - otherwise a candidate that flies out to a
     * quiet spoke with one strong edge straight back to the current airport
     * scores exactly as well as the current airport itself for reaching
     * anywhere, since the graph can always "get back" for free.
     *
     * That alone isn't enough, though: a spoke can also have its own strong
     * edge to a *different* hub (e.g. YMHB connects solidly to both YMML
     * and YSSY), so excluding only the current airport still lets it
     * inherit that other hub's entire reach and score as if it were a real
     * connection. discardCandidatesFartherFromDestination is therefore
     * applied to every candidate up front, not just as a last resort: a
     * candidate that would leave the passenger farther (great-circle) from
     * their destination than staying put is never a genuine step forward,
     * no matter how strong its traffic-graph score - this is what let
     * passengers board flights like YMML->YMHB with 189 people whose
     * destinations were scattered across the planet, since Hobart's real
     * connection to Sydney "proves" a route toward nearly anywhere on
     * paper even though flying there first is a pure detour.
     *
     * Once no candidate is left standing after that gate, the tier
     * tie-break only fires among candidates with real historical evidence,
     * or - if every survivor still scores 0 - only when the destination is
     * entirely unreachable from the current airport via any route at all;
     * otherwise this returns null and the passenger simply keeps waiting
     * rather than taking an ungrounded "go to the biggest hub" guess. See
     * plan §4a.
     *
     * A candidate landing at a curated international_destinations hub is
     * additionally barred outright unless it's the passenger's exact
     * destination (already handled by rule 1). Every dep/arr the graph ever
     * sees has already been collapsed by InternationalDestinationRouter to
     * the nearest curated hub, so "the international_destination closest to
     * this candidate's arrival" is always just the candidate's arrival
     * itself - there's no real intra-international network data behind a
     * widest-path score connecting two different overseas hubs, only
     * incidental shared domestic connectivity (e.g. both happening to be
     * reachable from Perth). Without this, a passenger bound for Honolulu
     * could board a Perth-to-Singapore flight purely because the graph
     * can technically piece together *some* path between them via the
     * domestic network on either end.
     *
     * A destination normally served by a strong direct connection - either
     * config('passengers.direct_flight_threshold') or more flights on
     * record between current_icao and destination_icao, or a Tier 1 to
     * Tier 1 pair unconditionally (real-world trunk routes between major
     * hubs, e.g. YMML<->YPPH, are always operated direct - never routed via
     * a lesser city even if this particular 8-week window happened to
     * record a thin count) - is never worth a connection for. A passenger
     * on a route like that holds out for the direct flight rather than
     * accepting whatever connection scores best today, since one is
     * reliably going to show up. Checked with the raw edge weight
     * (edgeWeights(), a direct 1-hop count) rather than the widest-path
     * score, which could reflect an indirect route entirely.
     *
     * A passenger who has already taken config('passengers.max_hops') real
     * flights on this itinerary never boards another connection, no matter
     * how strong - only an exact-terminus match (rule 1) can still fire.
     * Without this, max_hops only bounds each individual boarding decision's
     * GRAPH search depth, not the passenger's cumulative real-world flight
     * count - a passenger who keeps finding a real (but wrong-direction)
     * connection at every stop could otherwise ride an unbounded number of
     * actual flights, since no single decision plans further than one hop
     * ahead. A passenger stuck here simply keeps waiting until either a
     * direct flight to their destination appears or they expire (see
     * ExpireStrandedPassengers) - the same fallback that already covers any
     * other unreachable-destination case.
     *
     * @param  array<int, array{session_id: int, arr: string}>  $candidates
     * @param  array<string, array<string, int>>  $graphExcludingOrigin  widestPathMatrixExcluding($passenger->current_icao) - shared across a whole boarding-lock batch, see handleBoardingLock.
     * @param  array<string, true>  $internationalIcaos
     * @param  \Illuminate\Support\Collection<string, int>  $airportTiers
     * @param  \Illuminate\Support\Collection<string, int>  $legsTakenByPassenger  keyed by passenger_id - shared across a whole boarding-lock batch, see handleBoardingLock.
     * @return array{session_id: int, arr: string, is_terminus: bool}|null
     */
    private function resolvePreferredCandidate(Passenger $passenger, array $candidates, array $graphExcludingOrigin, array $internationalIcaos, $airportTiers, $legsTakenByPassenger): ?array
    {
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['arr'] === $passenger->destination_icao) {
                return $candidate + ['is_terminus' => true];
            }
        }

        if (($legsTakenByPassenger[$passenger->id] ?? 0) >= config('passengers.max_hops')) {
            return null;
        }

        $isTier1ToTier1 = ($airportTiers[$passenger->current_icao] ?? null) === 1
            && ($airportTiers[$passenger->destination_icao] ?? null) === 1;
        $directWeight = $this->trafficGraph->edgeWeights()[$passenger->current_icao][$passenger->destination_icao] ?? 0;

        if ($isTier1ToTier1 || $directWeight >= config('passengers.direct_flight_threshold')) {
            return null;
        }

        $candidates = array_values(array_filter(
            $candidates,
            fn (array $candidate) => ! isset($internationalIcaos[$candidate['arr']])
        ));

        if ($candidates === []) {
            return null;
        }

        $candidates = $this->discardWeakConnections($passenger, $candidates);

        if ($candidates === []) {
            return null;
        }

        $candidates = $this->discardCandidatesFartherFromDestination($passenger, $candidates);

        if ($candidates === []) {
            return null;
        }

        $best = [];
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $score = $graphExcludingOrigin[$candidate['arr']][$passenger->destination_icao] ?? 0;

            if ($score > $bestScore) {
                $best = [$candidate];
                $bestScore = $score;
            } elseif ($score === $bestScore) {
                $best[] = $candidate;
            }
        }

        if ($bestScore <= 0) {
            // None of tonight's (already distance-filtered) ground
            // candidates offer any real historical progress toward the
            // destination. Only fall back to "just go to the biggest hub
            // available" when the destination is entirely unreachable from
            // here via any route at all - if a real path DOES exist from
            // the current airport (just not through what's on the ground
            // right now), wait for a matching opportunity instead of
            // taking an ungrounded guess (see plan §4a - this is what let
            // passengers board flights like YMML->PHNL with zero
            // historical YMML->PHNL traffic at all).
            if (($graphExcludingOrigin[$passenger->current_icao][$passenger->destination_icao] ?? 0) > 0) {
                return null;
            }

            $best = $this->discardDeadEndCandidates($passenger, $best);

            if ($best === []) {
                return null;
            }
        }

        if (count($best) === 1) {
            return $best[0] + ['is_terminus' => false];
        }

        $tierByIcao = Airport::whereIn('icao', array_column($best, 'arr'))->pluck('tier', 'icao');
        usort($best, fn ($a, $b) => ($tierByIcao[$a['arr']] ?? 5) <=> ($tierByIcao[$b['arr']] ?? 5));

        return $best[0] + ['is_terminus' => false];
    }

    /**
     * Applied to every non-terminus candidate up front (see
     * resolvePreferredCandidate) - a candidate is only worth boarding if
     * it's an actual step closer (great-circle) to the destination than
     * staying put, regardless of how strong its traffic-graph score looks.
     * Without this, a spoke one strong edge away from a hub inherits that
     * hub's entire reach and scores as if it were a real connection (e.g. a
     * passenger bound for LA getting boarded onto a Hobart flight, since
     * Hobart's genuine link to Sydney "proves" a route toward almost
     * anywhere on paper - flying there first is still a pure detour), and
     * in the last-resort case (zero real evidence anywhere) "just go to
     * the biggest hub" could otherwise send a passenger clear across the
     * network onto a busy Tier 1 airport no nearer their real (often
     * small/remote) destination at all. A candidate whose airport
     * coordinates aren't on record is kept rather than dropped, since
     * "unknown" isn't evidence of being farther away.
     *
     * @param  array<int, array{session_id: int, arr: string}>  $candidates
     * @return array<int, array{session_id: int, arr: string}>
     */
    private function discardCandidatesFartherFromDestination(Passenger $passenger, array $candidates): array
    {
        $icaos = array_unique(array_merge(
            array_column($candidates, 'arr'),
            [$passenger->current_icao, $passenger->destination_icao]
        ));

        $coords = Airport::whereIn('icao', $icaos)->get(['icao', 'lat', 'lon'])->keyBy('icao');

        $currentDistance = $this->distanceToDestinationNm($passenger->current_icao, $passenger->destination_icao, $coords);

        if ($currentDistance === null) {
            return $candidates;
        }

        return array_values(array_filter($candidates, function (array $candidate) use ($passenger, $coords, $currentDistance) {
            $candidateDistance = $this->distanceToDestinationNm($candidate['arr'], $passenger->destination_icao, $coords);

            return $candidateDistance === null || $candidateDistance < $currentDistance;
        }));
    }

    /**
     * Applied to every non-terminus candidate up front (see
     * resolvePreferredCandidate) - every candidate reaching this point has
     * already failed the exact-terminus check, so boarding it is inherently
     * a connection relative to the passenger's real destination, not a
     * genuine direct flight. The actual leg about to be boarded (current
     * airport -> candidate's arrival) must itself carry at least
     * config('passengers.min_connection_flights') flights over the trailing
     * window, exactly like extendByOneHop enforces for every edge past a
     * path's first hop in the abstract graph search (see
     * TrafficGraphService) - a leg only ever observed a handful of times
     * isn't a connection a passenger could actually expect to catch.
     * Without this, scoring only ever looked at the candidate's onward
     * prospects (graphExcludingOrigin), never the strength of the leg
     * actually being boarded - e.g. a passenger sitting at YPAD could board
     * a YPAD->YAYE flight purely because YAYE happens to score well toward
     * the destination, even with zero YPAD->YAYE flights on record.
     *
     * @param  array<int, array{session_id: int, arr: string}>  $candidates
     * @return array<int, array{session_id: int, arr: string}>
     */
    private function discardWeakConnections(Passenger $passenger, array $candidates): array
    {
        $edgeWeights = $this->trafficGraph->edgeWeights();
        $minConnectionFlights = config('passengers.min_connection_flights');

        return array_values(array_filter($candidates, function (array $candidate) use ($edgeWeights, $passenger, $minConnectionFlights) {
            return ($edgeWeights[$passenger->current_icao][$candidate['arr']] ?? 0) >= $minConnectionFlights;
        }));
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Airport>  $coords
     */
    private function distanceToDestinationNm(string $fromIcao, string $destinationIcao, $coords): ?float
    {
        $from = $coords->get($fromIcao);
        $destination = $coords->get($destinationIcao);

        if ($from === null || $destination === null) {
            return null;
        }

        return GreatCircle::distanceNm((float) $from->lat, (float) $from->lon, (float) $destination->lat, (float) $destination->lon);
    }

    /**
     * Applied only within the last-resort branch (bestScore <= 0, no known
     * route to the destination from anywhere - see resolvePreferredCandidate)
     * - a candidate whose ONLY recorded edge in the entire network is the
     * very leg that brought the passenger to their current airport is a
     * proven dead end, not merely an unproven guess: there is no recorded
     * way to ever move on from there. Being geographically closer to the
     * destination (see discardCandidatesFartherFromDestination) means
     * nothing if the passenger can never leave again once they arrive -
     * e.g. a flight carrying dozens of passengers to a strip with exactly
     * one flight ever recorded (the one they're on), purely because that
     * strip happens to sit closer to their real destinations than their
     * current airport does.
     *
     * @param  array<int, array{session_id: int, arr: string}>  $candidates
     * @return array<int, array{session_id: int, arr: string}>
     */
    private function discardDeadEndCandidates(Passenger $passenger, array $candidates): array
    {
        $edgeWeights = $this->trafficGraph->edgeWeights();

        return array_values(array_filter($candidates, function (array $candidate) use ($edgeWeights, $passenger) {
            foreach ($edgeWeights[$candidate['arr']] ?? [] as $icao => $weight) {
                if ($icao !== $passenger->current_icao && $weight > 0) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Weighted random selection without replacement (Efraimidis-Spirakis),
     * weighted by how long each passenger has been waiting at their current
     * stop - the standard approach for "sample K of N, biased by weight, in
     * one pass" without needing repeated re-normalisation.
     *
     * @param  Collection<int, Passenger>  $passengers
     * @return Collection<int, Passenger>
     */
    private function fillSeats(Collection $passengers, int $seatsAvailable): Collection
    {
        if ($seatsAvailable <= 0 || $passengers->isEmpty()) {
            return new Collection;
        }

        if ($passengers->count() <= $seatsAvailable) {
            return $passengers;
        }

        $now = Carbon::now();

        return $passengers
            ->map(function (Passenger $passenger) use ($now) {
                $waitedSeconds = max(1, $now->diffInSeconds($passenger->last_movement_at));
                $u = mt_rand(1, PHP_INT_MAX - 1) / PHP_INT_MAX;

                return ['passenger' => $passenger, 'key' => $u ** (1 / $waitedSeconds)];
            })
            ->sortByDesc('key')
            ->take($seatsAvailable)
            ->pluck('passenger')
            ->values();
    }

    /**
     * VATSIM's filed deptime is a bare HHMM UTC string with no date. If the
     * parsed time looks well in the past relative to now, it's assumed to
     * mean tomorrow (handles a flight filed just before UTC midnight that
     * hasn't pushed back yet) rather than being treated as hours overdue.
     */
    private function withinDeptimeWindow(?string $deptime, Carbon $now): bool
    {
        if (empty($deptime) || $deptime === '0000') {
            return false;
        }

        $deptime = str_pad((string) $deptime, 4, '0', STR_PAD_LEFT);

        if (! preg_match('/^\d{4}$/', $deptime)) {
            return false;
        }

        $hours = (int) substr($deptime, 0, 2);
        $minutes = (int) substr($deptime, 2, 2);

        if ($hours > 23 || $minutes > 59) {
            return false;
        }

        $filed = $now->copy()->setTime($hours, $minutes, 0);

        if ($filed->lt($now->copy()->subHours(6))) {
            $filed->addDay();
        }

        return $now->greaterThanOrEqualTo($filed->copy()->subMinutes(config('passengers.boarding_lock_minutes_before_deptime')));
    }

    /**
     * Hook 2 - a session has just landed. Logs the completed leg for every
     * boarded passenger and advances them: onward to 'waiting' at the new
     * airport, or 'completed' (and their name released) if this was their
     * final destination.
     *
     * origin_icao is rolled forward to the new airport alongside
     * current_icao (kept equal) whenever the passenger isn't yet home - it
     * tracks "where this leg of the journey is being planned from", not a
     * permanently fixed trip-start, matching how the connection decision
     * itself resets fresh at every interim stop (see resolvePreferredCandidate).
     * This is also what keeps handleDisconnect's "return to origin" correct:
     * a mid-journey disconnect sends the passenger back to their last stable
     * stop, not all the way back to where their whole journey began. The
     * true original departure point remains fully recoverable from the
     * first passenger_flight_history row.
     */
    public function handleLegCompleted(FlightSession $session, Flight $flight): void
    {
        $manifest = Passenger::where('boarded_flight_session_id', $session->id)->get();

        if ($manifest->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $expiresAt = $now->copy()->addDays(config('passengers.expiry_days'));

        foreach ($manifest as $passenger) {
            PassengerFlightHistory::create([
                'passenger_id' => $passenger->id,
                'flight_id' => $flight->id,
                'callsign' => $session->callsign,
                'dep' => $flight->dep,
                'arr' => $flight->arr,
                'aircraft_icao' => $session->aircraft_icao,
                'departed_at' => $flight->departed_at,
                'landed_at' => $flight->landed_at,
            ]);

            $reachedDestination = $flight->arr === $passenger->destination_icao;

            $passenger->update([
                'origin_icao' => $flight->arr,
                'current_icao' => $flight->arr,
                'boarded_flight_session_id' => null,
                'status' => $reachedDestination ? 'completed' : 'waiting',
                'last_movement_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            if ($reachedDestination) {
                $this->namePool->release($passenger);
            }
        }
    }

    /**
     * Hook 3 - a session has been pruned as disconnected (never landed).
     * Boarded passengers return to their origin airport, per README's
     * documented disconnect behaviour, and re-enter the waiting pool.
     */
    public function handleDisconnect(FlightSession $session): void
    {
        $now = Carbon::now();

        Passenger::where('boarded_flight_session_id', $session->id)->update([
            'status' => 'waiting',
            'current_icao' => DB::raw('origin_icao'),
            'boarded_flight_session_id' => null,
            'last_movement_at' => $now,
            'expires_at' => $now->copy()->addDays(config('passengers.expiry_days')),
        ]);
    }
}
