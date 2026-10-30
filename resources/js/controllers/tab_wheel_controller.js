import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import * as overlays from '../overlays';
import { reducedMotion } from '../lib/motion';
import { scrollTop, scrollToY } from '../lib/scroller';

// Вкладки экрана колесом во весь экран (`x-ui.tab-title`, 30.10.2026, владелец: «как в iOS, как на Apple Watch»).
//
// Нажали заголовок — заливка проявляется (круг владельцу не подошёл, 30.10.2026), текущая вкладка стоит ровно на месте заголовка, остальные
// выплывают. Колесо — нативная прокрутка со снапом к «линии фокуса» (место заголовка); рост и цвет пункта считает браузер
// анимацией по прокрутке (`animation-timeline`, app.css) — в такт пальцу, без скрипта в кадре. Скрипт только ставит
// размеры и ловит, где колесо встало. Встало на другой вкладке — через SETTLE она грузится (владелец: «страница начинает
// грузиться, и когда загрузится — выбор закрывается угасанием»), колесо ждёт; переложили — прежняя загрузка отменяется.
//
// Переход — визит replace на тот же адрес, Turbo его морфит (`body`), поэтому на время загрузки колесо живёт в `<html>`
// вне `<body>`: морф его не видит, а в компонент вставляет свежее (скрытое) колесо новой страницы. Угасло — уходит.
const SETTLE = 250;  // встало — через столько грузим: пролистывая мимо, не грузим каждую
const REST = 140;    // без scrollend: столько тишины после прокрутки — встало
const ID = 'tab-wheel';

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

export default class extends Controller {
    open(event) {
        if (this.wheel) return;
        const wheel = this.element.querySelector('[data-wheel]');
        const button = event.currentTarget;
        if (!wheel) return;
        // Страница уехала — наверх под заливкой: после загрузки заголовок встанет туда же, где пункт в фокусе.
        if (scrollTop() > 0) scrollToY(0, 'instant');
        const r = button.getBoundingClientRect();
        const line = parseFloat(getComputedStyle(button).lineHeight) || r.height;

        this.wheel = wheel;
        this.button = button;
        this.scroll = wheel.querySelector('.tab-wheel-scroll');
        this.items = [...wheel.querySelectorAll('.tab-wheel-item')];
        this.current = Math.max(0, this.items.findIndex((a) => a.hasAttribute('aria-current')));
        this.at = this.current;
        this.chosen = null;
        document.documentElement.append(wheel);
        wheel.hidden = false;

        const row = this.items[0]?.offsetHeight || line;
        const port = this.scroll.clientHeight;
        // Линия фокуса — верх строки, чья середина на середине первой строки заголовка.
        const focus = Math.round(r.top + line / 2 - row / 2);
        const set = (k, v) => wheel.style.setProperty(k, v);
        this.row = row;
        set('--wheel-focus', `${focus}px`);
        set('--wheel-tail', `${Math.max(0, port - focus - row)}px`);
        set('--wheel-at', `${port - focus}px`);
        set('--wheel-left', `${Math.round(r.left)}px`);
        // «›» у выбранной — ровно там, где она у заголовка: показывает, что выбрано (владелец 30.10.2026).
        const chev = button.querySelector('.tab-title-chev')?.getBoundingClientRect();
        if (chev) { set('--wheel-chev-x', `${chev.left}px`); set('--wheel-chev-y', `${chev.top}px`); }
        this.items.forEach((a, i) => {
            a.style.setProperty('--wheel-d', String(Math.abs(i - this.current)));
            a.style.setProperty('--wheel-dir', String(Math.sign(i - this.current)));
        });
        this.scroll.scrollTop = this.current * row;
        this.listen();
        overlays.open(ID, { close: () => this.close(true) });
        button.setAttribute('aria-expanded', 'true');
        void wheel.offsetWidth;
        wheel.classList.add('is-open');
        this.items[this.current]?.focus({ preventScroll: true });
    }

    listen() {
        const s = this.scroll;
        this.off = new AbortController();
        const opts = { signal: this.off.signal };
        s.addEventListener('scroll', () => {
            const i = this.index();
            if (i !== this.at) { this.at = i; tick(); }
            clearTimeout(this.wait);
            clearTimeout(this.rest);
            this.rest = setTimeout(() => this.settled(), REST);
        }, { ...opts, passive: true });
        s.addEventListener('scrollend', () => this.settled(), opts);
        s.addEventListener('touchstart', () => { this.touching = true; this.stopGlide(); }, { ...opts, passive: true });
        const up = () => { this.touching = false; clearTimeout(this.rest); this.rest = setTimeout(() => this.settled(), REST); };
        s.addEventListener('touchend', up, { ...opts, passive: true });
        s.addEventListener('touchcancel', up, { ...opts, passive: true });
        s.addEventListener('wheel', () => this.stopGlide(), { ...opts, passive: true });
        this.wheel.addEventListener('click', (e) => this.tap(e), opts);
        document.addEventListener('keydown', (e) => this.key(e), opts);
        // Наш визит — по адресу (ответ мог прийти редиректом); начался чужой — колесо его не ждёт.
        document.addEventListener('turbo:visit', (e) => { this.mine = !!this.url && e.detail.url === this.url; }, opts);
        document.addEventListener('turbo:load', () => this.loaded(), opts);
        document.addEventListener('turbo:fetch-request-error', () => this.failed(), opts);
    }

    index() {
        return Math.max(0, Math.min(this.items.length - 1, Math.round(this.scroll.scrollTop / this.row)));
    }

    // Колесо встало: на другой вкладке — через SETTLE грузим её, на текущей — загрузку отменяем.
    settled() {
        if (this.touching || this.gliding || this.leaving || !this.wheel) return;
        clearTimeout(this.rest);
        clearTimeout(this.wait);
        const i = this.index();
        if (i === this.chosen) return;
        if (i === this.current) { this.cancel(); return; }
        this.wait = setTimeout(() => this.go(i), SETTLE);
    }

    tap(event) {
        const item = event.target.closest('.tab-wheel-item');
        if (event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        if (!item) { this.close(); return; }
        const i = this.items.indexOf(item);
        if (i === this.index() && !this.gliding) {
            if (i === this.current) this.close(); else this.go(i);
            return;
        }
        this.glide(i, () => (i === this.current ? this.cancel() : this.go(i)));
    }

    key(event) {
        if (event.key === 'Escape') { event.preventDefault(); this.close(); return; }
        const step = { ArrowDown: 1, ArrowUp: -1 }[event.key];
        if (step) {
            event.preventDefault();
            const i = Math.max(0, Math.min(this.items.length - 1, (this.gliding ? this.target : this.index()) + step));
            this.glide(i, () => this.settled());
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const i = this.index();
            if (i === this.current) this.close(); else this.go(i);
        }
    }

    // Пункт доезжает к фокусу своей кривой: системная плавная прокрутка медленная и у каждого браузера своя.
    glide(i, then) {
        this.stopGlide();
        const s = this.scroll, from = s.scrollTop, to = i * this.row;
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
        clearTimeout(this.wait);
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
        clearTimeout(this.wait);
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
        clearTimeout(this.wait);
        clearTimeout(this.rest);
        this.stopGlide();
        this.touching = this.leaving = false;
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
        if (this.wheel && this.chosen === null) {
            if (overlays.isOpen(ID)) overlays.shut(ID);
            this.teardown();
        }
    }
}
