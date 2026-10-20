import { Controller } from '@hotwired/stimulus';

// Лента пачки плиток (x-ui.shelf): на телефоне листается пальцем, на ПК — ещё стрелками в заголовке. Стрелка листает на
// видимую ширину, у края гаснет; лента помещается целиком — стрелок нет. На сенсорном экране стрелок нет — и следить
// за прокруткой незачем.
export default class extends Controller {
    static targets = ['track', 'prev', 'next', 'arrows'];

    connect() {
        if (matchMedia('(hover: none)').matches) return;
        this.onScroll = () => {
            if (this.frame) return;
            this.frame = requestAnimationFrame(() => { this.frame = null; this.sync(); });
        };
        this.trackTarget.addEventListener('scroll', this.onScroll, { passive: true });
        this.resize = new ResizeObserver(() => this.sync());
        this.resize.observe(this.trackTarget);
        this.sync();
    }

    disconnect() {
        if (!this.onScroll) return;
        this.trackTarget.removeEventListener('scroll', this.onScroll);
        this.resize?.disconnect();
        if (this.frame) cancelAnimationFrame(this.frame);
    }

    prev() { this.step(-1); }

    next() { this.step(1); }

    step(dir) {
        const t = this.trackTarget;
        const from = t.scrollLeft;
        const to = Math.max(0, Math.min(t.scrollWidth - t.clientWidth, from + dir * Math.max(t.clientWidth * 0.9, 200)));
        t.scrollTo({ left: to, behavior: 'smooth' });
        // Плавная прокрутка не пошла (вкладка без анимаций, reduced motion) — сразу на место.
        setTimeout(() => { if (Math.abs(t.scrollLeft - from) < 1) t.scrollLeft = to; this.sync(); }, 450);
    }

    sync() {
        const t = this.trackTarget;
        const max = t.scrollWidth - t.clientWidth;
        if (this.hasArrowsTarget) this.arrowsTarget.hidden = max <= 2;
        if (this.hasPrevTarget) this.prevTarget.disabled = t.scrollLeft <= 2;
        if (this.hasNextTarget) this.nextTarget.disabled = t.scrollLeft >= max - 2;
    }
}
