import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["confirm"];

  open() {
    this.element.showModal();
  }

  close() {
    this.element.close();
  }
}
