import { Controller } from "@hotwired/stimulus";
import { getComponent } from "@symfony/ux-live-component";

// Tokens already answered: a page restored from the Turbo cache keeps its value. Not cleared off
// the element, as LiveComponent would replay that on every later render.
const answered = new Set();

// Moves the focus to what a rejected submission has to say, so submitting lands the visitor on
// the errors rather than back at the top of the page.
export default class extends Controller {
  static targets = ["summary"];
  static values = { submission: String };

  async connect() {
    this.focusOnSubmission();

    // A live form re-renders in place and fires no second connect(). The Component object
    // carries the hook for that; no DOM event exists to listen to.
    const liveRoot = this.element.closest('[data-controller~="live"]');

    if (!liveRoot) {
      return;
    }

    this.component = await getComponent(liveRoot);
    this.component.on("render:finished", this.focusOnSubmission);
  }

  disconnect() {
    this.component?.off("render:finished", this.focusOnSubmission);
  }

  // Read rather than observed: Stimulus never fires `submissionValueChanged` for a mixed-case identifier.
  // A field property, not a method: `on` and `off` must be handed the same reference.
  focusOnSubmission = () => {
    const submission = this.submissionValue;

    if (!submission || answered.has(submission)) {
      return;
    }

    answered.add(submission);
    this.focusFirstError();
  };

  focusFirstError() {
    const form = this.element.closest("form");

    // Placing the focus is the job of a submitted form; rendered outside one, as the story is,
    // the component has nothing to send the reader back to.
    if (!form) {
      return;
    }

    // Reaching the summary reads the whole list; landing on a field reads that field alone.
    // The first field is what is left when a single visible error leaves no summary to go to.
    if (this.hasSummaryTarget) {
      this.summaryTarget.focus({ preventScroll: false });

      return;
    }

    form.querySelector('[aria-invalid="true"]')?.focus({ preventScroll: false });
  }
}
