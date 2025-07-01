import { Controller } from '@hotwired/stimulus';

class ModalController extends Controller {
  static targets = ['confirm'];

  initialize() {
    window.addEventListener('modal:open', () => this.open());
  }

  open() {
    this.element.showModal();
  }
  close() {
    this.element.close();
  }
}

export default ModalController;
