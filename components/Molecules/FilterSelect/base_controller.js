import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  // <label> has no native keyboard activation (unlike button/input): Tab reaches an option,
  // but Enter/Space did nothing. Replaying a real click reuses the exact same path as a mouse
  // selection (including closing the dropdown/drawer), so behavior stays identical either way.
  activate(event) {
    event.preventDefault();
    event.currentTarget.click();
  }
}
