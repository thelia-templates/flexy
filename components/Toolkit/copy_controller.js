import { Controller } from '@hotwired/stimulus';

const FEEDBACK_MS = 1500;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['source', 'copyLabel', 'toggleLabel', 'panel'];

    // Read once: reading it back while the feedback is on screen would make
    // "Copied!" the label a second click restores to, and it would never leave.
    connect() {
        this.idleLabel = this.copyLabelTarget.textContent;
        this.resetTimer = null;
    }

    disconnect() {
        clearTimeout(this.resetTimer);
    }

    toggle() {
        this.panelTarget.hidden = !this.panelTarget.hidden;
        this.toggleLabelTarget.textContent = this.panelTarget.hidden ? 'Show the code' : 'Hide the code';
    }

    // writeText rejects on a denied permission or an insecure context, and saying
    // "Copied!" then would be a lie about something the visitor cannot check.
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
