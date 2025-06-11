import { Controller } from '@hotwired/stimulus';
import Splide from '@splidejs/splide';
import '@splidejs/splide/css/core';

class GalleryController extends Controller {
  static targets = ['thumbnail', 'root'];
  static values = { currentPseId: Number };

  connect() {
    if (!this.main) {
      this.main = new Splide(this.rootTarget, {
        pagination: false,
        destroy: this.rootTarget.dataset?.count <= 1,
        breakpoints: {
          768: {
            pagination: true,
            arrows: false
          }
        }
      });

      this.main.mount();
      this.goToCurrentPse();
    }
  }

  disconnect() {
    if (this.main) {
      this.main.destroy();
      this.main = null;
    }
  }

  currentPseIdValueChanged() {
    this.goToCurrentPse();
  }

  goToCurrentPse() {
    if (!this.main || !this.hasCurrentPseIdValue) return;

    const index = this.thumbnailTargets.findIndex(
      (thumb) =>
        parseInt(thumb.parentNode.dataset.pseId) === this.currentPseIdValue
    );

    if (index !== -1) {
      this.main.go(index);
    }
  }

  update({ params }) {
    if (this.main) {
      this.main.go(params.index);
    }
  }
}

export default GalleryController;
