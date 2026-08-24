import { createMap, emptyCollection, addAirportsLayer, addFirBoundariesLayer, fetchJson } from './map-base.js';

const PAST_AIRPORTS_URL = '/flights/past/airports';
const PAST_ROUTES_URL = '/flights/past/routes';
const POLL_MS = 60_000; // historic totals only change as flights complete

const map = createMap('map');

let routesData = emptyCollection();
let airportsData = emptyCollection();

function connectedIcaos(icao) {
    const connected = new Set();

    for (const feature of routesData.features) {
        const { airport_a, airport_b } = feature.properties;
        if (airport_a === icao) connected.add(airport_b);
        if (airport_b === icao) connected.add(airport_a);
    }

    return connected;
}

function selectAirport(icao) {
    const highlighted = [icao, ...connectedIcaos(icao)];

    map.setPaintProperty('airports-circles', 'circle-opacity', [
        'case',
        ['in', ['get', 'icao'], ['literal', highlighted]], 0.95,
        0.12,
    ]);
    map.setPaintProperty('airports-circles', 'circle-stroke-width', [
        'case',
        ['==', ['get', 'icao'], icao], 3,
        ['in', ['get', 'icao'], ['literal', highlighted]], 1.5,
        0.5,
    ]);

    map.setFilter('routes-lines', [
        'any',
        ['==', ['get', 'airport_a'], icao],
        ['==', ['get', 'airport_b'], icao],
    ]);
    map.setLayoutProperty('routes-lines', 'visibility', 'visible');
}

function clearSelection() {
    map.setPaintProperty('airports-circles', 'circle-opacity', 0.8);
    map.setPaintProperty('airports-circles', 'circle-stroke-width', 1);
    map.setLayoutProperty('routes-lines', 'visibility', 'none');
}

function routePopupHtml(p) {
    let html = `<strong>${p.airport_a} ↔ ${p.airport_b}</strong><br>Flights: ${p.count}`;

    if (p.reroutes && p.reroutes.length > 0) {
        html += '<br><br>Includes flights originally filed as:';
        for (const r of p.reroutes) {
            html += `<br>${r.a} ↔ ${r.b}: ${r.count}`;
        }
    }

    return html;
}

function flyToAirport(icao) {
    const feature = airportsData.features.find((f) => f.properties.icao === icao);
    if (!feature) return;

    map.flyTo({ center: feature.geometry.coordinates, zoom: 9 });
    selectAirport(icao);
}

function renderStats() {
    const stats = document.getElementById('stats');
    if (!stats) return;

    const top = [...airportsData.features]
        .sort((a, b) => b.properties.total - a.properties.total)
        .slice(0, 30);

    const rows = top.length
        ? top.map((f) => (
            `<div class="row">`
            + `<a href="#" class="icao" data-icao="${f.properties.icao}">${f.properties.icao}</a>`
            + `<span class="counts">${f.properties.departures} dep / ${f.properties.arrivals} arr</span>`
            + `</div>`
        )).join('')
        : '<div class="row"><span>—</span></div>';

    stats.innerHTML = `<div class="heading">Top 30 airports</div>${rows}`;
}

map.on('load', async () => {
    addFirBoundariesLayer(map);

    addAirportsLayer(map, { onClick: (feature) => selectAirport(feature.properties.icao) });

    map.addSource('routes', { type: 'geojson', data: emptyCollection() });

    map.addLayer({
        id: 'routes-lines',
        type: 'line',
        source: 'routes',
        layout: { visibility: 'none' },
        paint: {
            'line-color': '#38bdf8',
            'line-width': ['interpolate', ['linear'], ['get', 'count'], 1, 1.5, 20, 4, 100, 7],
            'line-opacity': 0.85,
        },
    });

    map.on('click', 'routes-lines', (e) => {
        new mapboxgl.Popup()
            .setLngLat(e.lngLat)
            .setHTML(routePopupHtml(e.features[0].properties))
            .addTo(map);
    });

    map.on('mouseenter', 'routes-lines', () => (map.getCanvas().style.cursor = 'pointer'));
    map.on('mouseleave', 'routes-lines', () => (map.getCanvas().style.cursor = ''));

    // Clicking empty map background (i.e. not an airport or a route line)
    // clears the current highlight/selection.
    map.on('click', (e) => {
        const hits = map.queryRenderedFeatures(e.point, { layers: ['airports-circles', 'routes-lines'] });
        if (hits.length === 0) {
            clearSelection();
        }
    });

    document.getElementById('stats')?.addEventListener('click', (e) => {
        const link = e.target.closest('a.icao');
        if (!link) return;

        e.preventDefault();
        flyToAirport(link.dataset.icao);
    });

    const refresh = async () => {
        airportsData = await fetchJson(PAST_AIRPORTS_URL);
        map.getSource('airports')?.setData(airportsData);

        routesData = await fetchJson(PAST_ROUTES_URL);
        map.getSource('routes')?.setData(routesData);

        renderStats();
    };

    await refresh();
    setInterval(refresh, POLL_MS);
});
