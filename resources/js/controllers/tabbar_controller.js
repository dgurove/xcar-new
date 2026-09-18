import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Прокрутка, которую надо вернуть после ближайшего рендера (контроллер к тому моменту новый).
let restoreScroll;
document.addEventListener('turbo:load', () => {
    if (restoreScroll === undefined) return;
    const y = restoreScroll;
    restoreScroll = undefined;
    requestAnimationFrame(() => scrollTo(0, y));
});

// Бар рендерится с каждой страницей; пока фокус в поле (поиск по мере ввода, чат), новый
// бар приходит без is-hidden и выскакивал над клавиатурой. Класс переезжает на новый узел,
// а морф его не трогает.
const isField = (el) => el instanceof HTMLElement && el.matches('input:not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select, [contenteditable="true"], trix-editor');
document.addEventListener('turbo:before-render', (event) => {
    if (!isField(document.activeElement)) return;
    event.detail.newBody.querySelector('#tabbar')?.classList.add('is-hidden');
});
document.addEventListener('turbo:before-morph-attribute', (event) => {
    if (event.target.id === 'tabbar' && event.detail.attributeName === 'class') event.preventDefault();
});

// Таб-бар ведёт себя как нативный: при фокусе в поле уходит под клавиатуру,
// табы переключаются заменой без записи в историю (data-turbo-action="replace"),
// активный пункт загорается под пальцем, не дожидаясь ответа; тап по уже
// активному табу — наверх, а из глубины раздела — к его корню. У каждого таба
// своя память (sessionStorage): экран и прокрутка, на которых его оставили.
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
        const current = this.element.querySelector('.tab[aria-current="page"]');
        if (current !== link) {
            this.remember(current);
            for (const tab of this.element.querySelectorAll('.tab[aria-current]')) tab.removeAttribute('aria-current');
            link.setAttribute('aria-current', 'page');
            const saved = this.recall(link);
            if (saved) {
                event.preventDefault();
                restoreScroll = saved.y;
                Turbo.visit(saved.url, { action: 'replace' });
            }
            return;
        }
        event.preventDefault();
        this.forget(link);
        const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (new URL(link.href).pathname === location.pathname && location.search === '') {
            if (scrollY > 0) scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
            return;
        }
        Turbo.visit(link.href, { action: 'replace' });
    }

    remember(tab) {
        if (!tab?.href) return;
        try { sessionStorage.setItem('tab:' + new URL(tab.href).pathname, JSON.stringify({ url: location.href, y: scrollY })); } catch {}
    }

    recall(tab) {
        try {
            const saved = JSON.parse(sessionStorage.getItem('tab:' + new URL(tab.href).pathname) || 'null');
            if (!saved || new URL(saved.url).origin !== location.origin) return null;
            return saved.url !== tab.href || saved.y > 0 ? saved : null;
        } catch { return null; }
    }

    forget(tab) {
        try { sessionStorage.removeItem('tab:' + new URL(tab.href).pathname); } catch {}
    }

    isField(el) {
        return isField(el);
    }
}
