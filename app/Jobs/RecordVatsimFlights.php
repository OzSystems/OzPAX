<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSession;
use App\Services\AirlabsClient;
use App\Services\InternationalDestinationRouter;
use App\Services\VatsimClient;
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

    public function handle(VatsimClient $vatsimClient, AirlabsClient $airlabsClient): void
    {
        $pilots = $vatsimClient->getPilots();

        $vatpacIcaos = Airport::where('is_vatpac', true)->pluck('icao')->flip();
        $airportRecords = Airport::all(['icao', 'lat', 'lon', 'altitude'])
            ->keyBy('icao')
            ->map(fn (Airport $a) => [
                'lat' => (float) $a->lat,
                'lon' => (float) $a->lon,
                'altitude' => $a->altitude,
            ])
            ->toArray();

        // Airports we've already attempted to resolve via Airlabs this run,
        // so a burst of pilots sharing the same unresolved ICAO only costs
        // one API call.
        $resolvedThisRun = [];

        $router = new InternationalDestinationRouter;

        $now = Carbon::now('UTC');

        foreach ($pilots as $pilot) {
            $this->processPilot($pilot, $vatpacIcaos, $airportRecords, $router, $resolvedThisRun, $airlabsClient, $now);
        }

        $this->pruneDisconnected($now);
    }

    private function processPilot(
        object $pilot,
        $vatpacIcaos,
        array &$airportRecords,
        InternationalDestinationRouter $router,
        array &$resolvedThisRun,
        AirlabsClient $airlabsClient,
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

        $this->resolveAirport($dep, $airportRecords, $resolvedThisRun, $airlabsClient);
        $this->resolveAirport($arr, $airportRecords, $resolvedThisRun, $airlabsClient);

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
                && $this->distanceNm(
                    (float) $pilot->latitude,
                    (float) $pilot->longitude,
                    $airportRecords[$dep]['lat'],
                    $airportRecords[$dep]['lon']
                ) <= self::AIRPORT_PROXIMITY_RADIUS_NM;
        }

        $landed = false;

        if ($wasAirborne && ! $isAirborneNow && $arr !== null && isset($airportRecords[$arr])) {
            $distance = $this->distanceNm(
                (float) $pilot->latitude,
                (float) $pilot->longitude,
                $airportRecords[$arr]['lat'],
                $airportRecords[$arr]['lon']
            );

            if ($groundspeed <= self::LANDED_GROUNDSPEED_KT && $distance <= self::AIRPORT_PROXIMITY_RADIUS_NM) {
                $landed = true;
            }
        }

        if ($landed) {
            // Only save flights we watched start (departed_at set) and end
            // (landed while still connected) on VATSIM. A session first
            // sighted already airborne has a null departed_at - we never
            // witnessed its takeoff, so it doesn't count as a full flight.
            if ($session->departed_at !== null) {
                $this->recordCompletedFlight($session, $now, $router, $airportRecords);
            }

            if ($session->exists) {
                $session->delete();
            }

            return;
        }

        $session->save();
    }

    /**
     * Backfill an airport from Airlabs when it's completely unknown, or when
     * we already know it but are missing its runway elevation. Existing
     * classification (is_vatpac/fir_code/is_pseudo from VATSpy) is never
     * touched - only genuinely missing fields are filled in.
     *
     * @param  array<string, array{lat: float, lon: float, altitude: ?int}>  $airportRecords
     * @param  array<string, true>  $resolvedThisRun
     */
    private function resolveAirport(?string $icao, array &$airportRecords, array &$resolvedThisRun, AirlabsClient $airlabsClient): void
    {
        if ($icao === null || isset($resolvedThisRun[$icao]) || ! $airlabsClient->hasApiKey()) {
            return;
        }

        $existing = $airportRecords[$icao] ?? null;

        if ($existing !== null && $existing['altitude'] !== null) {
            return;
        }

        $resolvedThisRun[$icao] = true;

        $data = $airlabsClient->connectData($icao);

        if (! $data) {
            return;
        }

        $attributes = array_filter([
            'name' => $data['name'] ?? null,
            'iata' => $data['iata_code'] ?? null,
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
            'lon' => isset($data['lng']) ? (float) $data['lng'] : null,
            'altitude' => isset($data['alt']) ? (int) round((float) $data['alt']) : null,
        ], fn ($value) => $value !== null);

        if ($existing !== null) {
            if ($attributes !== []) {
                Airport::where('icao', $icao)->update($attributes);
            }
        } else {
            Airport::create(array_merge([
                'icao' => $icao,
                'name' => $icao,
                'lat' => 0,
                'lon' => 0,
                'is_vatpac' => false,
                'is_pseudo' => false,
            ], $attributes));
        }

        $airport = Airport::where('icao', $icao)->first(['lat', 'lon', 'altitude']);

        $airportRecords[$icao] = [
            'lat' => (float) $airport->lat,
            'lon' => (float) $airport->lon,
            'altitude' => $airport->altitude,
        ];
    }

    private function recordCompletedFlight(
        FlightSession $session,
        Carbon $landedAt,
        InternationalDestinationRouter $router,
        array $airportRecords
    ): void {
        $dep = $router->reroute($session->dep, $airportRecords);
        $arr = $router->reroute($session->arr, $airportRecords);

        if ($dep !== null && $dep === $arr) {
            // Same-airport circuits (e.g. training touch-and-goes) aren't a
            // real route and would only pollute per-airport/route stats.
            Log::info("RecordVatsimFlights: skipped {$session->callsign} - departure and arrival are the same airport ({$dep})");

            return;
        }

        Flight::firstOrCreate(
            [
                'cid' => $session->cid,
                'callsign' => $session->callsign,
                'logon_time' => $session->logon_time,
            ],
            [
                'dep' => $dep,
                'arr' => $arr,
                'original_dep' => $session->dep,
                'original_arr' => $session->arr,
                'aircraft_icao' => $session->aircraft_icao,
                'departed_at' => $session->departed_at,
                'connected_on_ground' => $session->connected_on_ground,
                'landed_at' => $landedAt,
            ]
        );

        Log::info("RecordVatsimFlights: recorded landed flight {$session->callsign} ({$dep} -> {$arr})");
    }

    private function pruneDisconnected(Carbon $now): void
    {
        FlightSession::where('last_seen_at', '<', $now->copy()->subMinutes(self::DISCONNECT_AFTER_MINUTES))->delete();
    }

    private function normaliseIcao(?string $icao): ?string
    {
        $icao = strtoupper(trim((string) $icao));

        return $icao === '' ? null : $icao;
    }

    private function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusNm = 3440.065;

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusNm * $c;
    }
}
