import trapItemsMenu from '../../../assets/js/trapItemsMenu';

const Header = () => {
  Menu();
  trapItemsMenu();
  const btn = document.getElementById('toggleMenu');

  btn?.addEventListener('click', () =>
    document.getElementById('Menu').classList.toggle('is-open')
  );
};

export function Menu() {
  const menu = document.getElementById('Menu');
  if (!menu) return null;

  let previous = 0;

  const closeBtn = menu.querySelector('[data-menu-close]');
  const backBtn = menu.querySelector('[data-menu-back]');
  const subBtns = menu.querySelectorAll('[data-menu-item]');
  const subs = menu.querySelectorAll('[data-menu-sub]');

  closeBtn?.addEventListener('click', () => {
    menu.classList.remove('is-open');
    backBtn.dataset.menuBack = -1;
    subs.forEach((sub) => {
      sub.classList.remove('is-active');
    });
  });

  backBtn?.addEventListener('click', () => {
    displaySubMenu(previous);
  });

  subBtns?.forEach((btn) => {
    btn.addEventListener('click', () => {
      displaySubMenu(btn.dataset.menuItem);
    });
  });

  function displaySubMenu(current) {
    subs.forEach((sub) => {
      sub.classList.remove('is-active');

      if (sub.dataset.menuSub === current) {
        previous = sub.dataset.menuPrevious;
        sub.classList.add('is-active');
        [...subs]
          .find((s) => s.dataset.menuSub === previous)
          ?.classList.add('is-active');
        displayBackBtn(previous ?? -1);
      }
    });
  }

  function displayBackBtn(previous) {
    backBtn.dataset.menuBack = previous;
  }
}

export default Header;
