import { Controller } from '@hotwired/stimulus';

// Лента фото: свайп по snap-scroll, стрелки и ←/→, счётчик, полноэкранный просмотр.
// Просмотрщик — Viewer.js: лента миниатюр, зум колесом и щипком, без анимации кадров.
let viewerModule = null;
const loadViewer = () => (viewerModule ??= Promise.all([import('viewerjs'), import('viewerjs/dist/viewer.css')]).then(([m]) => m.default));

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
        this.viewer = null;
    }

    prev() { this.to(this.index() - 1); }

    next() { this.to(this.index() + 1); }

    // ←/→ на странице листают ленту; внутри просмотрщика клавиши его собственные.
    key(event) {
        if (this.viewer || event.target.closest('input, textarea, select, [contenteditable]')) return;
        if (event.key === 'ArrowLeft') this.prev();
        if (event.key === 'ArrowRight') this.next();
    }

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
        this.counterTarget.textContent = `${this.index() + 1} / ${this.stripTarget.children.length}`;
    }

    async open(event) {
        event.preventDefault();
        // Индекс — до await: после него event.currentTarget уже пуст.
        const index = event.currentTarget.dataset.index === undefined ? this.index() : Number(event.currentTarget.dataset.index);
        const Viewer = await loadViewer();
        this.viewer?.destroy();
        this.viewer = new Viewer(this.stripTarget, {
            url: (img) => img.closest('a').href,
            navbar: true, title: false, transition: false, tooltip: false,
            toolbar: { prev: { show: 1, size: 'large' }, reset: { show: 1, size: 'large' }, next: { show: 1, size: 'large' } },
            initialViewIndex: index,
            hidden: () => { this.viewer?.destroy(); this.viewer = null; },
        });
        this.viewer.show();
    }
}
