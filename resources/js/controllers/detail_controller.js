import { Controller } from '@hotwired/stimulus';

// Список и карточка строки рядом (x-ui.shell :detail). Строка — ссылка (a[data-detail-link]) в фрейм detail; открывает и
// закрывает контроллер, адрес (?peek=) меняет он же, не Turbo: data-turbo-action у фрейма делал из каждой загрузки визит
// всей страницы (снимок, turbo:load у шапки, кадров, таб-бара — «весь интерфейс моргает», 07.10.2026). Открытие: лист
// выезжает сразу с заготовкой (название строки и полосы), карточка приходит в него. Закрытие без сервера: лист уезжает,
// фрейм пустеет на месте. Здесь же то, чего Turbo не умеет: нажатие мимо ссылки по строке, выделение строки, ↑/↓ и Esc,
// формы карточки — в фрейм, «следующий без цены» (поток advance). Телефон: лист одной высоты, полэкрана и «закрыт» —
// сдвиг transform (app.css .split-detail), тянут за полосу (grab), тело карточки листается внутри само.
const reduce = matchMedia('(prefers-reduced-motion: reduce)');

// Карточки заранее (07.10.2026, владелец: «быстрее и плавнее»): запрос уходит при касании строки (палец стоит 70 мс или
// уже отпущен), при наведении мышью 120 мс и за соседей открытой — к нажатию ответ уже в пути или в руках. Лежит 30 с,
// не больше 8; отправка формы и поток live память сбрасывают — карточка могла измениться.
const cards = new Map();
const KEEP = 30_000;

function fetchCard(href) {
    const url = new URL(href, location.href).href;
    const hit = cards.get(url);
    if (hit && Date.now() - hit.at < KEEP) return hit;
    if (navigator.onLine === false || navigator.connection?.saveData) return null;
    const entry = { at: Date.now() };
    entry.response = fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html, application/xhtml+xml', 'Turbo-Frame': 'detail', 'X-Sec-Purpose': 'prefetch' } });
    entry.response.then((r) => { if (!r.ok || r.redirected) cards.delete(url); else entry.ready = true; }, () => cards.delete(url));
    cards.set(url, entry);
    while (cards.size > 8) cards.delete(cards.keys().next().value);
    return entry;
}

const forget = () => cards.clear();
document.addEventListener('turbo:submit-start', forget);
document.addEventListener('turbo:before-stream-render', forget);

export default class extends Controller {
    static targets = ['frame', 'close', 'count'];

    connect() {
        this.wide = matchMedia('(min-width: 1024px)');
        this.onAdvance = () => this.advance();
        // Ответ пришёл, а лист ещё выезжает — вставка ждёт конца выезда: тяжёлая раскладка карточки не делит кадры с
        // анимацией.
        this.onRender = (e) => {
            this.rendering = true;
            // Пока ждали, лист закрыли — карточку не вставлять, иначе лист вернулся бы.
            const render = e.detail.render;
            e.detail.render = (...args) => { if (!this.shut) render(...args); };
            if (!this.opening) return;
            e.preventDefault();
            this.opening.then(() => e.detail.resume());
        };
        // Фрейм просит карточку — отдаём уже идущий ответ, если он есть.
        this.onFetch = (e) => {
            if (e.target !== this.frameTarget || e.detail.fetchOptions.method !== 'GET') return;
            const hit = cards.get(e.detail.url.href);
            if (hit && Date.now() - hit.at < KEEP) e.detail.fetchRequest = { response: hit.response.then((r) => r.clone()) };
        };
        this.frameTarget.addEventListener('turbo:before-fetch-request', this.onFetch);
        this.prefetching();
        this.onStream = (e) => {
            // Место выделенной строки — до потока: «remove» уберёт её, а «advance» продолжит с этого места.
            if (this.current) this.lastIndex = this.rows.indexOf(this.current);
            const render = e.detail.render;
            e.detail.render = async (stream) => { await render(stream); this.mark(); };
        };
        this.frameTarget.addEventListener('turbo:before-frame-render', this.onRender);
        document.addEventListener('detail:advance', this.onAdvance);
        document.addEventListener('turbo:before-stream-render', this.onStream);
        // Морф, загрузка страницы и свежая строка приносят строки без выделения (его знает только адрес) — вернуть. Свежую
        // строку ловит наблюдатель: поток из карточки, пришедшей со страницей, рисуется раньше, чем подключится контроллер,
        // и turbo:before-stream-render сюда не доходит — после перезагрузки строка стояла невыделенной.
        this.onMark = () => this.mark();
        ['turbo:load', 'turbo:morph'].forEach((n) => document.addEventListener(n, this.onMark));
        this.rowsSeen = new MutationObserver((records) => {
            if (records.some((r) => [...r.addedNodes].some((n) => n.nodeType === 1 && n.matches('[data-detail-key]')))) this.mark();
        });
        this.element.querySelectorAll('.split-main').forEach((main) => this.rowsSeen.observe(main, { childList: true, subtree: true }));
        this.settle(false);
    }

