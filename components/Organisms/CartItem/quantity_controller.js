import { Controller } from '@hotwired/stimulus';

/**
 * Clamps the quantity input between its min/max attributes while typing.
 * Wired through data-action (survives LiveComponent re-renders, unlike
 * listeners attached in initialize()).
 */
export default class extends Controller {
  enforce(event) {
    const el = event.target;

    if (el.value === '') {
      return;
    }

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
  }
}
