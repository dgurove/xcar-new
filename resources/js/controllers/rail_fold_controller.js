import { Controller } from '@hotwired/stimulus';
import { onMorphAttribute } from '../lib/morph-guard';

// «Ещё N писем» ленты (`x-mail.rail fold`, 23.11.2026, владелец: «сворачивать письма в сделке до трёх последних, красиво,
// надёжно, с анимацией не тормозящей»): класс `is-open` на ленте — высоту и проявление ведёт CSS (сетка 0fr → 1fr), скрытое
// `inert`. Живой морф страницы (новое письмо, ход сделки) раскрытое не сворачивает. Свернули, а кнопка ушла выше экрана —
// подвезти её обратно.
export default class extends Controller {
    static targets = ['more', 'button'];

    connect() {
        this.off = onMorphAttribute((e) => {
            if (!this.open) return;
            const t = e.target;
            const name = e.detail?.attributeName;
            if ((t === this.element && name === 'class') || (t === this.moreTarget && name === 'inert') || (t === this.buttonTarget && name === 'aria-expanded')) e.preventDefault();
        });
    }

    disconnect() {
        this.off?.();
    }

    get open() {
        return this.element.classList.contains('is-open');
    }

    toggle() {
        const open = !this.open;
        this.element.classList.toggle('is-open', open);
        this.moreTarget.inert = !open;
        this.buttonTarget.setAttribute('aria-expanded', String(open));
        if (!open) {
            this.moreTarget.addEventListener('transitionend', () => {
                if (this.buttonTarget.getBoundingClientRect().top < 80) this.buttonTarget.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }, { once: true });
        }
    }
}
