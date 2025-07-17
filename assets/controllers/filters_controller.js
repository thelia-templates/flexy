import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

class FiltersController extends Controller {
  async initialize() {
    this.component = await getComponent(this.element);
  }

  filterChange() {
    this.component.action('save');
  }

  sortChange(e) {
    this.component.action('save', { order: e.target.value });
  }
  resetForm() {
    const event = new CustomEvent("live:form:reset");
    this.component.action('save', { reset: true }).then(() => window.dispatchEvent(event));
  }
}

export default FiltersController;
