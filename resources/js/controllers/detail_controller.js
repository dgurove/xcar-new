import { Controller } from '@hotwired/stimulus';

// Список и карточка строки рядом (x-ui.shell :detail). Открывает и закрывает Turbo: строка — ссылка
// (a[data-detail-link]) в фрейм detail с data-turbo-action="replace", «Закрыть» — ссылка на список без ?peek=. Здесь
// только то, чего Turbo не умеет: нажатие мимо ссылки по строке, выделение строки, ↑/↓ и Esc, формы карточки — в фрейм,
// «следующий без цены» (поток advance). Телефон: лист — нативная прокрутка фрейма со scroll-snap (точки «закрыто»,
// «полэкрана», «во весь экран»), тут лишь стартовая точка и закрытие, когда лист опустили до конца.
export default class extends Controller {
    static targets = ['frame', 'close'];

    connect() {
        this.wide = matchMedia('(min-width: 1024px)');
        this.full = false;
        this.onScroll = () => this.scrolled();
        this.onAdvance = () => this.advance();
        this.onRender = () => { this.rendering = true; };
        this.onStream = (e) => {
            const render = e.detail.render;
            e.detail.render = async (stream) => { await render(stream); this.mark(); };
        };
        this.frameTarget.addEventListener('scroll', this.onScroll, { passive: true });
        this.frameTarget.addEventListener('turbo:before-frame-render', this.onRender);
        document.addEventListener('detail:advance', this.onAdvance);
        document.addEventListener('turbo:before-stream-render', this.onStream);
        this.settle(false);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.frameTarget.removeEventListener('scroll', this.onScroll);
        this.frameTarget.removeEventListener('turbo:before-frame-render', this.onRender);
        document.removeEventListener('detail:advance', this.onAdvance);
        document.removeEventListener('turbo:before-stream-render', this.onStream);
    }

    get open() { return this.frameTarget.childElementCount > 0; }
    get rows() { return [...this.element.querySelectorAll('tr[data-detail-key]')]; }
    get current() { return this.element.querySelector('tr[data-detail-key][aria-selected="true"]'); }

    // Нажатие по строке мимо её кнопок — её ссылка; по открытой — закрыть.
    tap(event) {
        const row = event.target.closest('tr[data-detail-key]');
        if (!row || !this.element.contains(row)) return;
        const link = event.target.closest('a[data-detail-link]');
        if (!link && event.target.closest('a, button, input, select, textarea, label, form')) return;
        if (row === this.current && this.open) {
            event.preventDefault();
            this.close();
            return;
        }
        event.preventDefault();
        this.wantFocus = matchMedia('(pointer: fine)').matches;
        this.show(row);
    }

    // Адрес строки — от текущего адреса списка: строки «Наличия» лежат в кэше готовыми, их ссылка могла быть собрана
    // при других чипах. Поиск лупой и номер подгруженной страницы не едут (как App\Support\Detail::url).
    show(row) {
        this.select(row);
        const url = new URL(location.href);
        url.searchParams.delete('q');
        url.searchParams.delete('page');
        url.searchParams.set('peek', row.dataset.detailKey);
        window.Turbo.visit(url.pathname + url.search, { frame: 'detail', action: 'replace' });
    }

    select(row) {
        this.current?.removeAttribute('aria-selected');
        row?.setAttribute('aria-selected', 'true');
        row?.scrollIntoView({ block: 'nearest' });
    }

    // Выделение — по ?peek= в адресе (перезагрузка, морф, свежая строка из потока).
    mark() {
        const key = new URL(location.href).searchParams.get('peek');
        const row = key && this.rows.find((r) => r.dataset.detailKey === key);
        if (row && row !== this.current) this.select(row);
        if (!this.open) this.current?.removeAttribute('aria-selected');
    }

    // Фрейм перерисован: строка, атрибут раскладки, формы, фокус, точка листа.
    loaded(event) {
        if (event.target !== this.frameTarget) return;
        const was = this.element.hasAttribute('data-open');
        this.settle(was);
        if (this.wantFocus) {
            this.wantFocus = false;
            const el = this.frameTarget.querySelector('[data-peek-focus]');
            el?.focus({ preventScroll: true });
            el?.select?.();
        }
    }

    settle(was) {
        const open = this.open;
        this.element.toggleAttribute('data-open', open);
        this.frameTarget.querySelectorAll('form:not([data-turbo-frame])').forEach((f) => { f.dataset.turboFrame = 'detail'; });
        this.mark();
        if (!open || this.wide.matches) { this.rendering = false; return; }
        // Лист: впервые — на полэкрана (выезжает анимацией CSS), при смене строки — остаётся там, где был.
        const pad = this.frameTarget.querySelector('.detail-pad');
        if (!pad) { this.rendering = false; return; }
        this.frameTarget.scrollTop = was && this.full ? pad.offsetHeight : pad.offsetHeight / 2;
        requestAnimationFrame(() => { this.rendering = false; });
    }

    // Лист отпустили: у самого низа — закрыть; запоминаем, во весь ли экран.
    scrolled() {
        if (this.wide.matches || this.rendering || !this.open) return;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            const pad = this.frameTarget.querySelector('.detail-pad');
            if (!pad || this.rendering) return;
            const top = this.frameTarget.scrollTop;
            this.full = top >= pad.offsetHeight * 0.9;
            if (top < 8) this.close();
        }, 140);
    }

    close() {
        if (!this.open) return;
        this.current?.removeAttribute('aria-selected');
        if (this.hasCloseTarget) this.closeTarget.click();
    }

    key(event) {
        if (!this.open || document.querySelector('dialog:modal')) return;
        if (event.key === 'Escape') { this.close(); return; }
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
        if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;
        const rows = this.rows, i = rows.indexOf(this.current);
        const row = rows[Math.min(rows.length - 1, Math.max(0, i + (event.key === 'ArrowDown' ? 1 : -1)))];
        if (!row || row === this.current) return;
        event.preventDefault();
        this.show(row);
    }

    // Оценили — следующий черновик без цены: дальше по таблице, иначе с начала; кончились — тост, со следующей
    // страницей списка — ссылкой на неё.
    advance() {
        const rows = this.rows, i = rows.indexOf(this.current);
        const next = rows.slice(i + 1).find((r) => r.hasAttribute('data-unpriced')) ?? rows.slice(0, i).find((r) => r.hasAttribute('data-unpriced'));
        if (next) {
            this.wantFocus = true;
            this.show(next);
            return;
        }
        const more = document.querySelector('a[rel="next"]');
        if (!more) { window.toast?.('Все оценены'); return; }
        const url = new URL(more.href);
        url.searchParams.set('peek', 'first');
        window.toast?.('На этой странице все оценены, дальше следующая', { href: url.pathname + url.search });
    }
}
