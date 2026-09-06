<?php

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

/**
 * Flags the VATPAC airports that actually operate international services
 * (customs/immigration facilities) - the only airports TrafficGraphService
 * treats as a legitimate jumping-off point for an itinerary reaching a
 * curated international_destinations hub (see its computeEdgeWeights).
 * Without this, VATSIM's "file international from anywhere" traffic would
 * let a tiny regional strip look like a real gateway to an overseas trip.
 * A starter list of Australia's actual designated international airports -
 * curate further here as needed, same pattern as InternationalDestinationSeeder.
 */
class InternationalGatewaySeeder extends Seeder
{
    private const GATEWAY_ICAOS = [
        'YSSY', // Sydney (Kingsford Smith)
        'YMML', // Melbourne (Tullamarine)
        'YBBN', // Brisbane
        'YPPH', // Perth
        'YPAD', // Adelaide
        'YPDN', // Darwin
        'YBCS', // Cairns
        'YBCG', // Gold Coast
    ];

    public function run(): void
    {
        Airport::whereIn('icao', self::GATEWAY_ICAOS)->update(['is_international_gateway' => true]);
    }
}
