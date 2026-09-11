import { Controller } from '@hotwired/stimulus';

// Лента фото: свайп по snap-scroll, счётчик, полноэкранный просмотр.
export default class extends Controller {
    static targets = ['strip', 'counter'];

    connect() {
        if (!this.hasStripTarget) return;
        this.onScroll = () => this.updateCounter();
        this.stripTarget.addEventListener('scroll', this.onScroll, { passive: true });
    }

    disconnect() {
        this.stripTarget?.removeEventListener('scroll', this.onScroll);
        this.viewer?.destroy();
    }

    updateCounter() {
        if (!this.hasCounterTarget) return;
        const i = Math.round(this.stripTarget.scrollLeft / this.stripTarget.clientWidth) + 1;
        this.counterTarget.textContent = `${i} / ${this.stripTarget.children.length}`;
    }

    async open(event) {
        event.preventDefault();
        const { default: Viewer } = await import('viewerjs');
        await import('viewerjs/dist/viewer.css');
        this.viewer?.destroy();
        this.viewer = new Viewer(this.stripTarget, {
            url: (img) => img.closest('a').href,
            navbar: false, title: false, toolbar: { zoomIn: 1, zoomOut: 1, prev: 1, next: 1, rotateLeft: 1, rotateRight: 1 },
            initialViewIndex: Number(event.currentTarget.dataset.index),
            hidden: () => this.viewer?.destroy(),
        });
        this.viewer.show();
    }
}
