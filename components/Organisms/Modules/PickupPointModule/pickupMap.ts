import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import markerIcon from '@assets/images/marker/dpd-logo.png';

type PickupPointUpdateEvent = CustomEvent<{
  pickups: PickupLocationType[];
  coordinates: [number, number];
}>;

export type PickupLocationType = {
  latitude: number;
  longitude: number;
  title: string;
};

export function PickupMap(pickupClick: (pickup: PickupLocationType) => void) {
  console.log('PickupMap');
  const map = L.map('map').setView([45.777222, 3.087025], 13);

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19
  }).addTo(map);

  const icon = L.icon({
    iconUrl: markerIcon,
    iconSize: [25, 41] // size of the icon
  });
  L.marker([51.5, -0.09], { icon: icon }).addTo(map);

  window.addEventListener('pickuppoint:update', (event: Event) => {
    const { pickups, coordinates } = (event as PickupPointUpdateEvent).detail;
    map.setView([coordinates[1], coordinates[0]], 13);

    for (const pickup of pickups) {
      const marker = L.marker([pickup.latitude, pickup.longitude], {
        icon: icon
      }).addTo(map);
      marker.on('click', () => {
        console.log('click', pickup);
        pickupClick(pickup);
      });
    }
  });

  // This is used to fix a bug with leaflet map not rendering correctly
  window.addEventListener(
    'invalidateMap',
    function (e) {
      map.invalidateSize();
    },
    false
  );
}
