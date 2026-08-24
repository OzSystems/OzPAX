<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="mapbox-token" content="{{ config('services.mapbox.token') }}">

        <title>{{ config('app.name', 'OzPAX') }} &mdash; Past Flights</title>

        <link href="https://api.mapbox.com/mapbox-gl-js/v3.9.0/mapbox-gl.css" rel="stylesheet">
        <script src="https://api.mapbox.com/mapbox-gl-js/v3.9.0/mapbox-gl.js"></script>

        <style>
            html, body {
                height: 100%;
                margin: 0;
                overflow: hidden;
            }

            #map {
                position: fixed;
                inset: 0;
            }

            #stats {
                position: fixed;
                top: 12px;
                left: 12px;
                z-index: 1;
                background: rgba(15, 23, 42, 0.85);
                color: #e2e8f0;
                padding: 10px 14px;
                border-radius: 6px;
                font: 13px system-ui, sans-serif;
                min-width: 220px;
                max-height: calc(100vh - 24px);
                overflow-y: auto;
            }

            #stats .heading {
                font-weight: 600;
                color: #94a3b8;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                margin: 0 0 6px;
            }

            #stats .row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 14px;
                line-height: 1.7;
            }

            #stats .row .counts {
                color: #94a3b8;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }

            #stats a.icao {
                color: #38bdf8;
                text-decoration: none;
                font-weight: 600;
                cursor: pointer;
            }

            #stats a.icao:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div id="map"></div>

        <div id="stats"></div>

        <script type="module" src="{{ asset('js/past-flights.js') }}"></script>
    </body>
</html>
