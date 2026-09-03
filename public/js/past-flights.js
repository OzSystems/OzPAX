import { createMap, emptyCollection, addAirportsLayer, addFirBoundariesLayer, fetchJson, DEPARTURE_COLOR, ARRIVAL_COLOR, legendDot } from './map-base.js';

const PAST_AIRPORTS_URL = '/flights/past/airports';
const PAST_ROUTES_URL = '/flights/past/routes';
const POLL_MS = 60_000; // historic totals only change as flights complete

const map = createMap('map');

let routesData = emptyCollection();
let airportsData = emptyCollection();
let selectedIcao = null;

function connectedIcaos(icao) {
    const connected = new Set();

    for (const feature of routesData.features) {
        const { airport_a, airport_b } = feature.properties;
        if (airport_a === icao) connected.add(airport_b);
        if (airport_b === icao) connected.add(airport_a);
    }

    return connected;
}

/**
 * Every route touching `icao`, reoriented so `outbound`/`inbound` are always
 * relative to it (i.e. departures from icao vs arrivals into icao), sorted
 * by busiest connection first.
 */
function routesForAirport(icao) {
    const rows = [];

    for (const feature of routesData.features) {
        const { airport_a, airport_b, a_to_b, b_to_a } = feature.properties;

        if (airport_a === icao) {
            rows.push({ icao: airport_b, outbound: a_to_b, inbound: b_to_a });
        } else if (airport_b === icao) {
            rows.push({ icao: airport_a, outbound: b_to_a, inbound: a_to_b });
        }
    }

    return rows.sort((a, b) => (b.outbound + b.inbound) - (a.outbound + a.inbound));
}

function selectAirport(icao) {
    selectedIcao = icao;
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

    renderStats();
}

function clearSelection() {
    selectedIcao = null;

    map.setPaintProperty('airports-circles', 'circle-opacity', 0.8);
    map.setPaintProperty('airports-circles', 'circle-stroke-width', 1);
    map.setLayoutProperty('routes-lines', 'visibility', 'none');

    renderStats();
}

function routePopupHtml(p) {
    let html = `<strong>${p.airport_a} ↔ ${p.airport_b}</strong><br>`
        + `${legendDot(DEPARTURE_COLOR)}${p.airport_a} → ${p.airport_b}: ${p.a_to_b}<br>`
        + `${legendDot(ARRIVAL_COLOR)}${p.airport_b} → ${p.airport_a}: ${p.b_to_a}`;

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

function renderTopAirports() {
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

    return `<div class="heading">Top 30 airports</div>${rows}`;
}

/**
 * Every route to/from the selected airport, each row showing how many
 * flights went each direction - e.g. selecting YBAS shows "5 → YPDN" and
 * "6 ← YPDN" using the same departure/arrival colors as the route popups.
 */
function renderSelectedAirport(icao) {
    const airport = airportsData.features.find((f) => f.properties.icao === icao);
    const routes = routesForAirport(icao);

    const rows = routes.length
        ? routes.map((r) => (
            `<div class="row">`
            + `<a href="#" class="icao" data-icao="${r.icao}">${r.icao}</a>`
            + `<span class="counts">${legendDot(DEPARTURE_COLOR)}${r.outbound}&nbsp;&nbsp;${legendDot(ARRIVAL_COLOR)}${r.inbound}</span>`
            + `</div>`
        )).join('')
        : '<div class="row"><span>No completed flights yet</span></div>';

    return `
        <div class="heading"><a href="#" id="back-to-top">‹ Top 30 airports</a></div>
        <div class="row"><strong>${icao}</strong><span class="counts">${airport?.properties.total ?? 0} total</span></div>
        <div class="heading">Routes (${legendDot(DEPARTURE_COLOR)}out / ${legendDot(ARRIVAL_COLOR)}in)</div>
        ${rows}
    `;
}

function renderStats() {
    const stats = document.getElementById('stats');
    if (!stats) return;

    stats.innerHTML = selectedIcao ? renderSelectedAirport(selectedIcao) : renderTopAirports();
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
        const hits = map.queryRenderedFeatures(e.point, { layers: ['airports-circles', 'airports-labels', 'routes-lines'] });
        if (hits.length === 0) {
            clearSelection();
        }
    });

    document.getElementById('stats')?.addEventListener('click', (e) => {
        if (e.target.closest('#back-to-top')) {
            e.preventDefault();
            clearSelection();
            return;
        }

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
