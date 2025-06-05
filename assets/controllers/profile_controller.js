import { Controller } from '@hotwired/stimulus';

class ProfileController extends Controller {
  static targets = ['dropdown'];

  toggle() {
    this.dropdownTarget.classList.toggle('active');
  }
}

export default ProfileController;
