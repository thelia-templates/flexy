import { Controller } from "@hotwired/stimulus";

// The dialog carries Molecules--Modal--base; CSS matches attribute names
// case-insensitively in HTML documents, so the lowercase form is safe here.
const CONFIRM_SELECTOR = '[data-molecules--modal--base-target~="confirm"]';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  openModal(event) {
    const trigger = event.currentTarget;
    const dialog = document.getElementById(trigger.dataset.modal);

    if (!dialog) {
      return;
    }

    // The confirm target is a POST form: point it at the action carried by the trigger.
    const confirm = dialog.querySelector(CONFIRM_SELECTOR);

    if (confirm) {
      confirm.action = trigger.dataset.confirm;
    }

    dialog.showModal();
  }
}
