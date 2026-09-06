<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\AircraftType;
use App\Models\Flight;
use App\Models\FlightSession;
use App\Services\AircraftEnginesDatabaseClient;
use App\Services\AirlabsClient;
use App\Services\FirBoundaries;
use App\Services\InternationalDestinationRouter;
use App\Services\PassengerBoardingEngine;
use App\Services\VatsimClient;
use App\Support\GreatCircle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RecordVatsimFlights implements ShouldQueue
{
    use Queueable;

    public $timeout = 60;

    public $tries = 1;

    // Below this groundspeed a session is considered on the ground.
    private const AIRBORNE_GROUNDSPEED_KT = 80;

    // Below this groundspeed, close to the arrival field, a session is landed.
    private const LANDED_GROUNDSPEED_KT = 40;

    // Radius used both to confirm a landing near the arrival field and to
    // confirm a session was first sighted on the ground at its departure
    // field (as opposed to somewhere else, e.g. a mid-route ground stop).
    private const AIRPORT_PROXIMITY_RADIUS_NM = 3;

    // A session not seen in this many minutes is treated as a disconnect and
    // discarded, per README S1 - mid-flight disconnects don't count as flights.
    private const DISCONNECT_AFTER_MINUTES = 10;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('record-vatsim-flights'))->dontRelease()->expireAfter(120)];
    }

    public function handle(
        VatsimClient $vatsimClient,
        AirlabsClient $airlabsClient,
        FirBoundaries $firBoundaries,
        PassengerBoardingEngine $boardingEngine,
        AircraftEnginesDatabaseClient $aircraftDb
    ): void {
        $pilots = $vatsimClient->getPilots();

        $vatpacIcaos = Airport::where('is_vatpac', true)->pluck('icao')->flip();
        $airportRecords = Airport::all(['icao', 'lat', 'lon', 'altitude', 'altitude_checked_at'])
            ->keyBy('icao')
            ->map(fn (Airport $a) => [
                'lat' => (float) $a->lat,
                'lon' => (float) $a->lon,
                'altitude' => $a->altitude,
                'altitude_checked_at' => $a->altitude_checked_at,
            ])
            ->toArray();

        // Airports we've already attempted to resolve via Airlabs this run,
        // so a burst of pilots sharing the same unresolved ICAO only costs
        // one API call.
        $resolvedThisRun = [];

        // Every aircraft ICAO type already known to aircraft_types - grown
        // in place as new types are resolved (see resolveAircraftType), so
        // a burst of pilots filing the same not-yet-seen type this run only
        // triggers one lookup/insert.
        $knownAircraftTypes = AircraftType::pluck('icao_type')->flip()->all();

        $router = new InternationalDestinationRouter;

        $now = Carbon::now('UTC');

        foreach ($pilots as $pilot) {
            $this->processPilot($pilot, $vatpacIcaos, $airportRecords, $router, $resolvedThisRun, $knownAircraftTypes, $airlabsClient, $firBoundaries, $boardingEngine, $aircraftDb, $now);
        }

        $this->pruneDisconnected($boardingEngine, $now);
    }

    private function processPilot(
        object $pilot,
        $vatpacIcaos,
        array &$airportRecords,
        InternationalDestinationRouter $router,
        array &$resolvedThisRun,
        array &$knownAircraftTypes,
        AirlabsClient $airlabsClient,
        FirBoundaries $firBoundaries,
        PassengerBoardingEngine $boardingEngine,
        AircraftEnginesDatabaseClient $aircraftDb,
        Carbon $now
    ): void {
        $plan = $pilot->flight_plan ?? null;

        if ($plan === null) {
            return;
        }

        $dep = $this->normaliseIcao($plan->departure ?? null);
        $arr = $this->normaliseIcao($plan->arrival ?? null);

        $relevant = ($dep !== null && isset($vatpacIcaos[$dep])) || ($arr !== null && isset($vatpacIcaos[$arr]));

        if (! $relevant) {
            return;
        }

        $this->resolveAirport($dep, $airportRecords, $resolvedThisRun, $airlabsClient, $firBoundaries);
        $this->resolveAirport($arr, $airportRecords, $resolvedThisRun, $airlabsClient, $firBoundaries);
        $this->resolveAircraftType($plan->aircraft_short ?? null, $knownAircraftTypes, $aircraftDb);

        $logonTime = Carbon::parse($pilot->logon_time)->utc();

        $session = FlightSession::firstOrNew([
            'cid' => $pilot->cid,
            'callsign' => $pilot->callsign,
            'logon_time' => $logonTime,
        ]);

        $wasAirborne = $session->status === 'airborne';
        $wasOnGround = $session->exists && $session->status === 'on_ground';
        $groundspeed = (int) ($pilot->groundspeed ?? 0);
        $isAirborneNow = $groundspeed >= self::AIRBORNE_GROUNDSPEED_KT;

        $session->dep = $dep;
        $session->arr = $arr;
        $session->aircraft_icao = $plan->aircraft_short ?? null;
        $session->lat = $pilot->latitude ?? null;
        $session->lon = $pilot->longitude ?? null;
        $session->altitude = $pilot->altitude ?? null;
        $session->groundspeed = $groundspeed;
        $session->heading = $pilot->heading ?? null;
        $session->relevant = true;
        $session->last_seen_at = $now;

        if ($wasOnGround && $isAirborneNow) {
            // We watched this session transition from on_ground to airborne -
            // a genuinely observed departure.
            $session->status = 'airborne';
            $session->departed_at = $now;
        } elseif (! $session->exists) {
            // First sighting of this session. If it's already airborne, we
            // never witnessed the actual departure, so departed_at stays null
            // rather than being backfilled to "now".
            $session->status = $isAirborneNow ? 'airborne' : 'on_ground';
            $session->connected_on_ground = ! $isAirborneNow
                && $dep !== null
                && isset($airportRecords[$dep])
                && GreatCircle::distanceNm(
                    (float) $pilot->latitude,
                    (float) $pilot->longitude,
                    $airportRecords[$dep]['lat'],
                    $airportRecords[$dep]['lon']
                ) <= self::AIRPORT_PROXIMITY_RADIUS_NM;
        }

        $landed = false;
        $divertedTo = null;

        if ($wasAirborne && ! $isAirborneNow && $groundspeed <= self::LANDED_GROUNDSPEED_KT) {
            if ($arr !== null && isset($airportRecords[$arr])) {
                $distance = GreatCircle::distanceNm(
                    (float) $pilot->latitude,
                    (float) $pilot->longitude,
                    $airportRecords[$arr]['lat'],
                    $airportRecords[$arr]['lon']
                );

                if ($distance <= self::AIRPORT_PROXIMITY_RADIUS_NM) {
                    $landed = true;
                }
            }

            // Stopped moving, but not at the filed arrival - check whether
            // it's actually down at some OTHER real airport (a diversion)
            // rather than treating "stopped somewhere unexpected" as still
            // mid-flight until the session eventually times out as a
            // disconnect and its passengers get bounced all the way back
            // to their origin instead of deboarding where they really are.
            if (! $landed) {
                $divertedTo = $this->findNearbyAirport((float) $pilot->latitude, (float) $pilot->longitude, $airportRecords, $vatpacIcaos);
                $landed = $divertedTo !== null;
            }
        }

        if ($landed) {
            // Only save flights we watched start (departed_at set) and end
            // (landed while still connected) on VATSIM. A session first
            // sighted already airborne has a null departed_at - we never
            // witnessed its takeoff, so it doesn't count as a full flight.
            if ($session->departed_at !== null) {
                $this->recordCompletedFlight($session, $now, $router, $airportRecords, $boardingEngine, $divertedTo);
            }

            if ($session->exists) {
                $session->delete();
            }

            return;
        }

        // Saved before the boarding-lock check (rather than at the very end)
        // so a brand-new session already has an id to record as
        // boarded_flight_session_id if boarding locks on this very poll.
        $session->save();

        // Both a departure and an arrival are required to identify a
        // current airport and a candidate destination - a flight plan
        // missing either (normaliseIcao returned null) has nothing for the
        // boarding engine to key passengers/candidates against.
        //
        // connected_on_ground gates this rather than a same-poll "was this
        // session first sighted airborne" check - that flag is only true on
        // the exact poll a session is created, so by the very next poll
        // $session->exists is already true and it silently stops
        // protecting anything. connected_on_ground is set once at first
        // sighting and never changes afterward, so it correctly keeps
        // gating boarding shut on every later poll too - this is what let
        // sessions that were only ever picked up mid-air (like FDX33,
        // logon_time already airborne, departed_at never set) still end up
        // with a full boarded manifest.
        //
        // ! $isAirborneNow additionally guards against a session whose
        // takeoff and first-ever boarding-lock opportunity land on the same
        // poll - e.g. a fast-departing aircraft whose brief taxi phase falls
        // entirely between two polls, so this is the first time we see it
        // moving at all and it's already airborne. Without this check,
        // maybeLockBoarding would still fire using the current (airborne)
        // groundspeed and lock a manifest onto a flight already in the air.
        // A session that never triggers a boarding lock while still on the
        // ground simply departs with no manifest - waiting passengers stay
        // on the ground for the next flight that actually connects, rather
        // than ever being assigned to one that's already left.
        if ($session->boarding_locked_at === null && $session->connected_on_ground && ! $isAirborneNow && $dep !== null && $arr !== null) {
            $reroutedDep = $router->reroute($dep, $airportRecords);
            $reroutedArr = $router->reroute($arr, $airportRecords);

            if ($reroutedDep === null || $reroutedArr === null) {
                return;
            }

            $locked = $boardingEngine->maybeLockBoarding(
                $session,
                $plan,
                $groundspeed,
                $reroutedDep,
                $reroutedArr,
                $router,
                $airportRecords,
                $now
            );

            if ($locked) {
                $session->boarding_locked_at = $now;
                $session->save();
            }
        }
    }

    /**
     * Scans every known VATPAC airport for one within landing-proximity of
     * the aircraft's current position - used when a session that was
     * airborne stops moving somewhere other than its filed arrival, to
     * recognise a diversion instead of leaving the session to eventually
     * time out as a disconnect (which would bounce its passengers all the
     * way back to their origin rather than deboarding where they actually
     * are). $airportRecords is already the full in-memory dump of every
     * airport's coordinates built once per run (see handle()), so this is a
     * plain in-memory scan, not a database query - cheap enough for the
     * rare case a session actually stops somewhere unplanned. Restricted to
     * VATPAC airports since a real diversion lands at a real local
     * alternate, never at some curated overseas international_destinations
     * hub thousands of miles away.
     *
     * @param  array<string, array{lat: float, lon: float, altitude: ?int, altitude_checked_at: ?Carbon}>  $airportRecords
     * @param  \Illuminate\Support\Collection<string, int>  $vatpacIcaos
     */
    private function findNearbyAirport(float $lat, float $lon, array $airportRecords, $vatpacIcaos): ?string
    {
        $nearestIcao = null;
        $nearestDistance = null;

        foreach ($vatpacIcaos as $icao => $_) {
            $record = $airportRecords[$icao] ?? null;

            if ($record === null) {
                continue;
            }

            $distance = GreatCircle::distanceNm($lat, $lon, $record['lat'], $record['lon']);

            if ($distance <= self::AIRPORT_PROXIMITY_RADIUS_NM && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearestDistance = $distance;
                $nearestIcao = $icao;
            }
        }

        return $nearestIcao;
    }

    // If Airlabs has already been asked about an airport within this many
    // days and still came back with no altitude, don't ask again yet - stops
    // an airport Airlabs simply has no data for from being re-queried on
    // every single poll forever. Still retried eventually, in case Airlabs'
    // coverage improves or the ICAO was a transient typo.
    private const ALTITUDE_RETRY_AFTER_DAYS = 30;

    /**
     * Backfill an airport from Airlabs when it's completely unknown, or when
     * we already know it but are missing its runway elevation. Existing
     * classification (is_vatpac/fir_code/is_pseudo from VATSpy) is never
     * touched for an airport we already know - only genuinely missing
     * fields are filled in. A brand-new airport gets its is_vatpac/fir_code
     * determined geometrically, since it's by definition not in VATSpy (that's
     * the only reason we're resolving it via Airlabs at all).
     *
     * @param  array<string, array{lat: float, lon: float, altitude: ?int, altitude_checked_at: ?Carbon}>  $airportRecords
     * @param  array<string, true>  $resolvedThisRun
     */
    private function resolveAirport(?string $icao, array &$airportRecords, array &$resolvedThisRun, AirlabsClient $airlabsClient, FirBoundaries $firBoundaries): void
    {
        if ($icao === null || isset($resolvedThisRun[$icao]) || ! $airlabsClient->hasApiKey()) {
            return;
        }

        $existing = $airportRecords[$icao] ?? null;

        if ($existing !== null && ($existing['altitude'] !== null || $this->checkedRecently($existing['altitude_checked_at']))) {
            return;
        }

        $resolvedThisRun[$icao] = true;
        $checkedAt = Carbon::now('UTC');

        $data = $airlabsClient->connectData($icao);

        if (! $data) {
            // Stamp the attempt even on failure/no-match, so an ICAO Airlabs
            // has never heard of doesn't get retried every single poll.
            if ($existing !== null) {
                Airport::where('icao', $icao)->update(['altitude_checked_at' => $checkedAt]);
                $airportRecords[$icao]['altitude_checked_at'] = $checkedAt;
            } else {
                Airport::create([
                    'icao' => $icao,
                    'name' => $icao,
                    'lat' => 0,
                    'lon' => 0,
                    'is_vatpac' => false,
                    'is_pseudo' => false,
                    'altitude_checked_at' => $checkedAt,
                ]);

                $airportRecords[$icao] = ['lat' => 0.0, 'lon' => 0.0, 'altitude' => null, 'altitude_checked_at' => $checkedAt];
            }

            return;
        }

        $attributes = array_filter([
            'name' => $data['name'] ?? null,
            'iata' => $data['iata_code'] ?? null,
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
            'lon' => isset($data['lng']) ? (float) $data['lng'] : null,
            'altitude' => isset($data['alt']) ? (int) round((float) $data['alt']) : null,
        ], fn ($value) => $value !== null);
        $attributes['altitude_checked_at'] = $checkedAt;

        if ($existing !== null) {
            Airport::where('icao', $icao)->update($attributes);
        } else {
            $fir = isset($attributes['lat'], $attributes['lon'])
                ? $firBoundaries->findVatpacFir($attributes['lat'], $attributes['lon'])
                : null;

            Airport::create(array_merge([
                'icao' => $icao,
                'name' => $icao,
                'lat' => 0,
                'lon' => 0,
                'fir_code' => $fir,
                'is_vatpac' => $fir !== null,
                'is_pseudo' => false,
            ], $attributes));
        }

        $airport = Airport::where('icao', $icao)->first(['lat', 'lon', 'altitude', 'altitude_checked_at']);

        $airportRecords[$icao] = [
            'lat' => (float) $airport->lat,
            'lon' => (float) $airport->lon,
            'altitude' => $airport->altitude,
            'altitude_checked_at' => $airport->altitude_checked_at,
        ];
    }

    private function checkedRecently(?Carbon $checkedAt): bool
    {
        return $checkedAt !== null && $checkedAt->gt(Carbon::now('UTC')->subDays(self::ALTITUDE_RETRY_AFTER_DAYS));
    }

    /**
     * Backfills aircraft_types on demand for any ICAO type filed on a
     * relevant flight plan that isn't already known. AircraftType::TYPICAL_MAX_PAX
     * (a curated, realistic-capacity list) is always checked first; only a
     * type it doesn't cover falls back to AircraftEnginesDatabaseClient's
     * certified seat count - that dataset reports each type's regulatory
     * EXIT-LIMIT maximum (e.g. 853 for an A380, a density no real airline
     * has ever flown), so it's a last resort, not a preferred source. A type
     * neither source has anything for is still recorded (so it's never
     * looked up again) with max_pax left null and is_estimate true, falling
     * back to AircraftType::FALLBACK_MAX_PAX wherever seat capacity is read.
     *
     * @param  array<string, true>  $knownAircraftTypes
     */
    private function resolveAircraftType(?string $icaoType, array &$knownAircraftTypes, AircraftEnginesDatabaseClient $aircraftDb): void
    {
        if ($icaoType === null || isset($knownAircraftTypes[$icaoType])) {
            return;
        }

        $knownAircraftTypes[$icaoType] = true;

        $curated = AircraftType::TYPICAL_MAX_PAX[$icaoType] ?? null;
        $maxSeats = $curated['max_pax'] ?? $aircraftDb->maxSeatsFor($icaoType);

        AircraftType::firstOrCreate(
            ['icao_type' => $icaoType],
            ['name' => $curated['name'] ?? $icaoType, 'max_pax' => $maxSeats, 'is_estimate' => $maxSeats === null]
        );
    }

    /**
     * $divertedTo, when given, is a real VATPAC airport the aircraft
     * actually landed at instead of its filed arrival (see
     * findNearbyAirport) - it's used as-is for `arr` rather than passed
     * through the international-reroute logic, since it's already a
     * resolved VATPAC icao (reroute() would just return it unchanged
     * anyway). `original_arr` still records what was actually filed, same
     * as it always has, so a diversion is fully recoverable from the data
     * even though `arr` (what boarding/stats treat as "where this flight
     * really went") reflects reality instead.
     */
    private function recordCompletedFlight(
        FlightSession $session,
        Carbon $landedAt,
        InternationalDestinationRouter $router,
        array $airportRecords,
        PassengerBoardingEngine $boardingEngine,
        ?string $divertedTo = null
    ): void {
        $dep = $router->reroute($session->dep, $airportRecords);
        $arr = $divertedTo ?? $router->reroute($session->arr, $airportRecords);

        if ($dep !== null && $dep === $arr) {
            // Same-airport circuits (e.g. training touch-and-goes) aren't a
            // real route and would only pollute per-airport/route stats.
            Log::info("RecordVatsimFlights: skipped {$session->callsign} - departure and arrival are the same airport ({$dep})");

            return;
        }

        // Keyed on departed_at, not logon_time - logon_time is per VATSIM
        // connection, not per flight plan, so a pilot flying several legs
        // (e.g. an out-and-back) without ever disconnecting keeps the same
        // logon_time throughout. Keying on it would let every leg after the
        // first silently match that first leg's already-existing row
        // instead of recording its own, and worse, hand handleLegCompleted
        // a stale $flight - decided against the FIRST leg's dep/arr,
        // scoring "did this passenger reach their destination" against the
        // wrong airport for anyone boarded on a later leg. departed_at is
        // unique per actual witnessed takeoff, so it correctly distinguishes
        // every completed flight plan regardless of how many legs share one
        // underlying connection (see the migration that changed the unique
        // index to match).
        $flight = Flight::firstOrCreate(
            [
                'cid' => $session->cid,
                'callsign' => $session->callsign,
                'departed_at' => $session->departed_at,
            ],
            [
                'dep' => $dep,
                'arr' => $arr,
                'original_dep' => $session->dep,
                'original_arr' => $session->arr,
                'aircraft_icao' => $session->aircraft_icao,
                'logon_time' => $session->logon_time,
                'connected_on_ground' => $session->connected_on_ground,
                'landed_at' => $landedAt,
            ]
        );

        $boardingEngine->handleLegCompleted($session, $flight);

        $note = $divertedTo !== null ? " [diverted, filed arrival was {$session->arr}]" : '';
        Log::info("RecordVatsimFlights: recorded landed flight {$session->callsign} ({$dep} -> {$arr}){$note}");
    }

    private function pruneDisconnected(PassengerBoardingEngine $boardingEngine, Carbon $now): void
    {
        $stale = FlightSession::where('last_seen_at', '<', $now->copy()->subMinutes(self::DISCONNECT_AFTER_MINUTES))->get();

        foreach ($stale as $session) {
            $boardingEngine->handleDisconnect($session);
            $session->delete();
        }
    }

    private function normaliseIcao(?string $icao): ?string
    {
        $icao = strtoupper(trim((string) $icao));

        return $icao === '' ? null : $icao;
    }

}
