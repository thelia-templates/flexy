import { Controller } from '@hotwired/stimulus';

class RangeController extends Controller {
  static targets = ['min', 'max', 'progress'];

  connect() {
    this.updateProgress();
    window.addEventListener('live:form:reset', () =>  this.updateProgress());
  }

  disconnect() {
    window.removeEventListener('live:form:reset', () => this.updateProgress());
  }

  updateInput({ target }) {
    if (target === this.minTarget) {
      this.updateMin();
    } else {
      this.updateMax();
    }

    this.updateProgress();
  }

  updateMin() {
    if (parseInt(this.minTarget.value) >= parseInt(this.maxTarget.value)) {
      this.maxTarget.value = this.minTarget.value;
    }
  }

  updateMax() {
    if (parseInt(this.maxTarget.value) <= parseInt(this.minTarget.value)) {
      this.minTarget.value = this.maxTarget.value;
    }
  }

  updateProgress() {
    const minValue = parseInt(this.minTarget.value);
    const maxValue = parseInt(this.maxTarget.value);

    // get the total range of the slider
    const range = this.maxTarget.max - this.minTarget.min;
    const valueRange = maxValue - minValue;
    const width = (valueRange / range) * 100;
    const minOffset = ((minValue - this.minTarget.min) / range) * 100;
    this.progressTarget.style.width = width + '%';
    this.progressTarget.style.left = minOffset + '%';

    this.minTarget.value = minValue;
    this.maxTarget.value = maxValue;
  }
}

export default RangeController;
