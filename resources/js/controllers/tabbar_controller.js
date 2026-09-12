import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Таб-бар ведёт себя как нативный: при фокусе в поле уходит под клавиатуру,
// табы переключаются заменой без записи в историю (data-turbo-action="replace"),
// активный пункт загорается под пальцем, не дожидаясь ответа; тап по уже
// активному табу — наверх, а из глубины раздела — к его корню.
export default class extends Controller {
    static targets = ['bar'];

    connect() {
        this.onFocusIn = (e) => { if (this.isField(e.target)) this.element.classList.add('is-hidden'); };
        this.onFocusOut = (e) => { if (this.isField(e.target)) this.element.classList.remove('is-hidden'); };
        document.addEventListener('focusin', this.onFocusIn);
        document.addEventListener('focusout', this.onFocusOut);
    }

    disconnect() {
        document.removeEventListener('focusin', this.onFocusIn);
        document.removeEventListener('focusout', this.onFocusOut);
    }

    tap(event) {
        const link = event.currentTarget;
        if (link.getAttribute('aria-current') !== 'page') {
            for (const tab of this.element.querySelectorAll('.tab[aria-current]')) tab.removeAttribute('aria-current');
            link.setAttribute('aria-current', 'page');
            return;
        }
        event.preventDefault();
        const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (new URL(link.href).pathname === location.pathname && location.search === '') {
            if (scrollY > 0) scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
            return;
        }
        Turbo.visit(link.href, { action: 'replace' });
    }

    isField(el) {
        return el instanceof HTMLElement && el.matches('input:not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select, [contenteditable="true"], trix-editor');
    }
}
