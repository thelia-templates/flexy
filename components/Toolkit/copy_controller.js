import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['source', 'copyLabel', 'toggleLabel', 'panel'];

    toggle() {
        this.panelTarget.hidden = !this.panelTarget.hidden;
        this.toggleLabelTarget.textContent = this.panelTarget.hidden ? 'Voir le code' : 'Masquer le code';
    }

    copy() {
        navigator.clipboard.writeText(this.sourceTarget.textContent);

        const label = this.copyLabelTarget;
        const original = label.textContent;

        label.textContent = 'Copié !';
        setTimeout(() => {
            label.textContent = original;
        }, 1500);
    }
}
