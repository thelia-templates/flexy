import Favorite from '@components/Molecules/Favorite/Favorite';

export default function AddressCard() {
  const inputs = document.querySelectorAll('.AddressCard input[type="radio"]');
  const deleteBtns = document.querySelectorAll(
    '[data-id="confirmDeleteAdresse"]'
  );

  inputs.forEach((input) => {
    input.addEventListener('change', function () {
      const parent = this.closest('.AddressCard');
      parent.classList.toggle('selected', input.checked);
    });
  });

  deleteBtns.forEach((btn) => {
    btn.addEventListener('openModal', () => {
      const link = document.getElementById('DeleteAddressBtn');
      if (link) {
        link.href = btn.dataset.url;
      }
    });
  });

  Favorite();
}
