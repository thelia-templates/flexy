import { Controller } from "@hotwired/stimulus";

// The mobile/tablet product count sits in the subheader, outside the ProductListing
// LiveComponent, so filtering never re-renders it. It listens for the total broadcast after each
// save() and updates its own number in place. The desktop toolbar count is inside the component
// and needs none of this.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["value"];

  connect() {
    this.onTotal = this.onTotal.bind(this);
    document.addEventListener("product-listing:total", this.onTotal);
    this.syncFromGrid();
  }

  // On a directly-loaded filtered URL (url-bound tfilters), no save() fires, so the initial
  // productCount prop is the unfiltered total. The re-rendered grid already carries the filtered
  // total from postMount — adopt it on connect so the count matches the listing from the start.
  syncFromGrid() {
    const total = document.querySelector(".ProductListing-grid")?.dataset.listingTotal;
    if (total !== undefined) {
      this.valueTarget.textContent = total;
    }
  }

  disconnect() {
    document.removeEventListener("product-listing:total", this.onTotal);
  }

  onTotal(event) {
    this.valueTarget.textContent = event.detail.total;
  }
}