    disconnect() {
        this.frameTarget.removeEventListener('turbo:before-frame-render', this.onRender);
        this.frameTarget.removeEventListener('turbo:before-fetch-request', this.onFetch);
        this.unprefetch?.();
        document.removeEventListener('detail:advance', this.onAdvance);
        document.removeEventListener('turbo:before-stream-render', this.onStream);
        ['turbo:load', 'turbo:morph'].forEach((n) => document.removeEventListener(n, this.onMark));
        this.rowsSeen.disconnect();
    }

    get open() { return this.frameTarget.childElementCount > 0; }

    // Адрес карточки строки — от текущего адреса списка (как show).
    urlFor(row) {
        const url = new URL(location.href);
        url.searchParams.delete('q');
        url.searchParams.delete('page');
        url.searchParams.set('peek', row.dataset.detailKey);
        return url;
    }

    // Касание строки и наведение мышью — запрос карточки заранее. Палец поехал (прокрутка) — не тап, запроса нет.
    prefetching() {
        let touch = null, hover = null;
        const row = (e) => {
            const r = e.target.closest?.('[data-detail-key]');
            return r && this.element.contains(r) && !e.target.closest('button, input, select, textarea, label') ? r : null;
        };
        const go = (r) => { if (r && r !== this.current) fetchCard(this.urlFor(r)); };
        const onTouchStart = (e) => {
            const r = row(e), t = e.touches[0];
            clearTimeout(touch?.timer);
            touch = r && e.touches.length === 1 ? { r, x: t.clientX, y: t.clientY, timer: setTimeout(() => { go(r); touch.done = true; }, 70) } : null;
        };
        const onTouchMove = (e) => {
            const t = e.touches[0];
            if (touch && Math.hypot(t.clientX - touch.x, t.clientY - touch.y) > 10) { clearTimeout(touch.timer); touch = null; }
        };
        const onTouchEnd = () => { if (touch && !touch.done) { clearTimeout(touch.timer); go(touch.r); } touch = null; };
        const onOver = (e) => {
            if (e.pointerType !== 'mouse') return;
            const r = row(e);
            if (r === hover?.r) return;
            clearTimeout(hover?.timer);
            hover = r ? { r, timer: setTimeout(() => go(r), 120) } : null;
        };
        const el = this.element, opts = { passive: true };
        el.addEventListener('touchstart', onTouchStart, opts);
        el.addEventListener('touchmove', onTouchMove, opts);
        el.addEventListener('touchend', onTouchEnd, opts);
        el.addEventListener('pointerover', onOver, opts);
        this.unprefetch = () => {
            el.removeEventListener('touchstart', onTouchStart, opts);
            el.removeEventListener('touchmove', onTouchMove, opts);
            el.removeEventListener('touchend', onTouchEnd, opts);
            el.removeEventListener('pointerover', onOver, opts);
        };
    }

