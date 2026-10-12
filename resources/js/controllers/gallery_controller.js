import { Controller } from '@hotwired/stimulus';
import { openLightbox } from '../lightbox';

// Лента фото: свайп по snap-scroll, стрелки и ←/→, счётчик, полноэкранный просмотр (lightbox.js);
// листание в просмотре двигает и ленту — после закрытия на странице тот же кадр.
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

    // Кадр, на котором стоит просмотр, — в видимую часть ленты, если он за краем. Только сама лента: scrollIntoView
    // двигал и страницу под открытым просмотром, после закрытия она стояла не там.
    reveal(i) {
        const strip = this.stripTarget, frame = strip.children[i];
        if (!frame || frame.hidden) return;
        const s = strip.getBoundingClientRect(), f = frame.getBoundingClientRect();
        if (f.left < s.left) strip.scrollLeft += f.left - s.left;
        else if (f.right > s.right) strip.scrollLeft += f.right - s.right;
    }

    index() {
        return Math.round(this.stripTarget.scrollLeft / this.stripTarget.clientWidth);
    }

    updateCounter() {
        // Текущая — в рамке и яркая; прочие приглушены (в разметке так стоит только у первого кадра).
        const at = this.index();
        this.thumbTargets?.forEach((t, k) => {
            t.classList.toggle('ring-2', k === at);
            t.classList.toggle('opacity-70', k !== at);
            t.classList.toggle('hover:opacity-100', k !== at);
        });
        if (!this.hasCounterTarget) return;
        this.counterTarget.textContent = `${this.index() + 1} / ${this.stripTarget.children.length}`;
    }

    async open(event) {
        event.preventDefault();
        // Индекс — до await: после него event.currentTarget уже пуст.
        const index = event.currentTarget.dataset.index === undefined ? this.index() : Number(event.currentTarget.dataset.index);
        const items = [...this.stripTarget.querySelectorAll('a[href]')].map((a) => {
            const img = a.querySelector('img');
            return { src: a.href, mid: a.dataset.mid, el: img, thumb: a.dataset.thumb || img?.currentSrc || img?.src };
        });
        this.viewer = await openLightbox({
            items, index,
            onChange: (i) => this.reveal(i),
            onClose: () => { this.viewer = null; },
        });
    }
}
