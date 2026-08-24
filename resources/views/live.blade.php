<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="mapbox-token" content="{{ config('services.mapbox.token') }}">

        <title>{{ config('app.name', 'OzPAX') }} &mdash; Live Map</title>

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
        </style>
    </head>
    <body>
        <div id="map"></div>

        <script type="module" src="{{ asset('js/live.js') }}"></script>
    </body>
</html>