    // Соседи открытой строки — заранее, когда всё уже нарисовано: ↑/↓ и «Дальше» открываются без ожидания.
    neighbours() {
        clearTimeout(this.nearTimer);
        this.nearTimer = setTimeout(() => {
            const rows = this.rows, i = rows.indexOf(this.current);
            if (i < 0) return;
            [rows[i + 1], rows[i - 1]].forEach((r) => r && fetchCard(this.urlFor(r)));
        }, 400);
    }
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
        this.shut = false;
        this.keep();
        this.select(row);
        const url = this.urlFor(row);
        this.address(url);
        const card = this.frameTarget.querySelector('.detail-card');
        if (card) {
            // Лист открыт — лист стоит, меняется содержимое: карточка уже в памяти — встанет сразу, прежняя стоит до неё;
            // нет — сразу заглушка новой строки, а не бледная прежняя.
            const hit = cards.get(url.href);
            if (!(hit?.ready && Date.now() - hit.at < KEEP)) this.frameTarget.replaceChildren(this.blank(row));
            this.mark();
            this.reveal(row);
        } else {
            this.frameTarget.replaceChildren(this.blank(row));
            this.settle(false);
            this.waitOpening();
        }
        this.frameTarget.src = url.pathname + url.search;
    }

    // Адрес страницы без визита Turbo: запись та же, у Turbo — тот же ключ восстановления.
    address(url) {
        const h = window.Turbo?.session?.history;
        if (h) h.replace(url, h.restorationIdentifier);
        else history.replaceState(history.state, '', url);
    }

    // Заготовка листа, пока карточка в пути, — по форме карточки (x-ui.row-card): полоса как у карточки (‹ ›, «4 из 7»,
    // «Закрыть»), плитки кадров, название из самой строки, метки, кнопки и поля плашкой строк.
    blank(row) {
        const card = document.createElement('div');
        card.className = 'detail-card detail-blank';
        card.setAttribute('aria-busy', 'true');
        const bars = (n, cls) => Array.from({ length: n }, () => `<i class="blank ${cls}"></i>`).join('');
        const icon = (d) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="${d}"/></svg>`;
        const rows = Array.from({ length: 6 }, () => '<div class="blank-row"><i class="blank blank-label"></i><i class="blank blank-value"></i></div>').join('');
        card.innerHTML = '<div class="detail-bar" data-action="pointerdown->detail#grab"><span class="detail-handle" aria-hidden="true"></span>'
            + `<button type="button" class="bar-btn" data-action="detail#prev" aria-label="Предыдущая">${icon('m18 15-6-6-6 6')}</button>`
            + `<button type="button" class="bar-btn" data-action="detail#next" aria-label="Следующая">${icon('m6 9 6 6 6-6')}</button>`
            + '<span class="detail-count nums mr-auto" data-detail-target="count"></span>'
            + `<button type="button" class="bar-btn" data-action="detail#close" aria-label="Закрыть">${icon('M18 6 6 18M6 6l12 12')}</button></div>`
            + `<div class="detail-body"><div class="blank-strip">${bars(4, 'blank-tile')}</div>`
            + `<div class="detail-head mt-3"><p class="detail-blank-title"></p><div class="detail-marks blank-marks">${bars(3, 'blank-mark')}</div></div>`
            + `<div class="blank-acts">${bars(2, 'blank-act')}</div><div class="blank-list">${rows}</div></div>`;
        const text = (el) => el?.textContent.trim().split('\n')[0].trim() ?? '';
        card.querySelector('.detail-blank-title').textContent = text(row.querySelector('.cell-name') ?? row.querySelector('.cell-title, .card-title') ?? row);
        return card;
    }

    // Выезд листа: обещание, что сбудется по его концу (не дольше 320 мс). Без анимации — сразу.
    waitOpening() {
        const frame = this.frameTarget;
        if (reduce.matches) { this.opening = null; return; }
        const done = this.opening = new Promise((resolve) => {
            const end = (e) => { if (!e || e.target === frame) { frame.removeEventListener('animationend', end); resolve(); } };
            frame.addEventListener('animationend', end);
            setTimeout(end, 320);
        }).then(() => { if (this.opening === done) this.opening = null; });
    }

    select(row) {
        this.current?.removeAttribute('aria-selected');
        row?.setAttribute('aria-selected', 'true');
    }

    // Строка — над листом (scroll-margin под него стоит при data-open); прокрутка одна и плавная, и только если нужна.
    reveal(row) {
        row?.scrollIntoView({ block: 'nearest', behavior: reduce.matches ? 'auto' : 'smooth' });
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
        this.neighbours();
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
        if (open && !was) this.reveal(this.current);
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

    // Лист едет к y (px вниз от «во весь экран») переходом transform за ms; по концу — then. Новое движение отменяет прежнее.
    glide(y, then, ms = 240) {
        const frame = this.frameTarget, token = this.motion = {};
        this.pin();
        frame.style.transition = `transform ${ms}ms var(--ease-sheet)`;
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
        setTimeout(end, ms + 160);
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
            // Бросили вниз — лист улетает с той скоростью, с какой бросили, а не доезжает свои 200 мс.
            if (to === 'gone') { this.close(Math.round(Math.max(80, Math.min(200, (h - off) / Math.max(speed, .5))))); return; }
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
    close(ms) {
        if (!this.open) return;
        this.opening = null;
        this.shut = true;
        const empty = () => {
            const frame = this.frameTarget, url = new URL(location.href);
            frame.removeAttribute('src');
            frame.replaceChildren();
            url.searchParams.delete('peek');
            this.address(url);
            this.current?.removeAttribute('aria-selected');
            this.settle(true);
        };
        if (this.wide.matches || reduce.matches) { empty(); return; }
        this.glide('100%', empty, typeof ms === 'number' ? ms : 200).closing = true;
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
