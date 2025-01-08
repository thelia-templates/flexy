export default function PasswordControlsFunction() {
  const controls = document.querySelectorAll('[data-control-password]');

  if (!controls.length) return;

  const getIndicators = (id) => ({
    length: document.getElementById(id + '_length'),
    uppercase: document.getElementById(id + '_uppercase'),
    lowercase: document.getElementById(id + '_lowercase'),
    number: document.getElementById(id + '_number'),
    special: document.getElementById(id + '_special')
  });

  const conditions = {
    length: (value) => value.length >= 12,
    uppercase: (value) => /[A-Z]/.test(value),
    lowercase: (value) => /[a-z]/.test(value),
    number: (value) => /[0-9]/.test(value),
    special: (value) => /[\W_]/.test(value)
  };

  const updateIndicator = (indicator, isValid) => {
    if (isValid) {
      indicator.classList.add('valid');
    } else {
      indicator.classList.remove('valid');
    }
  };

  controls.forEach((control) => {
    const input = document.getElementById(control.dataset.controlPassword);

    if (!input) return;

    input.addEventListener('focus', () => {
      control.style.display = 'block';
    });

    const indicators = getIndicators(input.id);

    input.addEventListener('input', function () {
      for (const [condition, check] of Object.entries(conditions)) {
        updateIndicator(indicators[condition], check(input.value));
      }
    });
  });
}
