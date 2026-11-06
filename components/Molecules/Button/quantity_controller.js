import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
class QuantityController extends Controller {
  static targets = ["input"];

  /**
   * step: what one press adds or takes away (6, 0.25). decimals: how many the quantity
   * accepts, 0 for a count of pieces. min and max: the bounds, "" for none — a text field
   * (a quantity sold by measure) has no min or max attribute to read them from.
   */
  static values = {
    step: { type: Number, default: 1 },
    decimals: { type: Number, default: 0 },
    min: { type: String, default: "" },
    max: { type: String, default: "" },
  };

  initialize() {
    // Bound once per element so inputTargetDisconnected() actually removes them: a fresh
    // bind() is a different reference and removeEventListener() matched nothing, so the
    // handlers stacked up on every LiveComponent re-render.
    this.handlers = new WeakMap();
    this.clamped = new WeakSet();
  }

  inputTargetConnected(element) {
    const handlers = {
      // Clamped on change, not on each key (see below); main's announce-on-blur stays for a clamp the browser did not announce itself.
      change: () => {
        this.enforceMinMax(element);
        this.clamped.delete(element);
      },
      keypress: (event) => this.enforceNumberOnly(event),
      keydown: (event) => event.key === "Enter" && this.announceClamp(element),
      blur: () => this.announceClamp(element),
    };

    this.handlers.set(element, handlers);
    // On change rather than on each key: "1" on its way to "12" is below a minimum of six,
    // and "1," on its way to "1,25" is no number yet. Registered on the field itself, it
    // runs before the LiveComponent, which listens on its root, so the model receives the
    // value once it is within its bounds.
    element.addEventListener("change", handlers.change);
    element.addEventListener("keypress", handlers.keypress);
    element.addEventListener("keydown", handlers.keydown);
    element.addEventListener("blur", handlers.blur);
  }

  inputTargetDisconnected(element) {
    const handlers = this.handlers.get(element);

    if (!handlers) {
      return;
    }

    element.removeEventListener("change", handlers.change);
    element.removeEventListener("keypress", handlers.keypress);
    element.removeEventListener("keydown", handlers.keydown);
    element.removeEventListener("blur", handlers.blur);
    this.handlers.delete(element);
  }

  /** Whole numbers scaled by the decimals, so that 0.1 + 0.2 lands on 0.3. */
  get scale() {
    return 10 ** Math.max(0, this.decimalsValue);
  }

  getStep() {
    const step = Math.round(this.stepValue * this.scale);

    return step > 0 ? step : this.scale;
  }

  /**
   * The field's own attribute first (a number field), then the value the component was
   * given. null means no bound: 0 is a real one.
   */
  getBound(name) {
    const attribute = this.inputTarget.getAttribute(name);
    const raw = attribute !== null && attribute !== "" ? attribute : this[`${name}Value`];
    const bound = this.parse(raw);

    return bound === null ? null : Math.round(bound * this.scale);
  }

  /**
   * "1,25" and "1.25" both read 1.25: a quantity is never written with a thousands
   * separator, so a single comma or point can only be the decimal one. Anything else is no
   * number, and is left for the cart to refuse with its own message.
   */
  parse(raw) {
    const text = String(raw ?? "").trim();

    if (!/^\d+([.,]\d+)?$/.test(text)) {
      return null;
    }

    return Number(text.replace(",", "."));
  }

  /** The quantity in units of the last decimal, or null when the field holds no number. */
  read() {
    const value = this.parse(this.inputTarget.value);

    return value === null ? null : Math.round(value * this.scale);
  }

  /**
   * Written the way the page writes numbers ("1,25" on a French page): the field is what the
   * shopper reads, and the cart reads both separators. A count of pieces stays digits only,
   * which is all a number field takes.
   */
  format(scaled) {
    const decimals = Math.max(0, this.decimalsValue);

    if (decimals === 0) {
      return String(Math.round(scaled / this.scale));
    }

    return new Intl.NumberFormat(document.documentElement.lang || undefined, {
      useGrouping: false,
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(scaled / this.scale);
  }

  decrement() {
    const min = this.getBound("min");
    const value = this.read() ?? 0;

    if (min !== null && value <= min) {
      return;
    }

    const next = value - this.getStep();

    this.setValue(min !== null && next < min ? min : Math.max(0, next));
  }

  increment() {
    const max = this.getBound("max");
    const min = this.getBound("min");
    const value = this.read();

    if (max !== null && value !== null && value >= max) {
      return;
    }

    // An empty field starts at the minimum rather than one step above nothing.
    const next = value === null ? (min ?? this.getStep()) : value + this.getStep();

    this.setValue(max !== null && next > max ? max : next);
  }

  /**
   * Assigning `value` from script fires no event, so anything listening on the input stays
   * unaware of the new quantity — inside a LiveComponent form the browser never posts this
   * field either (the component's own model is the only channel), and the server would keep
   * validating the quantity it last knew about. Announce the change explicitly.
   */
  setValue(scaled) {
    this.inputTarget.value = this.format(scaled);
    this.inputTarget.dispatchEvent(new Event("change", { bubbles: true }));
  }

  /** Digits only, and a decimal separator when the quantity takes decimals. */
  enforceNumberOnly(event) {
    if (event.key.length !== 1) {
      return;
    }

    const allowed = this.decimalsValue > 0 ? /[0-9.,]/ : /[0-9]/;

    if (!allowed.test(event.key)) {
      event.preventDefault();
    }
  }

  /** Brought back within the bounds, rewritten only when it had to move. */
  enforceMinMax(element) {
    const value = this.read();

    if (value === null) {
      return;
    }

    const min = this.getBound("min");
    const max = this.getBound("max");

    if (min !== null && value < min) {
      element.value = this.format(min);
      this.clamped.add(element);
    } else if (max !== null && value > max) {
      element.value = this.format(max);
      this.clamped.add(element);
    }
  }

  /**
   * A value the script put in the field is not a change of the user's for WebKit: leaving the
   * field, or submitting its form with Enter, fires no `change`, and a model bound on change
   * kept the quantity typed before. Announce it then, unless the browser fired its own
   * `change` meanwhile.
   */
  announceClamp(el) {
    if (!this.clamped.delete(el)) {
      return;
    }

    el.dispatchEvent(new Event("change", { bubbles: true }));
  }
}

export default QuantityController;
