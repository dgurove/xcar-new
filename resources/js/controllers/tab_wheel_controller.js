import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import * as overlays from '../overlays';
import { reducedMotion } from '../lib/motion';
import { scrollTop, scrollToY } from '../lib/scroller';
import { request } from '../lib/http';

// Вкладки экрана колесом во весь экран (`x-ui.tab-title`, 30.10.2026, владелец: «как в iOS, как на Apple Watch»).
//
// Нажали заголовок — заливка проявляется (круг владельцу не подошёл, 30.10.2026), текущая вкладка стоит ровно на месте заголовка, остальные
// выплывают. Колесо — нативная прокрутка со снапом к «линии фокуса» (место заголовка); рост и цвет пункта считает браузер
// анимацией по прокрутке (`animation-timeline`, app.css) — в такт пальцу, без скрипта в кадре. Скрипт только ставит
// размеры и ловит нажатия. Вкладка открывается только нажатием (16.11.2026, владелец: «чтобы всё равно надо было нажимать
// по нужному, даже если доскроллил на него»; до того колесо, вставшее на другой вкладке, грузило её само): нажали пункт —
// он доезжает к фокусу и грузится («страница начинает грузиться, и когда загрузится — выбор закрывается угасанием»).
//
// Переход — визит replace на тот же адрес, Turbo его морфит (`body`), поэтому на время загрузки колесо живёт в `<html>`
// вне `<body>`: морф его не видит, а в компонент вставляет свежее (скрытое) колесо новой страницы. Угасло — уходит.
//
// ПК (от 1024, 17.11.2026, владелец: «не скролл, а просто клик», «на пк не должно быть фокуса скролла на том, что выбрано»):
// те же пункты столбцами (CSS), скрипт ставит только место названия; клик открывает сразу, стрелки водят фокус по пунктам
// (←/→ — в соседний столбец), Enter открывает. Режим выбирается при открытии; ширина перешла границу — колесо закрывается.
const ID = 'tab-wheel';
const COLS = '(min-width: 1024px)';

// Щелчок, как у колёсика часов: iOS 18+ отдаёт касанием переключатель `<input switch>`, нажатый через подпись; на
// Android — вибрация. Не умеют — молча.
function tick() {
    const label = document.createElement('label');
    label.ariaHidden = 'true';
    label.style.display = 'none';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.setAttribute('switch', '');
    label.append(input);
    document.head.append(label);
    label.click();
    label.remove();
    try { navigator.vibrate?.(4); } catch {}
}

// Логотип CRM ведёт сюда (`Surface::brand`, 17.11.2026: космос на `/` не прижился): колесо «Предложений» открывается само,
// а на самих `/offers` — на месте, без перехода и с чипами вкладки.
const LOGO = '/offers#tabs';
const HOME = '/offers';

export default class extends Controller {
    connect() {
        if (location.pathname !== HOME) return;
        this.onLogo = (event) => {
            const link = event.target.closest?.('a[href]');
            if (!link || link.getAttribute('href') !== LOGO || event.metaKey || event.ctrlKey || event.shiftKey || event.button) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            this.show(this.element.querySelector('.tab-title-btn'));
        };
        document.addEventListener('click', this.onLogo, true);
        if (location.hash === '#tabs') {
            // Хэш снимается сразу: обновление и «Назад» колесо сами не открывают.
            history.replaceState(history.state, '', location.pathname + location.search);
            requestAnimationFrame(() => this.show(this.element.querySelector('.tab-title-btn')));
        }
    }

    open(event) {
        this.show(event.currentTarget);
    }

