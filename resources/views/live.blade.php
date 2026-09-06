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
                min-width: 190px;
                max-height: calc(100vh - 24px);
                overflow-y: auto;
            }

            #stats .heading {
                font-weight: 600;
                color: #94a3b8;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                margin: 10px 0 4px;
            }

            #stats .heading:first-child {
                margin-top: 0;
            }

            /* Shared by #stats, #passenger-panel, AND popup content (Mapbox
               renders popups into their own DOM subtree, outside either
               panel, so this must be a plain unscoped rule - a rule scoped
               to #stats/#passenger-panel would silently never match there). */
            .row {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
                gap: 12px;
                line-height: 1.6;
            }

            /* A nested/child row under a heading row (e.g. one flight's
               per-destination breakdown under its callsign) - same rule,
               indented and muted so the hierarchy reads at a glance. */
            .row.indent {
                padding-left: 12px;
                color: #94a3b8;
            }

            /* Visual separation between repeated blocks of the same kind
               (e.g. several flights on the ground at once) so they don't
               run together into one hard-to-parse wall of rows. */
            .block + .block {
                margin-top: 8px;
                padding-top: 8px;
                border-top: 1px dashed rgba(148, 163, 184, 0.3);
            }

            #passenger-panel {
                position: fixed;
                bottom: 12px;
                right: 12px;
                z-index: 1;
                background: rgba(15, 23, 42, 0.9);
                color: #e2e8f0;
                padding: 12px 16px;
                border-radius: 6px;
                font: 13px system-ui, sans-serif;
                min-width: 220px;
                max-width: 320px;
                max-height: calc(100vh - 24px);
                overflow-y: auto;
            }

            #travelled-panel {
                position: fixed;
                top: 12px;
                right: 12px;
                z-index: 1;
                background: rgba(15, 23, 42, 0.85);
                color: #ffffff;
                padding: 10px 14px;
                border-radius: 6px;
                font: 13px system-ui, sans-serif;
                width: 220px;
                max-height: calc(100vh - 24px);
                overflow-y: auto;
            }

            #travelled-panel .panel-header-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                user-select: none;
            }

            #travelled-panel .heading {
                font-weight: 600;
                color: #94a3b8;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                margin: 0;
            }

            #travelled-panel .toggle-arrow {
                color: #64748b;
                font-size: 10px;
                transition: transform 0.15s;
            }

            #travelled-panel.minimized .toggle-arrow {
                transform: rotate(-90deg);
            }

            #travelled-panel .body {
                margin-top: 8px;
            }

            #travelled-panel a.pax-link {
                color: #ffffff;
                text-decoration: none;
            }

            #travelled-panel a.pax-link:hover {
                text-decoration: underline;
            }

            #travelled-panel.minimized .body {
                display: none;
            }

            #travelled-panel .row .rank {
                color: #64748b;
                margin-right: 4px;
            }

            #passenger-panel .panel-header {
                margin-bottom: 6px;
            }

            #passenger-panel .panel-muted {
                color: #94a3b8;
            }

            #passenger-panel .panel-close {
                float: right;
                color: #94a3b8;
                text-decoration: none;
                line-height: 1;
            }

            #passenger-panel .panel-close:hover {
                color: #e2e8f0;
            }

            /* Dark-panel equivalent of map-base.js's popupSection, which is
               calibrated for Mapbox's light popup chrome and would render
               with poor contrast (light-gray-on-light-gray) in here. */
            #passenger-panel .panel-section {
                margin-top: 10px;
                padding-top: 8px;
                border-top: 1px solid rgba(148, 163, 184, 0.25);
            }

            #passenger-panel .panel-section-heading {
                font-size: 10px;
                font-weight: 700;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: #94a3b8;
                margin-bottom: 4px;
            }

            #passenger-panel a.pax-link {
                color: #38bdf8;
                text-decoration: none;
            }

            #passenger-panel a.pax-link:hover {
                text-decoration: underline;
            }

            #passenger-panel a.manifest-link {
                color: #38bdf8;
                text-decoration: none;
                font-size: 12px;
            }

            #passenger-panel a.manifest-link:hover {
                text-decoration: underline;
            }

            .pax-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
                font-size: 12px;
            }

            .pax-table th {
                text-align: left;
                cursor: pointer;
                user-select: none;
                color: #94a3b8;
                font-weight: 600;
                padding: 6px 8px;
                border-bottom: 1px solid rgba(148, 163, 184, 0.3);
                position: sticky;
                top: 0;
                background: #0f172a;
            }

            .pax-table th:hover {
                color: #e2e8f0;
            }

            .pax-table td {
                padding: 5px 8px;
                border-bottom: 1px solid rgba(148, 163, 184, 0.12);
            }

            #map-nav {
                position: fixed;
                top: 12px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 1;
                display: flex;
                gap: 2px;
                background: rgba(15, 23, 42, 0.85);
                border-radius: 999px;
                padding: 4px;
                font: 600 12px system-ui, sans-serif;
            }

            #map-nav a {
                color: #94a3b8;
                text-decoration: none;
                padding: 6px 14px;
                border-radius: 999px;
            }

            #map-nav a:hover {
                color: #e2e8f0;
            }

            #map-nav a.active {
                background: #38bdf8;
                color: #0c2a43;
            }
        </style>
    </head>
    <body>
        <div id="map"></div>

        <nav id="map-nav">
            <a href="{{ route('live') }}" class="active">Live</a>
            <a href="{{ route('past-flights') }}">All History</a>
            <a href="{{ route('recent') }}">Last 8 Weeks</a>
            <a href="{{ route('heatmap') }}">Heatmap</a>
        </nav>

        <div id="stats"></div>
        <div id="travelled-panel">
            <div class="panel-header-row" id="travelled-panel-toggle">
                <div class="heading">Most travelled</div>
                <span class="toggle-arrow">&#9660;</span>
            </div>
            <div class="body" id="travelled-panel-body"></div>
        </div>
        <div id="passenger-panel" hidden></div>

        <script type="module" src="{{ asset('js/live.js') }}"></script>
    </body>
</html>
