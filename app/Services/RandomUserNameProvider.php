<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Sources realistic passenger names from randomuser.me - free, no API key
 * required. Fails soft: any error just returns an empty array rather than
 * throwing, so a temporarily-unreachable API never blocks passenger
 * generation (see PassengerNamePool).
 */
class RandomUserNameProvider
{
    // randomuser.me's documented maximum results per request.
    private const MAX_RESULTS_PER_REQUEST = 5000;

    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://randomuser.me/api/',
            'timeout' => 15,
        ]);
    }

    /**
     * @return array<int, array{full_name: string, gender: ?string, nationality: ?string}>
     */
    public function fetchNames(int $count): array
    {
        $count = max(1, min($count, self::MAX_RESULTS_PER_REQUEST));

        try {
            $response = $this->client->get('', [
                'query' => [
                    'results' => $count,
                    'inc' => 'name,gender,nat',
                    'noinfo' => true,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return array_map(fn (array $result) => [
                'full_name' => trim(($result['name']['first'] ?? '').' '.($result['name']['last'] ?? '')),
                'gender' => $result['gender'] ?? null,
                'nationality' => $result['nat'] ?? null,
            ], $data['results'] ?? []);
        } catch (GuzzleException $e) {
            Log::error('RandomUserNameProvider: failed to fetch names - '.$e->getMessage());

            return [];
        }
    }
}
