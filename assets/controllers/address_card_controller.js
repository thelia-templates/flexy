import { Controller } from '@hotwired/stimulus';

class AddressCardController extends Controller {
  static outlets = ['modal'];

  openModal(e) {
    const modal = this.findModal(e.currentTarget.dataset.modal);

    if (modal) {
      const confirm = modal.confirmTarget;
      const url = e.currentTarget.dataset.confirm;

      // Delete and set-as-default are state changing: they go through a POST
      // form carrying a CSRF token, never a plain link.
      if (confirm.tagName === 'FORM') {
        confirm.action = url;
      } else {
        confirm.href = url;
      }

      modal.open(e);
    }
  }

  findModal(id) {
    return this.modalOutlets.find(({ element }) => element.id === id);
  }
}

export default AddressCardController;
