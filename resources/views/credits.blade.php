<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'OzPAX') }} &mdash; Credits</title>
        <meta name="description" content="Third-party data sources, APIs, and services OzPAX is built on.">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">

        <style>
            :root {
                color-scheme: light;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
                color: #0c2a43;
                background: radial-gradient(circle at 20% 15%, #eaf7ff 0%, #cdeeff 28%, #8fd3ff 58%, #4fb3f0 100%);
                background-attachment: fixed;
            }

            main {
                max-width: 44rem;
                margin: 0 auto;
                padding: 4rem 1.5rem 3rem;
            }

            .back {
                display: inline-block;
                margin-bottom: 2rem;
                color: #0a6cb8;
                text-decoration: none;
                font-weight: 600;
                font-size: 0.9rem;
            }

            .back:hover {
                text-decoration: underline;
            }

            h1 {
                font-size: clamp(2rem, 5vw, 2.75rem);
                font-weight: 700;
                margin: 0 0 0.5rem;
                letter-spacing: -0.02em;
                color: #073657;
            }

            .intro {
                font-size: 1rem;
                line-height: 1.65;
                color: #2c5975;
                max-width: 38rem;
                margin: 0 0 2.5rem;
            }

            .credit {
                background: rgba(255, 255, 255, 0.6);
                border: 1px solid rgba(12, 42, 67, 0.1);
                backdrop-filter: blur(8px);
                border-radius: 1rem;
                padding: 1.25rem 1.5rem;
                margin-bottom: 1rem;
            }

            .credit h2 {
                font-size: 1.05rem;
                margin: 0 0 0.35rem;
                color: #073657;
            }

            .credit p {
                font-size: 0.9rem;
                line-height: 1.55;
                color: #3a6580;
                margin: 0 0 0.6rem;
            }

            .credit a.link {
                font-size: 0.85rem;
                font-weight: 600;
                color: #0a6cb8;
                text-decoration: none;
                word-break: break-all;
            }

            .credit a.link:hover {
                text-decoration: underline;
            }

            .category {
                font-size: 0.75rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: #0f6ba8;
                margin: 2rem 0 0.75rem;
            }

            .category:first-of-type {
                margin-top: 0;
            }

            footer {
                text-align: center;
                padding: 1.5rem 1.5rem 2.5rem;
                font-size: 0.8rem;
                color: #3a6580;
            }

            footer a {
                color: #0a6cb8;
                text-decoration: none;
            }

            footer a:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <main>
            <a class="back" href="{{ route('welcome') }}">&larr; Back to OzPAX</a>

            <h1>Credits</h1>
            <p class="intro">
                OzPAX is built entirely on top of real network data and a handful of
                third-party services and open datasets. None of these projects endorse
                or are affiliated with OzPAX - they're credited here for transparency.
            </p>

            <div class="category">Network data</div>

            <div class="credit">
                <h2>VATSIM</h2>
                <p>The live pilot/flight data feed that everything in OzPAX is derived from.</p>
                <a class="link" href="https://vatsim.net" target="_blank" rel="noopener">vatsim.net</a>
            </div>

            <div class="credit">
                <h2>VATSpy Data Project</h2>
                <p>Airport and FIR/sector boundary reference data, used to classify airports and draw the FIR overlay on the maps.</p>
                <a class="link" href="https://github.com/vatsimnetwork/vatspy-data-project" target="_blank" rel="noopener">github.com/vatsimnetwork/vatspy-data-project</a>
            </div>

            <div class="category">Maps</div>

            <div class="credit">
                <h2>Mapbox GL JS</h2>
                <p>Powers every map page - base map styling, rendering, and interaction.</p>
                <a class="link" href="https://www.mapbox.com/" target="_blank" rel="noopener">mapbox.com</a>
            </div>

            <div class="credit">
                <h2>OpenStreetMap</h2>
                <p>Underlying map data for the Mapbox base style.</p>
                <a class="link" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">openstreetmap.org/copyright</a>
            </div>

            <div class="category">Reference data & APIs</div>

            <div class="credit">
                <h2>AirLabs</h2>
                <p>Airport runway elevation lookups, used to confirm landings/departures near the correct field.</p>
                <a class="link" href="https://airlabs.co/" target="_blank" rel="noopener">airlabs.co</a>
            </div>

            <div class="credit">
                <h2>AircraftEnginesDatabase (The CFR Project)</h2>
                <p>Certified maximum seat count per aircraft ICAO type, used to cap how many passengers a flight can carry.</p>
                <a class="link" href="https://github.com/The-CFR-Project/AircraftEnginesDatabase" target="_blank" rel="noopener">github.com/The-CFR-Project/AircraftEnginesDatabase</a>
            </div>

            <div class="credit">
                <h2>Random User Generator</h2>
                <p>Generates the pool of passenger names used across OzPAX's simulated population.</p>
                <a class="link" href="https://randomuser.me/" target="_blank" rel="noopener">randomuser.me</a>
            </div>

            <div class="category">Built with</div>

            <div class="credit">
                <h2>Laravel</h2>
                <p>The web framework OzPAX runs on.</p>
                <a class="link" href="https://laravel.com/" target="_blank" rel="noopener">laravel.com</a>
            </div>
        </main>

        <footer>
            &copy; Joshua Micallef, {{ date('Y') }} OzPAX &mdash; an independent community project for the VATSIM network.
        </footer>
    </body>
</html>
