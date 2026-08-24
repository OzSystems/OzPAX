<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class AirlabsClient
{
    protected Client $client;

    protected ?string $logon;

    public function __construct()
    {
        $this->logon = env('AIRLABS_API');

        $this->client = new Client([
            'base_uri' => 'https://airlabs.co/api/v9/',
            'timeout' => 15,
        ]);
    }

    public function hasApiKey(): bool
    {
        return ! empty($this->logon);
    }

    // Run the script to register RCLOPS as a connected station on Hoppie
    public function connectData($icao)
    {
        try {
            $response = $this->client->get('airports', [
                'query' => [
                    'icao_code' => $icao,
                    'api_key' => $this->logon,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['response'][0] ?? null;

        } catch (GuzzleException $e) {
            Log::error("AirlabsClient: failed to fetch airport data for {$icao} - ".$e->getMessage());

            return false;
        }
    }
}
