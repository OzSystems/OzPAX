import { createMap, emptyCollection, addAirportsLayer, addFirBoundariesLayer, fetchJson, DEPARTURE_COLOR, ARRIVAL_COLOR } from './map-base.js';

const LIVE_FLIGHTS_URL = '/flights/live/flights';
const LIVE_AIRPORTS_URL = '/flights/live/airports';
const POLL_MS = 20_000; // VATSIM datafeed is cached server-side for 15s

const map = createMap('map');

let flightsData = emptyCollection();
let airportsData = emptyCollection();
let selection = null; // { type: 'airport', icao } | { type: 'aircraft', callsign }
let hideUngrounded = false;

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

function legendDot(color) {
    return `<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${color};margin-right:5px;"></span>`;
}

function airportCoords(icao) {
    return airportsData.features.find((f) => f.properties.icao === icao)?.geometry.coordinates ?? null;
}

// Aircraft whose session was never seen connected on the ground at their
// filed departure airport - so we can't vouch for the departure leg - are
// excluded here when the "hide untracked departures" checkbox is ticked.
function visibleFlights() {
    return hideUngrounded
        ? flightsData.features.filter((f) => f.properties.connected_on_ground)
        : flightsData.features;
}

function line(a, b, kind) {
    return { type: 'Feature', geometry: { type: 'LineString', coordinates: [a, b] }, properties: { kind } };
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

    for (const flight of visibleFlights()) {
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
        + `${legendDot(DEPARTURE_COLOR)}Departures: ${p.departures}${depNote}<br>`
        + `${legendDot(ARRIVAL_COLOR)}Arrivals: ${p.arrivals}${arrNote}<br>`
        + `Total: ${p.total}`;
}

function aircraftPopupHtml(p) {
    return `<strong>${p.callsign}</strong><br>`
        + `${legendDot(DEPARTURE_COLOR)}${p.dep ?? '?'} → ${legendDot(ARRIVAL_COLOR)}${p.arr ?? '?'}<br>`
        + `${p.aircraft ?? ''}<br>FL${Math.round((p.altitude ?? 0) / 100)} / ${p.groundspeed ?? 0} kt`;
}

map.on('load', async () => {
    addFirBoundariesLayer(map);

    addAirportsLayer(map, {
        onClick: (feature) => selectAirport(feature.properties.icao),
        popupHtml: airportPopupHtml,
        // Tiny dot for 1-5 aircraft, a bit larger for 6-10, then growing
        // steadily up to a large circle at 150+.
        radiusStops: [1, 4, 5, 6, 10, 8, 30, 14, 80, 24, 150, 36],
        // Blue (1-10) -> light orange (11-30) -> dark orange (31-80) -> red (80-150+).
        colorExpression: ['step', ['get', 'total'], '#38bdf8', 11, '#fb923c', 31, '#c2410c', 80, '#dc2626'],
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
        const hits = map.queryRenderedFeatures(e.point, { layers: ['airports-circles', 'live-flights-symbols'] });
        if (hits.length === 0) {
            clearSelection();
        }
    });

    document.getElementById('hide-ungrounded')?.addEventListener('change', (e) => {
        hideUngrounded = e.target.checked;
        map.setFilter('live-flights-symbols', hideUngrounded ? ['==', ['get', 'connected_on_ground'], true] : null);
        applySelection();
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

        applySelection();
    };

    refresh();
    setInterval(refresh, POLL_MS);
});
