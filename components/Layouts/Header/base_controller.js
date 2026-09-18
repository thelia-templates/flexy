import { Controller } from "@hotwired/stimulus";
import { syncBodyLock } from "page_lock";

export default class extends Controller {
  static targets = ["panel", "back", "sub"];

  constructor(arg) {
    super(arg);
    this.previous = 0;
    this.selectedButton = null;
  }

  // Bound here rather than in connect(), which can run more than once per instance: rebinding
  // would leave the previous listener unremovable.
  initialize() {
    this.onDocumentKeydown = this.onDocumentKeydown.bind(this);
    this.onDocumentClick = this.onDocumentClick.bind(this);
    this.onResize = this.onResize.bind(this);
  }

  connect() {
    document.addEventListener("keydown", this.onDocumentKeydown);
    document.addEventListener("click", this.onDocumentClick);
    window.addEventListener("resize", this.onResize);
  }

  disconnect() {
    document.removeEventListener("keydown", this.onDocumentKeydown);
    document.removeEventListener("click", this.onDocumentClick);
    window.removeEventListener("resize", this.onResize);
  }

  // Both decisions read the computed position of the open panel, so crossing a breakpoint with a
  // panel still open has to revisit them — the menu becomes a dropdown bar past md and must stop
  // locking the page. The removed CSS overrides used to get this for free from media queries.
  onResize() {
    syncBodyLock();
    this.syncInertness();
  }

  onDocumentKeydown(event) {
    if (event.key === "Escape" && this.openPanel()) this.close();
  }

  // The trigger lives inside this element, so the click that opens a panel never closes it here.
  onDocumentClick(event) {
    if (this.openPanel() && !this.element.contains(event.target)) this.close();
  }

  togglePanel(event) {
    const { panel: panelId } = event.params;
    const target = this.findPanel(panelId);
    if (!target) return;

    const isOpen = target.classList.contains("is-open");
    this.closeAll();

    if (!isOpen) {
      target.classList.add("is-open");
      syncBodyLock();
      this.syncInertness();
      event.currentTarget.classList.add("is-selected");
      event.currentTarget.setAttribute("aria-expanded", "true");
      this.selectedButton = event.currentTarget;
      this.focusFirstControl(target);
    }
  }

  close() {
    // Captured before closeAll() clears it, so the focus can go back where it came from.
    const trigger = this.selectedButton;

    this.closeAll();
    if (this.hasBackTarget) this.backTarget.dataset.menuBack = -1;
    this.subTargets.forEach((sub) => sub.classList.remove("is-active"));

    trigger?.focus({ preventScroll: true });
  }

  displaySubMenu({ params }) {
    const { item } = params;

    this.subTargets.forEach((sub) => sub.classList.remove("is-active"));

    const targetSub = this.subTargets.find((sub) => sub.dataset.menuSub === item.toString());

    if (targetSub) {
      this.previous = targetSub.dataset.menuPrevious;
      targetSub.classList.add("is-active");

      if (this.previous !== undefined) {
        const previousSub = this.subTargets.find((s) => s.dataset.menuSub === this.previous);
        if (previousSub) {
          previousSub.classList.add("is-active");
        }
      }
    } else {
      this.previous = -1;
    }

    if (this.hasBackTarget) this.backTarget.dataset.menuBack = this.previous;
  }

  back(event) {
    this.displaySubMenu({ params: { item: event.currentTarget.dataset.menuBack } });
  }

  findPanel(panelId) {
    return this.panelTargets.find((p) => p.dataset.headerPanelId === panelId);
  }

  openPanel() {
    return this.panelTargets.find((panel) => panel.classList.contains("is-open")) ?? null;
  }

  // A search field is what the user came for; anything else takes its first control.
  focusFirstControl(panel) {
    const control =
      panel.querySelector("input:not([type='hidden'])") ?? panel.querySelector("a, button, [tabindex]");

    control?.focus({ preventScroll: true });
  }

  // An open panel that covers the bar must take it out of the tab order, or the keyboard walks
  // through links hidden underneath. Two panels are exempt: one that lives inside the bar (the
  // menu — making the bar inert would disable the menu itself), and a fixed fullscreen one, whose
  // breakpoint keeps the bottom navigation visible and usable outside the bar's box.
  syncInertness() {
    const bar = this.element.querySelector(".Header-top");
    if (!bar) return;

    const panel = this.openPanel();

    bar.inert =
      panel !== null && !bar.contains(panel) && getComputedStyle(panel).position !== "fixed";
  }

  closeAll() {
    this.panelTargets.forEach((p) => p.classList.remove("is-open"));
    syncBodyLock();
    this.syncInertness();

    if (this.selectedButton) {
      this.selectedButton.classList.remove("is-selected");
      this.selectedButton.setAttribute("aria-expanded", "false");
      this.selectedButton = null;
    }
  }
}
