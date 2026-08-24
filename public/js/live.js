import { createMap, emptyCollection, addAirportsLayer, refreshSource } from './map-base.js';

const LIVE_FLIGHTS_URL = '/flights/live/flights';
const LIVE_AIRPORTS_URL = '/flights/live/airports';
const POLL_MS = 20_000; // VATSIM datafeed is cached server-side for 15s

const map = createMap('map');

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

map.on('load', async () => {
    addAirportsLayer(map);

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

    const refresh = () => {
        refreshSource(map, 'live-flights', LIVE_FLIGHTS_URL);
        refreshSource(map, 'airports', LIVE_AIRPORTS_URL);
    };

    refresh();
    setInterval(refresh, POLL_MS);
});
