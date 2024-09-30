export const BREAKPOINT_MOBILE = 767;

export default function trapItemsMenu(callback) {
  const categories = document.querySelectorAll('.js-toggleCategory');

  if (!categories.length) return;

  categories.forEach((category) => {
    category.addEventListener('click', (e) => {
      e.stopPropagation();

      const oldActivated = document.querySelector('li.active');
      if (oldActivated && oldActivated !== category.parentElement) {
        oldActivated.classList.remove('active');
      }
      let isOpen = false;
      if (category.parentElement.classList.contains('active')) {
        document.body.classList.remove('is-open');
      } else {
        document.body.classList.add('is-open');
        isOpen = true;
      }
      if (callback) {
        callback(category.parentElement, isOpen);
      }
      category.parentElement.classList.toggle('active');
    });

    const sub = category.parentElement.querySelector('.SubMenu');

    sub.addEventListener('keydown', (e) => {
      trapTabKey(sub, e);
      trapEscape(category, e);
    });

    sub.querySelector('.SubMenu-back').addEventListener('click', (e) => {
      e.stopPropagation();
      sub.parentNode.classList.remove('active');
    });
  });

  const subMenus = document.querySelectorAll('.SubMenu');
  const page = document.querySelector('body:not(.SubMenu)');
  const close = document.querySelectorAll('.Navigation-close');

  page.addEventListener('click', function (event) {
    let clickedInsideSubMenu = false;
    subMenus.forEach((subMenu) => {
      if (subMenu.contains(event.target)) {
        clickedInsideSubMenu = true;
      }
    });
    if (!clickedInsideSubMenu) {
      closeMenu();
    }
  });

  close.forEach((el) => el.addEventListener('click', closeMenu));
}

function closeMenu() {
  if (document.body.classList.contains('is-open')) {
    document.body.classList.remove('is-open');
    document.querySelectorAll('.ItemHeader').forEach((category) => {
      category.classList.remove('active');
    });
  }
}
export function trapTabKey(container, event) {
  const { shiftKey, keyCode } = event;

  if (keyCode !== 9) return;
  // get list of focusable items
  const focusableItems = [
    ...container.querySelectorAll('a[href],input,button:not(.no-focusTrap)')
  ];

  // get currently focused item
  const focusedItem = document.querySelector(':focus');
  // get the number of focusable items
  const numberOfFocusableItems = focusableItems.length;

  // get the index of the currently focused item
  const focusedItemIndex = focusableItems.indexOf(focusedItem);

  if (shiftKey) {
    //back tab
    // if focused on first item and user preses back-tab, go to the last focusable item
    if (focusedItemIndex !== 0) return;

    focusableItems[numberOfFocusableItems - 1]?.focus();
    event.preventDefault();
  } else {
    //forward tab
    // if focused on the last item and user preses tab, go to the first focusable item
    if (focusedItemIndex !== numberOfFocusableItems - 1) return;

    focusableItems[0]?.focus();
    event.preventDefault();
  }
}

export function trapEscape(container, event) {
  event.stopPropagation();

  if (event.keyCode !== 27) return;

  container.parentElement.classList.remove('active');
  container.focus();
}
