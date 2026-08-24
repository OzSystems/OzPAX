<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class VatsimClient
{
    public function getData(): ?object
    {
        // The datafeed is a multi-MB JSON blob - cache it on the file store
        // regardless of the app's default CACHE_STORE, since a database
        // cache backend (MySQL etc.) can reject it via max_allowed_packet.
        return Cache::store('file')->remember('vatsim.datafeed', 15, function () {
            $client = new Client;
            $statusResponse = $client->get('https://status.vatsim.net/status.json');
            $dataUrl = json_decode($statusResponse->getBody())->data->v3[0];

            $response = $client->get($dataUrl);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody());
            }

            return null;
        });
    }

    /**
     * @return object[] Pilots with a filed flight plan.
     */
    public function getPilots(): array
    {
        $data = $this->getData();

        if ($data === null || ! isset($data->pilots)) {
            return [];
        }

        return array_values(array_filter($data->pilots, fn ($pilot) => ! empty($pilot->flight_plan)));
    }

    public function searchCallsign(string $callsign, bool $precise): object|array|null
    {
        $data = $this->getData();

        if ($data === null || ! isset($data->controllers)) {
            return $precise ? null : [];
        }

        $controllers = [];

        foreach ($data->controllers as $controller) {
            if ($precise) {
                if ($controller->callsign === $callsign) {
                    return $controller;
                }
            } else {
                $controllerCallsignParts = explode('_', $controller->callsign);
                $callsignParts = explode('_', $callsign);
                if (($controllerCallsignParts[0] === $callsignParts[0]) && (end($controllerCallsignParts) === end($callsignParts))) {
                    $controllers[] = $controller;
                }
            }
        }

        return $precise ? null : $controllers;
    }
}
