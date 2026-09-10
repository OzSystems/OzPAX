<?php

return [

    // TrafficGraphService's widest-path search never considers a route
    // needing more flights than this to connect origin to destination - a
    // destination only "reachable" via a longer chain of historical
    // connections isn't a realistic itinerary. hop_distribution below is
    // keyed 1..this value, so raising or lowering it changes how many
    // buckets there are to target. Also enforced directly by
    // PassengerBoardingEngine as a hard cap on a passenger's cumulative
    // REAL flights taken so far on their current itinerary (see
    // handleBoardingLock's $legsTakenByPassenger) - without this, a
    // passenger who keeps finding a real (but ultimately wrong-direction)
    // connection at every single stop could rack up far more actual
    // flights than any planned route ever considered, since each boarding
    // decision only ever looks one hop ahead and has no memory of how many
    // hops already happened.
    'max_hops' => 3,

    // An airport numerically ABOVE this tier (i.e. quieter than it) is
    // never used as an intermediate connecting point in any path - it
    // remains perfectly valid as a trip's actual origin or final
    // destination, just never as a layover. A quiet field having one or
    // two strong edges is common but isn't evidence it's a real connecting
    // hub, and without this a stray data quirk (or just an obscure
    // airport's only two routes happening to align) can make an
    // implausible connection look "reachable" - e.g. a passenger routed
    // via a remote Pacific strip purely because it has decent edges on
    // both sides. Raised from 3 to 4 now that min_connection_flights below
    // does the precise per-edge plausibility check directly - a Tier 4
    // airport with a genuinely well-serviced connecting route no longer
    // needs to be blanket-excluded just for being Tier 4.
    'hop_min_tier' => 4,

    // A leg used to EXTEND a path past its first hop must have carried at
    // least this many flights over the trailing window to count as a real
    // connection (see TrafficGraphService::extendByOneHop) - a leg only
    // ever observed once or twice isn't a connection a passenger could
    // actually expect to catch, it's a one-off. Never applied to a path's
    // first/direct leg (see TrafficGraphService's class docblock) - e.g.
    // YMML->YPAD->YPDN needs YPAD->YPDN specifically to clear this bar even
    // though YMML->YPAD does not, while YMML->YPDN flown directly is always
    // accepted regardless of count.
    'min_connection_flights' => 10,

    // When a passenger's current airport and destination have this many or
    // more flights on record between them (a direct 1-hop edge count, not a
    // widest-path score), boarding holds out for that direct flight rather
    // than accepting any connection - see
    // PassengerBoardingEngine::resolvePreferredCandidate. A route this
    // heavily serviced will reliably produce a direct flight soon, so
    // settling for a roundabout connection instead isn't realistic
    // passenger behaviour. A Tier 1 to Tier 1 pair holds out for direct
    // unconditionally regardless of this threshold - see hop_min_tier's
    // neighbouring rule in the same method.
    'direct_flight_threshold' => 40,

    // Per-aerodrome ceiling on how many passengers may be simultaneously waiting at any ONE airport of a given tier at once
    'tier_caps' => [
        1 => 3000,
        2 => 1000,
        3 => 500,
        4 => 100,
        5 => 50,
    ],

    // Hard ceiling on total active itineraries (any status except completed/stranded) network-wide at any time.
    'global_cap' => 14000,

    // Multiplier on an origin's 8-week-traffic sampling weight, keyed by
    // airports.tier, applied fresh on EVERY independently-drawn itinerary -
    // not a one-time per-origin coin flip. Only the relative scale between
    // tiers matters (these don't need to sum to 1, or stay within 0-1).
    // Independent of destination_tier_weight (destination selection within
    // a draw, not origin selection) and tier_caps (waiting-population
    // limit, not how draws are weighted). Tier 5 is the vast majority of
    // airports on the network - left at 1.0 it would still dominate total
    // volume by sheer count even though each individual Tier 5 airport gets
    // a tiny weight, so it's throttled down hard by default. See
    // GeneratePassengerItineraries's class docblock for why this is a
    // per-draw weight rather than a per-origin gate - the earlier gate
    // design let a single small airport that "won" its coin flip dump an
    // entire deterministic quota onto itself while a major hub that "lost"
    // contributed nothing that run.
    'origin_tier_chance' => [
        1 => 0.40,
        2 => 0.30,
        3 => 0.25,
        4 => 0.04,
        5 => 0.01,
    ],

    // Target split of how many flights a newly generated itinerary should
    // need to complete, keyed by hop count (1 = direct, 2 = one connection,
    // 3 = two connections - see max_hops above). Applied per
    // origin before the usual traffic-weighted destination pick happens
    // within whichever bucket gets rolled - if an origin has nothing in a
    // given bucket, that bucket is skipped and the remaining buckets' odds
    // still apply normally (see GeneratePassengerItineraries::pickHopBucket).
    // Most real trips are direct or one-stop, so weight it heavily that way.
    'hop_distribution' => [
        1 => 0.75,
        2 => 0.20,
        3 => 0.05,
    ],

    // Multiplies a candidate destination's traffic-graph weight, keyed by
    // that destination airport's tier (1-5), before GeneratePassengerItineraries
    // randomly picks one. Independent of tier_caps (which limits how many
    // passengers may be waiting, not which destinations get chosen) - lower
    // a tier's multiplier if it's soaking up too large a share of newly
    // generated itineraries (e.g. everything piling onto one busy Tier 1 hub).
    'destination_tier_weight' => [
        1 => 0.5,
        2 => 0.25,
        3 => 0.20,
        4 => 0.04,
        5 => 0.01,
    ],

    // Exponent (0-1) applied to a destination's raw traffic-graph score
    // before destination_tier_weight, compressing the gap between a
    // dominant route and its runners-up while preserving their rank order -
    // e.g. at 0.25, a 6x raw-traffic edge becomes only a ~1.6x weight edge.
    // 1.0 = no dampening (the full raw traffic ratio drives destination
    // probability one-for-one). Without dampening, a single dominant real
    // route can linearly monopolise an origin's destination mix - "6x more
    // traffic" becomes "6x more likely" - which is realistic ranking but an
    // unrealistically monotonous simulation (one city absorbing most of
    // another's entire outbound demand). Lower toward 0 for a more varied
    // destination spread; raise toward 1 to track real traffic volume more
    // closely. See GeneratePassengerItineraries::weightDestinationsByTier.
    'destination_score_exponent' => 0.25,

    // Total new passengers the daily generation job attempts to create,
    // before tier/global headroom and stranding dampening are applied.
    'daily_generation_target' => 2000,

    // An itinerary's destination must be at least this far (nm, great-
    // circle) from its origin - a few nautical miles isn't a realistic air-
    // travel trip, it's noise from two nearby fields both touching the
    // traffic graph.
    'min_itinerary_distance_nm' => 30,

    // No individual flight leg under this great-circle distance (nm) is
    // ever added to the traffic graph - see
    // TrafficGraphService::computeEdgeWeights. Unlike min_itinerary_distance_nm
    // above (which only gates an itinerary's overall origin-to-final-
    // destination distance at generation time), this applies to every edge
    // unconditionally, including a path's direct/first leg, since a short
    // hop like YBCG->YBBN (~60nm) isn't a real passenger flight regardless
    // of whether it's flown direct or used as a connection - e.g.
    // YSSY->YSCB (~130nm) remains valid, YBCG->YBBN does not.
    'min_flight_distance_nm' => 110,

    // Airport name substrings (case-insensitive) that disqualify an airport
    // from ever originating OR receiving an itinerary, regardless of tier,
    // traffic, or is_vatpac status - heliports and similar aren't part of
    // the fixed-wing passenger network this simulates. "hospital" covers a
    // large class of helipad-only landing sites (emergency/medevac) whose
    // name doesn't happen to literally say heliport/helipad - e.g. "Royal
    // Melbourne Hospital", "Royal North Shore Hospital".
    'excluded_aerodrome_name_patterns' => ['heliport', 'helipad', 'helistop', 'hospital'],

    // Days of no movement (boarding or landing) before a waiting passenger's
    // itinerary is marked stranded - see ExpireStrandedPassengers.
    'expiry_days' => 7,

    // How far back to look for recent stranding events at an airport when
    // dampening that airport's future generation quota.
    'stranding_dampening_window_days' => 14,

    // Minutes before a session's filed departure time that its boarding
    // manifest locks, if it hasn't already locked from starting to taxi
    // first (see PassengerBoardingEngine::maybeLockBoarding) - a session
    // never re-evaluates once locked.
    'boarding_lock_minutes_before_deptime' => 10,

    // Groundspeed (kt) above which a parked session counts as "starting to
    // taxi" and locks its boarding manifest immediately, without waiting
    // for the deptime window above. Comfortably above simulator/GPS jitter
    // for a parked aircraft, comfortably below
    // RecordVatsimFlights::AIRBORNE_GROUNDSPEED_KT (80).
    'taxi_groundspeed_kt' => 5,

    'name_pool' => [
        // Target total pool size (in_use + available combined).
        'target' => 15000,
        // Trigger a top-up once available (in_use = false) names drop
        // below this count.
        'low_water_mark' => 500,
        // randomuser.me's documented max results per request.
        'batch_size' => 5000,
    ],

];
