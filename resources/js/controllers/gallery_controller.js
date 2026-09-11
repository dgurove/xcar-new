import { Controller } from '@hotwired/stimulus';

// Лента фото: свайп по snap-scroll, счётчик, полноэкранный просмотр.
export default class extends Controller {
    static targets = ['strip', 'counter', 'thumb'];

    connect() {
        if (!this.hasStripTarget) return;
        this.onScroll = () => this.updateCounter();
        this.stripTarget.addEventListener('scroll', this.onScroll, { passive: true });
    }

    disconnect() {
        this.stripTarget?.removeEventListener('scroll', this.onScroll);
        this.viewer?.destroy();
    }

    prev() { this.to(this.index() - 1); }

    next() { this.to(this.index() + 1); }

    // Клик по миниатюре или стрелке: прокрутить ленту к кадру.
    to(i) {
        const n = this.stripTarget.children.length;
        if (typeof i === 'object') i = Number(i.params.index);
        i = ((i % n) + n) % n;
        this.stripTarget.scrollTo({ left: i * this.stripTarget.clientWidth, behavior: 'smooth' });
    }

    index() {
        return Math.round(this.stripTarget.scrollLeft / this.stripTarget.clientWidth);
    }

    updateCounter() {
        this.thumbTargets?.forEach((t, k) => t.classList.toggle('ring-2', k === this.index()));
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
