<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\InternationalDestination;
use Illuminate\Database\Seeder;

class InternationalDestinationSeeder extends Seeder
{
    // Starter list of major international gateway airports that VATPAC-area
    // outbound/inbound flights get rerouted to when their filed dep/arr isn't
    // already one of these (see RecordVatsimFlights::rerouteIfInternational).
    // Weighted toward Asia-Pacific since that's the realistic bulk of
    // Australia-linked overseas traffic, with a handful of other major hubs.
    private const HUBS = [
        ['icao' => 'WSSS', 'name' => 'Singapore Changi', 'lat' => 1.3644, 'lon' => 103.9915],
        ['icao' => 'RJAA', 'name' => 'Tokyo Narita', 'lat' => 35.7647, 'lon' => 140.3864],
        ['icao' => 'RJTT', 'name' => 'Tokyo Haneda', 'lat' => 35.5494, 'lon' => 139.7798],
        ['icao' => 'VHHH', 'name' => 'Hong Kong Intl', 'lat' => 22.3080, 'lon' => 113.9185],
        ['icao' => 'ZBAA', 'name' => 'Beijing Capital', 'lat' => 40.0801, 'lon' => 116.5846],
        ['icao' => 'ZSPD', 'name' => 'Shanghai Pudong', 'lat' => 31.1443, 'lon' => 121.8083],
        ['icao' => 'ZGGG', 'name' => 'Guangzhou Baiyun', 'lat' => 23.3924, 'lon' => 113.2988],
        ['icao' => 'RKSI', 'name' => 'Seoul Incheon', 'lat' => 37.4602, 'lon' => 126.4407],
        ['icao' => 'VTBS', 'name' => 'Bangkok Suvarnabhumi', 'lat' => 13.6900, 'lon' => 100.7501],
        ['icao' => 'WMKK', 'name' => 'Kuala Lumpur Intl', 'lat' => 2.7456, 'lon' => 101.7099],
        ['icao' => 'WIII', 'name' => 'Jakarta Soekarno-Hatta', 'lat' => -6.1256, 'lon' => 106.6559],
        ['icao' => 'RPLL', 'name' => 'Manila Ninoy Aquino', 'lat' => 14.5086, 'lon' => 121.0198],
        ['icao' => 'OMDB', 'name' => 'Dubai Intl', 'lat' => 25.2532, 'lon' => 55.3657],
        ['icao' => 'OTHH', 'name' => 'Doha Hamad Intl', 'lat' => 25.2731, 'lon' => 51.6081],
        ['icao' => 'EGLL', 'name' => 'London Heathrow', 'lat' => 51.4700, 'lon' => -0.4543],
        ['icao' => 'LFPG', 'name' => 'Paris Charles de Gaulle', 'lat' => 49.0097, 'lon' => 2.5479],
        ['icao' => 'EHAM', 'name' => 'Amsterdam Schiphol', 'lat' => 52.3105, 'lon' => 4.7683],
        ['icao' => 'EDDF', 'name' => 'Frankfurt am Main', 'lat' => 50.0379, 'lon' => 8.5622],
        ['icao' => 'KLAX', 'name' => 'Los Angeles Intl', 'lat' => 33.9416, 'lon' => -118.4085],
        ['icao' => 'KJFK', 'name' => 'New York JFK', 'lat' => 40.6413, 'lon' => -73.7781],
        ['icao' => 'KSFO', 'name' => 'San Francisco Intl', 'lat' => 37.6213, 'lon' => -122.3790],
        ['icao' => 'PHNL', 'name' => 'Honolulu Daniel K. Inouye', 'lat' => 21.3187, 'lon' => -157.9224],
        ['icao' => 'CYVR', 'name' => 'Vancouver Intl', 'lat' => 49.1947, 'lon' => -123.1792],
        ['icao' => 'NZAA', 'name' => 'Auckland Intl', 'lat' => -37.0082, 'lon' => 174.7850],
        ['icao' => 'NFFN', 'name' => 'Nadi Intl', 'lat' => -17.7554, 'lon' => 177.4434],
        ['icao' => 'FAOR', 'name' => 'Johannesburg OR Tambo', 'lat' => -26.1392, 'lon' => 28.2460],
        ['icao' => 'SCEL', 'name' => 'Santiago Arturo Merino Benitez', 'lat' => -33.3930, 'lon' => -70.7858],
        ['icao' => 'SBGR', 'name' => 'Sao Paulo Guarulhos', 'lat' => -23.4356, 'lon' => -46.4731],
    ];

    /**
     * Seed the international_destinations table, creating each hub's Airport
     * row too (without touching it if it already exists, so real VATSpy/
     * Airlabs data is never overwritten).
     */
    public function run(): void
    {
        foreach (self::HUBS as $hub) {
            Airport::firstOrCreate(
                ['icao' => $hub['icao']],
                [
                    'name' => $hub['name'],
                    'lat' => $hub['lat'],
                    'lon' => $hub['lon'],
                    'is_vatpac' => false,
                    'is_pseudo' => false,
                ]
            );

            InternationalDestination::firstOrCreate(['icao' => $hub['icao']]);
        }
    }
}
