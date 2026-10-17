import "./stimulus_bootstrap.js";

// Turbo Drive is opt-in: only zones marked data-turbo="true" (the checkout
// tunnel) get client-side navigation, the rest of the theme keeps full loads.
// Those zones are rendered by the server (checkout-base.html.twig), never added
// to a page later, so the library is imported only on the pages that have one.
// The import is dynamic on purpose: AssetMapper does not preload a module that
// is only reached through import(), so the other pages do not download it.
if (document.querySelector('[data-turbo="true"]')) {
  import("@hotwired/turbo").then((Turbo) => {
    Turbo.session.drive = false;

    // Turbo runs its page-load steps (first turbo:load, scroll restoration) on
    // a readystatechange event. Started after the page has finished loading,
    // it would never see one: replay the current state.
    if (document.readyState === "complete") {
      Turbo.session.pageObserver?.interpretReadyState?.();
    }
  });
}

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
