import "./stimulus_bootstrap.js";
import * as Turbo from "@hotwired/turbo";

// Turbo Drive is opt-in: only zones marked data-turbo="true" (the checkout
// tunnel) get client-side navigation, the rest of the theme keeps full loads.
Turbo.session.drive = false;

function main() {
  document.body.classList.remove("no-js");
}

document.addEventListener("DOMContentLoaded", () => {
  main();
});

// DOMContentLoaded does not fire again after a Turbo visit.
document.addEventListener("turbo:load", () => {
  main();
});
