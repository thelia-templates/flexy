import { Controller } from "@hotwired/stimulus";
import { syncBodyLock } from "page_lock";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["confirm"];
  static values = { closeOnBackdrop: Boolean };

  initialize() {
    this.openObserver = new MutationObserver(syncBodyLock);
    this.closeBeforeCache = this.closeBeforeCache.bind(this);
    this.onPointerDown = this.onPointerDown.bind(this);
    this.onClick = this.onClick.bind(this);
  }

  connect() {
    // Also sees a showModal() called from elsewhere, Escape and method="dialog" buttons.
    this.openObserver.observe(this.element, { attributeFilter: ["open"] });
    document.addEventListener("turbo:before-cache", this.closeBeforeCache);
    this.pressedOnBackdrop = false;
    this.element.addEventListener("pointerdown", this.onPointerDown);
    this.element.addEventListener("click", this.onClick);
    // Loaded lazily, so the dialog may already be open.
    syncBodyLock();
  }

  disconnect() {
    this.openObserver.disconnect();
    document.removeEventListener("turbo:before-cache", this.closeBeforeCache);
    this.element.removeEventListener("pointerdown", this.onPointerDown);
    this.element.removeEventListener("click", this.onClick);
    syncBodyLock();
  }

  open() {
    this.element.showModal();
  }

  close() {
    this.element.close();
  }

  // The scroll event has Turbo record the position the lock had zeroed, before it caches.
  closeBeforeCache() {
    this.element.close();
    syncBodyLock();
    window.dispatchEvent(new Event("scroll"));
  }

  onPointerDown(event) {
    this.pressedOnBackdrop = event.button === 0 && this.isOnBackdrop(event);
  }

  onClick(event) {
    if (this.closeOnBackdropValue && this.pressedOnBackdrop && this.isOnBackdrop(event)) {
      this.close();
    }

    this.pressedOnBackdrop = false;
  }

  // A click in the padding targets the dialog too.
  isOnBackdrop(event) {
    if (event.target !== this.element) {
      return false;
    }

    const box = this.element.getBoundingClientRect();

    return (
      event.clientX < box.left ||
      event.clientX > box.right ||
      event.clientY < box.top ||
      event.clientY > box.bottom
    );
  }
}
