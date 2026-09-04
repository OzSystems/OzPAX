export function createMap(containerId) {
    mapboxgl.accessToken = document.querySelector('meta[name="mapbox-token"]').content;

    const map = new mapboxgl.Map({
        container: containerId,
        style: 'mapbox://styles/mapbox/dark-v11',
        center: [133.7751, -25.2744], // Australia
        zoom: 3.5,
    });

    map.addControl(new mapboxgl.NavigationControl(), 'top-right');

    return map;
}

export function emptyCollection() {
    return { type: 'FeatureCollection', features: [] };
}

// Shared so that route-line colors and their popup legends always match.
export const DEPARTURE_COLOR = '#38bdf8';
export const ARRIVAL_COLOR = '#f97316';

export function legendDot(color) {
    return `<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${color};margin-right:5px;"></span>`;
}

// A labelled, divider-topped block within a popup - keeps distinct kinds of
// information (e.g. tier, traffic, passengers) visually separate instead of
// all running together as one flat list of lines. Shared across every map's
// airport popup so they all read the same way.
export function popupSection(heading, bodyHtml) {
    return `<div style="margin-top:8px;padding-top:6px;border-top:1px solid #e2e8f0;">`
        + `<div style="font-size:10px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:#64748b;margin-bottom:3px;">${heading}</div>`
        + bodyHtml
        + `</div>`;
}

export function fetchJson(url) {
    return fetch(url).then((res) => res.json());
}

export function refreshSource(map, sourceId, url) {
    fetchJson(url)
        .then((data) => map.getSource(sourceId)?.setData(data))
        .catch((err) => console.error(`${sourceId} refresh failed`, err));
}

// Five clearly distinct hues (not shades of one color) so tiers are easy to
// tell apart at a glance - ordered like a heat scale, hottest/most
// important (Tier 1) to coolest/quietest (Tier 5, the vast majority of
// airports, deliberately muted so the long tail doesn't clutter the map).
// Shared by every map that shows tier-colored airports, so they always agree.
export const TIER_COLOR_HEX = { 1: '#ef4444', 2: '#f97316', 3: '#eab308', 4: '#22c55e', 5: '#64748b' };

export const TIER_COLORS = ['match', ['get', 'tier'],
    1, TIER_COLOR_HEX[1],
    2, TIER_COLOR_HEX[2],
    3, TIER_COLOR_HEX[3],
    4, TIER_COLOR_HEX[4],
    5, TIER_COLOR_HEX[5],
    TIER_COLOR_HEX[5],
];

// Real-world nautical-mile radius per tier.
const TIER_RADIUS_NM = ['match', ['get', 'tier'],
    1, 25,
    2, 15,
    3, 12,
    4, 11,
    5, 8,
    8,
];

// Ground resolution at zoom 20 is ~0.075m/pixel at the equator; dividing by
// cos(latitude) corrects for Web Mercator's north/south stretching. Feeding
// that into a base-2 exponential zoom interpolation from (0, 0) makes the
// circle represent a constant real-world size (the tier's nm radius) at any
// zoom or latitude, rather than a flat pixel radius that never matches the
// map's actual scale as you zoom in or out. Needs each feature to carry its
// latitude as a plain 'lat' property (style expressions can't read it out of
// the geometry) - see attachLatProperty() below.
const NM_IN_METERS = 1852;
const GROUND_RES_AT_Z20 = 0.075;
const TIER_RADIUS_METERS = ['*', NM_IN_METERS, TIER_RADIUS_NM];
const TIER_RADIUS_PX_AT_Z20 = ['/', TIER_RADIUS_METERS, ['*', GROUND_RES_AT_Z20, ['cos', ['*', ['get', 'lat'], Math.PI / 180]]]];
export const TIER_RADIUS_EXPRESSION = ['interpolate', ['exponential', 2], ['zoom'], 0, 0, 20, TIER_RADIUS_PX_AT_Z20];

