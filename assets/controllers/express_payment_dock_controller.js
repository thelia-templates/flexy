import { Controller } from "@hotwired/stimulus";

// Puts the button of the wallet the buyer chose where the order button of the checkout was.
//
// The buttons are rendered with the page, outside the live component, because a script a
// module puts next to its button only runs when the page is parsed: one inserted by a
// re-render of the component never does, and the button would stay dead. Each module marks
// the root of its button with data-express-payment-module. When the order button gives its
// place to a wallet, it renders a slot naming the module and saying whether the order could
// be placed now (addresses, carrier, consents); the chosen module's button moves into the
// slot and follows that state, the others wait here. The slot is ignored by the re-renders,
// which leaves the button in place; when the buyer picks another payment method and the
// slot goes, the button comes back here.
export default class extends Controller {
  static targets = ["content"];

  connect() {
    this.buttons = [...this.contentTarget.querySelectorAll("[data-express-payment-module]")];
    this.observer = new MutationObserver(() => this.dock());
    this.observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["data-enabled", "data-module"],
    });
    this.dock();
  }

  disconnect() {
    this.observer.disconnect();
  }

  dock() {
    const place = document.querySelector("[data-express-payment-slot]");
    const slot = place?.querySelector("#checkout-express-payment-slot");

    for (const button of this.buttons) {
      const chosen = slot && button.dataset.expressPaymentModule === place.dataset.module;
      const home = chosen ? slot : this.contentTarget;

      if (button.parentElement !== home) {
        home.appendChild(button);
      }

      const live = chosen && place.dataset.enabled === "1";

      button.inert = !live;
      button.style.opacity = live ? "" : ".4";
    }
  }
}
