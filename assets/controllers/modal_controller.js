import { Controller } from '@hotwired/stimulus';

class ModalController extends Controller {
  static targets = ['confirm'];

  toggle({ currentTarget }) {
    this.confirmTarget.href = currentTarget.dataset.confirm;

    this.element.classList.toggle('show-modal');
  }
}

export default ModalController;
