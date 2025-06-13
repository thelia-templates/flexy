import { Controller } from '@hotwired/stimulus';
import Splide from '@splidejs/splide';
import '@splidejs/splide/css/core';
import { getComponent } from '@symfony/ux-live-component';

class ProductController extends Controller {
  static targets = ['slide', 'slider'];
  static values = { currentPseId: Number };

  async initialize() {
    this.component = await getComponent(this.element);
  }

  connect() {
    console.log(this.sliderTarget);

    if (!this.main) {
      this.main = new Splide(this.sliderTarget, {
        pagination: false,
        destroy: this.slideTargets.length <= 1,
        breakpoints: {
          768: {
            pagination: true,
            arrows: false
          }
        }
      });

      this.main.mount();
      console.log('test');

      this.goToCurrentPse();

      this.main.on('move', (index) => {
        const slide = this.slideTargets.find((_, i) => i === index);

        if (slide && parseInt(slide.dataset.pseId) !== this.currentPseIdValue) {
          this.component.action('updateCurrentPseFromId', {
            pseId: slide.dataset.pseId
          });
        }
      });
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

    const index = this.slideTargets.findIndex(
      (slide) => parseInt(slide.dataset.pseId) === this.currentPseIdValue
    );

    if (index !== -1) {
      this.main.go(index);
    }
  }
}

export default ProductController;
