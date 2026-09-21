import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
class BaseController extends Controller {
  static targets = ["stage"];

  static values = {
    provider: String,
    embedUrl: String,
    fileUrl: String,
    label: String,
  };

  connect() {
    // The poster and the button the stage holds before a player takes their place:
    // kept as they are, so that leaving the visual puts them back untouched.
    this.poster = Array.from(this.stageTarget.children);

    this.onSlideShown = (event) => {
      const { gallery, slide } = event.detail ?? {};

      if (!gallery?.contains(this.element) || slide?.contains(this.element)) {
        return;
      }

      this.pause();
    };

    window.addEventListener("product-gallery:slide-shown", this.onSlideShown);
  }

  disconnect() {
    window.removeEventListener("product-gallery:slide-shown", this.onSlideShown);
  }

  /**
   * Stops what is playing when the shopper moves to another visual.
   *
   * A file the shop serves is paused where it is: coming back, the shopper picks it
   * up where he left it. A platform frame cannot be paused without loading the
   * platform's own script, so it is taken down and the poster comes back - which also
   * stops the platform from following a shopper who has moved on. One click starts it
   * again.
   */
  pause() {
    const playing = this.stageTarget.firstElementChild;

    if (!playing || this.poster.includes(playing)) {
      return;
    }

    if (playing.tagName === "VIDEO") {
      playing.pause();

      return;
    }

    this.stageTarget.replaceChildren(...this.poster);
  }

  /**
   * Builds the player and puts it in place of the poster. Both addresses come from the
   * values above, which the core filled: nothing here concatenates or rewrites a URL, so
   * what a merchant typed can never reach the frame.
   */
  play() {
    // 'file' is the platform code the core gives a video the shop hosts itself; every other
    // code is a platform, and is played in a frame.
    const player = this.providerValue === "file" ? this.buildFile() : this.buildFrame();

    if (!player) {
      return;
    }

    // replaceChildren, not innerHTML: the element is built attribute by attribute, so no
    // markup is ever parsed out of a value.
    this.stageTarget.replaceChildren(player);

    // The button that had the focus has just been removed from the document, so the focus is
    // on its way back to <body>: it is moved onto the player, which is where the shopper who
    // clicked expects to be, whichever kind of player was built.
    player.focus();

    // A <video> can start straight away — a user gesture is what the browser asks for and
    // this method only runs from one. A rejected promise (a policy this shop cannot see)
    // leaves the shopper with the native controls, which is a fine place to land.
    if (player.tagName === "VIDEO") {
      player.play().catch(() => {});
    }
  }

  buildFrame() {
    if (!this.hasEmbedUrlValue || !this.embedUrlValue) {
      return null;
    }

    const frame = document.createElement("iframe");

    frame.src = this.embedUrlValue;
    frame.title = this.labelValue;
    frame.className = "VideoPlayer-frame";
    frame.loading = "lazy";
    frame.allow = "autoplay; fullscreen; picture-in-picture";
    frame.allowFullscreen = true;
    frame.referrerPolicy = "strict-origin-when-cross-origin";

    return frame;
  }

  buildFile() {
    if (!this.hasFileUrlValue || !this.fileUrlValue) {
      return null;
    }

    const video = document.createElement("video");

    video.src = this.fileUrlValue;
    video.className = "VideoPlayer-file";
    video.controls = true;
    video.preload = "metadata";
    video.setAttribute("aria-label", this.labelValue);

    return video;
  }
}

export default BaseController;
