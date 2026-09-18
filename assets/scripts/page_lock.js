/**
 * The page lock, shared by every controller that opens a full-screen panel.
 *
 * Locking moves the scroll from the viewport to the body, so the position is carried across
 * by hand or the page jumps to the top.
 */

const LOCK_CLASS = "locked";

// The same markup is a fullscreen overlay below its breakpoint and in-flow above it, where
// the page must stay scrollable.
const PANEL_SELECTOR = ".MobileDrawer.is-open, .Header-menu.is-open, .MobilePanel.is-open";

/**
 * Derived from what is open, so any number of writers may call this in any order. Anything
 * that adds or removes a PANEL_SELECTOR class has to call this right after, including from a
 * Stimulus disconnect() or after a LiveComponent re-render.
 */
export function syncBodyLock() {
  const shouldLock = [...document.querySelectorAll(PANEL_SELECTOR)].some(
    (panel) => getComputedStyle(panel).position === "fixed",
  );

  // Without this, a second call while already locked would read window.scrollY — which is 0
  // once the body is capped — and overwrite the body scroll position with it.
  if (shouldLock === document.body.classList.contains(LOCK_CLASS)) {
    return;
  }

  if (shouldLock) {
    // Read before the class lands: afterwards the body is the scroller and this reads 0.
    const scrollTop = window.scrollY;
    document.body.classList.add(LOCK_CLASS);
    document.body.scrollTop = scrollTop;

    return;
  }

  const scrollTop = document.body.scrollTop;
  document.body.classList.remove(LOCK_CLASS);
  window.scrollTo(0, scrollTop);
}