    show(button) {
        if (this.wheel || !button || !this.element.isConnected) return;
        const wheel = this.element.querySelector('[data-wheel]');
        if (!wheel) return;
        // Страница уехала — наверх под заливкой: после загрузки заголовок встанет туда же, где пункт в фокусе.
        if (scrollTop() > 0) scrollToY(0, 'instant');
        const r = button.getBoundingClientRect();
        const line = parseFloat(getComputedStyle(button).lineHeight) || r.height;

        this.cols = matchMedia(COLS).matches;
        this.wheel = wheel;
        this.button = button;
        this.scroll = wheel.querySelector('.tab-wheel-scroll');
        this.collect();
        this.chosen = null;
        document.documentElement.append(wheel);
        wheel.hidden = false;

        const set = (k, v) => wheel.style.setProperty(k, v);
        set('--wheel-left', `${Math.round(r.left)}px`);
        if (this.cols) {
            // Столбцы — от места названия; прокрутки к выбранной нет.
            set('--wheel-top', `${Math.round(r.top)}px`);
            this.scroll.scrollTop = 0;
        } else {
            const row = this.items[0]?.offsetHeight || line;
            const port = this.scroll.clientHeight;
            // Линия фокуса — верх строки, чья середина на середине первой строки заголовка.
            const focus = Math.round(r.top + line / 2 - row / 2);
            this.row = row;
            set('--wheel-focus', `${focus}px`);
            set('--wheel-tail', `${Math.max(0, port - focus - row)}px`);
            set('--wheel-at', `${port - focus}px`);
            // «›» у выбранной — ровно там, где она у заголовка: показывает, что выбрано (владелец 30.10.2026).
            const chev = button.querySelector('.tab-title-chev')?.getBoundingClientRect();
            if (chev) { set('--wheel-chev-x', `${chev.left}px`); set('--wheel-chev-y', `${chev.top}px`); }
            this.focus = focus;
            this.place();
        }
        this.listen();
        overlays.open(ID, { close: () => this.close(true) });
        button.setAttribute('aria-expanded', 'true');
        void wheel.offsetWidth;
        wheel.classList.add('is-open');
        this.items[this.current]?.focus({ preventScroll: true });
    }

    // Пункты колеса — видимые: «Только мои» (18.11.2026) прячет вкладки, за которые человек не отвечает (`data-not-mine`).
    collect() {
        const mine = this.wheel.classList.contains('is-mine');
        this.items = [...this.wheel.querySelectorAll('.tab-wheel-item')].filter((a) => !(mine && a.hasAttribute('data-not-mine')));
        this.current = Math.max(0, this.items.findIndex((a) => a.hasAttribute('aria-current')));
        this.at = this.current;
    }

    // Телефон: лесенка выплывания от текущей и места вкладок у линии фокуса.
    place() {
        this.items.forEach((a, i) => {
            a.style.setProperty('--wheel-d', String(Math.abs(i - this.current)));
            a.style.setProperty('--wheel-dir', String(Math.sign(i - this.current)));
        });
        // Место каждой вкладки — её верх у линии фокуса: между разделами стоят подзаголовки (`.tab-wheel-sec`, 16.11.2026),
        // поэтому не «номер × строка». Снап встаёт только на вкладки — подзаголовки пролистываются.
        this.tops = this.items.map((a) => a.offsetTop - this.focus);
        this.scroll.scrollTop = this.pos(this.current);
    }

    // Тумблер «Только мои» (18.11.2026, владелец): колесо сужается сразу, запоминается у человека в фоне. Имя не `mine`: это поле «наш визит» ниже — оно затирало обработчик после первого закрытия колеса, и тумблер молчал.
    onlyMine(event) {
        const on = event.target.checked;
        this.wheel.classList.toggle('is-mine', on);
        this.collect();
        if (!this.cols) this.place();
        const body = new FormData();
        body.append('on', on ? '1' : '0');
        request('/offers/wheel-mine', { method: 'POST', body });
    }

    listen() {
        const s = this.scroll;
        this.off = new AbortController();
        const opts = { signal: this.off.signal };
        if (!this.cols) {
            s.addEventListener('scroll', () => {
                const i = this.index();
                if (i !== this.at) { this.at = i; tick(); }
            }, { ...opts, passive: true });
            s.addEventListener('touchstart', () => this.stopGlide(), { ...opts, passive: true });
            s.addEventListener('wheel', () => this.stopGlide(), { ...opts, passive: true });
        }
        matchMedia(COLS).addEventListener('change', () => this.close(), opts);
        this.wheel.addEventListener('click', (e) => this.tap(e), opts);
        // Колесо открыто вне своего контроллера (в `<html>`), действия Stimulus до тумблера не доходят — слушаем сами.
        this.wheel.querySelector('.tab-wheel-mine input')?.addEventListener('change', (e) => this.onlyMine(e), opts);
        document.addEventListener('keydown', (e) => this.key(e), opts);
        // Наш визит — по адресу (ответ мог прийти редиректом); начался чужой — колесо его не ждёт.
        document.addEventListener('turbo:visit', (e) => { this.mine = !!this.url && e.detail.url === this.url; }, opts);
        document.addEventListener('turbo:load', () => this.loaded(), opts);
        document.addEventListener('turbo:fetch-request-error', () => this.failed(), opts);
    }

