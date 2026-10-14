import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["text"];

  toggle() {
    this.textTarget.classList.toggle("Tooltip-text--show");
  }
}
