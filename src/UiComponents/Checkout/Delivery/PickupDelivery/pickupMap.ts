import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import markerIcon from '@assets/images/marker/pin.png';
import markerSelectedIcon from '@assets/images/marker/pin-selected.png';

type PickupPointUpdateEvent = CustomEvent<{
  pickups: PickupLocationType[];
  coordinates: [number, number];
}>;
type PickupSelectedEvent = CustomEvent<{
  pickup: PickupLocationType;
}>;

export type PickupLocationType = {
  latitude: number;
  longitude: number;
  title: string;
  id: string;
};

export function PickupMap(pickupClick: (pickup: PickupLocationType) => void) {
  const map = L.map('map').setView([45.777222, 3.087025], 13);

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19
  }).addTo(map);

  const icon = L.icon({
    iconUrl: markerIcon,
    iconSize: [38, 38] // size of the icon
  });

  const selectedIcon = L.icon({
    iconUrl: markerSelectedIcon,
    iconSize: [38, 38]
  });

  let markersMap: Record<string, L.Marker> = {};

  window.addEventListener('pickuppoint:update', (event: Event) => {
    const { pickups, coordinates } = (event as PickupPointUpdateEvent).detail;
    map.setView([coordinates[1], coordinates[0]], 13);
    markersMap = {};
    for (const pickup of pickups) {
      const marker = L.marker([pickup.latitude, pickup.longitude], {
        icon: icon
      }).addTo(map);
      markersMap[pickup.id] = marker;

      marker.on('click', () => {
        pickupClick(pickup);

        Object.values(markersMap).forEach((m) => m.setIcon(icon));
        marker.setIcon(selectedIcon);
      });
    }
  });

  window.addEventListener('pickup:selected', (event: Event) => {
    const { pickup } = (event as PickupSelectedEvent).detail;
    const marker = markersMap[pickup.id];
    if (marker === undefined) {
      return;
    }
    Object.values(markersMap).forEach((m) => m.setIcon(icon));
    marker.setIcon(selectedIcon);
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
