import { Controller } from '@hotwired/stimulus';

class TooltipController extends Controller {
  static targets = ['text'];

  toggle() {
    this.textTarget.classList.toggle('Tooltip-text--show');
  }
}

export default TooltipController;
