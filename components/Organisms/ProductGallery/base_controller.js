import { Controller } from "@hotwired/stimulus";
import Splide from "@splidejs/splide";
import "@splidejs/splide/dist/css/splide-core.min.css";
import { getComponent } from "@symfony/ux-live-component";

/* stimulusFetch: 'lazy' */
class BaseController extends Controller {
  static targets = ["item", "thumbnail", "productImg", "thumblist", "slider", "slide"];

  static values = {
    currentPseId: Number,
  };

  async initialize() {
    // The gallery is rendered inside the product LiveComponent, not as its root: getComponent()
    // only accepts the exact element the live controller is bound to, hence the closest() lookup.
    // Outside a LiveComponent (toolkit) there is nothing to resolve — the slider still works,
    // only the PSE round-trip is inert.
    const liveRoot = this.element.closest("[data-live-name-value]");

    this.component = liveRoot ? await getComponent(liveRoot) : null;
  }

  connect() {
    // change:pse is dispatched on the LiveComponent root, which is an ancestor of this element —
    // a bubbling event never travels down, so window is the only node both ends share.
    this.onPseChanged = (event) => this.syncToPse(event.detail.pseId, false);
    window.addEventListener("change:pse", this.onPseChanged);

    this.initSlider();
    this.syncToPse(this.currentPseIdValue, true);
  }

  disconnect() {
    window.removeEventListener("change:pse", this.onPseChanged);
    this.slider?.destroy();
    this.slider = null;
  }

  initSlider() {
    this.slider = new Splide(this.sliderTarget, {
      // Splide reads its direction from its own options only, never from the document: without
      // this the track would still run left to right under a right-to-left page, and the arrows
      // mirrored by the template would point away from the slide they reach.
      direction: document.documentElement.dir === "rtl" ? "rtl" : "ltr",
      pagination: false,
      destroy: this.slideTargets.length <= 1,
      drag: false,
      // Matches the sm breakpoint (640px): below it the slider is swipeable with dots, from it
      // the arrows rendered by the template are shown and must be wired.
      breakpoints: {
        639: {
          pagination: true,
          arrows: false,
          drag: true,
        },
      },
    });

    this.slider.mount();
    this.slider.on("moved", (index) => this.onMoved(index));
  }

  /** Called from the thumbnail's list item, so its own live action attribute stays untouched. */
  select(event) {
    this.goToSlide(this.itemTargets.indexOf(event.currentTarget));
  }

  /** Swiping the main slider selects the PSE the reached visual belongs to. */
  onMoved(index) {
    this.setActive(index);

    const pseIds = (this.slideTargets[index]?.dataset.pseIds ?? "")
      .split(",")
      .filter(Boolean);

    if (pseIds.length === 0 || pseIds.includes(String(this.currentPseIdValue))) {
      return;
    }

    this.component?.action("updateCurrentPseFromId", { pseIds: pseIds.join(",") });
  }

  /**
   * Shows the visual matching the given PSE. `force` positions the gallery unconditionally (initial
   * load); without it, a PSE that has no visual of its own leaves the current one alone as long as
   * that one is shared by every variant — see below.
   */
  syncToPse(pseId, force) {
    this.currentPseIdValue = pseId;

    const index = this.thumbnailTargets.findIndex((thumbnail) =>
      (thumbnail.dataset.pseId ?? "").split(",").includes(String(pseId)),
    );

    if (index !== -1) {
      this.goToSlide(index);

      return;
    }

    // The selected variant has no visual of its own. A product-level visual stays accurate for
    // every variant, so keep showing it rather than yanking the shopper back to the first image —
    // only a visual belonging to *another* PSE has become misleading and must be replaced.
    if (!force && !this.currentSlideBelongsToAPse()) {
      return;
    }

    // Falls back to the first product-level visual, then to the first of all. The source template
    // guessed `loop.first` server-side, which is already wrong when the URL carries a ?ref
    // pointing at another PSE.
    const productImg = this.productImgTargets[0];

    this.goToSlide(productImg ? this.thumbnailTargets.indexOf(productImg) : 0);
  }

  currentSlideBelongsToAPse() {
    const current = this.slideTargets[this.slider?.index ?? 0];

    return (current?.dataset.pseIds ?? "") !== "";
  }

  goToSlide(index) {
    if (index < 0) {
      return;
    }

    this.slider?.go(index);
    this.setActive(index);
    this.scrollThumbnailIntoView(index);
  }

  setActive(index) {
    this.itemTargets.forEach((item, itemIndex) => {
      item.classList.toggle("is-active", itemIndex === Number(index));
    });
  }

  scrollThumbnailIntoView(index) {
    if (!this.hasThumblistTarget || !this.itemTargets[index]) {
      return;
    }

    this.thumblistTarget.scrollTo({
      top: this.itemTargets[index].offsetTop,
      behavior: "smooth",
    });
  }
}

export default BaseController;
