import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static values = { id: String };

  open() {
    document.getElementById(this.idValue)?.showModal();
  }
}
