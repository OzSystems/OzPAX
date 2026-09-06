import {
    createMap, emptyCollection, addAirportsLayer, addFirBoundariesLayer, fetchJson,
    DEPARTURE_COLOR, ARRIVAL_COLOR, legendDot, popupSection,
    TIER_COLOR_HEX, TIER_COLORS, TIER_RADIUS_EXPRESSION, attachLatProperty,
} from './map-base.js';

const LIVE_FLIGHTS_URL = '/flights/live/flights';
const LIVE_AIRPORTS_URL = '/flights/live/airports';
const LIVE_PASSENGERS_URL = '/flights/live/passengers';
const LIVE_MOST_TRAVELLED_URL = '/flights/live/most-travelled';
const passengerDetailUrl = (id) => `/flights/live/passengers/${id}`;
const POLL_MS = 20_000; // VATSIM datafeed is cached server-side for 15s
const TRAVELLED_PANEL_MINIMIZED_KEY = 'ozpax-travelled-panel-minimized';

const map = createMap('map');

let flightsData = emptyCollection(); // airborne only - what's actually drawn as map icons
let groundFlights = []; // on-the-ground sessions, surfaced only via airport popups
let airportsData = emptyCollection();
let selection = null; // { type: 'airport', icao } | { type: 'aircraft', callsign }

// Blue family, deliberately outside the tier palette's red/orange/yellow/
// green/slate-gray spectrum (TIER_COLOR_HEX in map-base.js) - airports are
// always a warm-to-neutral tier color, aircraft are always blue, so the two
// are never confusable by color alone even before shape is considered.
// Three shades within that family carry the three states an airborne icon
// can be in: a deep blue for carrying passengers, a light blue for tracked-
// but-empty, and a pale blue for untracked (see live-flights-symbols'
// icon-image expression - only a connected_on_ground session can ever have
// boarded passengers, so "loaded" and "untracked" are mutually exclusive).
const TRACKED_LOADED_COLOR = '#1d4ed8';
const TRACKED_EMPTY_COLOR = '#38bdf8';
const UNTRACKED_COLOR = '#93c5fd';

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

