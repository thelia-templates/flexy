import { Controller } from '@hotwired/stimulus';
import Splide from '@splidejs/splide';
import '@splidejs/splide/css/core';
import { getComponent } from '@symfony/ux-live-component';

class ProductController extends Controller {
  static targets = ['slide', 'slider', 'thumbnail', 'thumblist'];

  async initialize() {
    this.component = await getComponent(this.element);

    window.addEventListener('change:pse', (e) => {
      let currentThumb = null;
      let index = -1;
      this.thumbnailTargets.forEach((thumb, i) => {
        if (parseInt(thumb.dataset.pseId) === e.detail.pseId) {
          currentThumb = thumb;
          index = i;
        }
      });

      if (!currentThumb) {
        this.fallbackImg();
        return;
      }

      this.goToSlide(currentThumb, index);
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
      thumbnail.addEventListener('click', () => {
        this.goToSlide(thumbnail, index);
      });
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

  goToSlide(thumbnail, index) {
    this.main?.go(index);
    this.manageActiveClass(index);
    this.scrollToCurrentThumbnail(thumbnail);
  }

  manageActiveClass(index) {
    this.thumbnailTargets.forEach((thumbnail, i) => {
      thumbnail.parentNode.classList.toggle('is-active', index === i);
    });
  }

  scrollToCurrentThumbnail(thumbnail) {
    this.thumblistTarget.scrollTo({
      top: thumbnail.offsetTop,
      behavior: 'smooth'
    });
  }
  fallbackImg() {}
}

export default ProductController;
