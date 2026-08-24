<?php

namespace App\Jobs;

use App\Models\Airport;
use GuzzleHttp\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncAirports implements ShouldQueue
{
    use Queueable;

    public $timeout = 120;

    public $tries = 1;

    private const VATSPY_URL = 'https://raw.githubusercontent.com/vatsimnetwork/vatspy-data-project/master/VATSpy.dat';

    // FIR name prefixes covering VATPAC's real-world jurisdiction: Australia
    // (Melbourne/Brisbane) plus Fiji, French Polynesia, Nauru, PNG, and the
    // Solomon Islands. New Zealand is deliberately excluded - it's VATNZ's
    // territory, not VATPAC's, despite the two sometimes being conflated.
    private const VATPAC_FIR_NAME_PREFIXES = [
        'Melbourne',
        'Brisbane',
        'Nadi',
        'Honiara',
        'Port Moresby',
        'Nauru',
        'Tahiti',
    ];

    public function handle(): void
    {
        Log::info('SyncAirports: downloading VATSpy.dat...');

        $client = new Client;
        $body = (string) $client->get(self::VATSPY_URL)->getBody();
        $lines = preg_split('/\r\n|\r|\n/', $body);

        [$airportRows, $firRows] = $this->splitSections($lines);

        $vatpacFirs = $this->resolveVatpacFirs($firRows);
        $airports = $this->parseAirports($airportRows, $vatpacFirs);

        $now = Carbon::now();
        foreach (array_chunk($airports, 500) as $chunk) {
            foreach ($chunk as &$row) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
            }
            unset($row);

            Airport::upsert(
                $chunk,
                ['icao'],
                ['iata', 'name', 'lat', 'lon', 'fir_code', 'is_vatpac', 'is_pseudo', 'updated_at']
            );
        }

        Log::info(sprintf(
            'SyncAirports: resolved %d VATPAC FIR boundary codes, parsed %d unique airports, %d flagged is_vatpac.',
            count($vatpacFirs),
            count($airports),
            Airport::where('is_vatpac', true)->count()
        ));
    }

    /**
     * @param  string[]  $lines
     * @return array{0: string[], 1: string[]}
     */
    private function splitSections(array $lines): array
    {
        $airportRows = [];
        $firRows = [];
        $section = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            if ($line === '[Airports]') {
                $section = 'airports';

                continue;
            }

            if ($line === '[FIRs]') {
                $section = 'firs';

                continue;
            }

            if (str_starts_with($line, '[')) {
                $section = null;

                continue;
            }

            if ($section === 'airports') {
                $airportRows[] = $line;
            } elseif ($section === 'firs') {
                $firRows[] = $line;
            }
        }

        return [$airportRows, $firRows];
    }

    /**
     * FIR boundary codes belonging to a VATPAC-controlled FIR, determined
     * from the [FIRs] section's NAME column rather than a hardcoded ICAO
     * list, so this stays correct as VATSpy's data changes.
     *
     * @param  string[]  $firRows
     * @return array<string, true>
     */
    private function resolveVatpacFirs(array $firRows): array
    {
        $vatpacFirs = [];

        foreach ($firRows as $row) {
            $fields = explode('|', $row);

            if (count($fields) < 4) {
                continue;
            }

            [$icao, $name] = $fields;

            foreach (self::VATPAC_FIR_NAME_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $vatpacFirs[$icao] = true;

                    break;
                }
            }
        }

        return $vatpacFirs;
    }

    /**
     * @param  string[]  $airportRows
     * @param  array<string, true>  $vatpacFirs
     * @return array<int, array<string, mixed>>
     */
    private function parseAirports(array $airportRows, array $vatpacFirs): array
    {
        $airports = [];

        foreach ($airportRows as $row) {
            $fields = explode('|', $row);

            if (count($fields) < 7) {
                continue;
            }

            [$icao, $name, $lat, $lon, $iata, $fir, $isPseudo] = $fields;

            if ($icao === '') {
                continue;
            }

            // An ICAO can repeat with alternate sector-position suffixes (e.g.
            // YBBN also appears as BN-F, BN-R, BN-N... with IsPseudo=1). Once
            // we've stored a canonical (non-pseudo) row for an ICAO, later
            // duplicate rows are ignored.
            if (isset($airports[$icao]) && ! $airports[$icao]['is_pseudo']) {
                continue;
            }

            $airports[$icao] = [
                'icao' => $icao,
                'iata' => $iata !== '' ? $iata : null,
                'name' => $name,
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'fir_code' => $fir !== '' ? $fir : null,
                'is_vatpac' => $fir !== '' && isset($vatpacFirs[$fir]),
                'is_pseudo' => $isPseudo === '1',
            ];
        }

        return array_values($airports);
    }
}
