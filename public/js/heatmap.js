import { createMap, emptyCollection, addAirportsLayer, addFirBoundariesLayer, fetchJson, DEPARTURE_COLOR, ARRIVAL_COLOR, legendDot } from './map-base.js';

const RANGE_URLS = {
    all: { airports: '/flights/past/airports', routes: '/flights/past/routes' },
    recent: { airports: '/flights/recent/airports', routes: '/flights/recent/routes' },
};
const POLL_MS = 60_000; // historic totals only change as flights complete
const TOP_CORRIDORS_COUNT = 12;

const map = createMap('map');

let routesData = emptyCollection();
let airportsData = emptyCollection();
let selectedIcao = null;
let range = 'all';

// Single-hue sequential ramp (magnitude encoding: "how much traffic"),
// anchored for a dark basemap - near-zero fades toward the map's own dark
// background, the busiest corridors/airports glow near-white. Shared between
// the Mapbox paint expressions (heatColorExpression) and the plain-JS swatch
// renderer (heatColorAt) used in the sidebar list, so both always agree.
const HEAT_STOPS = [
    [0, '#0d366b'],
    [0.06, '#104281'],
    [0.15, '#184f95'],
    [0.3, '#256abf'],
    [0.5, '#3987e5'],
    [0.7, '#6da7ec'],
    [0.88, '#9ec5f4'],
    [1, '#cde2fb'],
];

// A linear count/max ratio crushes everything below the single busiest
// corridor into the bottom of the scale whenever one route dominates by a
// wide margin (e.g. 361 vs a next-busiest of 55) - sqrt spreads the lower
// end back out so meaningfully-different corridors still look different,
// while the busiest one still tops out at 1.
function magnitudeRatio(property, max) {
    return ['sqrt', ['/', ['get', property], Math.max(1, max)]];
}

function heatColorExpression(property, max) {
    const expression = ['interpolate', ['linear'], magnitudeRatio(property, max)];
    for (const [ratio, hex] of HEAT_STOPS) expression.push(ratio, hex);
    return expression;
}

