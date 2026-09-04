import { createMap, emptyCollection, addAirportsLayer, addFirBoundariesLayer, fetchJson, DEPARTURE_COLOR, ARRIVAL_COLOR, legendDot } from './map-base.js';

const LIVE_FLIGHTS_URL = '/flights/live/flights';
const LIVE_AIRPORTS_URL = '/flights/live/airports';
const POLL_MS = 20_000; // VATSIM datafeed is cached server-side for 15s

const map = createMap('map');

let flightsData = emptyCollection();
let airportsData = emptyCollection();
let selection = null; // { type: 'airport', icao } | { type: 'aircraft', callsign }

const TRACKED_COLOR = '#f8fafc';
const UNTRACKED_COLOR = '#64748b';

// Small aircraft glyph drawn at runtime so no binary asset file is needed.
function loadAircraftIcon(fillColor) {
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">
            <path d="M16 1 L20 13 L30 18 L20 19 L21 27 L26 30 L16 28 L6 30 L11 27 L12 19 L2 18 L12 13 Z"
                  fill="${fillColor}" stroke="#0c2a43" stroke-width="1"/>
        </svg>`;
    const img = new Image(32, 32);
    return new Promise((resolve) => {
        img.onload = () => resolve(img);
        img.src = `data:image/svg+xml;base64,${btoa(svg)}`;
    });
}

function airportCoords(icao) {
    return airportsData.features.find((f) => f.properties.icao === icao)?.geometry.coordinates ?? null;
}

// Shifts b's longitude by a multiple of 360 so it's within 180deg of a's.
// Mapbox GL draws LineStrings using the raw longitudes given, so a route
// crossing the antimeridian (e.g. lon 179 to lon -179) would otherwise be
// drawn the "long way" around - stretching across the entire map.
function unwrapAntimeridian([lngA, latA], [lngB, latB]) {
    lngB -= Math.round((lngB - lngA) / 360) * 360;
    return [[lngA, latA], [lngB, latB]];
}

function line(a, b, kind) {
    return { type: 'Feature', geometry: { type: 'LineString', coordinates: unwrapAntimeridian(a, b) }, properties: { kind } };
}

/**
 * For the clicked airport: a departure- or arrival-colored line from the
 * airport to each aircraft flying to/from it (colored by whether *this*
 * airport is that aircraft's departure or arrival), plus a same-colored line
 * continuing from the aircraft onward to the *other* airport in its pair.
 */
function buildAirportLinks(icao) {
    const airportPos = airportCoords(icao);
    if (!airportPos) return { collection: emptyCollection(), callsigns: [] };

    const features = [];
    const callsigns = [];

    for (const flight of flightsData.features) {
        const { dep, arr, callsign } = flight.properties;
        if (dep !== icao && arr !== icao) continue;

        callsigns.push(callsign);
        const aircraftPos = flight.geometry.coordinates;
        const kind = dep === icao ? 'departure' : 'arrival';

        features.push(line(airportPos, aircraftPos, kind));

        const other = dep === icao ? arr : dep;
        const otherPos = other && other !== icao ? airportCoords(other) : null;
        if (otherPos) {
            features.push(line(aircraftPos, otherPos, kind));
        }
    }

    return { collection: { type: 'FeatureCollection', features }, callsigns };
}

// For the clicked aircraft: a departure-colored line to its departure
// airport and an arrival-colored line to its arrival airport.
function buildAircraftLinks(flight) {
    const { dep, arr } = flight.properties;
    const aircraftPos = flight.geometry.coordinates;
    const features = [];

    const depPos = dep ? airportCoords(dep) : null;
    if (depPos) features.push(line(aircraftPos, depPos, 'departure'));

    const arrPos = arr ? airportCoords(arr) : null;
    if (arrPos) features.push(line(aircraftPos, arrPos, 'arrival'));

    return { type: 'FeatureCollection', features };
}

function applySelection() {
    if (!selection) {
        map.getSource('airport-links')?.setData(emptyCollection());
        map.setLayoutProperty('airport-links', 'visibility', 'none');
        map.setPaintProperty('live-flights-symbols', 'icon-opacity', 1);
        map.setPaintProperty('airports-circles', 'circle-opacity', 0.8);
        map.setPaintProperty('airports-circles', 'circle-stroke-width', 1);
        return;
    }

    if (selection.type === 'airport') {
        const { icao } = selection;
        const { collection, callsigns } = buildAirportLinks(icao);

        map.getSource('airport-links')?.setData(collection);
        map.setLayoutProperty('airport-links', 'visibility', 'visible');
        map.setPaintProperty('live-flights-symbols', 'icon-opacity', [
            'case', ['in', ['get', 'callsign'], ['literal', callsigns]], 1, 0.2,
        ]);
        map.setPaintProperty('airports-circles', 'circle-opacity', [
            'case', ['==', ['get', 'icao'], icao], 0.95, 0.25,
        ]);
        map.setPaintProperty('airports-circles', 'circle-stroke-width', [
            'case', ['==', ['get', 'icao'], icao], 3, 1,
        ]);
        return;
    }

    const flight = flightsData.features.find((f) => f.properties.callsign === selection.callsign);
    if (!flight) {
        selection = null;
        applySelection();
        return;
    }

    const { dep, arr } = flight.properties;
    const pairIcaos = [dep, arr].filter(Boolean);

    map.getSource('airport-links')?.setData(buildAircraftLinks(flight));
    map.setLayoutProperty('airport-links', 'visibility', 'visible');
    map.setPaintProperty('live-flights-symbols', 'icon-opacity', [
        'case', ['==', ['get', 'callsign'], selection.callsign], 1, 0.2,
    ]);
    map.setPaintProperty('airports-circles', 'circle-opacity', [
        'case', ['in', ['get', 'icao'], ['literal', pairIcaos]], 0.95, 0.25,
    ]);
    map.setPaintProperty('airports-circles', 'circle-stroke-width', [
        'case', ['in', ['get', 'icao'], ['literal', pairIcaos]], 3, 1,
    ]);
}

function selectAirport(icao) {
    selection = { type: 'airport', icao };
    applySelection();
}

function selectAircraft(callsign) {
    selection = { type: 'aircraft', callsign };
    applySelection();
}

function clearSelection() {
    selection = null;
    applySelection();
}

function airportPopupHtml(p) {
    const depNote = p.departuresRerouted ? ` (${p.departuresRerouted} rerouted here)` : '';
    const arrNote = p.arrivalsRerouted ? ` (${p.arrivalsRerouted} rerouted here)` : '';

    return `<strong>${p.icao}</strong> — ${p.name}<br>`
        + `${legendDot(TIER_COLOR_HEX[p.tier] ?? TIER_COLOR_HEX[5])}Tier ${p.tier} (${p.movements_8w ?? 0} movements/8wk)<br>`
        + `${legendDot(DEPARTURE_COLOR)}Departures: ${p.departures}${depNote}<br>`
        + `${legendDot(ARRIVAL_COLOR)}Arrivals: ${p.arrivals}${arrNote}<br>`
        + `Total: ${p.total}`;
}

function aircraftPopupHtml(p) {
    if (!p.connected_on_ground) {
        return `<strong>${p.callsign}</strong><br>Connected in air, will not count to data`;
    }

    return `<strong>${p.callsign}</strong><br>`
        + `${legendDot(DEPARTURE_COLOR)}${p.dep ?? '?'} → ${legendDot(ARRIVAL_COLOR)}${p.arr ?? '?'}<br>`
        + `${p.aircraft ?? ''}<br>FL${Math.round((p.altitude ?? 0) / 100)} / ${p.groundspeed ?? 0} kt`;
}

function renderStats() {
    const stats = document.getElementById('stats');
    if (!stats) return;

    const trackedCount = flightsData.features.filter((f) => f.properties.connected_on_ground).length;

    const topAirports = [...airportsData.features]
        .sort((a, b) => b.properties.total - a.properties.total)
        .slice(0, 30);

    // Deliberately no tier color here - this list ranks *live* traffic right
    // now, a different signal from the 8-week tier, so it gets its own
    // plain style rather than borrowing the tier legend's colors.
    const rows = topAirports.length
        ? topAirports.map((f) => `<div class="row"><span>${f.properties.icao}</span><span>${f.properties.total}</span></div>`).join('')
        : '<div class="row"><span>—</span></div>';

    const tierRows = [1, 2, 3, 4, 5]
        .map((tier) => `<div class="row"><span>${legendDot(TIER_COLOR_HEX[tier])}Tier ${tier}</span></div>`)
        .join('');

    stats.innerHTML = `
        <div class="row"><span>Tracked aircraft</span><span>${trackedCount}</span></div>
        <div class="heading">Airport tiers (8-week movements)</div>
        ${tierRows}
        <div class="heading">Top 30 airport movements</div>
        ${rows}
    `;
}

// Five clearly distinct hues (not shades of one color) so tiers are easy to
// tell apart at a glance - ordered like a heat scale, hottest/most
// important (Tier 1) to coolest/quietest (Tier 5, the vast majority of
// airports, deliberately muted so the long tail doesn't clutter the map).
const TIER_COLOR_HEX = { 1: '#ef4444', 2: '#f97316', 3: '#eab308', 4: '#22c55e', 5: '#64748b' };
const TIER_COLORS = ['match', ['get', 'tier'],
    1, TIER_COLOR_HEX[1],
    2, TIER_COLOR_HEX[2],
    3, TIER_COLOR_HEX[3],
    4, TIER_COLOR_HEX[4],
    5, TIER_COLOR_HEX[5],
    TIER_COLOR_HEX[5],
];

// Real-world nautical-mile radius per tier - 30nm max at Tier 1, stepping
// down 4nm per tier; Tier 5 is floored to 10nm rather than continuing the
// same step, since it covers the vast majority of airports and would
// otherwise swamp the map.
const TIER_RADIUS_NM = ['match', ['get', 'tier'],
    1, 30,
    2, 26,
    3, 22,
    4, 18,
    5, 10,
    10,
];

// Ground resolution at zoom 20 is ~0.075m/pixel at the equator; dividing by
// cos(latitude) corrects for Web Mercator's north/south stretching. Feeding
// that into a base-2 exponential zoom interpolation from (0, 0) makes the
// circle represent a constant real-world size (the tier's nm radius) at any
// zoom or latitude, rather than a flat pixel radius that never matches the
// map's actual scale as you zoom in or out.
const NM_IN_METERS = 1852;
const GROUND_RES_AT_Z20 = 0.075;
const AIRPORT_RADIUS_METERS = ['*', NM_IN_METERS, TIER_RADIUS_NM];
const AIRPORT_RADIUS_PX_AT_Z20 = ['/', AIRPORT_RADIUS_METERS, ['*', GROUND_RES_AT_Z20, ['cos', ['*', ['get', 'lat'], Math.PI / 180]]]];
const AIRPORT_RADIUS_EXPRESSION = ['interpolate', ['exponential', 2], ['zoom'], 0, 0, 20, AIRPORT_RADIUS_PX_AT_Z20];

map.on('load', async () => {
    addFirBoundariesLayer(map);

    addAirportsLayer(map, {
        onClick: (feature) => selectAirport(feature.properties.icao),
        popupHtml: airportPopupHtml,
        // A fixed real-world radius (5nm-20nm depending on tier) rather
        // than a flat pixel size - see AIRPORT_RADIUS_EXPRESSION above.
        radiusExpression: AIRPORT_RADIUS_EXPRESSION,
        colorExpression: TIER_COLORS,
    });

    map.addSource('airport-links', { type: 'geojson', data: emptyCollection() });
    map.addLayer({
        id: 'airport-links',
        type: 'line',
        source: 'airport-links',
        layout: { visibility: 'none' },
        paint: {
            'line-color': ['match', ['get', 'kind'], 'departure', DEPARTURE_COLOR, 'arrival', ARRIVAL_COLOR, '#94a3b8'],
            'line-width': 1.5,
            'line-opacity': 0.85,
        },
    }, 'airports-circles');

    const [trackedIcon, untrackedIcon] = await Promise.all([
        loadAircraftIcon(TRACKED_COLOR),
        loadAircraftIcon(UNTRACKED_COLOR),
    ]);
    map.addImage('aircraft-tracked', trackedIcon, { pixelRatio: 2 });
    map.addImage('aircraft-untracked', untrackedIcon, { pixelRatio: 2 });

    map.addSource('live-flights', { type: 'geojson', data: emptyCollection() });

    map.addLayer({
        id: 'live-flights-symbols',
        type: 'symbol',
        source: 'live-flights',
        layout: {
            'icon-image': ['case', ['get', 'connected_on_ground'], 'aircraft-tracked', 'aircraft-untracked'],
            'icon-size': 1.2,
            'icon-rotate': ['get', 'heading'],
            'icon-rotation-alignment': 'map',
            'icon-allow-overlap': true,
        },
    });

    map.on('click', 'live-flights-symbols', (e) => {
        const feature = e.features[0];
        const p = feature.properties;

        selectAircraft(p.callsign);

        new mapboxgl.Popup()
            .setLngLat(feature.geometry.coordinates)
            .setHTML(aircraftPopupHtml(p))
            .addTo(map);
    });

    map.on('mouseenter', 'live-flights-symbols', () => (map.getCanvas().style.cursor = 'pointer'));
    map.on('mouseleave', 'live-flights-symbols', () => (map.getCanvas().style.cursor = ''));

    // Clicking empty map background (i.e. not an airport or an aircraft)
    // clears the current selection/links.
    map.on('click', (e) => {
        const hits = map.queryRenderedFeatures(e.point, { layers: ['airports-circles', 'airports-labels', 'live-flights-symbols'] });
        if (hits.length === 0) {
            clearSelection();
        }
    });

    const refresh = async () => {
        const [flights, airports] = await Promise.all([
            fetchJson(LIVE_FLIGHTS_URL),
            fetchJson(LIVE_AIRPORTS_URL),
        ]);

        flightsData = flights;
        // AIRPORT_RADIUS_EXPRESSION needs each feature's latitude directly as
        // a property (style expressions can't read it out of the geometry).
        airportsData = {
            ...airports,
            features: airports.features.map((f) => ({
                ...f,
                properties: { ...f.properties, lat: f.geometry.coordinates[1] },
            })),
        };

        map.getSource('live-flights')?.setData(flightsData);
        map.getSource('airports')?.setData(airportsData);

        applySelection();
        renderStats();
    };

    refresh();
    setInterval(refresh, POLL_MS);
});
