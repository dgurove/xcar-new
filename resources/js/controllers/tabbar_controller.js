import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import * as overlays from '../overlays';
import { scrollTop, scrollToY } from '../lib/scroller';
import * as sections from '../lib/sections';
import { arm } from '../lib/shape';

// Бар постоянный (data-turbo-permanent): Turbo переносит живой узел, а бар новой страницы выбрасывает.
// Текущий таб — из самой страницы (`<body data-nav-tab>`), а не из её копии бара (17.11.2026): в снимке страницы на месте
// бара лежит его клон, снятый уже после перехода, — с табом следующей страницы. При «Назад» из чата на список таб-бар
// оставался «в Чатах», и нажатие таба клало адрес списка в память чужого раздела («Чаты» открывали «Предложения»).
// Числа и бейджи — морфом детей и только из бара свежее живого (`data-at`): снимок не откатывает их назад.
const mark = (bar, href) => {
    bar.dataset.tabbarShownValue = href ?? '';
    for (const tab of bar.querySelectorAll('.tab')) {
        if (href && tab.getAttribute('href') === href) tab.setAttribute('aria-current', 'page');
        else tab.removeAttribute('aria-current');
    }
};

document.addEventListener('turbo:before-render', (event) => {
    const bar = document.getElementById('tabbar');
    const body = event.detail.newBody;
    const next = body.querySelector('#tabbar');
    if (!bar || bar === next) return;
    // Строго свежее: клон бара в снимке несёт то же `data-at`, что живой на момент ухода, а числа с тех пор обновлял
    // `/live/badges` — при равенстве снимок откатил бы их.
    if (next && Number(next.dataset.at || 0) > Number(bar.dataset.at || 0)) {
        Turbo.morphChildren(bar, next);
        bar.dataset.at = next.dataset.at || '';
    }
    mark(bar, 'navTab' in body.dataset ? body.dataset.navTab : next?.dataset.tabbarShownValue);
});

// Таб-бар ведёт себя как нативный (под клавиатурой его прячет html.kb-open, app.css):
// табы переключаются заменой без записи в историю (data-turbo-action="replace"),
// активный пункт загорается под пальцем, не дожидаясь ответа; тап по уже
// активному табу — наверх, а из глубины раздела — к его корню. У каждого таба
// своя память — экран, прокрутка и снимок страницы, на которых его оставили (`lib/sections.js`): раздел, где уже были,
// открывается сразу, без запроса к серверу.
export default class extends Controller {
    // Таб страницы, что на экране, — от сервера. aria-current загорается под пальцем раньше ответа: пока медленная
    // страница едет, текущим по нему был бы уже новый таб.
    static values = { shown: String };

    tap(event) {
        const link = event.currentTarget;
        const href = link.getAttribute('href');
        // Таб экрана, пока едет переход в другой раздел, — «остаться здесь»: переход останавливается (18.11.2026).
        if (this.shownValue === href && sections.stay()) { event.preventDefault(); mark(this.element, this.shownValue); return; }
        if (this.shownValue !== href) {
            for (const tab of this.element.querySelectorAll('.tab[aria-current]')) tab.removeAttribute('aria-current');
            link.setAttribute('aria-current', 'page');
            const saved = sections.recall(href);
            if (saved) {
                event.preventDefault();
                // Окно писем закроется как «ушли в другой раздел», а не «закрыл человек»: вернутся — оно снова открыто.
                window.dispatchEvent(new CustomEvent('section:leave'));
                overlays.clear().then((ok) => { if (!ok || !sections.open(href, saved)) mark(this.element, this.shownValue); });
            } else if (link.dataset.go) {
                // Раздел открывают впервые и знают его вкладку — сразу туда, без круга через редирект; пока едет — силуэт.
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('section:leave'));
                const to = sections.goOf(link);
                overlays.clear().then((ok) => { if (ok) { arm(to); Turbo.visit(to, { action: 'replace' }); } else mark(this.element, this.shownValue); });
            } else {
                // Раздел открывают впервые: пока едет страница — силуэт экрана.
                arm(link.href);
            }
            return;
        }
        event.preventDefault();
        sections.forget(href);
        const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
        const root = new URL(sections.goOf(link));
        if (root.pathname === location.pathname && (location.search === '' || location.search === root.search)) {
            if (scrollTop() > 0) scrollToY(0, reduce ? 'auto' : 'smooth');
            return;
        }
        const to = sections.goOf(link);
        overlays.clear().then((ok) => { if (ok) { arm(to); Turbo.visit(to, { action: 'replace' }); } });
    }
}
