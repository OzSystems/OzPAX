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

export function fetchJson(url) {
    return fetch(url).then((res) => res.json());
}

export function refreshSource(map, sourceId, url) {
    fetchJson(url)
        .then((data) => map.getSource(sourceId)?.setData(data))
        .catch((err) => console.error(`${sourceId} refresh failed`, err));
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
