<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'OzPAX') }} &mdash; Coming Late 2026</title>
        <meta name="description" content="OzPAX is a passenger and cargo movement emulator for the VATSIM network, generating realistic individual passenger journeys from real network traffic.">

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
                display: flex;
                flex-direction: column;
                overflow-x: hidden;
            }

            .sky {
                position: fixed;
                inset: 0;
                z-index: 0;
                pointer-events: none;
                overflow: hidden;
            }

            .cloud {
                position: absolute;
                background: #ffffff;
                opacity: 0.55;
                border-radius: 999px;
                filter: blur(1px);
            }

            .cloud::before, .cloud::after {
                content: '';
                position: absolute;
                background: inherit;
                border-radius: 999px;
            }

            .cloud-a { width: 180px; height: 60px; top: 12%; left: -10%; animation: drift 70s linear infinite; }
            .cloud-a::before { width: 90px; height: 90px; top: -45px; left: 20px; }
            .cloud-a::after { width: 70px; height: 70px; top: -30px; left: 100px; }

            .cloud-b { width: 240px; height: 70px; top: 55%; left: -20%; animation: drift 95s linear infinite; animation-delay: -30s; opacity: 0.4; }
            .cloud-b::before { width: 110px; height: 110px; top: -55px; left: 30px; }
            .cloud-b::after { width: 90px; height: 90px; top: -35px; left: 140px; }

            .cloud-c { width: 140px; height: 46px; top: 78%; left: -15%; animation: drift 60s linear infinite; animation-delay: -12s; opacity: 0.5; }
            .cloud-c::before { width: 70px; height: 70px; top: -35px; left: 15px; }
            .cloud-c::after { width: 55px; height: 55px; top: -22px; left: 78px; }

            @keyframes drift {
                from { transform: translateX(0); }
                to { transform: translateX(140vw); }
            }

            main {
                position: relative;
                z-index: 1;
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                padding: 6rem 1.5rem 4rem;
            }

            .badge {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.4rem 0.9rem;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.65);
                border: 1px solid rgba(12, 42, 67, 0.12);
                backdrop-filter: blur(6px);
                font-size: 0.8rem;
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #0f6ba8;
                margin-bottom: 2rem;
            }

            .badge .dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #1ea7ff;
                box-shadow: 0 0 0 4px rgba(30, 167, 255, 0.25);
            }

            .logo-mark {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 4.5rem;
                height: 4.5rem;
                border-radius: 1.25rem;
                background: linear-gradient(135deg, #1ea7ff, #0a6cb8);
                box-shadow: 0 12px 30px -8px rgba(10, 90, 150, 0.55);
                margin-bottom: 1.75rem;
            }

            .logo-mark svg {
                width: 2.25rem;
                height: 2.25rem;
                fill: #ffffff;
            }

            h1 {
                font-size: clamp(2.75rem, 6vw, 4.5rem);
                line-height: 1.05;
                font-weight: 700;
                margin: 0 0 0.75rem;
                letter-spacing: -0.02em;
                color: #073657;
            }

            h1 span {
                background: linear-gradient(90deg, #0a6cb8, #1ea7ff 55%, #5fd0ff);
                -webkit-background-clip: text;
                background-clip: text;
                color: transparent;
            }

            .tagline {
                font-size: clamp(1.05rem, 2vw, 1.35rem);
                font-weight: 500;
                color: #14547f;
                max-width: 44rem;
                margin: 0 0 1.75rem;
            }

            .description {
                font-size: 1rem;
                line-height: 1.65;
                color: #2c5975;
                max-width: 38rem;
                margin: 0 0 3rem;
            }

            .features {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
                gap: 1.25rem;
                width: 100%;
                max-width: 60rem;
                margin-bottom: 3.5rem;
            }

            .feature {
                background: rgba(255, 255, 255, 0.55);
                border: 1px solid rgba(12, 42, 67, 0.1);
                backdrop-filter: blur(8px);
                border-radius: 1rem;
                padding: 1.5rem 1.25rem;
                text-align: left;
            }

            .feature .icon {
                width: 2.25rem;
                height: 2.25rem;
                border-radius: 0.65rem;
                background: rgba(30, 167, 255, 0.15);
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 0.9rem;
            }

            .feature .icon svg {
                width: 1.2rem;
                height: 1.2rem;
                fill: #0a6cb8;
            }

            .feature h3 {
                font-size: 1rem;
                margin: 0 0 0.4rem;
                color: #073657;
            }

            .feature p {
                font-size: 0.875rem;
                line-height: 1.5;
                color: #3a6580;
                margin: 0;
            }

            .eta {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.5rem;
            }

            .eta .label {
                font-size: 0.8rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: #3a6580;
                font-weight: 600;
            }

            .eta .value {
                font-size: 1.75rem;
                font-weight: 700;
                color: #0a6cb8;
            }

            footer {
                position: relative;
                z-index: 1;
                text-align: center;
                padding: 1.5rem 1.5rem 2rem;
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

            @media (max-width: 480px) {
                main { padding: 4.5rem 1.25rem 3rem; }
            }
        </style>
    </head>
    <body>
        <div class="sky" aria-hidden="true">
            <div class="cloud cloud-a"></div>
            <div class="cloud cloud-b"></div>
            <div class="cloud cloud-c"></div>
        </div>

        <main>
            <div class="badge"><span class="dot"></span> In Development</div>

            <div class="logo-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M22 16.5v-2l-8.5-5V4a1.5 1.5 0 0 0-3 0v5.5L2 14.5v2l8.5-2.6V19l-2.5 1.9V22l3.5-1 3.5 1v-1.1L12.5 19v-5.1z"/></svg>
            </div>

            <h1>Oz<span>PAX</span></h1>

            <p class="tagline">A passenger emulator for the VATSIM Network inside VATSIM Australia Pacific (VATPAC) Airspace.</p>

            <p class="description">
                OzPAX watches network traffic in Australian and Oceania airspace and turns that
                traffic into a living population of individual passengers &mdash; each one
                planning a realistic journey, connecting through airports the network actually
                flies to, and boarding real pilots' aircraft to get where they need to go.
            </p>

            <div class="features">
                <div class="feature">
                    <div class="icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 2a5 5 0 0 1 5 5v3.5l4 2.5v2l-4-1v3l2 1.5V20l-4-1-4 1v-1.5l2-1.5v-3l-4 1v-2l4-2.5V7a5 5 0 0 1 5-5z"/></svg>
                    </div>
                    <h3>Individual passengers</h3>
                    <p>Every PAX is its own traveller with an origin, a destination, and a plan &mdash; not a generic statistic.</p>
                </div>
                <div class="feature">
                    <div class="icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 1 18 0 9 9 0 0 1-18 0zm9-7v7l5 3"/></svg>
                    </div>
                    <h3>Driven by real traffic</h3>
                    <p>Itineraries are built from flights previously flown on the network, so routings stay realistic to what occurs on VATSIM.</p>
                </div>
                <div class="feature">
                    <div class="icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 21s-7-5.6-7-11a7 7 0 0 1 14 0c0 5.4-7 11-7 11zm0-8a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/></svg>
                    </div>
                    <h3>Live network maps</h3>
                    <p>Watch passengers gather at airports and track live flights carrying them across the map.</p>
                </div>
            </div>

            <div class="eta">
                <span class="label">Expected Launch</span>
                <span class="value">Q4 '26 / Q1 '27</span>
            </div>
        </main>

        <footer>
            &copy; Joshua Micallef, {{ date('Y') }} OzPAX &mdash; an independent community project for the VATSIM network.
            &mdash; <a href="{{ route('credits') }}">Credits</a>
        </footer>
    </body>
</html>
