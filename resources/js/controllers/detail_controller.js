import { Controller } from '@hotwired/stimulus';

// Список и карточка строки рядом (x-ui.shell :detail). Открывает Turbo: строка — ссылка (a[data-detail-link]) в фрейм
// detail с data-turbo-action="replace". Закрывает сам контроллер, без сервера: лист уезжает, фрейм пустеет на месте,
// ?peek= уходит из адреса. Здесь же то, чего Turbo не умеет: нажатие мимо ссылки по строке, выделение строки, ↑/↓ и Esc,
// формы карточки — в фрейм, «следующий без цены» (поток advance). Телефон: лист одной высоты, полэкрана и «закрыт» —
// сдвиг transform (app.css .split-detail), тянут за полосу (grab), тело карточки листается внутри само.
const reduce = matchMedia('(prefers-reduced-motion: reduce)');

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
        this.keep();
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
        // Лист только что открылся — под ним появилось место (padding снизу), и выбранная строка встаёт над листом.
        if (open && !was) requestAnimationFrame(() => this.current?.scrollIntoView({ block: 'nearest' }));
        // Лист открывается на полэкрана; при смене строки остаётся, каким был.
        if (!open || !was) this.frameTarget.removeAttribute('data-full');
        if (!open) this.still();
        this.rendering = false;
    }

    // Сдвиг полэкрана в px — из CSS (--sheet-half зарегистрирован длиной, app.css), чтобы снап кончался ровно там, где
    // лист встанет без сдвига в стиле.
    get half() { return parseFloat(getComputedStyle(this.frameTarget).getPropertyValue('--sheet-half')) || 0; }

    // Лист стоит там, где он сейчас виден: выезд при открытии или прежний переход сняты, их место — в стиле. Иначе
    // анимация открытия перебивала бы стиль жеста, а новый переход начинался бы не оттуда.
    pin() {
        const frame = this.frameTarget, moving = frame.getAnimations();
        const y = new DOMMatrixReadOnly(getComputedStyle(frame).transform).m42;
        moving.forEach((a) => a.cancel());
        frame.style.transition = 'none';
        frame.style.transform = `translateY(${y}px)`;
        frame.offsetHeight;
        return y;
    }

    // Лист едет к y (px вниз от «во весь экран») переходом transform; по концу — then. Новое движение отменяет прежнее.
    glide(y, then) {
        const frame = this.frameTarget, token = this.motion = {};
        this.pin();
        frame.style.transition = 'transform var(--dur) var(--ease-sheet)';
        frame.style.transform = `translateY(${y})`;
        let done = false;
        const end = (e) => {
            if (e && (e.target !== frame || e.propertyName !== 'transform')) return;
            frame.removeEventListener('transitionend', end);
            if (done || this.motion !== token) return;
            done = true;
            this.motion = null;
            then();
        };
        frame.addEventListener('transitionend', end);
        setTimeout(end, 400);
        return token;
    }

    // Стиль движения снят: лист стоит там, где его ставит CSS (data-full или полэкрана); своего перехода у листа в CSS
    // нет, поэтому встаёт без анимации.
    still() {
        this.motion = null;
        this.frameTarget.style.transition = '';
        this.frameTarget.style.transform = '';
    }

    // Тянут за полосу: лист едет за пальцем (transform своим слоем, высота не меняется), отпустили — полэкрана, во весь
    // экран или закрыть, с учётом взмаха. 07.10.2026 (владелец: окна «норовят закрыться»): лист трогается после 8 px,
    // скорость — по последним 80 мс, а не по одному замеру, взмах добавляет до 120 px, и с полного экрана жест вниз ведёт
    // на половину — закрыть можно только с половины, вторым жестом.
    grab(event) {
        const frame = this.frameTarget;
        if (this.wide.matches || event.button > 0 || event.target.closest('a, button')) return;
        event.preventDefault();
        // С клавиатурой лист не тянут: жест убирает её, как у шторок.
        if (document.documentElement.classList.contains('kb-open')) { document.activeElement?.blur(); return; }
        const y0 = event.clientY, wasFull = frame.hasAttribute('data-full');
        const h = frame.offsetHeight, half = this.half;
        let off0, off, pts = [], moving = false;
        const start = () => {
            moving = true;
            this.motion = null;
            off0 = off = this.pin();
        };
        const move = (e) => {
            if (!moving) { if (Math.abs(e.clientY - y0) < 8) return; start(); }
            off = Math.min(h, Math.max(0, off0 + e.clientY - y0));
            frame.style.transform = `translateY(${off}px)`;
            pts.push([e.timeStamp, e.clientY]);
            while (pts.length > 2 && e.timeStamp - pts[0][0] > 80) pts.shift();
        };
        const up = () => {
            removeEventListener('pointermove', move);
            removeEventListener('pointerup', up);
            removeEventListener('pointercancel', up);
            if (!moving) return;
            const [t0, p0] = pts[0] ?? [0, 0], [t1, p1] = pts[pts.length - 1] ?? [0, 0];
            const speed = pts.length > 1 ? (p1 - p0) / Math.max(16, t1 - t0) : 0;
            // Куда метили — по видимой высоте листа: весь h, полэкрана h - half.
            const aim = h - off - Math.max(-120, Math.min(120, speed * 120)), mid = h - half;
            let to = aim > (mid + h) / 2 ? 'full' : aim > mid / 2 ? 'half' : 'gone';
            if (to === 'gone' && wasFull) to = 'half';
            if (to === 'gone') { this.close(); return; }
            const full = to === 'full';
            this.glide(`${full ? 0 : half}px`, () => {
                frame.toggleAttribute('data-full', full);
                this.still();
            });
        };
        addEventListener('pointermove', move);
        addEventListener('pointerup', up);
        addEventListener('pointercancel', up);
    }

    // «Закрыть», жест вниз, Esc, нажатие по открытой строке. На телефоне лист сначала уезжает вниз, потом фрейм пустеет;
    // запроса нет — пустой фрейм и так известен, а ждать ответа сервера и было «закрывается рывком».
    close() {
        if (!this.open) return;
        const href = this.hasCloseTarget ? this.closeTarget.href : null;
        const empty = () => {
            const frame = this.frameTarget;
            frame.removeAttribute('src');
            frame.replaceChildren();
            if (href) {
                const h = window.Turbo?.session?.history, url = new URL(href, location.href);
                if (h) h.replace(url, h.restorationIdentifier);
                else history.replaceState(history.state, '', url);
            }
            this.current?.removeAttribute('aria-selected');
            this.settle(true);
        };
        if (this.wide.matches || reduce.matches) { empty(); return; }
        this.glide('100%', empty).closing = true;
    }

    // Пока лист уезжал, открыли строку — он остаётся, едет новая карточка.
    keep() {
        if (this.motion?.closing) this.still();
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
