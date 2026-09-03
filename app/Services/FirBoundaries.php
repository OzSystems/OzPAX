<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * VATSpy's worldwide FIR/sector boundary polygons: fetched from GitHub and
 * cached to disk (see geoJson()), plus a point-in-polygon lookup restricted
 * to the VATPAC division - used both to render the FIR overlay on the maps
 * and to classify newly-discovered airports as inside/outside VATPAC
 * airspace (see RecordVatsimFlights::resolveAirport).
 */
class FirBoundaries
{
    private const URL = 'https://raw.githubusercontent.com/vatsimnetwork/vatspy-data-project/master/Boundaries.geojson';

    /** @var array{type: string, features: array}|null */
    private ?array $geoJson = null;

    /** @var array<int, array>|null */
    private ?array $vatpacFeatures = null;

    /**
     * The full worldwide FeatureCollection, decoded. Cached on disk rather
     * than the (database-backed) cache store because the ~2MB payload
     * exceeds MySQL's max_allowed_packet as a single cache row, and cached
     * per-instance in memory so a single request/job only decodes it once.
     */
    public function geoJson(): array
    {
        return $this->geoJson ??= json_decode($this->raw(), true);
    }

    public function raw(): string
    {
        $path = storage_path('app/vatspy-fir-boundaries.geojson');
        $isStale = ! is_file($path) || filemtime($path) < now()->subDays(7)->timestamp;

        if ($isStale) {
            try {
                file_put_contents($path, (string) (new Client)->get(self::URL)->getBody());
            } catch (\Throwable $e) {
                if (! is_file($path)) {
                    throw $e;
                }

                Log::warning('FirBoundaries: refresh failed, serving stale cached copy - '.$e->getMessage());
            }
        }

        return file_get_contents($path);
    }

    /**
     * The FIR/sector ICAO code (the feature's "id" property) of the VATPAC
     * boundary polygon containing this point, or null if it falls outside
     * every VATPAC boundary. VATPAC's real-world jurisdiction (Melbourne,
     * Brisbane, Nadi, Honiara, Port Moresby, Nauru) is exactly the
     * "division": "VATPAC" features in this dataset.
     */
    public function findVatpacFir(float $lat, float $lon): ?string
    {
        foreach ($this->vatpacFeatures() as $feature) {
            if ($this->geometryContainsPoint($feature['geometry'], $lat, $lon)) {
                return $feature['properties']['id'];
            }
        }

        return null;
    }

    private function vatpacFeatures(): array
    {
        return $this->vatpacFeatures ??= array_values(array_filter(
            $this->geoJson()['features'],
            fn (array $feature) => ($feature['properties']['division'] ?? null) === 'VATPAC'
        ));
    }

    private function geometryContainsPoint(array $geometry, float $lat, float $lon): bool
    {
        return match ($geometry['type']) {
            'Polygon' => $this->polygonContainsPoint($geometry['coordinates'], $lat, $lon),
            'MultiPolygon' => collect($geometry['coordinates'])->contains(
                fn (array $polygon) => $this->polygonContainsPoint($polygon, $lat, $lon)
            ),
            default => false,
        };
    }

    /**
     * @param  array<int, array<int, array{0: float, 1: float}>>  $rings  Ring 0 is the
     *                                                                    outer boundary, any further rings are holes.
     */
    private function polygonContainsPoint(array $rings, float $lat, float $lon): bool
    {
        if (! $this->ringContainsPoint($rings[0], $lat, $lon)) {
            return false;
        }

        for ($i = 1; $i < count($rings); $i++) {
            if ($this->ringContainsPoint($rings[$i], $lat, $lon)) {
                return false; // Inside a hole cut out of the polygon.
            }
        }

        return true;
    }

    /**
     * Standard ray-casting point-in-polygon test: counts how many times a
     * ray cast from the point crosses the ring's edges, going east. An odd
     * number of crossings means the point is inside.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring  [lon, lat] pairs.
     */
    private function ringContainsPoint(array $ring, float $lat, float $lon): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$lonI, $latI] = $ring[$i];
            [$lonJ, $latJ] = $ring[$j];

            $straddles = ($latI > $lat) !== ($latJ > $lat);

            if ($straddles && $lon < ($lonJ - $lonI) * ($lat - $latI) / ($latJ - $latI) + $lonI) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
