import { Controller } from '@hotwired/stimulus';

class AddressCardController extends Controller {
  static outlets = ['modal'];

  openModal(e) {
    console.log(this.findModal(e.currentTarget.id));

    this.findModal(e.currentTarget.dataset.modal).toggle(e);
  }

  findModal(id) {
    return this.modalOutlets.find(({ element }) => element.id === id);
  }
}

export default AddressCardController;
