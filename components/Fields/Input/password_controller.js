import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
class PasswordController extends Controller {
  static targets = [
    'input',
    'wrapper',
    'toggler',
    'control',
    'lowercase',
    'size',
    'uppercase',
    'number',
    'special'
  ];

  constructor(...arg) {
    super(...arg);

    this.conditions = {
      size: (value) => value.length >= 12,
      uppercase: (value) => /[A-Z]/.test(value),
      lowercase: (value) => /[a-z]/.test(value),
      number: (value) => /[0-9]/.test(value),
      special: (value) => /[\W_]/.test(value)
    };

    this.indicators = null;
    this.onToggleClick = this.onToggleClick.bind(this);
    this.onInputFocus = this.onInputFocus.bind(this);
  }

  connect() {
    if (this.hasControlTarget) {
      this.indicators = {
        size: this.hasSizeTarget ? this.sizeTarget : null,
        uppercase: this.hasUppercaseTarget ? this.uppercaseTarget : null,
        lowercase: this.hasLowercaseTarget ? this.lowercaseTarget : null,
        number: this.hasNumberTarget ? this.numberTarget : null,
        special: this.hasSpecialTarget ? this.specialTarget : null
      };
      this.inputTarget.addEventListener('focus', this.onInputFocus);
    }

    this.togglerTarget.addEventListener('click', this.onToggleClick);
  }

  disconnect() {
    if (this.hasControlTarget) {
      this.inputTarget.removeEventListener('focus', this.onInputFocus);
    }

    this.togglerTarget.removeEventListener('click', this.onToggleClick);
  }

  onInputFocus() {
    this.controlTarget.style.display = 'block';
  }

  onToggleClick() {
    this.inputTarget.type = this.inputTarget.type === 'password' ? 'text' : 'password';
    this.togglerTarget.classList.toggle('is-visible');
  }

  control() {
    if (!this.hasControlTarget) {
      return;
    }

    const handleConditions = [];
    for (const [condition, check] of Object.entries(this.conditions)) {
      const isValid = check(this.inputTarget.value);
      updateIndicator(this.indicators[condition], isValid);
      handleConditions.push(isValid);
    }
    this.inputTarget.classList.toggle('is-error', handleConditions.some((c) => !c));
  }
}

function updateIndicator(indicator, isValid) {
  if (isValid) {
    indicator.classList.add('valid');
  } else {
    indicator.classList.remove('valid');
  }

  return isValid;
}

export default PasswordController;
