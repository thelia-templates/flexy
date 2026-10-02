import { Controller } from "@hotwired/stimulus";
import Panzoom from "@panzoom/panzoom";

const PAN_KEYS = {
  ArrowLeft: [1, 0],
  ArrowRight: [-1, 0],
  ArrowUp: [0, 1],
  ArrowDown: [0, -1],
};

/**
 * Pan and zoom one visual in a native dialog. The host renders the trigger and rewrites
 * data-image-zoom-src and data-image-zoom-alt to follow its selection.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["dialog", "canvas"];
  static values = {
    step: { type: Number, default: 0.5 },
    panStep: { type: Number, default: 60 },
    // Below 1, so the visual clears the edges of the overlay at rest.
    restScale: { type: Number, default: 0.9 },
  };

  // Only Escape and the close button close it: a drag may end outside the picture.
  connect() {
    this.dialogTarget.addEventListener("close", this.teardown);
  }

  disconnect() {
    this.dialogTarget.removeEventListener("close", this.teardown);
    this.teardown();
  }

  open() {
    if (!this.element.dataset.imageZoomSrc) {
      return;
    }

    // Shown before loading: a closed dialog is display:none, and a cached image loads at once.
    this.dialogTarget.showModal();
    this.load();
  }

  // Zooming about the middle never uncovers the centre: no settle() needed.
  zoomIn() {
    this.panzoom?.zoomIn({ step: this.stepValue });
  }

  zoomOut() {
    this.panzoom?.zoomOut({ step: this.stepValue });
  }

  pan(event) {
    const direction = PAN_KEYS[event.key];

    if (!direction || !this.panzoom) {
      return;
    }

    event.preventDefault();

    const scale = this.panzoom.getScale();
    const [x, y] = direction;

    this.panzoom.pan((x * this.panStepValue) / scale, (y * this.panStepValue) / scale, {
      relative: true,
      animate: false,
    });
    this.settle();
  }

  close() {
    this.dialogTarget.close();
  }

  load() {
    const src = this.element.dataset.imageZoomSrc;

    if (!src) {
      return;
    }

    this.teardown();

    const image = document.createElement("img");
    image.className = "ImageZoom-image";
    image.alt = this.element.dataset.imageZoomAlt ?? "";
    // Panzoom's start scale, so the picture does not shrink once loaded.
    image.style.transform = `scale(${this.restScaleValue})`;
    image.src = src;
    this.canvasTarget.appendChild(image);
    // A close or a reopen drops the node, not its download: a late `load` must not build a
    // second Panzoom on a detached image.
    this.pendingImage = image;

    // `load`, not decode(): a decode never settles in a hidden tab.
    const start = () => {
      if (image !== this.pendingImage) {
        return;
      }

      this.panzoom = Panzoom(image, {
        maxScale: 5,
        minScale: this.restScaleValue,
        startScale: this.restScaleValue,
      });
      this.canvasTarget.addEventListener("wheel", this.onWheel);
      image.addEventListener("panzoomend", this.settle);
      window.addEventListener("resize", this.onResize);
    };

    if (image.complete && image.naturalWidth > 0) {
      start();
    } else {
      image.addEventListener("load", start, { once: true });
      image.addEventListener("error", () => image === this.pendingImage && this.close(), { once: true });
    }
  }

  /**
   * Keeps the picture over the canvas centre. Run on gesture end: correcting mid-drag stutters.
   * Computed from Panzoom's state, not the screen, which Panzoom only paints a frame later.
   */
  settle = () => {
    const image = this.canvasTarget.firstElementChild;

    if (!this.panzoom || !image) {
      return;
    }

    // On screen the picture sits scale × pan from the centre and spans scale × its size: it
    // covers the centre while the pan stays within half its size, whatever the scale.
    const { x, y } = this.panzoom.getPan();
    const { width, height } = this.painted(image);
    const clamp = (value, half) => Math.min(half, Math.max(-half, value));
    const dx = clamp(x, width / 2) - x;
    const dy = clamp(y, height / 2) - y;

    if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) {
      return;
    }

    this.panzoom.pan(dx, dy, {
      relative: true,
      animate: true,
      duration: 200,
      easing: "ease-out",
    });
  };

  /** What object-fit paints in the unscaled box. */
  painted(image) {
    const ratio = Math.min(
      image.clientWidth / image.naturalWidth,
      image.clientHeight / image.naturalHeight,
    );

    return { width: image.naturalWidth * ratio, height: image.naturalHeight * ratio };
  }

  /** The wheel emits a stream: settle once it stops rather than on every notch. */
  onWheel = (event) => {
    this.panzoom?.zoomWithWheel(event);
    clearTimeout(this.wheelTimer);
    this.wheelTimer = setTimeout(this.settle, 150);
  };

  onResize = () => {
    this.panzoom?.zoom(this.restScaleValue, { animate: false });
    this.settle();
  };

  teardown = () => {
    this.pendingImage = null;

    if (this.panzoom) {
      this.canvasTarget.removeEventListener("wheel", this.onWheel);
      clearTimeout(this.wheelTimer);
      this.panzoom.destroy();
      this.panzoom = null;
    }

    window.removeEventListener("resize", this.onResize);
    this.canvasTarget.replaceChildren();
  };
}
