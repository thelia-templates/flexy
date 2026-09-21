import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
class QuantityController extends Controller {
  static targets = ["input"];

  initialize() {
    // Bound once per element so inputTargetDisconnected() actually removes them: a fresh
    // bind() is a different reference and removeEventListener() matched nothing, so the
    // handlers stacked up on every LiveComponent re-render.
    this.handlers = new WeakMap();
  }

  inputTargetConnected(element) {
    const handlers = {
      keyup: (event) => this.enforceMinMax(element, event),
      keypress: (event) => this.enforceNumberOnly(element, event),
    };

    this.handlers.set(element, handlers);
    element.addEventListener("keyup", handlers.keyup);
    element.addEventListener("keypress", handlers.keypress);
  }

  inputTargetDisconnected(element) {
    const handlers = this.handlers.get(element);

    if (!handlers) {
      return;
    }

    element.removeEventListener("keyup", handlers.keyup);
    element.removeEventListener("keypress", handlers.keypress);
    this.handlers.delete(element);
  }

  getStep() {
    const step = parseInt(this.inputTarget.getAttribute("step"), 10);

    return step > 0 ? step : 1;
  }

  /** An absent attribute reads as "", the only thing that means no bound: 0 is a real one. */
  getBound(name) {
    const raw = this.inputTarget[name];

    return raw === "" ? null : parseInt(raw, 10);
  }

  decrement() {
    const min = this.getBound("min");
    const value = parseInt(this.inputTarget.value) || 0;

    if (min !== null && value <= min) {
      return;
    }

    const next = value - this.getStep();

    this.setValue(min !== null && next < min ? min : next);
  }
  increment() {
    const max = this.getBound("max");
    const value = parseInt(this.inputTarget.value) || 0;

    if (max !== null && value >= max) {
      return;
    }

    const next = value + this.getStep();

    this.setValue(max !== null && next > max ? max : next);
  }

  /**
   * Assigning `value` from script fires no event, so anything listening on the input stays
   * unaware of the new quantity — inside a LiveComponent form the browser never posts this
   * field either (the component's own model is the only channel), and the server would keep
   * validating the quantity it last knew about. Announce the change explicitly.
   */
  setValue(value) {
    this.inputTarget.value = value;
    this.inputTarget.dispatchEvent(new Event("change", { bubbles: true }));
  }

  enforceNumberOnly(el, e) {
    if (e.key.length === 1 && !/[0-9]/.test(e.key)) {
      e.preventDefault();
    }
  }

  enforceMinMax(el, e) {
    if (el.value !== "") {
      if (parseInt(el.value) < parseInt(el.min)) {
        el.value = el.min;
      }
      if (parseInt(el.value) > parseInt(el.max)) {
        el.value = el.max;
      } else {
        el.value = parseInt(el.value);
      }
    }
  }
}

export default QuantityController;
