export default function PasswordControlsFunction() {
  const controls = document.querySelectorAll('[data-control-password]');

  if (!controls.length) return;

  const getIndicators = (id) => ({
    size: document.getElementById(id + '_size'),
    uppercase: document.getElementById(id + '_uppercase'),
    lowercase: document.getElementById(id + '_lowercase'),
    number: document.getElementById(id + '_number'),
    special: document.getElementById(id + '_special')
  });

  const conditions = {
    size: (value) => value.length >= 12,
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

    return isValid;
  };

  controls.forEach((control) => {
    const input = document.getElementById(control.dataset.controlPassword);
    const parent = input.closest('.FieldInput');

    if (!input) return;

    input.addEventListener('focus', () => {
      control.style.display = 'block';
    });

    const indicators = getIndicators(input.id);

    input.addEventListener('input', function () {
      const handleConditions = [];
      for (const [condition, check] of Object.entries(conditions)) {
        updateIndicator(indicators[condition], check(input.value));
        handleConditions.push(check(input.value));
      }
      parent?.classList.toggle(
        'FieldInput--error',
        handleConditions.filter((c) => !c).length
      );
    });
  });
}
