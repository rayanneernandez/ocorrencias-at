// mapa.js
export function initMap(coords, elementId, onChange) {
    const map = L.map(elementId).setView(coords, 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    let marker = L.marker(coords, {draggable:true}).addTo(map);
    
    marker.on('dragend', function(e) {
        const pos = e.target.getLatLng();
        onChange(pos.lat, pos.lng);
    });

    map.on('click', function(e) {
        marker.setLatLng(e.latlng);
        onChange(e.latlng.lat, e.latlng.lng);
    });
}
