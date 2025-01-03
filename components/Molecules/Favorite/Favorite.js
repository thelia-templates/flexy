export default function Favorite() {
  const btns = document.querySelectorAll('[data-id="confirmDefaultAdresse"]');
  btns.forEach((btn) => {
    btn.addEventListener('openModal', () => {
      const link = document.getElementById('ConfirmDefaultAddressBtn');
      if (link) {
        link.href = btn.dataset.url;
      }
    });
  });
}
