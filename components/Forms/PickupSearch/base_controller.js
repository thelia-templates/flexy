import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/**
 * Drives the pickup point search UX around the Forms:PickupSearch live
 * component: the Leaflet map (markers fed by the pickuppoint:update browser
 * event dispatched from the component) and the list/map view toggle. The
 * current view lives in a Stimulus value and is re-applied after every live
 * re-render (the morph restores the server-side classes).
 */
export default class extends Controller {
  static targets = ['map', 'mapView', 'listView', 'mapButton', 'listButton'];
  static values = { view: { type: String, default: 'list' } };

  async initialize() {
    this.component = await getComponent(this.element);
  }

  connect() {
    this.onPickupsUpdate = this.onPickupsUpdate.bind(this);
    this.onPickupSelected = this.onPickupSelected.bind(this);
    this.syncView = this.syncView.bind(this);
    window.addEventListener('pickuppoint:update', this.onPickupsUpdate);
    window.addEventListener('pickup:selected', this.onPickupSelected);
    this.element.addEventListener('live:render:finished', this.syncView);

    this.markers = {};
    this.syncView();
  }

  disconnect() {
    window.removeEventListener('pickuppoint:update', this.onPickupsUpdate);
    window.removeEventListener('pickup:selected', this.onPickupSelected);
    this.element.removeEventListener('live:render:finished', this.syncView);

    try {
      this.map?.remove();
    } catch {
      // The map container may already be gone when the parent live
      // component replaced this whole subtree.
    }
    this.map = null;
  }

  // The map is created on first use: initializing Leaflet on a hidden or
  // about-to-be-morphed node breaks its size computation.
  ensureMap() {
    if (!this.map) {
      this.map = L.map(this.mapTarget).setView([45.777222, 3.087025], 13);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
      }).addTo(this.map);
    }

    return this.map;
  }

  showMap() {
    this.viewValue = 'map';
    this.syncView();
  }

  showList() {
    this.viewValue = 'list';
    this.syncView();
  }

  syncView() {
    const mapShown = this.viewValue === 'map';

    this.mapViewTarget.classList.toggle('hidden', !mapShown);
    this.listViewTarget.classList.toggle('hidden', mapShown);
    this.styleButton(this.mapButtonTarget, mapShown);
    this.styleButton(this.listButtonTarget, !mapShown);

    if (mapShown) {
      // Leaflet computes its size when created: recompute once visible.
      this.ensureMap().invalidateSize();
    }
  }

  styleButton(button, active) {
    button.classList.toggle('button-style', active);
    button.classList.toggle('button-style--secondary', !active);
  }

  onPickupsUpdate(event) {
    const { pickups, coordinates } = event.detail;
    const map = this.ensureMap();
    map.setView([coordinates[1], coordinates[0]], 13);
    Object.values(this.markers).forEach((marker) => marker.remove());
    this.markers = {};

    for (const pickup of pickups) {
      const marker = L.marker([pickup.latitude, pickup.longitude], {
        icon: this.markerIcon(false),
      }).addTo(map);
      this.markers[pickup.id] = marker;

      marker.on('click', () => {
        this.component.action('pickupPointClick', { pickup });
        this.highlight(pickup.id);
      });
    }
  }

  onPickupSelected(event) {
    this.highlight(event.detail.pickup.id);
  }

  highlight(id) {
    Object.entries(this.markers).forEach(([markerId, marker]) => {
      marker.setIcon(this.markerIcon(markerId === id));
    });
  }

  markerIcon(selected) {
    return L.divIcon({
      className: `PickupSearch-marker${selected ? ' PickupSearch-marker--selected' : ''}`,
      iconSize: [22, 22],
    });
  }
}
