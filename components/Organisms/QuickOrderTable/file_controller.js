import { Controller } from "@hotwired/stimulus";
import { getComponent } from "@symfony/ux-live-component";

// The CSV file is read here, as text, and never sent: the table receives the same text a
// paste would give it, and the one parser on the server reads both. The bound matches the
// server's (ReferenceQuantityTextParser::MAX_BYTES), which refuses a longer text anyway.
const MAX_BYTES = 100000;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["error"];

  static values = { tooLarge: String, notText: String };

  async read(event) {
    const input = event.currentTarget;
    const file = input.files?.[0];

    input.value = "";
    this.showError("");

    if (!file) {
      return;
    }

    if (!/\.(csv|txt)$/i.test(file.name)) {
      this.showError(this.notTextValue);

      return;
    }

    if (file.size > MAX_BYTES) {
      this.showError(this.tooLargeValue);

      return;
    }

    const component = await getComponent(this.element.closest("[data-live-name-value]"));

    component.set("pastedText", await file.text(), false);
    component.action("import");
  }

  showError(message) {
    this.errorTarget.textContent = message;
    this.errorTarget.hidden = "" === message;
  }
}
