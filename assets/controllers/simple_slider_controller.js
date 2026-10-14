import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.watcher = { isDown: false, startX: 0, scrollLeft: 0 };

        this.onMouseDown = (e) => this.start(e.pageX);
        this.onTouchStart = (e) => this.start(e.touches[0].pageX);
        this.onEnd = () => this.end();
        this.onMouseMove = (e) => this.move(e, e.pageX);
        this.onTouchMove = (e) => this.move(e, e.touches[0].pageX);

        this.element.addEventListener('mousedown', this.onMouseDown);
        this.element.addEventListener('touchstart', this.onTouchStart);
        this.element.addEventListener('mouseleave', this.onEnd);
        this.element.addEventListener('mouseup', this.onEnd);
        this.element.addEventListener('touchend', this.onEnd);
        this.element.addEventListener('touchcancel', this.onEnd);
        this.element.addEventListener('mousemove', this.onMouseMove);
        this.element.addEventListener('touchmove', this.onTouchMove);
    }

    disconnect() {
        this.element.removeEventListener('mousedown', this.onMouseDown);
        this.element.removeEventListener('touchstart', this.onTouchStart);
        this.element.removeEventListener('mouseleave', this.onEnd);
        this.element.removeEventListener('mouseup', this.onEnd);
        this.element.removeEventListener('touchend', this.onEnd);
        this.element.removeEventListener('touchcancel', this.onEnd);
        this.element.removeEventListener('mousemove', this.onMouseMove);
        this.element.removeEventListener('touchmove', this.onTouchMove);
    }

    start(pageX) {
        this.watcher.isDown = true;
        this.element.classList.add('active');
        this.watcher.startX = pageX - this.element.offsetLeft;
        this.watcher.scrollLeft = this.element.scrollLeft;
    }

    end() {
        this.watcher.isDown = false;
        this.element.classList.remove('active');
    }

    move(e, pageX) {
        if (!this.watcher.isDown) {
            return;
        }

        e.preventDefault();
        const x = pageX - this.element.offsetLeft;
        const walk = (x - this.watcher.startX) * 3;
        this.element.scrollLeft = this.watcher.scrollLeft - walk;
    }
}
