import { Controller } from '@hotwired/stimulus';
import Splide from '@splidejs/splide';
import '@splidejs/splide/css/core';
import { getComponent } from '@symfony/ux-live-component';

class ProductController extends Controller {
  static targets = ['slide', 'slider', 'thumbnail', 'thumblist', 'productImg'];

  static values = {
    currentPseId: Number
  };

  async initialize() {
    this.component = await getComponent(this.element);

    window.addEventListener('change:pse', (e) => {
      let currentThumb = this.thumbnailTargets.find((thumb) =>
        thumb.dataset.pseId?.split(',').includes(e.detail.pseId.toString())
      );

      if (!currentThumb) {
        currentThumb = this.productImgTarget;
      }

      this.goToSlide(currentThumb);
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

    this.main = new Splide(this.sliderTarget, {
      pagination: false,
      destroy: this.slideTargets.length <= 1,
      drag: false,
      breakpoints: {
        768: {
          pagination: true,
          arrows: false,
          drag: true
        }
      }
    });

    this.thumbnailTargets.forEach((thumbnail) => {
      thumbnail.addEventListener('click', () => {
        this.goToSlide(thumbnail);
      });
    });

    this.main.mount();
    this.main.on('moved', (index) => this.onSliderMove(index));
  }

  onSliderMove(index) {
    const slide = this.slideTargets[index];
    const pseIds = slide?.dataset?.pseIds.split(',').map((p) => parseInt(p));

    if (pseIds.length && !pseIds.includes(this.currentPseIdValue)) {
      this.component.action('updateCurrentPseFromId', {
        pseIds: slide?.dataset?.pseIds
      });
    }

    this.manageActiveClass(index);
  }

  goToSlide(thumbnail) {
    this.main?.go(parseInt(thumbnail.dataset.index));
    this.manageActiveClass(thumbnail.dataset.index);
    this.scrollToCurrentThumbnail(thumbnail);
  }

  manageActiveClass(index) {
    this.thumbnailTargets.forEach((thumbnail, i) => {
      thumbnail.parentNode.classList.toggle('is-active', parseInt(index) === i);
    });
  }

  scrollToCurrentThumbnail(thumbnail) {
    this.thumblistTarget.scrollTo({
      top: thumbnail.offsetTop,
      behavior: 'smooth'
    });
  }
}

export default ProductController;
