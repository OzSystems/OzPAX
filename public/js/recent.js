import {
    createMap, emptyCollection, addAirportsLayer, addFirBoundariesLayer, fetchJson,
    DEPARTURE_COLOR, ARRIVAL_COLOR, legendDot, popupSection,
    TIER_COLOR_HEX, TIER_COLORS, TIER_RADIUS_EXPRESSION, attachLatProperty,
} from './map-base.js';

const RECENT_AIRPORTS_URL = '/flights/recent/airports';
const RECENT_ROUTES_URL = '/flights/recent/routes';
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

// Airport icons follow the live map's format - tier color/size and the same
// sectioned popup layout - but "Traffic" here is the 8-week total rather
// than what's happening on the network right now.
function airportPopupHtml(p) {
    const depNote = p.departuresRerouted ? ` (${p.departuresRerouted} rerouted here)` : '';
    const arrNote = p.arrivalsRerouted ? ` (${p.arrivalsRerouted} rerouted here)` : '';
    const tierColor = TIER_COLOR_HEX[p.tier] ?? TIER_COLOR_HEX[5];

    return `<strong>${p.icao}</strong> — ${p.name}`
        + popupSection('Tier', `${legendDot(tierColor)}Tier ${p.tier} <span style="color:#64748b;">(${p.movements_8w ?? 0} movements/8wk)</span>`)
        + popupSection('Last 8 weeks', ''
            + `${legendDot(DEPARTURE_COLOR)}Departures: ${p.departures}${depNote}<br>`
            + `${legendDot(ARRIVAL_COLOR)}Arrivals: ${p.arrivals}${arrNote}<br>`
            + `Total: ${p.total}`)
        // Itinerary generation doesn't exist yet - placeholder, matching the
        // live map's popup, ready for real passenger counts once it does.
        + popupSection('Passengers', `<span style="color:#64748b;">Passenger itineraries coming soon</span>`);
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
            `<tr>`
            + `<td><a href="#" class="icao" data-icao="${f.properties.icao}">${legendDot(TIER_COLOR_HEX[f.properties.tier] ?? TIER_COLOR_HEX[5])}${f.properties.icao}</a></td>`
            + `<td>${f.properties.departures}</td>`
            + `<td>${f.properties.arrivals}</td>`
            + `<td>${f.properties.total}</td>`
            + `</tr>`
        )).join('')
        : '<tr><td colspan="4">—</td></tr>';

    return `<div class="heading">Top 30 airports (8wk)</div>`
        + `<table class="top-airports">`
        + `<thead><tr><th></th><th>Dep</th><th>Arr</th><th>Total</th></tr></thead>`
        + `<tbody>${rows}</tbody>`
        + `</table>`;
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
        : '<div class="row"><span>No flights in the last 8 weeks</span></div>';

    const tier = airport?.properties.tier;
    const tierNote = tier ? ` · ${legendDot(TIER_COLOR_HEX[tier] ?? TIER_COLOR_HEX[5])}Tier ${tier}` : '';

    return `
        <div class="heading"><a href="#" id="back-to-top">‹ Top 30 airports</a></div>
        <div class="row"><strong>${icao}</strong><span class="counts">${airport?.properties.total ?? 0} total${tierNote}</span></div>
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

    addAirportsLayer(map, {
        onClick: (feature) => selectAirport(feature.properties.icao),
        popupHtml: airportPopupHtml,
        // Tier color/size, matching the live map - see map-base.js.
        radiusExpression: TIER_RADIUS_EXPRESSION,
        colorExpression: TIER_COLORS,
    });

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
        airportsData = attachLatProperty(await fetchJson(RECENT_AIRPORTS_URL));
        map.getSource('airports')?.setData(airportsData);

        routesData = await fetchJson(RECENT_ROUTES_URL);
        map.getSource('routes')?.setData(routesData);

        renderStats();
    };

    await refresh();
    setInterval(refresh, POLL_MS);
});
