import { createMap, emptyCollection, addAirportsLayer, fetchJson } from './map-base.js';

const LIVE_FLIGHTS_URL = '/flights/live/flights';
const LIVE_AIRPORTS_URL = '/flights/live/airports';
const POLL_MS = 20_000; // VATSIM datafeed is cached server-side for 15s

const map = createMap('map');

let flightsData = emptyCollection();
let airportsData = emptyCollection();
let selectedIcao = null;

// Small aircraft glyph drawn at runtime so no binary asset file is needed.
function loadAircraftIcon() {
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">
            <path d="M16 1 L20 13 L30 18 L20 19 L21 27 L26 30 L16 28 L6 30 L11 27 L12 19 L2 18 L12 13 Z"
                  fill="#f8fafc" stroke="#0c2a43" stroke-width="1"/>
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

function line(a, b, kind) {
    return { type: 'Feature', geometry: { type: 'LineString', coordinates: [a, b] }, properties: { kind } };
}

/**
 * For the clicked airport: one line from the airport to each aircraft
 * currently flying to/from it, plus one line from each of those aircraft
 * onward to the *other* airport in its dep/arr pair.
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
        features.push(line(airportPos, aircraftPos, 'to-aircraft'));

        const other = dep === icao ? arr : dep;
        const otherPos = other && other !== icao ? airportCoords(other) : null;
        if (otherPos) {
            features.push(line(aircraftPos, otherPos, 'to-airport'));
        }
    }

    return { collection: { type: 'FeatureCollection', features }, callsigns };
}

function selectAirport(icao) {
    selectedIcao = icao;
    const { collection, callsigns } = buildAirportLinks(icao);

    map.getSource('airport-links')?.setData(collection);
    map.setLayoutProperty('airport-links', 'visibility', 'visible');

    map.setPaintProperty('live-flights-symbols', 'icon-opacity', [
        'case',
        ['in', ['get', 'callsign'], ['literal', callsigns]], 1,
        0.2,
    ]);
    map.setPaintProperty('airports-circles', 'circle-opacity', [
        'case',
        ['==', ['get', 'icao'], icao], 0.95,
        0.25,
    ]);
    map.setPaintProperty('airports-circles', 'circle-stroke-width', [
        'case',
        ['==', ['get', 'icao'], icao], 3,
        1,
    ]);
}

function clearSelection() {
    selectedIcao = null;

    map.getSource('airport-links')?.setData(emptyCollection());
    map.setLayoutProperty('airport-links', 'visibility', 'none');
    map.setPaintProperty('live-flights-symbols', 'icon-opacity', 1);
    map.setPaintProperty('airports-circles', 'circle-opacity', 0.8);
    map.setPaintProperty('airports-circles', 'circle-stroke-width', 1);
}

map.on('load', async () => {
    addAirportsLayer(map, {
        onClick: (feature) => selectAirport(feature.properties.icao),
        // 1 aircraft -> tiny dot; 50+ aircraft -> large, hard-to-miss circle.
        radiusStops: [1, 4, 5, 7, 15, 12, 30, 18, 50, 26, 100, 36],
    });

    map.addSource('airport-links', { type: 'geojson', data: emptyCollection() });
    map.addLayer({
        id: 'airport-links',
        type: 'line',
        source: 'airport-links',
        layout: { visibility: 'none' },
        paint: {
            'line-color': ['match', ['get', 'kind'], 'to-airport', '#f97316', '#38bdf8'],
            'line-width': 1.5,
            'line-dasharray': ['match', ['get', 'kind'], 'to-airport', ['literal', [2, 2]], ['literal', [1, 0]]],
            'line-opacity': 0.85,
        },
    }, 'airports-circles');

    const aircraftIcon = await loadAircraftIcon();
    map.addImage('aircraft', aircraftIcon, { pixelRatio: 2 });

    map.addSource('live-flights', { type: 'geojson', data: emptyCollection() });

    map.addLayer({
        id: 'live-flights-symbols',
        type: 'symbol',
        source: 'live-flights',
        layout: {
            'icon-image': 'aircraft',
            'icon-size': 1.2,
            'icon-rotate': ['get', 'heading'],
            'icon-rotation-alignment': 'map',
            'icon-allow-overlap': true,
        },
    });

    map.on('click', 'live-flights-symbols', (e) => {
        const p = e.features[0].properties;
        new mapboxgl.Popup()
            .setLngLat(e.features[0].geometry.coordinates)
            .setHTML(`<strong>${p.callsign}</strong><br>${p.dep ?? '?'} → ${p.arr ?? '?'}<br>${p.aircraft ?? ''}<br>FL${Math.round((p.altitude ?? 0) / 100)} / ${p.groundspeed ?? 0} kt`)
            .addTo(map);
    });

    map.on('mouseenter', 'live-flights-symbols', () => (map.getCanvas().style.cursor = 'pointer'));
    map.on('mouseleave', 'live-flights-symbols', () => (map.getCanvas().style.cursor = ''));

    // Clicking empty map background (i.e. not an airport or an aircraft)
    // clears the current airport selection/links.
    map.on('click', (e) => {
        const hits = map.queryRenderedFeatures(e.point, { layers: ['airports-circles', 'live-flights-symbols'] });
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
        airportsData = airports;
        map.getSource('live-flights')?.setData(flightsData);
        map.getSource('airports')?.setData(airportsData);

        if (selectedIcao) {
            map.getSource('airport-links')?.setData(buildAirportLinks(selectedIcao).collection);
        }
    };

    refresh();
    setInterval(refresh, POLL_MS);
});
