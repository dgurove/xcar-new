import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Ключ памяти табов; «tab:» — прежний, в нём могли остаться чужие адреса, его не читаем.
const KEY = 'tabs:';

// Прокрутка, которую надо вернуть после ближайшего рендера.
let restoreScroll;
document.addEventListener('turbo:load', () => {
    if (restoreScroll === undefined) return;
    const y = restoreScroll;
    restoreScroll = undefined;
    requestAnimationFrame(() => scrollTo(0, y));
});

// Бар постоянный (data-turbo-permanent): Turbo переносит живой узел, а бар новой страницы выбрасывает. Из него
// берём числа, бейджи и текущий таб — морфом детей, чтобы не трогать сам fixed-узел: пересозданный бар iOS
// заново раскладывала по viewport.
document.addEventListener('turbo:before-render', (event) => {
    const bar = document.getElementById('tabbar');
    const next = event.detail.newBody.querySelector('#tabbar');
    if (!bar || !next || bar === next) return;
    bar.dataset.tabbarShownValue = next.dataset.tabbarShownValue;
    Turbo.morphChildren(bar, next);
});

// Таб-бар ведёт себя как нативный (под клавиатурой его прячет html.kb-open, app.css):
// табы переключаются заменой без записи в историю (data-turbo-action="replace"),
// активный пункт загорается под пальцем, не дожидаясь ответа; тап по уже
// активному табу — наверх, а из глубины раздела — к его корню. У каждого таба
// своя память (sessionStorage): экран и прокрутка, на которых его оставили.
export default class extends Controller {
    // Таб страницы, что на экране, — от сервера. aria-current загорается под пальцем раньше ответа: пока медленная
    // страница едет, текущим по нему был бы уже новый таб, и в его память лёг бы чужой адрес («Наличие» → Заявки).
    static values = { shown: String };

    tap(event) {
        const link = event.currentTarget;
        const current = this.element.querySelector('.tab[aria-current="page"]');
        if (current !== link) {
            this.remember(this.shownValue);
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

    remember(href) {
        if (!href) return;
        try { sessionStorage.setItem(KEY + new URL(href, location.origin).pathname, JSON.stringify({ url: location.href, y: scrollY })); } catch {}
    }

    recall(tab) {
        try {
            const saved = JSON.parse(sessionStorage.getItem(KEY + new URL(tab.href).pathname) || 'null');
            if (!saved || new URL(saved.url).origin !== location.origin) return null;
            return saved.url !== tab.href || saved.y > 0 ? saved : null;
        } catch { return null; }
    }

    forget(tab) {
        try { sessionStorage.removeItem(KEY + new URL(tab.href).pathname); } catch {}
    }
}
