import { Controller } from "@hotwired/stimulus";
import { syncBodyLock } from "page_lock";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["confirm"];

  initialize() {
    this.openObserver = new MutationObserver(syncBodyLock);
    this.closeBeforeCache = this.closeBeforeCache.bind(this);
  }

  connect() {
    // Also sees a showModal() called from elsewhere, Escape and method="dialog" buttons.
    this.openObserver.observe(this.element, { attributeFilter: ["open"] });
    document.addEventListener("turbo:before-cache", this.closeBeforeCache);
    // Loaded lazily, so the dialog may already be open.
    syncBodyLock();
  }

  disconnect() {
    this.openObserver.disconnect();
    document.removeEventListener("turbo:before-cache", this.closeBeforeCache);
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
}
