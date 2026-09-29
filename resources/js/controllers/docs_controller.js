import { Controller } from '@hotwired/stimulus';
import { reduce } from '../sheet';

// Шторка документов (x-ui.docs, одна на страницу в x-ui.shell): скан заявки, СТС, Excel, письмо, фото —
// внутри интерфейса, а не в Quick Look, из которого установленное приложение на iPhone не выпускает.
// Не модальная: поля под ней заполняются, пока смотришь документ.
// Телефон — лист снизу: высота тянется за шапку (точки 30/55/88 % экрана, ниже нижней — закрыть), страница
// получает отступ на его высоту, поле с фокусом выезжает над листом, с клавиатурой лист ужимается и потом
// возвращается. От 1024 — панель справа во всю высоту, ширина тянется за левый край, страница сдвигается.
// Открывает любая ссылка `a[data-doc]` (x-ui.doc) и любая ссылка на файл (`/files/{id}`,
// `…/mail/attachments/{id}`) без download; вкладки — все документы страницы по порядку, без повторов.
// Рисует ../docs/viewer.js (pdf.js, картинка, Excel и Word с сервера, письмо, фото) — отдельным куском.
// Высота и ширина помнятся (localStorage); форма, отправленная при открытой шторке, вернётся на ту же
// страницу — шторка откроется снова на том же документе. `a[data-doc-auto]` открывается сам.
const FILE = /^\/(?:files|(?:[\w-]+\/)*mail\/attachments)\/\d+\/?$/;
const SNAPS = [.3, .55, .88];
const wide = matchMedia('(min-width: 1024px)');
const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch {} },
};
const html = document.documentElement;

export default class extends Controller {
    static targets = ['tabs', 'body', 'count', 'rotate', 'download'];

