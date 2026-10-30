import { Controller } from '@hotwired/stimulus';

/**
 * Clamps the quantity input between its min/max attributes while typing.
 * Wired through data-action (survives LiveComponent re-renders, unlike
 * listeners attached in initialize()).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  initialize() {
    this.clamped = new WeakSet();
  }

  enforce(event) {
    const el = event.target;

    if (el.value === '') {
      return;
    }

    const typed = el.value;
    const value = parseInt(el.value, 10);
    const min = el.min === '' ? null : parseInt(el.min, 10);
    const max = el.max === '' ? null : parseInt(el.max, 10);

    if (min !== null && value < min) {
      el.value = min;
    } else if (max !== null && value > max) {
      el.value = max;
    } else {
      el.value = value;
    }

    if (el.value !== typed) {
      this.clamped.add(el);
    }
  }

  /**
   * WebKit fires no `change` for a value the script brought back within its bounds, neither
   * when the field is left nor on Enter, and the line kept the quantity typed before. Announce
   * it then, unless the browser fired its own `change` meanwhile: the line would move twice.
   */
  announce(event) {
    if (!this.clamped.delete(event.target)) {
      return;
    }

    event.target.dispatchEvent(new Event('change', { bubbles: true }));
  }

  settle(event) {
    this.clamped.delete(event.target);
  }
}
