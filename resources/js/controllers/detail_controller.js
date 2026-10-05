import { Controller } from '@hotwired/stimulus';

// Список и карточка строки рядом (x-ui.shell :detail). Открывает и закрывает Turbo: строка — ссылка
// (a[data-detail-link]) в фрейм detail с data-turbo-action="replace", «Закрыть» — ссылка на список без ?peek=. Здесь
// только то, чего Turbo не умеет: нажатие мимо ссылки по строке, выделение строки, ↑/↓ и Esc, формы карточки — в фрейм,
// «следующий без цены» (поток advance). Телефон: лист полэкрана или во весь экран, высоту меняет только полоса сверху
// (grab), тело карточки листается внутри само.
export default class extends Controller {
    static targets = ['frame', 'close', 'count'];

    connect() {
        this.wide = matchMedia('(min-width: 1024px)');
        this.onAdvance = () => this.advance();
        this.onRender = () => { this.rendering = true; };
        this.onStream = (e) => {
            // Место выделенной строки — до потока: «remove» уберёт её, а «advance» продолжит с этого места.
            if (this.current) this.lastIndex = this.rows.indexOf(this.current);
            const render = e.detail.render;
            e.detail.render = async (stream) => { await render(stream); this.mark(); };
        };
        this.frameTarget.addEventListener('turbo:before-frame-render', this.onRender);
        document.addEventListener('detail:advance', this.onAdvance);
        document.addEventListener('turbo:before-stream-render', this.onStream);
        this.settle(false);
    }

    disconnect() {
        this.frameTarget.removeEventListener('turbo:before-frame-render', this.onRender);
        document.removeEventListener('detail:advance', this.onAdvance);
        document.removeEventListener('turbo:before-stream-render', this.onStream);
    }

    get open() { return this.frameTarget.childElementCount > 0; }
    get rows() { return [...this.element.querySelectorAll('[data-detail-key]')]; }
    get current() { return this.element.querySelector('[data-detail-key][aria-selected="true"]'); }

    // Нажатие по строке мимо её кнопок — её ссылка; по открытой — закрыть.
    tap(event) {
        const row = event.target.closest('[data-detail-key]');
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
        // «3 из 20» — место строки в таблице.
        if (this.hasCountTarget && this.current) this.countTarget.textContent = `${this.rows.indexOf(this.current) + 1} из ${this.rows.length}`;
    }

    // Фрейм перерисован: строка, атрибут раскладки, формы, фокус, точка листа.
    loaded(event) {
        if (event.target !== this.frameTarget) return;
        const was = this.element.hasAttribute('data-open');
        this.settle(was);
        // Фокус ждёт карточку, где есть куда его поставить: ответ на «Оценить» сперва рисует уже оценённую (поля нет),
        // следом приходит следующая без цены.
        const el = this.wantFocus && this.frameTarget.querySelector('[data-detail-focus]');
        if (el) {
            this.wantFocus = false;
            el.focus({ preventScroll: true });
            el.select?.();
        }
    }

    settle(was) {
        const open = this.open;
        this.element.toggleAttribute('data-open', open);
        this.frameTarget.querySelectorAll('form:not([data-turbo-frame])').forEach((f) => { f.dataset.turboFrame = 'detail'; });
        this.mark();
        // Лист открывается на полэкрана; при смене строки остаётся, каким был.
        if (!open || !was) this.frameTarget.removeAttribute('data-full');
        if (!open) { this.frameTarget.style.translate = ''; this.frameTarget.style.transition = ''; }
        this.rendering = false;
    }

    // Тянут за полосу: лист едет за пальцем (translate, без перекладки содержимого), отпустили — полэкрана, во весь
    // экран или закрыть, с учётом взмаха; тогда уже меняется высота.
    grab(event) {
        const frame = this.frameTarget;
        if (this.wide.matches || event.button > 0 || event.target.closest('a, button')) return;
        event.preventDefault();
        const h0 = frame.offsetHeight;
        frame.setAttribute('data-full', '');
        const full = frame.offsetHeight, half = Math.min(full, innerHeight / 2);
        const y0 = event.clientY, off0 = full - h0;
        let off = off0, lastY = y0, lastT = event.timeStamp, speed = 0;
        frame.style.transition = 'none';
        frame.style.translate = `0 ${off}px`;
        const move = (e) => {
            speed = (e.clientY - lastY) / Math.max(1, e.timeStamp - lastT);
            lastY = e.clientY;
            lastT = e.timeStamp;
            off = Math.min(full, Math.max(0, off0 + e.clientY - y0));
            frame.style.translate = `0 ${off}px`;
        };
        const up = () => {
            removeEventListener('pointermove', move);
            removeEventListener('pointerup', up);
            removeEventListener('pointercancel', up);
            const aim = full - off - speed * 250;
            const to = aim > (half + full) / 2 ? full : aim > half / 2 ? half : 0;
            frame.style.transition = 'translate var(--dur) var(--ease-out)';
            frame.style.translate = `0 ${full - to}px`;
            setTimeout(() => {
                // Закрыли — лист остаётся внизу, пока фрейм не опустеет (settle снимет сдвиг).
                if (!to) { this.close(); return; }
                frame.style.transition = 'none';
                frame.style.translate = '';
                frame.toggleAttribute('data-full', to === full);
                requestAnimationFrame(() => { frame.style.transition = ''; });
            }, 260);
        };
        addEventListener('pointermove', move);
        addEventListener('pointerup', up);
        addEventListener('pointercancel', up);
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
        event.preventDefault();
        this.step(event.key === 'ArrowDown' ? 1 : -1);
    }

    // Стрелки в полосе карточки и ↑/↓: соседняя строка.
    prev() { this.step(-1); }
    next() { this.step(1); }

    step(delta) {
        const rows = this.rows, i = rows.indexOf(this.current);
        const row = rows[Math.min(rows.length - 1, Math.max(0, i + delta))];
        if (row && row !== this.current) this.show(row);
    }

    // Оценили — следующий черновик без цены: дальше по таблице, иначе с начала; кончились — тост, со следующей
    // страницей списка — ссылкой на неё.
    // Оценённая строка могла уйти из списка («Без цены») — тогда дальше с её места.
    advance() {
        const rows = this.rows, cur = this.current;
        const from = cur ? rows.indexOf(cur) + 1 : (this.lastIndex ?? 0);
        const next = rows.slice(from).find((r) => r.hasAttribute('data-unpriced')) ?? rows.slice(0, from).find((r) => r.hasAttribute('data-unpriced') && r !== cur);
        if (next) {
            this.wantFocus = true;
            this.show(next);
            return;
        }
        const more = document.querySelector('a[rel="next"]');
        // Кончились — куда дальше, говорит список (`data-advance-done`: «Без продажной цены» → «Оцененные»; без ссылки —
        // дальше не его шаг).
        const done = document.querySelector('[data-advance-done]');
        if (!more) { window.toast?.(done?.textContent || 'Все оценены', done?.getAttribute('href') ? { href: done.getAttribute('href') } : undefined); return; }
        const url = new URL(more.href);
        url.searchParams.set('peek', 'first');
        window.toast?.('На этой странице все оценены, дальше следующая', { href: url.pathname + url.search });
    }
}
