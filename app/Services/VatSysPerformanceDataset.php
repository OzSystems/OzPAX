<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * vatSys's Australian dataset Performance.xml - the per-aircraft-type
 * performance profiles (climb/descent rates, cruise speeds, ceilings) the
 * VATPAC controller client works from. A type with no entry there has no
 * profile for ATC to plan against, so this answers one maintenance question:
 * which aircraft types are pilots actually filing on the network that the
 * dataset doesn't cover yet?
 *
 * Fetched on demand only (see MapController::checkAircraftTypes), never on a
 * schedule or from a request path - it's a check someone runs when they're
 * about to go update the dataset. The result is persisted to disk instead,
 * so the page can show the last run's findings and when it ran without
 * re-fetching 50KB of XML on every page load.
 */
class VatSysPerformanceDataset
{
    private const URL = 'https://raw.githubusercontent.com/vatSys/australia-dataset/master/Performance.xml';

    /**
     * Every ICAO type designator the dataset defines a profile for.
     *
     * Types are grouped into one PerformanceData node per shared profile,
     * each listing its members in a single comma-separated <Types> node as
     * "ICAO/WakeCategory" entries (e.g. "B738/M,B38M/M"), occasionally with
     * the wake suffix missing entirely (B37M, as of writing) - so the code is
     * everything before the first slash, or the whole entry when there is no
     * slash.
     *
     * @return array<string, true>
     */
    public function definedTypes(): array
    {
        $body = (string) (new Client)->get(self::URL, ['timeout' => 30])->getBody();

        $xml = simplexml_load_string($body);

        if ($xml === false) {
            throw new RuntimeException('Performance.xml could not be parsed as XML.');
        }

        $types = [];

        foreach ($xml->PerformanceData as $performanceData) {
            foreach (explode(',', (string) $performanceData->Types) as $entry) {
                $entry = trim($entry);

                if ($entry === '') {
                    continue;
                }

                $types[strtok($entry, '/')] = true;
            }
        }

        // Treated as a failed check rather than a clean "nothing missing"
        // result: a successfully-fetched file that yields no types at all
        // means the dataset's format has moved on, and silently reporting
        // full coverage would be the one outcome nobody would question.
        if ($types === []) {
            throw new RuntimeException('Performance.xml defined no aircraft types - the dataset format has probably changed.');
        }

        return $types;
    }

    /**
     * Diffs the observed types against the live dataset, then persists and
     * returns the result.
     *
     * @param  array<string, int>  $flightCounts  [icao => flights recorded]
     * @return array{checked_at: string, dataset_types: int, observed_types: int, missing: array<int, array{icao: string, flights: int}>}
     */
    public function check(array $flightCounts): array
    {
        $defined = $this->definedTypes();

        $missing = [];

        foreach ($flightCounts as $icao => $flights) {
            if (! isset($defined[$icao])) {
                $missing[] = ['icao' => (string) $icao, 'flights' => $flights];
            }
        }

        $result = [
            'checked_at' => Carbon::now()->toIso8601String(),
            'dataset_types' => count($defined),
            'observed_types' => count($flightCounts),
            'missing' => $missing,
        ];

        file_put_contents($this->resultPath(), json_encode($result, JSON_PRETTY_PRINT));

        return $result;
    }

    /**
     * The last run's persisted result, or null if the check has never run.
     *
     * @return array{checked_at: string, dataset_types: int, observed_types: int, missing: array<int, array{icao: string, flights: int}>}|null
     */
    public function lastCheck(): ?array
    {
        $path = $this->resultPath();

        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }

    private function resultPath(): string
    {
        return storage_path('app/vatsys-performance-check.json');
    }
}