    connect() {
        this.items = [];
        this.token = 0;
        this.onClick = (e) => this.intercept(e);
        this.onFocus = (e) => this.focused(e);
        this.onSubmit = () => this.rememberOpen();
        this.onCache = () => this.close(true);
        this.onViewport = () => this.fit();
        this.onWide = () => this.relayout();
        this.onMove = (e) => this.dragMove(e);
        this.onUp = (e) => this.dragEnd(e);
        this.kb = html.classList.contains('kb-open');
        this.kbWatch = new MutationObserver(() => this.keyboard());
        this.kbWatch.observe(html, { attributes: true, attributeFilter: ['class'] });
        document.addEventListener('click', this.onClick);
        document.addEventListener('focusin', this.onFocus);
        document.addEventListener('turbo:submit-start', this.onSubmit);
        document.addEventListener('turbo:before-cache', this.onCache);
        window.visualViewport?.addEventListener('resize', this.onViewport);
        addEventListener('resize', this.onViewport);
        wide.addEventListener('change', this.onWide);
        const start = () => this.restore();
        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start, { once: true }) : setTimeout(start);
    }

    disconnect() {
        this.kbWatch.disconnect();
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('focusin', this.onFocus);
        document.removeEventListener('turbo:submit-start', this.onSubmit);
        document.removeEventListener('turbo:before-cache', this.onCache);
        window.visualViewport?.removeEventListener('resize', this.onViewport);
        removeEventListener('resize', this.onViewport);
        wide.removeEventListener('change', this.onWide);
        this.view?.destroy();
        this.view = null;
        html.classList.remove('docs-open');
        html.style.removeProperty('--docs-h');
        html.style.removeProperty('--docs-w');
    }

    // После сохранения формы — тот же документ; иначе тот, что страница просит открыть сразу.
    restore() {
        let again = null;
        try {
            again = JSON.parse(sessionStorage.getItem('docs:again') || 'null');
            sessionStorage.removeItem('docs:again');
        } catch {}
        const links = this.links();
        if (again && again.path === location.pathname && Date.now() - again.at < 120000) {
            const a = links.find((l) => this.key(l) === again.key);
            if (a) { this.open(a, { instant: true }); return; }
        }
        const auto = links.find((l) => l.hasAttribute('data-doc-auto'));
        if (auto) this.open(auto, { auto: true });
    }

    rememberOpen() {
        if (this.element.hidden || !this.items[this.index]) return;
        try { sessionStorage.setItem('docs:again', JSON.stringify({ path: location.pathname, key: this.items[this.index].key, at: Date.now() })); } catch {}
    }

    links() {
        return [...document.querySelectorAll('a[data-doc]')].filter((a) => !this.element.contains(a) && !a.closest('dialog'));
    }

    key(a) {
        if (a.dataset.doc === 'photos') return '#photos';
        // Файлы одного архива различаются только `?entry=N`.
        const url = new URL(a.href, location.href);
        return url.pathname + (url.searchParams.has('entry') ? `?entry=${url.searchParams.get('entry')}` : '');
    }

    item(a) {
        let photos = null;
        try { photos = a.dataset.docPhotos ? JSON.parse(a.dataset.docPhotos) : null; } catch {}
        return {
            key: this.key(a), url: a.href, type: a.dataset.doc || '', photos,
            name: a.dataset.docName || a.title || a.textContent.trim() || 'Файл', file: a.title || '',
            src: a.dataset.docSrc || null, thread: a.dataset.docThread || null,
        };
    }

    intercept(e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest('a[href]');
        if (!a || a.hasAttribute('download') || a.dataset.doc === 'off' || this.element.contains(a)) return;
        const url = new URL(a.href, location.href);
        if (url.origin !== location.origin || !('doc' in a.dataset || FILE.test(url.pathname))) return;
        e.preventDefault();
        // Под модальным окном (окно писем) шторка была бы неживой: там — встроенный браузер с «Готово», как у
        // file_controller, а на компьютере — новая вкладка.
        if (a.closest('dialog:modal')) {
            const inline = new URL(url);
            inline.searchParams.set('inline', '1');
            window.open(matchMedia('(pointer: coarse)').matches ? inline.href : url.href, '_blank');
            return;
        }
        this.open(a);
    }

    // auto — открылся сам (скан на разборе письма): не выше середины, чтобы поля под ним были видны.
    open(a, { instant = false, auto = false } = {}) {
        const seen = new Set();
        this.items = [];
        for (const link of [...this.links(), a]) {
            const item = this.item(link);
            if (seen.has(item.key)) continue;
            seen.add(item.key);
            this.items.push(item);
        }
        this.tabsTarget.replaceChildren(...this.items.map((item, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'docs-tab';
            b.textContent = item.name;
            b.title = item.name;
            b.dataset.action = 'docs#pick';
            b.dataset.index = i;
            return b;
        }));
        this.reveal(instant);
        if (auto && !wide.matches) this.setHeight(Math.min(this.height, this.snaps()[1]));
        this.show(this.items.findIndex((item) => item.key === this.key(a)));
    }

    pick(event) {
        const i = Number(event.currentTarget.dataset.index);
        if (i !== this.index) this.show(i);
    }

    async show(i) {
        const item = this.items[i];
        if (!item) return;
        this.index = i;
        [...this.tabsTarget.children].forEach((b, n) => b.toggleAttribute('aria-current', n === i));
        this.tabsTarget.children[i]?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
        this.countTarget.textContent = '';
        this.rotateTarget.hidden = !['pdf', 'image', ''].includes(item.type);
        const file = !['letter', 'photos'].includes(item.type);
        this.downloadTarget.hidden = !file;
        if (file) this.downloadTarget.href = item.url;
        const token = ++this.token;
        this.view?.destroy();
        this.view = null;
        const box = document.createElement('div');
        box.className = 'docs-view';
        this.bodyTarget.replaceChildren(box);
        const { render } = await (this.viewer ??= import('../docs/viewer.js'));
        if (token !== this.token) return;
        // Поворот помнится у документа: скан, пришедший боком, второй раз поворачивать не надо.
        const turn = `docs:rot:${item.key}`;
        const view = await render(box, item, {
            count: (text) => { if (token === this.token) this.countTarget.textContent = text; },
            rotation: Number(store.get(turn)) || 0,
            rotated: (deg) => store.set(turn, String(deg)),
        });
        if (token !== this.token) { view?.destroy(); return; }
        this.view = view;
    }

    rotate() {
        this.view?.rotate?.();
    }

    reveal(instant) {
        if (!this.element.hidden) return;
        this.element.classList.remove('is-closing');
        this.element.classList.toggle('is-instant', instant || reduce.matches);
        this.element.hidden = false;
        html.classList.add('docs-open');
        this.relayout();
    }

    relayout() {
        if (this.element.hidden) return;
        if (wide.matches) this.setWidth(this.wanted());
        else this.setHeight(this.startHeight());
    }

    close(instant = false) {
        if (this.element.hidden || this.element.classList.contains('is-closing')) return;
        this.token++;
        const done = () => {
            if (this.element.hidden) return;
            this.element.hidden = true;
            this.element.classList.remove('is-closing');
            html.classList.remove('docs-open');
            this.view?.destroy();
            this.view = null;
            this.bodyTarget.replaceChildren();
        };
        if (instant === true || reduce.matches) { done(); return; }
        this.element.classList.add('is-closing');
        this.element.addEventListener('animationend', done, { once: true });
        setTimeout(done, 400);
    }

    // Esc — не поверх фото во весь экран и не поверх шторки-диалога.
    escape(event) {
        if (this.element.hidden || document.querySelector('dialog:modal')) return;
        event.preventDefault();
        this.close();
    }

    // ——— высота на телефоне

    get vh() { return window.visualViewport?.height ?? innerHeight; }

    // Выше шапки лист не уходит: её кнопки нужны и при открытом документе.
    maxHeight() {
        const header = document.querySelector('.header')?.getBoundingClientRect().bottom ?? 0;
        return Math.round(this.vh - Math.max(header, 0) - 8);
    }

    snaps() {
        const max = this.maxHeight();
        return SNAPS.map((f) => Math.min(Math.round(this.vh * f), max));
    }

    startHeight() {
        const saved = Number(store.get('docs:h'));
        const snaps = this.snaps();
        const h = saved > 0 && saved < 1 ? saved * this.vh : this.vh * SNAPS[1];
        return Math.min(Math.max(h, snaps[0]), snaps[2]);
    }

    setHeight(h) {
        this.height = Math.max(0, Math.round(h));
        html.style.setProperty('--docs-h', `${this.height}px`);
    }

    settle(h) {
        const el = this.element;
        if (reduce.matches) { this.setHeight(h); return; }
        el.classList.add('is-settling');
        this.setHeight(h);
        clearTimeout(this.settling);
        this.settling = setTimeout(() => el.classList.remove('is-settling'), 350);
    }

    // Экран повернули, клавиатура поменяла высоту — лист не выше доступного.
    fit() {
        if (this.element.hidden) return;
        if (wide.matches) { this.setWidth(this.wanted()); return; }
        const max = this.maxHeight();
        if (this.height > max) this.setHeight(max);
    }

    dragStart(e) {
        if (wide.matches || this.drag || (e.pointerType === 'mouse' && (e.button !== 0 || e.target.closest('button, a')))) return;
        this.drag = { y: e.clientY, h: this.height, moved: false, id: e.pointerId, pts: [[e.timeStamp, e.clientY]] };
        addEventListener('pointermove', this.onMove);
        addEventListener('pointerup', this.onUp);
        addEventListener('pointercancel', this.onUp);
    }

    dragMove(e) {
        const d = this.drag;
        if (!d || e.pointerId !== d.id) return;
        const dy = e.clientY - d.y;
        if (!d.moved) {
            if (Math.abs(dy) < 8) return;
            d.moved = true;
            this.element.classList.add('is-dragging');
        }
        d.pts.push([e.timeStamp, e.clientY]);
        if (d.pts.length > 6) d.pts.shift();
        const max = this.maxHeight();
        const h = d.h - dy;
        this.setHeight(h > max ? max + Math.pow(h - max, .6) : h);
    }

    dragEnd(e) {
        const d = this.drag;
        if (!d || e.pointerId !== d.id) return;
        this.drag = null;
        removeEventListener('pointermove', this.onMove);
        removeEventListener('pointerup', this.onUp);
        removeEventListener('pointercancel', this.onUp);
        if (!d.moved) return;
        this.element.classList.remove('is-dragging');
        // Отпустили после перетаскивания — нажатие по вкладке под пальцем не считается.
        const swallow = (ev) => { ev.stopPropagation(); ev.preventDefault(); };
        this.element.addEventListener('click', swallow, { capture: true, once: true });
        setTimeout(() => this.element.removeEventListener('click', swallow, { capture: true }), 50);
        const [t0, y0] = d.pts[0], [t1, y1] = d.pts.at(-1);
        const v = (y1 - y0) / Math.max(1, t1 - t0);
        const snaps = this.snaps();
        const aim = this.height - v * 180;
        if (aim < snaps[0] * .7) { this.close(); return; }
        const h = snaps.reduce((a, b) => (Math.abs(b - aim) < Math.abs(a - aim) ? b : a));
        this.settle(h);
        if (!this.kb) store.set('docs:h', (h / this.vh).toFixed(3));
    }

    // Клавиатура: лист ужимается, чтобы над ним было видно поле; ушла — прежняя высота.
    keyboard() {
        const open = html.classList.contains('kb-open');
        if (open === this.kb) return;
        this.kb = open;
        if (this.element.hidden || wide.matches) return;
        if (open) {
            this.beforeKb = this.height;
            const cap = Math.round(this.vh * .42);
            if (this.height > cap) this.settle(cap);
        } else if (this.beforeKb) {
            this.settle(Math.min(this.beforeKb, this.maxHeight()));
            this.beforeKb = 0;
        }
        const field = document.activeElement;
        if (open && field?.matches('input, textarea, select')) setTimeout(() => this.keepVisible(field), 360);
    }

    focused(e) {
        const field = e.target;
        if (this.element.hidden || wide.matches || !field.matches?.('input, textarea, select') || this.element.contains(field)) return;
        // Лист во весь экран, а человек взялся за поле — опускаем до середины, иначе поле некуда поднять.
        const mid = this.snaps()[1];
        if (this.height > mid && !this.kb) this.settle(mid);
        clearTimeout(this.focusing);
        this.focusing = setTimeout(() => this.keepVisible(field), 380);
    }

    // Поле с фокусом — над листом и плашкой действий, под шапкой.
    keepVisible(field) {
        if (this.element.hidden || !field.isConnected || document.activeElement !== field) return;
        const r = field.getBoundingClientRect();
        let bottom = this.element.getBoundingClientRect().top;
        const bar = document.querySelector('.action-bar');
        if (bar && bar.offsetParent !== null) bottom = Math.min(bottom, bar.getBoundingClientRect().top);
        const top = Math.max(document.querySelector('.header')?.getBoundingClientRect().bottom ?? 0, 0);
        if (r.bottom > bottom - 12) scrollBy(0, r.bottom - bottom + 24);
        else if (r.top < top + 8) scrollBy(0, r.top - top - 24);
    }

    // ——— ширина на компьютере

    // Ширина, которую выбрал человек (или 44 % экрана); сама панель — в пределах 24rem…60 % экрана сейчас.
    wanted() {
        return Number(store.get('docs:w')) || innerWidth * .44;
    }

    setWidth(w) {
        const max = Math.round(innerWidth * .6);
        this.width = Math.round(Math.min(max, Math.max(384, w || 0)));
        html.style.setProperty('--docs-w', `${this.width}px`);
    }

    resizeStart(e) {
        if (!wide.matches || e.button !== 0) return;
        e.preventDefault();
        const move = (ev) => this.setWidth(innerWidth - ev.clientX);
        const up = () => {
            removeEventListener('pointermove', move);
            removeEventListener('pointerup', up);
            this.element.classList.remove('is-resizing');
            store.set('docs:w', String(this.width));
        };
        this.element.classList.add('is-resizing');
        addEventListener('pointermove', move);
        addEventListener('pointerup', up);
    }
}
