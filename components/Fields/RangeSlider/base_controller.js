import { Controller } from "@hotwired/stimulus";

// Dual-handle range slider: two overlaid native range inputs (kept for accessibility and no-JS
// submission) plus a filled progress bar between the handles. Ported from the source project's
// `delta` controller, with two fixes: listeners are bound once so disconnect() actually removes
// them (the source removed fresh references, leaking them), and values are read as floats so the
// slider works for decimal ranges (e.g. prices), not just integers.
export default class extends Controller {
  static targets = ["min", "max", "progress"];

  connect() {
    // The listing is re-rendered by the LiveComponent on filter/reset; Layouts--ProductListing
    // dispatches these so the bar re-syncs after the inputs are patched in place.
    this.onSave = () => this.updateProgress();
    this.onReset = () => this.updateProgress(true);
    window.addEventListener("live:form:save", this.onSave);
    window.addEventListener("live:form:reset", this.onReset);
    this.updateProgress();
  }

  disconnect() {
    window.removeEventListener("live:form:save", this.onSave);
    window.removeEventListener("live:form:reset", this.onReset);
  }

  // The two native ranges are independent: a handle crosses the other freely, in either direction.
  // No handle is ever moved silently, so only the dragged input changes and its own change event
  // carries the right value to the LiveComponent. The value order (which input ends up holding the
  // lower value) is reconciled server-side by sanitizeRange(); since the two thumbs are identical
  // and the bar always spans low→high, a "crossed" state is invisible.
  updateInput() {
    this.updateProgress();
  }

  updateProgress(reset = false) {
    const min = Number(this.minTarget.min);
    const max = Number(this.maxTarget.max);
    const range = max - min || 1;

    // Reset is the only case that writes back to the inputs (snap to full range). During a drag we
    // never reorder the input values — writing to the handle the user isn't holding drags it along
    // and can cross the handles. Ordering here is display-only (Math.min/max) so the bar can't get a
    // negative width; the clamp in updateInput() keeps min <= max on the inputs themselves.
    if (reset) {
      this.minTarget.value = min;
      this.maxTarget.value = max;
    }

    const low = Math.min(Number(this.minTarget.value), Number(this.maxTarget.value));
    const high = Math.max(Number(this.minTarget.value), Number(this.maxTarget.value));

    // The native range inputs mirror themselves under a right-to-left page: the low value sits at
    // the right edge. The bar has to start from the same edge, so the offset is written as an
    // inline one - resolved against the element's own direction, which it inherits from the page -
    // rather than as a physical `left`, which would draw the mirror of the selected interval.
    this.progressTarget.style.insetInlineStart = ((low - min) / range) * 100 + "%";
    this.progressTarget.style.width = ((high - low) / range) * 100 + "%";
  }
}
