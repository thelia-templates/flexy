import { Controller } from '@hotwired/stimulus';

const FEEDBACK_MS = 1500;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['source', 'copyLabel', 'toggleLabel', 'toggleButton', 'panel'];

    // Read once: read back while the feedback shows, "Copied!" would become the label to
    // restore, and never leave.
    connect() {
        this.idleLabel = this.copyLabelTarget.textContent;
        this.resetTimer = null;
    }

    disconnect() {
        clearTimeout(this.resetTimer);
    }

    // aria-expanded is the state: the stylesheet turns the chevron on it.
    toggle() {
        const opening = this.panelTarget.hidden;

        this.panelTarget.hidden = !opening;
        this.toggleLabelTarget.textContent = opening ? 'Hide the code' : 'Show the code';
        this.toggleButtonTarget.setAttribute('aria-expanded', String(opening));
    }

    // writeText rejects on a denied permission or an insecure context.
    async copy() {
        try {
            await navigator.clipboard.writeText(this.sourceTarget.textContent);
        } catch {
            this.flash('Copy failed');

            return;
        }

        this.flash('Copied!');
    }

    flash(message) {
        clearTimeout(this.resetTimer);

        this.copyLabelTarget.textContent = message;
        this.resetTimer = setTimeout(() => {
            this.copyLabelTarget.textContent = this.idleLabel;
        }, FEEDBACK_MS);
    }
}
