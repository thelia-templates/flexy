export default function headerButtonProfileFunction() {
  const button = document.querySelector('.profile');
  const dropdown = document.querySelector('.DropdownProfile');

  if (!button || !dropdown) return;

  button.addEventListener('click', () => dropdown.classList.toggle('active'));

  document.addEventListener('click', (event) => {
    if (!button.contains(event.target) && !dropdown.contains(event.target)) {
      dropdown.classList.remove('active');
    }
  });
}
