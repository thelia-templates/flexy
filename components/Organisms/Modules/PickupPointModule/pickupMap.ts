import 'leaflet/dist/leaflet.css';
import L from 'leaflet';

export function PickupMap() {
  console.log('PickupMap');
  const map = L.map('map').setView([45.777222, 3.087025], 13);

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19
  }).addTo(map);

  window.addEventListener(
    'invalidateMap',
    function (e) {
      map.invalidateSize();
    },
    false
  );
}
