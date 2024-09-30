import trapItemsMenu from '../../../assets/js/trapItemsMenu';

const Navigation = () => {
  trapItemsMenu();

  const btn = document.getElementById('toggleMenu');

  btn?.addEventListener('click', (e) => {
    e.stopPropagation();
    document.body.classList.toggle('is-open');
  });

  function toggleAccordion() {
    const details = document.querySelectorAll('details');
    if (window.innerWidth >= 768) {
      details.forEach((detail) => {
        detail.setAttribute('open', true);
        detail.querySelector('.Accordion-summary').style.pointerEvents = 'none';
      });
    } else {
      details.forEach((detail) => {
        detail.removeAttribute('open');
        detail.querySelector('.Accordion-summary').style.pointerEvents = 'auto';
      });
    }
  }

  toggleAccordion();

  window.addEventListener('resize', toggleAccordion);
};

export default Navigation;
