import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'toolkit:width';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['select', 'content', 'frame', 'main'];
    static classes = ['previewing'];

    // Each component now lives on its own page, so the chosen preview width
    // is kept in sessionStorage and re-applied here, otherwise it would reset
    // to "Responsive" every time the sidebar navigates to another page.
    connect() {
        const width = sessionStorage.getItem(STORAGE_KEY);

        if (width) {
            this.selectTarget.value = width;
            this.setWidth();
        }
    }

    setWidth() {
        const width = this.selectTarget.value;

        if (!width) {
            sessionStorage.removeItem(STORAGE_KEY);
            this.contentTarget.hidden = false;
            this.frameTarget.hidden = true;
            this.mainTarget.classList.remove(...this.previewingClasses);
            return;
        }

        sessionStorage.setItem(STORAGE_KEY, width);

        const src = `${window.location.pathname}?embed=1`;
        if (this.frameTarget.getAttribute('src') !== src) {
            this.frameTarget.src = src;
        }

        this.frameTarget.style.width = `${width}px`;
        this.contentTarget.hidden = true;
        this.frameTarget.hidden = false;
        this.mainTarget.classList.add(...this.previewingClasses);
    }
}
