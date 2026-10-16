import { Controller } from '@hotwired/stimulus';
import { scrollBehavior } from '../lib/motion';

// Кадры в карточке списка — лента со snap: кадр едет за пальцем и доезжает с
// инерцией, точки следуют за прокруткой. Стрелки и общий тик cards:tick листают
// программно; касание ленты останавливает автолистание на всей странице.
// Морф сохраняет узел ленты вместе с её scrollLeft — кадр не сбрасывается.
// Какие плитки на экране — один наблюдатель на все (раньше каждая плитка на каждый такт мерила себя getBoundingClientRect).
const onScreen = new WeakSet();
const seen = new IntersectionObserver((entries) => {
    for (const e of entries) e.isIntersecting ? onScreen.add(e.target) : onScreen.delete(e.target);
});

export default class extends Controller {
    static targets = ['strip', 'frame', 'dot'];

    connect() {
        seen.observe(this.element);
        this.i = 0;
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
        seen.unobserve(this.element);
        onScreen.delete(this.element);
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
        this.stripTarget.scrollTo({ left: this.i * this.stripTarget.clientWidth, behavior: scrollBehavior() });
        this.dots();
    }

    dots() {
        this.dotTargets.forEach((d, k) => d.classList.toggle('card-dot--on', k === this.i));
    }

    preload() {
        const next = this.frameTargets[(this.i + 1) % this.frameTargets.length];
        if (next && next.loading === 'lazy') next.loading = 'eager';
    }

    // Сам листается только кадр на экране: карточки ниже не качают свои кадры, пока до них не долистали.
    next() {
        if (this.stopped) return;
        // Вид «строки»: кадр — миниатюра, точек нет — не листаем и не качаем остальные кадры.
        if (this.hasDotTarget && !this.dotTargets[0].offsetParent) return;
        // И по горизонтали: плитка за краем ленты пачки (x-ui.shelf) не крутит кадры и не качает их.
        if (onScreen.has(this.element)) this.show(this.i + 1);
    }

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
