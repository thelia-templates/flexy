import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/**
 * The quantity field of a cart line. Wired through data-action (survives LiveComponent
 * re-renders, unlike listeners attached in initialize()).
 *
 * A line sold by the piece is a number field, clamped between its min/max attributes while
 * typing. A line sold by measure is a text field, which reads "1,5" as well as "1.5": its
 * value is handed to the component with a point, since the component reads a number.
 *
 * The field of a line sold by measure is bound to the quantity of the line, a number: after
 * each render the component writes it back as such ("1.5"). It is shown again as the page
 * writes it, with the decimals of the rule ("1,50"), from data-field-value.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  initialize() {
    this.clamped = new WeakSet();
  }

  async connect() {
    this.showAsWritten = () => this.show();
    this.show();

    const liveRoot = this.element.closest('[data-controller~="live"]');
    this.component = liveRoot ? await getComponent(liveRoot) : null;
    this.component?.on('render:finished', this.showAsWritten);
    this.show();
  }

  disconnect() {
    this.component?.off('render:finished', this.showAsWritten);
  }

  show() {
    const field = this.element.querySelector('input[data-field-value]');

    if (field && field !== document.activeElement) {
      field.value = field.dataset.fieldValue;
    }
  }

  enforce(event) {
    const el = event.target;

    if (el.value === '' || el.type !== 'number') {
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

  /**
   * Runs on the field before the component reads it (the component listens on its root):
   * "1,5" becomes "1.5", and a value that is no number puts back the quantity the line had
   * rather than reaching the cart as a zero that would remove the line.
   */
  normalize(event) {
    const el = event.target;

    if (el.type === 'number') {
      return;
    }

    const text = el.value.trim();

    if (!/^\d+([.,]\d+)?$/.test(text)) {
      el.value = el.dataset.liveBaseQuantityParam ?? '';

      return;
    }

    el.value = text.replace(',', '.');
  }
}
