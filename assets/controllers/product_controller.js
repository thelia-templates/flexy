import { Controller } from '@hotwired/stimulus';
import Splide from '@splidejs/splide';
import '@splidejs/splide/css/core';
import { getComponent } from '@symfony/ux-live-component';

class ProductController extends Controller {
  static targets = ['slide', 'slider', 'thumbnail'];

  async initialize() {
    this.component = await getComponent(this.element);

    window.addEventListener('change:pse', (e) => {
      const index = this.thumbnailTargets.findIndex(
        (slide) => parseInt(slide.dataset.pseId) === e.detail.pseId
      );

      if (index === -1) {
        this.fallbackImg();
        return;
      }

      this.goToSlide(index);
    });
  }

  connect() {
    this.initSlider();
  }

  disconnect() {
    this.main?.destroy();
    this.main = null;
  }

  initSlider() {
    if (this.main || this.slideTargets.length <= 1) return;

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

    this.thumbnailTargets.forEach((thumbnail, index) => {
      thumbnail.addEventListener('click', () => this.goToSlide(index));
    });

    this.main.mount();
    this.main.on('moved', (index) => this.onSliderMove(index));
  }

  onSliderMove(index) {
    const slide = this.slideTargets[index];
    const pseId = slide?.dataset?.pseId;

    if (pseId && parseInt(pseId) !== this.currentPseIdValue) {
      this.component.action('updateCurrentPseFromId', { pseId });
    }

    this.manageActiveClass(index);
  }

  goToSlide(index) {
    this.main?.go(index);
    this.manageActiveClass(index);
  }

  manageActiveClass(index) {
    this.thumbnailTargets.forEach((thumbnail, i) => {
      thumbnail.parentNode.classList.toggle('is-active', index === i);
    });
  }

  fallbackImg() {}
}

export default ProductController;
