import { Controller } from '@hotwired/stimulus';
import { reduce } from '../sheet';

// Таблица списка и её окошко (peek). Нажатие по строке выделяет её и открывает
// окошко (фрейм peek грузит карточку строки), по той же — закрывает, по другой —
// меняет; ↑/↓ двигают выделение, Enter открывает или раскрывает окошко во весь
// экран (телефон), ⌘Enter и двойное нажатие — на страницу; Esc, крестик, свайп
// вниз и «Назад» закрывают. Открытое окошко — запись в истории, как у шторки
// (sheet.js): у записи страницы на время снимается ключ turbo, чтобы popstate не
// стал restore-визитом; ссылка из окошка сначала снимает запись, потом визит.
// Перед снимком страницы (turbo:before-cache) окошко закрывается: снимок и
// восстановление из него — всегда без окошка (data-turbo-temporary не годится:
// Turbo снимает снимок и на popstate с пустым состоянием — окошко исчезало бы).
// Формы внутри отвечают в окошко: у них data-turbo-frame="peek", заголовок
// X-Peek-Back говорит серверу (PeekBack), куда редиректить. В ответе едут
// шаблоны: свежая строка таблицы (заменяет выделенную), flash-сообщение,
// просьба перейти к следующей без нашей цены (data-unpriced) — оценка закупки.
// Положение «во весь экран» помнится в localStorage.
export default class extends Controller {
    static targets = ['body', 'panel', 'frame', 'count', 'open'];
    static values = { open: String };

    connect() {
        this.inHistory = false;
        // Esc: сначала просмотрщик фото (Viewer держит body.viewer-open, снимает его на
        // своём keydown — потому смотрим на захвате), потом окошко.
        this.onKey = (e) => { if (e.key === 'Escape' && !this.panelTarget.hidden && !document.body.classList.contains('viewer-open')) this.close(); };
        this.onPop = (e) => this.popped(e);
        this.onVisit = () => { this.inHistory = false; };
        this.onCache = () => this.reset();
        addEventListener('keydown', this.onKey, true);
        addEventListener('popstate', this.onPop);
        document.addEventListener('turbo:visit', this.onVisit);
        document.addEventListener('turbo:before-cache', this.onCache);
        try { this.full = localStorage.getItem('peek:full') === '1'; } catch { this.full = false; }
        // Открыть сразу (?peek=): при первой загрузке контроллер подключается, пока
        // таблица ещё парсится и окошка внизу нет — ждём конца разбора.
        if (this.openValue) {
            const open = () => { const row = this.bodyTarget.querySelector(`#${CSS.escape(this.openValue)}`); if (row) this.show(row, { focus: 'fine' }); };
            document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', open, { once: true }) : open();
        }
    }

    disconnect() {
        removeEventListener('keydown', this.onKey, true);
        removeEventListener('popstate', this.onPop);
        document.removeEventListener('turbo:visit', this.onVisit);
        document.removeEventListener('turbo:before-cache', this.onCache);
    }

    get rows() { return [...this.bodyTarget.querySelectorAll('tr[data-peek-url]')]; }
    get current() { return this.bodyTarget.querySelector('tr[aria-selected="true"]'); }

    tap(event) {
        if (event.target.closest('a, button, form')) return;
        const row = event.target.closest('tr[data-peek-url]');
        if (!row) return;
        row === this.current ? this.close() : this.show(row, { focus: 'fine' });
    }

    open(event) {
        const row = event.target.closest('tr[data-href]');
        if (row && !event.target.closest('a, button, form')) this.visit(row.dataset.href);
    }

    key(event) {
        if (event.target.closest('input, textarea, select, [contenteditable]')) return;
        const rows = this.rows;
        if (!rows.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            const i = rows.indexOf(this.current);
            const next = rows[Math.min(rows.length - 1, Math.max(0, i + (event.key === 'ArrowDown' ? 1 : -1)))];
            if (next && next !== this.current) { event.preventDefault(); this.show(next); }
        } else if (event.key === 'Enter' && this.current && !event.target.closest('a, button')) {
            event.preventDefault();
            if (event.metaKey || event.ctrlKey) this.visit(this.current.dataset.href);
            else if (this.panelTarget.hidden) this.show(this.current, { focus: 'fine' });
            else this.toggle();
        }
    }

