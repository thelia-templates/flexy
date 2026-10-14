import { Controller } from "@hotwired/stimulus";

// A listing page can show one Countdown per card. Each instance ticking its own
// setInterval would mean N timers firing on N different phases; instead every connected
// instance registers itself here and a single shared interval drives them all, so N
// cards cost one timer instead of N. The visibilitychange listener that resyncs every
// instance when the tab comes back is shared the same way, for the same reason.
const instances = new Set();
let intervalId = null;
let visibilityListenerAttached = false;

function tick() {
  for (const instance of instances) {
    instance.decrement();
  }
}

function resyncAll() {
  for (const instance of instances) {
    instance.resync();
  }
}

function onVisibilityChange() {
  if (document.visibilityState === "visible") {
    resyncAll();
  }
}

function joinSharedInterval(instance) {
  instances.add(instance);

  if (intervalId === null) {
    intervalId = window.setInterval(tick, 1000);
  }

  if (!visibilityListenerAttached) {
    document.addEventListener("visibilitychange", onVisibilityChange);
    visibilityListenerAttached = true;
  }
}

function leaveSharedInterval(instance) {
  instances.delete(instance);

  if (instances.size === 0 && intervalId !== null) {
    window.clearInterval(intervalId);
    intervalId = null;
  }

  if (instances.size === 0 && visibilityListenerAttached) {
    document.removeEventListener("visibilitychange", onVisibilityChange);
    visibilityListenerAttached = false;
  }
}

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ["days", "hours", "minutes", "seconds", "announcement"];
  static values = { remainingSeconds: Number };

  connect() {
    // The server never sends a negative duration, but a stale render or a manual edit
    // of the DOM must not make the client count backwards into negative digits.
    const initialRemaining = Math.max(0, this.remainingSecondsValue);

    // Anchor a monotone deadline instead of counting down a mutable integer. A background
    // tab gets its setInterval throttled to roughly one tick a minute, or suspended
    // outright, so a tick-by-tick decrement drifts hours behind reality and can miss the
    // zero crossing that triggers the reload entirely. performance.now() only ever moves
    // forward at a steady rate — unlike Date.now()/the wall clock, which a user or an NTP
    // sync can jump — so deriving `remaining` from the delta to this deadline on every
    // tick (and again on visibilitychange, below) always reflects real elapsed time
    // regardless of how many ticks the browser dropped.
    this.deadline = performance.now() + initialRemaining * 1000;
    this.remaining = initialRemaining;
    this.expired = this.remaining <= 0;

    if (!this.expired) {
      joinSharedInterval(this);
    }
  }

  disconnect() {
    leaveSharedInterval(this);
  }

  decrement() {
    if (this.expired) {
      return;
    }

    this.remaining = Math.max(0, Math.ceil((this.deadline - performance.now()) / 1000));
    this.render();

    if (this.remaining === 0) {
      this.expire();
    }
  }

  // The tab just came back to the foreground: its interval may have been throttled or
  // suspended for minutes, so recompute from the deadline right away instead of waiting
  // for the next tick — and expire immediately if time already ran out while hidden.
  resync() {
    this.decrement();
  }

  render() {
    const days = Math.floor(this.remaining / 86400);
    const hours = Math.floor((this.remaining % 86400) / 3600);
    const minutes = Math.floor((this.remaining % 3600) / 60);
    const seconds = this.remaining % 60;

    this.paint(this.hasDaysTarget, () => (this.daysTarget.textContent = pad(days)));
    this.paint(this.hasHoursTarget, () => (this.hoursTarget.textContent = pad(hours)));
    this.paint(this.hasMinutesTarget, () => (this.minutesTarget.textContent = pad(minutes)));
    this.paint(this.hasSecondsTarget, () => (this.secondsTarget.textContent = pad(seconds)));
  }

  paint(hasTarget, write) {
    if (hasTarget) {
      write();
    }
  }

  // The operation is over: announce it once (not once per second, like the digits
  // above), then reload so the server-rendered page reflects what comes next — the
  // sale gone from the catalog, or its price back to normal.
  expire() {
    this.expired = true;
    leaveSharedInterval(this);

    if (this.hasAnnouncementTarget) {
      this.announcementTarget.textContent = this.element.dataset.countdownExpiredMessage || "This offer has ended.";
    }

    window.setTimeout(() => window.location.reload(), 1000);
  }
}

function pad(value) {
  return String(value).padStart(2, "0");
}
