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

  // A plain property, not a Stimulus value: a value is backed by a data attribute, and the
  // morph drops it on every re-render since the server never renders it. Nothing outside this
  // controller sets the view.
  view = 'list';

  initialize() {
    this.syncView = this.syncView.bind(this);
    this.ready = getComponent(this.element).then((component) => {
      this.component = component;
    });
  }

  async connect() {
    this.disconnected = false;
    this.onPickupsUpdate = this.onPickupsUpdate.bind(this);
    this.onPickupSelected = this.onPickupSelected.bind(this);
    window.addEventListener('pickuppoint:update', this.onPickupsUpdate);
    window.addEventListener('pickup:selected', this.onPickupSelected);

    this.markers = {};
    this.syncView();

    // Subscribed here rather than in initialize(), which runs once: Stimulus keeps the same
    // controller instance across a disconnect, so a subscription made there would be dropped
    // by the first disconnect and never restored.
    await this.ready;

    if (this.disconnected) {
      return;
    }

    // On the component object, not the DOM: the package dispatches live:appear alone, and the
    // live:render events this once listened for were withdrawn in 2.5.0.
    this.component.on('render:finished', this.syncView);
  }

  disconnect() {
    this.disconnected = true;
    window.removeEventListener('pickuppoint:update', this.onPickupsUpdate);
    window.removeEventListener('pickup:selected', this.onPickupSelected);
    this.component?.off('render:finished', this.syncView);

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
    this.view = 'map';
    this.syncView();
  }

  showList() {
    this.view = 'list';
    this.syncView();
  }

  syncView() {
    const mapShown = this.view === 'map';

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
