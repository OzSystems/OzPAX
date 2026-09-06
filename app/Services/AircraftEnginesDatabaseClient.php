<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Looks up an aircraft ICAO type designator's certified maximum seat count
 * from The-CFR-Project/AircraftEnginesDatabase (a public CSV on GitHub,
 * sourced from FAA type-certificate data sheets and Wikipedia). Fetched
 * once and cached to disk - same reasoning as FirBoundaries: this is a
 * multi-thousand-row reference dataset that doesn't change often, so
 * there's no need to hit GitHub on every lookup.
 *
 * Used by RecordVatsimFlights to backfill aircraft_types on demand for any
 * ICAO type it sees that isn't already known (see resolveAircraftType()).
 */
class AircraftEnginesDatabaseClient
{
    private const CSV_URL = 'https://raw.githubusercontent.com/The-CFR-Project/AircraftEnginesDatabase/main/aircraft_engines.csv';

    private const CACHE_MAX_AGE_DAYS = 7;

    // Standard ICAO flight-plan placeholder for "type not assigned" - filed
    // by real pilots for unusual/gliders/unlisted types, but reused in this
    // dataset for several unrelated one-off aircraft (a military jet
    // prototype, a UAV, ...), so it must never be treated as a real,
    // reusable type's seat count.
    private const UNASSIGNED_TYPE_PLACEHOLDER = 'ZZZZ';

    /** @var array<string, int>|null */
    private ?array $maxSeatsByType = null;

    /**
     * The certified max seat count for this ICAO type designator (e.g.
     * 'B738'), or null if the dataset has no row for it.
     */
    public function maxSeatsFor(string $icaoType): ?int
    {
        return $this->maxSeatsByType()[$icaoType] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private function maxSeatsByType(): array
    {
        return $this->maxSeatsByType ??= $this->parse($this->raw());
    }

    private function raw(): string
    {
        $path = storage_path('app/aircraft-engines-database.csv');
        $isStale = ! is_file($path) || filemtime($path) < now()->subDays(self::CACHE_MAX_AGE_DAYS)->timestamp;

        if ($isStale) {
            try {
                file_put_contents($path, (string) (new Client)->get(self::CSV_URL)->getBody());
            } catch (\Throwable $e) {
                if (! is_file($path)) {
                    Log::error('AircraftEnginesDatabaseClient: fetch failed and no cached copy exists - '.$e->getMessage());

                    return '';
                }

                Log::warning('AircraftEnginesDatabaseClient: refresh failed, serving stale cached copy - '.$e->getMessage());
            }
        }

        return is_file($path) ? file_get_contents($path) : '';
    }

    /**
     * @return array<string, int>
     */
    private function parse(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv(array_shift($lines));

        $typeCol = array_search('aircraft_type', $header, true);
        $seatsCol = array_search('max_seats', $header, true);

        if ($typeCol === false || $seatsCol === false) {
            Log::error('AircraftEnginesDatabaseClient: expected columns not found in CSV header');

            return [];
        }

        $seats = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line);
            $type = $row[$typeCol] ?? null;
            $count = (int) ($row[$seatsCol] ?? 0);

            if ($type === null || $type === '' || $type === self::UNASSIGNED_TYPE_PLACEHOLDER || $count <= 0) {
                continue;
            }

            // A type designator can cover many model rows - e.g. every
            // 737-800 sub-variant is 'B738' - which normally agree, but a
            // single type can also legitimately span both a dense civilian
            // passenger config and a sparse military/VIP-conversion config
            // sharing the same designator (private-jet A320s, tanker/VIP
            // 737s, ...), and the source data has occasional outright typos
            // (a Eurofighter Typhoon trainer listed as 92 seats instead of
            // 2, everywhere else in the dataset). Collecting every
            // observation and taking the median downstream is far more
            // robust against either than max() would be - a lone bad or
            // atypical row can't skew the result the way it would with max,
            // and overestimating a real aircraft's capacity is a worse
            // failure (over-boarding) than underestimating it.
            $seats[$type][] = $count;
        }

        return array_map([$this, 'median'], $seats);
    }

    /**
     * @param  int[]  $values
     */
    private function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 0
            ? (int) round(($values[$mid - 1] + $values[$mid]) / 2)
            : $values[$mid];
    }
}
