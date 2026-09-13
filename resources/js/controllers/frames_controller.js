import { Controller } from '@hotwired/stimulus';

// Кадры в карточке списка — лента со snap: кадр едет за пальцем и доезжает с
// инерцией, точки следуют за прокруткой. Стрелки и общий тик cards:tick листают
// программно; касание ленты останавливает автолистание на всей странице.
// Морф сохраняет узел ленты вместе с её scrollLeft — кадр не сбрасывается.
export default class extends Controller {
    static targets = ['strip', 'frame', 'dot'];

    connect() {
        this.i = 0;
        this.reduce = matchMedia('(prefers-reduced-motion: reduce)');
        this.onScroll = () => {
            // Лента поехала не от show() — человек листает сам: автолистание на странице гаснет.
            if (!this.programmatic) this.stopAll();
            const i = Math.round(this.stripTarget.scrollLeft / Math.max(1, this.stripTarget.clientWidth));
            if (i === this.i) return;
            this.i = i;
            this.dots();
            this.preload();
        };
        this.stripTarget.addEventListener('scroll', this.onScroll, { passive: true });
    }

    disconnect() {
        this.stripTarget.removeEventListener('scroll', this.onScroll);
        clearTimeout(this.programmaticTimer);
    }

    show(i) {
        const n = this.frameTargets.length;
        if (n < 2) return;
        this.i = (i + n) % n;
        this.preload();
        this.programmatic = true;
        clearTimeout(this.programmaticTimer);
        this.programmaticTimer = setTimeout(() => { this.programmatic = false; }, 700);
        this.stripTarget.scrollTo({ left: this.i * this.stripTarget.clientWidth, behavior: this.reduce.matches ? 'auto' : 'smooth' });
        this.dots();
    }

    dots() {
        this.dotTargets.forEach((d, k) => d.classList.toggle('card-dot--on', k === this.i));
    }

    preload() {
        const next = this.frameTargets[(this.i + 1) % this.frameTargets.length];
        if (next && next.loading === 'lazy') next.loading = 'eager';
    }

    next() { if (!this.stopped && this.element.getBoundingClientRect().bottom > 0) this.show(this.i + 1); }

    manual(event) {
        event.preventDefault(); event.stopPropagation();
        this.stopAll();
        this.show(this.i + 1);
    }

    prev(event) {
        event.preventDefault(); event.stopPropagation();
        this.stopAll();
        this.show(this.i - 1);
    }

    stop() { this.stopped = true; }

    stopAll() { window.dispatchEvent(new CustomEvent('cards:stop')); }

    touch() {
        this.left = this.stripTarget.scrollLeft;
    }

    // Протянул ленту — это не тап по карточке.
    click(event) {
        if (this.left !== undefined && Math.abs(this.stripTarget.scrollLeft - this.left) > 8) event.preventDefault();
    }
}
