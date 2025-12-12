import { Controller } from '@hotwired/stimulus';

class HeaderController extends Controller {
  static targets = ['toggler', 'menu', 'close', 'back', 'sub'];

  constructor(arg) {
    super(arg);
    this.previous = 0;
  }

  connect() {
    if (!this.hasTogglerTarget) {
      return;
    }

    this.togglerTarget?.addEventListener('click', () => {
      this.menuTarget.classList.toggle('is-open');
      this.togglerTarget.classList.toggle('is-selected');
      document.body.classList.toggle('locked');
    });
  }

  close() {
    if (!this.hasTogglerTarget) {
      return;
    }

    this.menuTarget.classList.remove('is-open');
    this.backTarget.dataset.menuBack = -1;
    this.togglerTarget.classList.remove('is-selected');
    document.body.classList.remove('locked');
    this.subTargets.forEach((sub) => sub.classList.remove('is-active'));
  }

  displaySubMenu({ params }) {
    const { item } = params;

    this.subTargets.forEach((sub) => sub.classList.remove('is-active'));

    const targetSub = this.subTargets.find(
      (sub) => sub.dataset.menuSub === item.toString()
    );

    if (targetSub) {
      this.previous = targetSub.dataset.menuPrevious;
      targetSub.classList.add('is-active');

      if (this.previous !== undefined) {
        const previousSub = this.subTargets.find(
          (s) => s.dataset.menuSub === this.previous
        );
        if (previousSub) {
          previousSub.classList.add('is-active');
        }
      }
    } else {
      this.previous = -1;
    }

    displayBackBtn(this.backTarget, this.previous);
  }

  back(event) {
    this.displaySubMenu({
      params: { item: event.currentTarget.dataset.menuBack }
    });
  }
}

function displayBackBtn(backBtn, previous) {
  backBtn.dataset.menuBack = previous;
}

export default HeaderController;