function hexToRgb(hex) {
    const n = parseInt(hex.slice(1), 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function rgbToHex([r, g, b]) {
    return '#' + [r, g, b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');
}

// `ratio` here is the raw (linear) count/max fraction - sqrt matches the
// same curve magnitudeRatio() applies in the map's own paint expressions,
// so a sidebar swatch always matches what's drawn on the map.
function heatColorAt(ratio) {
    ratio = Math.sqrt(Math.min(1, Math.max(0, ratio)));

    for (let i = 0; i < HEAT_STOPS.length - 1; i++) {
        const [r0, c0] = HEAT_STOPS[i];
        const [r1, c1] = HEAT_STOPS[i + 1];

        if (ratio <= r1) {
            const t = (ratio - r0) / (r1 - r0 || 1);
            const a = hexToRgb(c0);
            const b = hexToRgb(c1);
            return rgbToHex(a.map((v, idx) => v + (b[idx] - v) * t));
        }
    }

    return HEAT_STOPS.at(-1)[1];
}

function widthExpression(property, max, stops) {
    const expression = ['interpolate', ['linear'], magnitudeRatio(property, max)];
    for (const [ratio, value] of stops) expression.push(ratio, value);
    return expression;
}

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

function airportPopupHtml(p) {
    return `<strong>${p.icao}</strong> — ${p.name}<br>Departures: ${p.departures}<br>Arrivals: ${p.arrivals}<br>Total: ${p.total}`;
}

function maxCount() {
    return Math.max(1, ...routesData.features.map((f) => f.properties.count));
}

function maxAirportTotal() {
    return Math.max(1, ...airportsData.features.map((f) => f.properties.total));
}

function flyToAirport(icao) {
    const feature = airportsData.features.find((f) => f.properties.icao === icao);
    if (!feature) return;

    map.flyTo({ center: feature.geometry.coordinates, zoom: 8 });
}

function flyToRoute(feature) {
    const [a, b] = feature.geometry.coordinates;
    map.fitBounds([a, b], { padding: 120, maxZoom: 8, duration: 800 });
}

function selectAirport(icao) {
    selectedIcao = icao;

    map.setPaintProperty('airports-circles', 'circle-stroke-width', [
        'case', ['==', ['get', 'icao'], icao], 3, 1,
    ]);
    map.setPaintProperty('airports-circles', 'circle-stroke-color', [
        'case', ['==', ['get', 'icao'], icao], '#ffffff', '#0c2a43',
    ]);

    renderStats();
}

function clearSelection() {
    selectedIcao = null;

    map.setPaintProperty('airports-circles', 'circle-stroke-width', 1);
    map.setPaintProperty('airports-circles', 'circle-stroke-color', '#0c2a43');

    renderStats();
}

function renderRangeToggle() {
    return `
        <div id="range-toggle">
            <button type="button" data-range="all" class="${range === 'all' ? 'active' : ''}">All Time</button>
            <button type="button" data-range="recent" class="${range === 'recent' ? 'active' : ''}">Last 8 Weeks</button>
        </div>
    `;
}

function renderLegend() {
    return `
        <div id="legend-bar"></div>
        <div id="legend-labels"><span>Quiet</span><span>Busiest corridors</span></div>
    `;
}

function renderTopCorridors() {
    const max = maxCount();
    const top = [...routesData.features]
        .sort((a, b) => b.properties.count - a.properties.count)
        .slice(0, TOP_CORRIDORS_COUNT);

    const rows = top.length
        ? top.map((f) => {
            const p = f.properties;
            const swatch = heatColorAt(p.count / max);

            return `<div class="row">`
                + `<span><span class="swatch" style="background:${swatch}"></span> `
                + `<a href="#" class="route" data-a="${p.airport_a}" data-b="${p.airport_b}">${p.airport_a} ↔ ${p.airport_b}</a></span>`
                + `<span class="counts">${p.count}</span>`
                + `</div>`;
        }).join('')
        : '<div class="row"><span>No completed flights yet</span></div>';

    return `${renderLegend()}<div class="heading">Busiest corridors</div>${rows}`;
}

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
        ${renderLegend()}
        <div class="heading"><a href="#" id="back-to-top">‹ Busiest corridors</a></div>
        <div class="row"><strong>${icao}</strong><span class="counts">${airport?.properties.total ?? 0} total</span></div>
        <div class="heading">Connections (${legendDot(DEPARTURE_COLOR)}out / ${legendDot(ARRIVAL_COLOR)}in)</div>
        ${rows}
    `;
}

function renderStats() {
    const stats = document.getElementById('stats');
    if (!stats) return;

    stats.innerHTML = renderRangeToggle() + (selectedIcao ? renderSelectedAirport(selectedIcao) : renderTopCorridors());
}

map.on('load', async () => {
    addFirBoundariesLayer(map);

    map.addSource('heatmap-routes', { type: 'geojson', data: emptyCollection() });

    // Glow: a wide, blurred underlay so busy corridors visibly radiate,
    // making them easy to pick out at a glance even at low zoom.
    map.addLayer({
        id: 'heatmap-routes-glow',
        type: 'line',
        source: 'heatmap-routes',
        layout: { 'line-cap': 'round' },
        paint: { 'line-blur': 4 },
    });

    // Core: a crisp line on top of the glow carrying the same color ramp.
    map.addLayer({
        id: 'heatmap-routes-core',
        type: 'line',
        source: 'heatmap-routes',
        layout: { 'line-cap': 'round' },
        paint: {},
    });

    addAirportsLayer(map, {
        onClick: (feature) => selectAirport(feature.properties.icao),
        popupHtml: airportPopupHtml,
    });
    // A soft glow on airport nodes too, consistent with the corridor lines.
    map.setPaintProperty('airports-circles', 'circle-blur', 0.25);

    map.on('click', 'heatmap-routes-core', (e) => {
        new mapboxgl.Popup()
            .setLngLat(e.lngLat)
            .setHTML(routePopupHtml(e.features[0].properties))
            .addTo(map);
    });

    map.on('mouseenter', 'heatmap-routes-core', () => (map.getCanvas().style.cursor = 'pointer'));
    map.on('mouseleave', 'heatmap-routes-core', () => (map.getCanvas().style.cursor = ''));

    map.on('click', (e) => {
        const hits = map.queryRenderedFeatures(e.point, { layers: ['airports-circles', 'airports-labels', 'heatmap-routes-core'] });
        if (hits.length === 0) {
            clearSelection();
        }
    });

    document.getElementById('stats')?.addEventListener('click', (e) => {
        const rangeButton = e.target.closest('#range-toggle button');
        if (rangeButton) {
            if (rangeButton.dataset.range !== range) {
                range = rangeButton.dataset.range;
                clearSelection();
                refresh();
            }
            return;
        }

        if (e.target.closest('#back-to-top')) {
            e.preventDefault();
            clearSelection();
            return;
        }

        const routeLink = e.target.closest('a.route');
        if (routeLink) {
            e.preventDefault();
            const { a, b } = routeLink.dataset;
            const feature = routesData.features.find((f) => f.properties.airport_a === a && f.properties.airport_b === b);
            if (feature) flyToRoute(feature);
            return;
        }

        const icaoLink = e.target.closest('a.icao');
        if (icaoLink) {
            e.preventDefault();
            flyToAirport(icaoLink.dataset.icao);
            selectAirport(icaoLink.dataset.icao);
        }
    });

    const refresh = async () => {
        const urls = RANGE_URLS[range];
        const [airports, routes] = await Promise.all([
            fetchJson(urls.airports),
            fetchJson(urls.routes),
        ]);

        airportsData = airports;
        // Busiest corridors last, so they draw on top of the criss-crossing
        // quiet routes instead of being buried under them.
        routesData = { ...routes, features: [...routes.features].sort((a, b) => a.properties.count - b.properties.count) };

        const routeMax = maxCount();
        const airportMax = maxAirportTotal();

        map.setPaintProperty('heatmap-routes-glow', 'line-color', heatColorExpression('count', routeMax));
        map.setPaintProperty('heatmap-routes-glow', 'line-width', widthExpression('count', routeMax, [[0, 2], [0.3, 6], [0.6, 11], [1, 18]]));
        map.setPaintProperty('heatmap-routes-glow', 'line-opacity', widthExpression('count', routeMax, [[0, 0.04], [0.3, 0.1], [0.6, 0.18], [1, 0.32]]));

        map.setPaintProperty('heatmap-routes-core', 'line-color', heatColorExpression('count', routeMax));
        map.setPaintProperty('heatmap-routes-core', 'line-width', widthExpression('count', routeMax, [[0, 0.6], [0.3, 1.6], [0.6, 3], [1, 5.5]]));
        map.setPaintProperty('heatmap-routes-core', 'line-opacity', widthExpression('count', routeMax, [[0, 0.22], [0.3, 0.5], [0.6, 0.75], [1, 0.95]]));

        map.setPaintProperty('airports-circles', 'circle-color', heatColorExpression('total', airportMax));
        map.setPaintProperty('airports-circles', 'circle-radius', widthExpression('total', airportMax, [[0, 3], [0.3, 6], [0.6, 10], [1, 16]]));
        map.setPaintProperty('airports-circles', 'circle-opacity', widthExpression('total', airportMax, [[0, 0.5], [0.3, 0.7], [0.6, 0.85], [1, 1]]));

        map.getSource('heatmap-routes')?.setData(routesData);
        map.getSource('airports')?.setData(airportsData);

        renderStats();
    };

    await refresh();
    setInterval(refresh, POLL_MS);
});
