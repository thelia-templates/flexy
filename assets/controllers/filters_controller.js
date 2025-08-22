import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

class FiltersController extends Controller {
  async initialize() {
    this.component = await getComponent(this.element);

    window.addEventListener('live:form:reset', () => {
      const url = new URL(window.location.href);

      setTimeout(() => {
        window.history.replaceState({ }, 'reset filters on current page', url.pathname);
      },100);
    });
  }

  filterChange() {
    this.component.action('save');
  }

  sortChange(e) {
    this.component.action('save', { order: e.target.value });
  }
  resetForm() {
    const event = new CustomEvent('live:form:reset');
    this.component
      .action('save', { reset: true })
      .then(() => window.dispatchEvent(event));
  }
}

export default FiltersController;