    pos(i) {
        return this.tops[i] ?? i * this.row;
    }

    // Вкладка у линии фокуса — ближайшая по месту.
    index() {
        const y = this.scroll.scrollTop;
        let best = 0;
        this.tops.forEach((t, i) => { if (Math.abs(t - y) < Math.abs(this.tops[best] - y)) best = i; });
        return best;
    }

    tap(event) {
        // Тумблер «Только мои» — не вкладка и не мимо: колесо остаётся открытым.
        if (event.target.closest('.tab-wheel-mine')) return;
        // Подзаголовок раздела — не вкладка: нажатие по нему ничего не делает (и колесо не закрывает).
        if (event.target.closest('.tab-wheel-sec')) { event.preventDefault(); return; }
        const item = event.target.closest('.tab-wheel-item');
        if (event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        if (!item) { this.close(); return; }
        const i = this.items.indexOf(item);
        // ПК: клик — сразу; по выбранной — закрыть (или отменить начатую загрузку другой).
        if (this.cols) {
            if (i !== this.current) this.go(i);
            else if (this.chosen !== null) this.cancel();
            else this.close();
            return;
        }
        if (i === this.index() && !this.gliding) {
            if (i === this.current) this.close(); else this.go(i);
            return;
        }
        this.glide(i, () => (i === this.current ? this.cancel() : this.go(i)));
    }

    key(event) {
        if (event.key === 'Escape') { event.preventDefault(); this.close(); return; }
        if (this.cols) { this.colKey(event); return; }
        const step = { ArrowDown: 1, ArrowUp: -1 }[event.key];
        if (step) {
            event.preventDefault();
            const i = Math.max(0, Math.min(this.items.length - 1, (this.gliding ? this.target : this.index()) + step));
            // Стрелки только крутят: открывает Enter (выбор — нажатием).
            this.glide(i, () => {});
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const i = this.index();
            if (i === this.current) this.close(); else this.go(i);
        }
    }

    // ПК: ↑/↓ — по пунктам подряд, ←/→ — к ближайшему по высоте пункту соседнего столбца, Enter — открыть пункт в фокусе.
    colKey(event) {
        const at = this.items.indexOf(document.activeElement);
        const from = at < 0 ? this.current : at;
        const step = { ArrowDown: 1, ArrowUp: -1 }[event.key];
        const side = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
        if (step) {
            event.preventDefault();
            this.items[Math.max(0, Math.min(this.items.length - 1, at < 0 ? from : from + step))]?.focus();
        } else if (side) {
            event.preventDefault();
            const cols = [...this.wheel.querySelectorAll('.tab-wheel-col')];
            const item = this.items[from];
            const next = cols[cols.indexOf(item?.closest('.tab-wheel-col')) + side];
            if (!item || !next) return;
            const y = item.getBoundingClientRect().top;
            const near = [...next.querySelectorAll('.tab-wheel-item')]
                .reduce((best, a) => (!best || Math.abs(a.getBoundingClientRect().top - y) < Math.abs(best.getBoundingClientRect().top - y) ? a : best), null);
            near?.focus();
        } else if (event.key === 'Enter' && at >= 0) {
            event.preventDefault();
            if (at === this.current) this.close(); else this.go(at);
        }
    }

    // Пункт доезжает к фокусу своей кривой: системная плавная прокрутка медленная и у каждого браузера своя.
    glide(i, then) {
        this.stopGlide();
        const s = this.scroll, from = s.scrollTop, to = this.pos(i);
        this.target = i;
        if (reducedMotion() || Math.abs(to - from) < 1) { s.scrollTop = to; then(); return; }
        const time = Math.min(420, 200 + 40 * Math.abs(to - from) / this.row);
        const t0 = performance.now();
        this.gliding = true;
        s.classList.add('is-gliding');
        const step = (t) => {
            if (!this.gliding) return;
            const k = Math.min(1, (t - t0) / time);
            s.scrollTop = from + (to - from) * (1 - (1 - k) ** 3);
            if (k < 1) { this.raf = requestAnimationFrame(step); return; }
            this.stopGlide();
            then();
        };
        this.raf = requestAnimationFrame(step);
    }

    stopGlide() {
        if (!this.gliding) return;
        this.gliding = false;
        cancelAnimationFrame(this.raf);
        this.scroll.classList.remove('is-gliding');
    }

    async go(i) {
        if (this.chosen === i || this.leaving) return;
        this.chosen = i;
        this.items.forEach((a, j) => a.classList.toggle('is-loading', j === i));
        // Запись «Назад» колеса снимаем до перехода: визит заменит запись страницы, а не её.
        if (overlays.isOpen(ID)) await overlays.shut(ID);
        if (this.chosen !== i || !this.wheel) return;
        this.url = new URL(this.items[i].href, location.href).href;
        Turbo.visit(this.url, { action: 'replace' });
    }

    cancel() {
        if (this.chosen === null) return;
        this.chosen = null;
        this.url = null;
        this.items.forEach((a) => a.classList.remove('is-loading'));
        Turbo.navigator.stop();
        document.documentElement.removeAttribute('aria-busy');
        Turbo.session.adapter?.hideVisitProgressBar?.();
        if (!overlays.isOpen(ID)) overlays.open(ID, { close: () => this.close(true) });
    }

    failed() {
        if (this.chosen === null) return;
        this.chosen = null;
        this.url = null;
        this.items.forEach((a) => a.classList.remove('is-loading'));
    }

    // Наша страница отрисовалась под колесом — колесо угасает, под ним уже новая вкладка с заголовком на том же месте.
    loaded() {
        if (!this.mine || this.leaving) return;
        this.leaving = true;
        this.wheel.classList.add('is-leaving');
        this.after(600);
    }

    close(fromBack = false) {
        const wheel = this.wheel;
        if (!wheel || this.leaving) return;
        this.leaving = true;
        this.stopGlide();
        if (this.chosen !== null) {
            Turbo.navigator.stop();
            document.documentElement.removeAttribute('aria-busy');
            Turbo.session.adapter?.hideVisitProgressBar?.();
        }
        if (!fromBack) overlays.shut(ID);
        wheel.classList.remove('is-open');
        this.button?.setAttribute('aria-expanded', 'false');
        this.after(500);
    }

    // Колесо уходит угасанием — по его концу, запасом — по таймеру.
    after(ms) {
        const wheel = this.wheel, off = this.off;
        if (reducedMotion()) { this.teardown(); return; }
        wheel.addEventListener('transitionend', (e) => { if (e.target === wheel) this.teardown(); }, { signal: off.signal });
        setTimeout(() => { if (this.off === off) this.teardown(); }, ms);
    }

    // Колесо — обратно в заголовок (или прочь, если морф уже вставил свежее), всё как до открытия.
    teardown() {
        const wheel = this.wheel;
        if (!wheel) return;
        this.wheel = null;
        this.off?.abort();
        this.stopGlide();
        this.leaving = false;
        this.chosen = this.url = null;
        this.mine = false;
        wheel.classList.remove('is-open', 'is-leaving');
        this.items.forEach((a) => a.classList.remove('is-loading'));
        wheel.hidden = true;
        const home = this.element.isConnected && !this.element.querySelector('[data-wheel]') ? this.element : null;
        if (home) home.append(wheel); else wheel.remove();
        this.element.querySelector('.tab-title-btn')?.setAttribute('aria-expanded', 'false');
    }

    disconnect() {
        if (this.onLogo) document.removeEventListener('click', this.onLogo, true);
        this.onLogo = null;
        if (this.wheel && this.chosen === null) {
            if (overlays.isOpen(ID)) overlays.shut(ID);
            this.teardown();
        }
    }
}
