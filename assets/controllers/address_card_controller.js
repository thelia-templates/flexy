import { Controller } from '@hotwired/stimulus';

class AddressCardController extends Controller {
  static outlets = ['modal'];

  openModal(e) {
    const modal = this.findModal(e.currentTarget.dataset.modal);

    if (modal) {
      modal.confirmTarget.href = e.currentTarget.dataset.confirm;

      this.findModal(e.currentTarget.dataset.modal).open(e);
    }
  }

  findModal(id) {
    return this.modalOutlets.find(({ element }) => element.id === id);
  }
}

export default AddressCardController;