// TIER_RADIUS_EXPRESSION needs each feature's latitude directly as a
// property - call this on a FeatureCollection before handing it to
// map.getSource(...).setData().
export function attachLatProperty(collection) {
    return {
        ...collection,
        features: collection.features.map((f) => ({
            ...f,
            properties: { ...f.properties, lat: f.geometry.coordinates[1] },
        })),
    };
}

/**
 * Adds VATSpy's worldwide FIR/sector boundary polygons as a light overlay,
 * proxied through our own backend (see MapController::firBoundaries) so the
 * 2MB dataset is fetched from GitHub once and cached rather than on every
 * page load. Purely decorative context - drawn as thin lines only, no fill,
 * so it doesn't compete visually with airports/aircraft on top of it.
 */
export function addFirBoundariesLayer(map) {
    map.addSource('fir-boundaries', { type: 'geojson', data: '/flights/fir-boundaries' });

    map.addLayer({
        id: 'fir-boundaries-lines',
        type: 'line',
        source: 'fir-boundaries',
        paint: {
            'line-color': '#fb923c',
            'line-width': 1,
            'line-opacity': 0.35,
        },
    });
}

/**
 * Adds an 'airports' GeoJSON source + a circle layer sized/colored by the
 * feature's 'total' property, with a click popup and hover cursor. Shared by
 * both the live map (in-progress traffic counts) and the past-flights map
 * (historic totals) - only the data feeding the source (and, optionally, the
 * radius scale) differs.
 */
function defaultAirportPopupHtml(p) {
    const depNote = p.departuresRerouted ? ` (${p.departuresRerouted} rerouted here)` : '';
    const arrNote = p.arrivalsRerouted ? ` (${p.arrivalsRerouted} rerouted here)` : '';

    return `<strong>${p.icao}</strong> — ${p.name}<br>Departures: ${p.departures}${depNote}<br>Arrivals: ${p.arrivals}${arrNote}<br>Total: ${p.total}`;
}

export function addAirportsLayer(map, { onClick, radiusStops, radiusExpression, colorExpression, popupHtml } = {}) {
    map.addSource('airports', { type: 'geojson', data: emptyCollection() });

    const radius = radiusExpression ?? ['interpolate', ['linear'], ['get', 'total'], ...(radiusStops ?? [1, 5, 20, 10, 100, 16, 500, 26])];
    const color = colorExpression ?? ['interpolate', ['linear'], ['get', 'total'], 1, '#38bdf8', 50, '#facc15', 200, '#f97316', 500, '#ef4444'];

    map.addLayer({
        id: 'airports-circles',
        type: 'circle',
        source: 'airports',
        paint: {
            'circle-radius': radius,
            'circle-color': color,
            'circle-opacity': 0.8,
            'circle-stroke-width': 1,
            'circle-stroke-color': '#0c2a43',
        },
    });

    map.addLayer({
        id: 'airports-labels',
        type: 'symbol',
        source: 'airports',
        layout: {
            'text-field': ['get', 'icao'],
            'text-size': 11,
            'text-offset': [0, 1.4],
            'text-anchor': 'top',
            'text-allow-overlap': false,
            'text-optional': true,
            // When icons overlap at low zoom, only the busiest airport's
            // label wins the shared space - lower sort key placed first.
            'symbol-sort-key': ['-', 0, ['get', 'total']],
        },
        paint: {
            'text-color': '#e2e8f0',
            'text-halo-color': '#0c2a43',
            'text-halo-width': 1.2,
        },
    });

    const interactiveLayers = ['airports-circles', 'airports-labels'];

    map.on('click', interactiveLayers, (e) => {
        const feature = e.features[0];

        new mapboxgl.Popup()
            .setLngLat(feature.geometry.coordinates)
            .setHTML((popupHtml ?? defaultAirportPopupHtml)(feature.properties))
            .addTo(map);

        onClick?.(feature);
    });

    map.on('mouseenter', interactiveLayers, () => (map.getCanvas().style.cursor = 'pointer'));
    map.on('mouseleave', interactiveLayers, () => (map.getCanvas().style.cursor = ''));
}