    // focus: 'always' — фокус в поле окошка (data-peek-focus) и на телефоне (поток
    // оценки), 'fine' — только с мышью, иначе клавиатура закрыла бы фото.
    show(row, { focus = false } = {}) {
        this.current?.removeAttribute('aria-selected');
        row.setAttribute('aria-selected', 'true');
        row.focus({ preventScroll: true });
        const panel = this.panelTarget, frame = this.frameTarget, rows = this.rows;
        if (panel.hidden) {
            panel.hidden = false;
            panel.classList.remove('is-closing');
            panel.classList.toggle('is-full', this.full);
            this.remember();
        }
        this.openTarget.href = row.dataset.href;
        this.countTarget.textContent = `${rows.indexOf(row) + 1} из ${rows.length}`;
        this.wantFocus = focus;
        if (frame.src !== new URL(row.dataset.peekUrl, location.href).href) {
            frame.innerHTML = this.skeleton ??= frame.innerHTML;
            frame.src = row.dataset.peekUrl;
        } else {
            this.focus();
        }
        frame.scrollTop = 0;
        row.scrollIntoView({ block: 'nearest', behavior: reduce.matches ? 'auto' : 'smooth' });
    }

    // Ответ фрейма: свежая строка, сообщение, формы — в окошко, переход к следующей.
    loaded(event) {
        const frame = this.frameTarget;
        if (event.target !== frame) return;
        const row = frame.querySelector('template[data-peek-row]');
        const fresh = row?.content.firstElementChild;
        if (fresh?.tagName === 'TR' && this.current) {
            fresh.setAttribute('aria-selected', 'true');
            this.current.replaceWith(fresh);
        }
        row?.remove();
        const flash = frame.querySelector('template[data-peek-toast]');
        if (flash) { window.toast?.(flash.dataset.message, flash.dataset.kind); flash.remove(); }
        frame.querySelectorAll('form:not([data-turbo-frame])').forEach((f) => { f.dataset.turboFrame = 'peek'; });
        if (frame.querySelector('template[data-peek-advance]')) { this.advance(); return; }
        this.focus();
    }

    // Форма из окошка: серверу — куда возвращать ответ (PeekBack).
    request(event) {
        if (this.current && this.frameTarget.contains(event.target)) event.detail.fetchOptions.headers['X-Peek-Back'] = this.current.dataset.peekUrl;
    }

    focus() {
        const want = this.wantFocus;
        this.wantFocus = false;
        if (!want || (want === 'fine' && !matchMedia('(pointer: fine)').matches)) return;
        const el = this.frameTarget.querySelector('[data-peek-focus]');
        if (!el) return;
        el.focus({ preventScroll: true });
        el.select?.();
    }

    // Следующая без нашей цены: дальше по таблице, иначе с начала; кончились —
    // сообщение, со следующей страницей списка — ссылкой на неё.
    advance() {
        const rows = this.rows, i = rows.indexOf(this.current);
        const next = rows.slice(i + 1).find((r) => r.hasAttribute('data-unpriced')) ?? rows.slice(0, i).find((r) => r.hasAttribute('data-unpriced'));
        if (next) { this.show(next, { focus: 'always' }); return; }
        const more = document.querySelector('a[rel="next"]');
        if (!more) { window.toast?.('Все оценены'); return; }
        const url = new URL(more.href);
        url.searchParams.set('peek', 'first');
        window.toast?.('На этой странице все оценены, дальше — следующая', { href: url.pathname + url.search });
    }

    toggle() {
        if (this.panelTarget.hidden) return;
        this.full = !this.full;
        this.panelTarget.classList.toggle('is-full', this.full);
        try { localStorage.setItem('peek:full', this.full ? '1' : '0'); } catch {}
    }

