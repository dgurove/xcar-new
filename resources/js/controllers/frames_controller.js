import { Controller } from '@hotwired/stimulus';

// Кадры в карточке списка: точки, стрелки, свайп, общий тик cards:tick.
// Ручное листание останавливает автолистание на всей странице.
export default class extends Controller {
    static targets = ['frame', 'dot'];

    connect() {
        this.i = 0;
        this.swiped = false;
    }

    show(i) {
        const n = this.frameTargets.length;
        if (n < 2) return;
        this.i = (i + n) % n;
        this.frameTargets.forEach((f, k) => { f.hidden = k !== this.i; });
        this.dotTargets.forEach((d, k) => d.classList.toggle('card-dot--on', k === this.i));
        const next = this.frameTargets[(this.i + 1) % n];
        if (next && !next.dataset.loaded) { next.loading = 'eager'; next.dataset.loaded = '1'; }
    }

    next() { if (!this.stopped) this.show(this.i + 1); }

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

    start(event) {
        const t = event.touches[0];
        this.x = t.clientX; this.y = t.clientY;
    }

    end(event) {
        if (this.x === undefined) return;
        const t = event.changedTouches[0];
        const dx = t.clientX - this.x, dy = t.clientY - this.y;
        this.x = undefined;
        if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) return;
        this.stopAll();
        this.show(this.i + (dx < 0 ? 1 : -1));
        this.swiped = true;
        setTimeout(() => { this.swiped = false; }, 400);
    }

    click(event) { if (this.swiped) event.preventDefault(); }
}
