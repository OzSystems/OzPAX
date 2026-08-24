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

export function fetchJson(url) {
    return fetch(url).then((res) => res.json());
}

export function refreshSource(map, sourceId, url) {
    fetchJson(url)
        .then((data) => map.getSource(sourceId)?.setData(data))
        .catch((err) => console.error(`${sourceId} refresh failed`, err));
}

/**
 * Adds an 'airports' GeoJSON source + a circle layer sized/colored by the
 * feature's 'total' property, with a click popup and hover cursor. Shared by
 * both the live map (in-progress traffic counts) and the past-flights map
 * (historic totals) - only the data feeding the source (and, optionally, the
 * radius scale) differs.
 */
export function addAirportsLayer(map, { onClick, radiusStops } = {}) {
    map.addSource('airports', { type: 'geojson', data: emptyCollection() });

    const radius = radiusStops ?? [1, 5, 20, 10, 100, 16, 500, 26];

    map.addLayer({
        id: 'airports-circles',
        type: 'circle',
        source: 'airports',
        paint: {
            'circle-radius': ['interpolate', ['linear'], ['get', 'total'], ...radius],
            'circle-color': ['interpolate', ['linear'], ['get', 'total'], 1, '#38bdf8', 50, '#facc15', 200, '#f97316', 500, '#ef4444'],
            'circle-opacity': 0.8,
            'circle-stroke-width': 1,
            'circle-stroke-color': '#0c2a43',
        },
    });

    map.on('click', 'airports-circles', (e) => {
        const feature = e.features[0];
        const p = feature.properties;
        const depNote = p.departuresRerouted ? ` (${p.departuresRerouted} rerouted here)` : '';
        const arrNote = p.arrivalsRerouted ? ` (${p.arrivalsRerouted} rerouted here)` : '';

        new mapboxgl.Popup()
            .setLngLat(feature.geometry.coordinates)
            .setHTML(`<strong>${p.icao}</strong> — ${p.name}<br>Departures: ${p.departures}${depNote}<br>Arrivals: ${p.arrivals}${arrNote}<br>Total: ${p.total}`)
            .addTo(map);

        onClick?.(feature);
    });

    map.on('mouseenter', 'airports-circles', () => (map.getCanvas().style.cursor = 'pointer'));
    map.on('mouseleave', 'airports-circles', () => (map.getCanvas().style.cursor = ''));
}