    // viaHistory — закрытие уже пришло из popstate (или это событие кнопки: тогда нет);
    // иначе запись из истории снимается по концу анимации.
    close(viaHistory = false) {
        viaHistory = viaHistory === true;
        const panel = this.panelTarget;
        if (panel.hidden || panel.classList.contains('is-closing')) return;
        this.current?.blur();
        this.current?.removeAttribute('aria-selected');
        panel.style.translate = '';
        const done = () => {
            if (panel.hidden) return;
            panel.classList.remove('is-closing');
            panel.hidden = true;
            if (!viaHistory) this.unwind();
        };
        if (reduce.matches) { done(); return; }
        panel.classList.add('is-closing');
        panel.addEventListener('animationend', done, { once: true });
        setTimeout(done, 350);
    }

    // Снимок страницы: окошко закрыто, фрейм пуст.
    reset() {
        const panel = this.panelTarget, frame = this.frameTarget;
        this.current?.removeAttribute('aria-selected');
        panel.classList.remove('is-closing');
        panel.hidden = true;
        if (this.skeleton) frame.innerHTML = this.skeleton;
        frame.removeAttribute('src');
    }

    // Запись окошка в истории и её снятие — как у шторки в sheet.js.
    remember() {
        if (this.inHistory) return;
        const { turbo, ...rest } = history.state || {};
        this.pageTurbo = turbo;
        history.replaceState(rest, '');
        history.pushState({ ...rest, peek: true }, '');
        this.inHistory = true;
    }

    unwind() {
        if (!this.inHistory || !history.state?.peek) return Promise.resolve();
        return new Promise((resolve) => { this.settle = resolve; history.back(); });
    }

    popped(event) {
        if (!this.inHistory || event.state?.peek) return;
        this.inHistory = false;
        // Turbo на пустое состояние сам ставит свой ключ; если нет — возвращаем снятый.
        if (!history.state?.turbo && this.pageTurbo !== undefined) history.replaceState({ ...(history.state || {}), turbo: this.pageTurbo }, '');
        this.close(true);
        this.settle?.();
        this.settle = null;
    }

    // Ссылка из окошка: сначала снять запись, потом визит — иначе «Назад» вернёт окошко.
    leave(event) {
        if (!this.panelTarget.contains(event.target) || !this.inHistory) return;
        event.preventDefault();
        event.detail.originalEvent.preventDefault();
        const action = event.target.closest('a')?.dataset.turboAction || 'advance';
        this.unwind().then(() => window.Turbo.visit(event.detail.url, { action }));
    }

    visit(href) {
        this.unwind().then(() => window.Turbo.visit(href));
    }

    // Свайп по окошку: за ручку — всегда, за тело — когда оно у верха прокрутки.
    // Вниз: из полного — свернуть, из короткого — закрыть; вверх из короткого — раскрыть.
    touchStart(e) {
        if (e.touches.length !== 1) return;
        const inBody = this.frameTarget.contains(e.target);
        if (inBody && this.frameTarget.scrollTop > 0) return;
        this.drag = { y: e.touches[0].clientY, at: e.timeStamp, dy: 0, inBody };
        this.panelTarget.style.transition = 'none';
    }

    touchMove(e) {
        if (!this.drag) return;
        const y = e.touches[0].clientY - this.drag.y;
        if (y > 0 && (!this.drag.inBody || this.frameTarget.scrollTop <= 0)) {
            this.drag.dy = y;
            this.panelTarget.style.translate = `0 ${y}px`;
            e.preventDefault();
        } else if (y < 0 && !this.full && !this.drag.inBody) {
            this.drag.dy = y;
            this.panelTarget.style.translate = `0 ${-Math.pow(-y, .6)}px`;
            e.preventDefault();
        } else if (this.drag.dy > 0) {
            this.drag.dy = 0;
            this.panelTarget.style.translate = '';
        }
    }

    touchEnd(e) {
        if (!this.drag) return;
        const { dy, at } = this.drag, panel = this.panelTarget;
        this.drag = null;
        panel.style.transition = '';
        if (dy < -40) { panel.style.translate = ''; if (!this.full) this.toggle(); return; }
        if (dy > Math.min(120, panel.clientHeight / 3) || dy / Math.max(1, e.timeStamp - at) > .6) {
            panel.style.translate = '';
            this.full ? this.toggle() : this.close();
            return;
        }
        panel.style.transition = 'translate var(--dur-slow) var(--ease-spring)';
        panel.style.translate = '';
        panel.addEventListener('transitionend', () => { panel.style.transition = ''; }, { once: true });
    }
}