// Attaches each passenger feature's passengers_json/total onto the matching
// airports feature by icao. liveAirports() already guarantees every airport
// with waiting passengers is present in the airports collection, so this is
// a pure property merge, never a feature-synthesis step. passengers_total is
// duplicated out as a plain number (rather than only inside the JSON string)
// because Mapbox GL style expressions can't parse JSON - they need a real
// property to filter/style the airport-passenger-badge layer by.
function mergeAirportPassengers(airports, passengers) {
    const byIcao = new Map(passengers.features.map((f) => [f.properties.icao, f.properties.passengers_json]));

    return {
        ...airports,
        features: airports.features.map((f) => {
            const passengersJson = byIcao.get(f.properties.icao) ?? null;
            const total = passengersJson ? (JSON.parse(passengersJson).total ?? 0) : 0;

            return { ...f, properties: { ...f.properties, passengers_json: passengersJson, passengers_total: total } };
        }),
    };
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

// Airport popups show aggregate counts only - a Tier-1 airport can have up
// to (config('passengers.tier_caps')) waiting passengers, far too many to
// list individually. Per-passenger click-through lives on the aircraft
// popup instead, where the list is naturally bounded by real seat counts.
function passengersSectionHtml(passengersJson) {
    if (!passengersJson) {
        return popupSection('Passengers', `<span style="color:#64748b;">No passengers waiting</span>`);
    }

    const data = JSON.parse(passengersJson);
    const rows = (data.byDestination ?? [])
        .sort((a, b) => b.count - a.count)
        .map((d) => `<div class="row"><span>→ ${d.icao}${d.tier ? ` (T${d.tier})` : ''}</span><span>${d.count}</span></div>`)
        .join('');

    return popupSection('Passengers', `Waiting: ${data.total ?? 0}<br>${rows}`);
}

// A small "view list" link that opens the full sortable manifest table for
// a flight in #passenger-panel - shared by the on-ground breakdown and the
// airborne aircraft popup, rather than dumping every passenger inline.
function manifestLinkHtml(callsign) {
    return `<a href="#" class="manifest-link" data-callsign="${callsign}">View passenger list &#9656;</a>`;
}

// For a single on-ground flight: its header line (callsign, destination,
// seats filled/capacity) plus - once its manifest has locked (see
// PassengerBoardingEngine) - a destination breakdown of who's actually on
// board, indented beneath it, and a link to the full sortable list.
function groundFlightHtml(p) {
    const manifest = JSON.parse(p.manifest_json ?? '[]');
    const capacity = p.capacity ?? '?';
    const header = `<div class="row"><span>${p.callsign} (${p.aircraft ?? '?'}) → ${p.arr ?? '?'}</span><span>${p.manifest_count ?? 0}/${capacity}</span></div>`;

    if (manifest.length === 0) {
        return `<div class="block">${header}</div>`;
    }

    const byDestination = new Map();
    for (const m of manifest) {
        byDestination.set(m.destination, (byDestination.get(m.destination) ?? 0) + 1);
    }

    const breakdown = [...byDestination.entries()]
        .sort((a, b) => b[1] - a[1])
        .map(([dest, count]) => `<div class="row indent"><span>→ ${dest}</span><span>${count}</span></div>`)
        .join('');

    const link = `<div class="row indent">${manifestLinkHtml(p.callsign)}</div>`;

    return `<div class="block">${header}${breakdown}${link}</div>`;
}

// Aircraft currently on the ground at this airport (parked/taxiing,
// pre-departure) are deliberately not drawn as map icons (see
// live-flights-symbols) - this is the only place they're surfaced.
function groundAircraftSectionHtml(icao) {
    const here = groundFlights.filter((f) => f.properties.dep === icao);

    if (here.length === 0) {
        return popupSection('On the ground', `<span style="color:#64748b;">None</span>`);
    }

    const rows = here.map((f) => groundFlightHtml(f.properties)).join('');

    return popupSection(`On the ground (${here.length})`, rows);
}

function airportPopupHtml(p) {
    const depNote = p.departuresRerouted ? ` (${p.departuresRerouted} rerouted here)` : '';
    const arrNote = p.arrivalsRerouted ? ` (${p.arrivalsRerouted} rerouted here)` : '';
    const tierColor = TIER_COLOR_HEX[p.tier] ?? TIER_COLOR_HEX[5];

    return `<strong>${p.icao}</strong> — ${p.name}`
        + popupSection('Tier', `${legendDot(tierColor)}Tier ${p.tier} <span style="color:#64748b;">(${p.movements_8w ?? 0} movements/8wk)</span>`)
        + popupSection('Traffic now', ''
            + `${legendDot(DEPARTURE_COLOR)}Departures: ${p.departures}${depNote}<br>`
            + `${legendDot(ARRIVAL_COLOR)}Arrivals: ${p.arrivals}${arrNote}<br>`
            + `Total: ${p.total}`)
        + groundAircraftSectionHtml(p.icao)
        + passengersSectionHtml(p.passengers_json);
}

function manifestSectionHtml(callsign, manifestCount) {
    if (!manifestCount) {
        return popupSection('Passengers onboard', `<span style="color:#64748b;">None</span>`);
    }

    return popupSection(`Passengers onboard (${manifestCount})`, manifestLinkHtml(callsign));
}

function aircraftPopupHtml(p) {
    if (!p.connected_on_ground) {
        return `<strong>${p.callsign}</strong><br>Connected in air, will not count to data`;
    }

    return `<strong>${p.callsign}</strong><br>`
        + `${legendDot(DEPARTURE_COLOR)}${p.dep ?? '?'} → ${legendDot(ARRIVAL_COLOR)}${p.arr ?? '?'}<br>`
        + `${p.aircraft ?? ''}<br>FL${Math.round((p.altitude ?? 0) / 100)} / ${p.groundspeed ?? 0} kt`
        + manifestSectionHtml(p.callsign, p.manifest_count);
}

// --- #passenger-panel: shared dark-theme layout helpers ---
//
// popupSection/legendDot (map-base.js) are calibrated for Mapbox's default
// *light* popup chrome - reusing them as-is inside this dark floating panel
// left muted text and dividers rendered in light-on-light colors with poor
// contrast. These are the panel-native equivalents.

// Every panel view starts with the same close control, wired up the same
// way - centralised so the color/behavior only needs fixing in one place.
function closeButtonHtml() {
    return `<a href="#" id="passenger-panel-close" class="panel-close">✕</a>`;
}

function bindPanelClose(panel, onClose) {
    document.getElementById('passenger-panel-close')?.addEventListener('click', (e) => {
        e.preventDefault();
        panel.hidden = true;
        onClose?.();
    });
}

// A divider-topped labelled block, styled for #passenger-panel's dark
// background (see popupSection in map-base.js for the light-popup version
// this mirrors).
function panelSection(heading, bodyHtml) {
    return `<div class="panel-section">`
        + `<div class="panel-section-heading">${heading}</div>`
        + bodyHtml
        + `</div>`;
}

// --- Sortable passenger manifest table (#passenger-panel) ---

let manifestTableState = null; // { callsign, manifest, sortKey, sortDir }

function manifestTableHtml({ callsign, manifest, sortKey, sortDir }) {
    const sorted = [...manifest].sort((a, b) => {
        const cmp = String(a[sortKey] ?? '').localeCompare(String(b[sortKey] ?? ''));
        return sortDir === 'asc' ? cmp : -cmp;
    });

    const arrow = (key) => (sortKey === key ? (sortDir === 'asc' ? ' ▲' : ' ▼') : '');
    const rows = sorted
        .map((m) => `<tr><td><a href="#" class="pax-link" data-id="${m.id}">${m.name}</a></td><td>${m.destination}</td></tr>`)
        .join('');

    return closeButtonHtml()
        + `<div class="panel-header"><strong>${callsign}</strong><br><span class="panel-muted">${manifest.length} passengers onboard</span></div>`
        + `<table class="pax-table"><thead><tr>`
        + `<th data-sort="name">Name${arrow('name')}</th>`
        + `<th data-sort="destination">Destination${arrow('destination')}</th>`
        + `</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderManifestTable() {
    const panel = document.getElementById('passenger-panel');
    if (!panel || !manifestTableState) return;

    panel.innerHTML = manifestTableHtml(manifestTableState);
    panel.hidden = false;
    minimizeTravelledPanel();
    bindPanelClose(panel, () => (manifestTableState = null));
}

function showManifestTable(callsign, manifestJson) {
    manifestTableState = { callsign, manifest: JSON.parse(manifestJson ?? '[]'), sortKey: 'destination', sortDir: 'asc' };
    renderManifestTable();
}

document.addEventListener('click', (e) => {
    const link = e.target.closest('.manifest-link');
    if (!link) return;
    e.preventDefault();

    const callsign = link.dataset.callsign;
    const feature = [...flightsData.features, ...groundFlights].find((f) => f.properties.callsign === callsign);
    if (feature) showManifestTable(callsign, feature.properties.manifest_json);
});

document.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-sort]');
    if (!th || !manifestTableState) return;

    const key = th.dataset.sort;
    if (manifestTableState.sortKey === key) {
        manifestTableState.sortDir = manifestTableState.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        manifestTableState.sortKey = key;
        manifestTableState.sortDir = 'asc';
    }

    renderManifestTable();
});

function passengerDetailHtml(d) {
    const rows = d.history.length
        ? d.history.map((leg) => `<div class="row"><span>${leg.callsign}</span><span>${leg.dep} → ${leg.arr}</span></div>`).join('')
        : '<div class="row panel-muted">No completed legs yet</div>';

    return closeButtonHtml()
        + `<div class="panel-header"><strong>${d.name}</strong><br><span class="panel-muted">${d.origin} → ${d.destination}</span></div>`
        + panelSection('Status', `${d.status} at ${d.current_icao}`)
        + panelSection('Journey so far', rows);
}

function selectPassenger(id) {
    const panel = document.getElementById('passenger-panel');
    if (!panel) return;

    fetchJson(passengerDetailUrl(id)).then((detail) => {
        manifestTableState = null;
        panel.innerHTML = passengerDetailHtml(detail);
        panel.hidden = false;
        minimizeTravelledPanel();
        bindPanelClose(panel);
    });
}

document.addEventListener('click', (e) => {
    const link = e.target.closest('.pax-link');
    if (!link) return;
    e.preventDefault();
    selectPassenger(link.dataset.id);
});

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

// The 50 most-travelled currently-active passengers (see
// MapController::liveMostTravelled), ranked by completed-leg count -
// already sorted server-side, so this just renders the order it arrives
// in. Each name is a standard .pax-link, picked up by the same delegated
// click handler that opens #passenger-panel for any other passenger link
// on the page - no extra wiring needed here.
let mostTravelled = [];

function renderMostTravelled() {
    const body = document.getElementById('travelled-panel-body');
    if (!body) return;

    body.innerHTML = mostTravelled.length
        ? mostTravelled.map((p, i) => (
            `<div class="row">`
            + `<span><span class="rank">${i + 1}.</span><a href="#" class="pax-link" data-id="${p.id}">${p.name}</a></span>`
            + `<span>${p.flights}</span>`
            + `</div>`
        )).join('')
        : '<div class="row"><span>No completed flights yet</span></div>';
}

// Called whenever #passenger-panel opens (a single passenger's detail or a
// flight's full manifest table) - the two panels compete for the same
// corner of the screen, so opening one tucks the other away rather than
// leaving both fighting for attention.
function minimizeTravelledPanel() {
    const panel = document.getElementById('travelled-panel');
    if (!panel) return;

    panel.classList.add('minimized');

    try {
        localStorage.setItem(TRAVELLED_PANEL_MINIMIZED_KEY, '1');
    } catch {
        // Fine to just not persist it.
    }
}

// Persisted per-viewer so the panel doesn't pop back open on every reload
// once someone's tucked it away - purely a display preference, never sent
// anywhere.
function initTravelledPanelToggle() {
    const panel = document.getElementById('travelled-panel');
    const toggle = document.getElementById('travelled-panel-toggle');
    if (!panel || !toggle) return;

    // Closed by default - only stays open across a reload if the viewer
    // has explicitly expanded it before (a stored '0').
    let minimized = true;
    try {
        const stored = localStorage.getItem(TRAVELLED_PANEL_MINIMIZED_KEY);
        minimized = stored === null ? true : stored === '1';
    } catch {
        // Private browsing / storage blocked - just default to closed.
    }
    panel.classList.toggle('minimized', minimized);

    toggle.addEventListener('click', () => {
        minimized = panel.classList.toggle('minimized');
        try {
            localStorage.setItem(TRAVELLED_PANEL_MINIMIZED_KEY, minimized ? '1' : '0');
        } catch {
            // Fine to just not persist it.
        }
    });
}

map.on('load', async () => {
    initTravelledPanelToggle();
    addFirBoundariesLayer(map);

    addAirportsLayer(map, {
        onClick: (feature) => selectAirport(feature.properties.icao),
        popupHtml: airportPopupHtml,
        // A fixed real-world radius (tier-dependent) rather than a flat
        // pixel size - see TIER_RADIUS_EXPRESSION in map-base.js.
        radiusExpression: TIER_RADIUS_EXPRESSION,
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

    // Airports with at least one waiting passenger get a small badge above
    // their circle - the count in dark text on an amber halo, so it reads
    // as a distinct "people here" marker at a glance without needing to
    // click through to the popup. Text-based rather than an icon image, so
    // it doesn't depend on the map style's glyph set including any specific
    // symbol/emoji.
    map.addLayer({
        id: 'airports-passenger-badge',
        type: 'symbol',
        source: 'airports',
        filter: ['>', ['get', 'passengers_total'], 0],
        layout: {
            'text-field': ['to-string', ['get', 'passengers_total']],
            'text-size': 11,
            'text-font': ['DIN Pro Bold', 'Arial Unicode MS Bold'],
            'text-offset': [0, -1.6],
            'text-anchor': 'bottom',
            'text-allow-overlap': true,
            'text-ignore-placement': true,
        },
        paint: {
            'text-color': '#0c2a43',
            'text-halo-color': '#facc15',
            'text-halo-width': 3,
        },
    });

    const [loadedIcon, emptyIcon, untrackedIcon] = await Promise.all([
        loadAircraftIcon(TRACKED_LOADED_COLOR),
        loadAircraftIcon(TRACKED_EMPTY_COLOR),
        loadAircraftIcon(UNTRACKED_COLOR),
    ]);
    map.addImage('aircraft-loaded', loadedIcon, { pixelRatio: 2 });
    map.addImage('aircraft-tracked', emptyIcon, { pixelRatio: 2 });
    map.addImage('aircraft-untracked', untrackedIcon, { pixelRatio: 2 });

    // Only airborne flights get a map icon - on-the-ground aircraft are
    // deliberately not drawn as map symbols (they'd sit exactly on top of
    // their departure airport's circle with no useful heading), they're
    // surfaced instead in that airport's own popup (see
    // groundAircraftSectionHtml, built from groundFlights).
    map.addSource('live-flights', { type: 'geojson', data: emptyCollection() });

    map.addLayer({
        id: 'live-flights-symbols',
        type: 'symbol',
        source: 'live-flights',
        layout: {
            'icon-image': [
                'case',
                ['!', ['get', 'connected_on_ground']], 'aircraft-untracked',
                ['>', ['get', 'manifest_count'], 0], 'aircraft-loaded',
                'aircraft-tracked',
            ],
            'icon-size': 1.2,
            'icon-rotate': ['get', 'heading'],
            'icon-rotation-alignment': 'map',
            'icon-allow-overlap': true,
        },
    });

    // Same amber-badge treatment as the airport passenger count (see
    // airports-passenger-badge) - a quick visual "this flight is carrying
    // passengers" cue without needing to click every aircraft to find out.
    // Fixed screen-space offset (not tied to icon-rotate), so it stays
    // legible regardless of the aircraft's heading.
    map.addLayer({
        id: 'live-flights-passenger-badge',
        type: 'symbol',
        source: 'live-flights',
        filter: ['>', ['get', 'manifest_count'], 0],
        layout: {
            'text-field': ['to-string', ['get', 'manifest_count']],
            'text-size': 10,
            'text-font': ['DIN Pro Bold', 'Arial Unicode MS Bold'],
            'text-offset': [1.1, -0.9],
            'text-anchor': 'left',
            'text-allow-overlap': true,
            'text-ignore-placement': true,
        },
        paint: {
            'text-color': '#0c2a43',
            'text-halo-color': ARRIVAL_COLOR,
            'text-halo-width': 3,
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
        const hits = map.queryRenderedFeatures(e.point, {
            layers: ['airports-circles', 'airports-labels', 'airports-passenger-badge', 'live-flights-symbols', 'live-flights-passenger-badge'],
        });
        if (hits.length === 0) {
            clearSelection();
        }
    });

    const refresh = async () => {
        const [flights, airports, passengers, travelled] = await Promise.all([
            fetchJson(LIVE_FLIGHTS_URL),
            fetchJson(LIVE_AIRPORTS_URL),
            fetchJson(LIVE_PASSENGERS_URL),
            fetchJson(LIVE_MOST_TRAVELLED_URL),
        ]);

        groundFlights = flights.features.filter((f) => f.properties.status === 'on_ground');
        flightsData = { ...flights, features: flights.features.filter((f) => f.properties.status !== 'on_ground') };
        airportsData = attachLatProperty(mergeAirportPassengers(airports, passengers));
        mostTravelled = travelled;

        map.getSource('live-flights')?.setData(flightsData);
        map.getSource('airports')?.setData(airportsData);

        applySelection();
        renderStats();
        renderMostTravelled();
    };

    refresh();
    setInterval(refresh, POLL_MS);
});
