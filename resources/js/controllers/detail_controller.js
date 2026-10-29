import { Controller } from '@hotwired/stimulus';
import { onMorphAttribute, onMorphElement } from '../lib/morph-guard';
import { focusField } from '../keyboard';
import * as overlays from '../overlays';
import { confirmSheet } from '../confirm';
import { refreshPage, refreshRows } from '../live';
import { fling } from '../lib/fling';

// Список и карточка строки рядом (x-ui.shell :detail). Строка — ссылка (a[data-detail-link]) в фрейм detail; открывает и
// закрывает контроллер, адрес (?peek=) меняет он же, не Turbo: data-turbo-action у фрейма делал из каждой загрузки визит
// всей страницы (снимок, turbo:load у шапки, кадров, таб-бара — «весь интерфейс моргает», 07.10.2026). Открытие: лист
// выезжает сразу с заготовкой (название строки и полосы), карточка приходит в него. Закрытие без сервера: лист уезжает,
// фрейм пустеет на месте. Здесь же то, чего Turbo не умеет: нажатие мимо ссылки по строке, выделение строки, ↑/↓ и Esc,
// формы карточки — в фрейм, «следующий без цены» (поток advance). Телефон: лист полэкрана или во весь экран — в покое
// коробкой ровно видимой части, в движении — сдвигом transform (app.css .split-detail, pin), тянут за полосу (grab), тело
// карточки листается внутри само.
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
// Поток бейджей, тревоги и чисел строк не меняет — готовые карточки не выбрасываем (раньше каждый бейдж стирал память, и
// карточки качались снова).
const CHROME = /^\[data-(badge|alarm-button)|^meta\[|^\[data-(pill-)?count/;
document.addEventListener('turbo:submit-start', forget);
document.addEventListener('turbo:before-stream-render', (e) => {
    const s = e.detail?.newStream ?? e.target;
    const target = s?.getAttribute?.('targets') || s?.getAttribute?.('target') || '';
    if (['toast', 'count'].includes(s?.getAttribute?.('action')) || CHROME.test(target)) return;
    forget();
});
// Касание, остановившее инерцию прокрутки, — не выбор строки: запроса карточки нет.
let scrolledAt = 0;
addEventListener('scroll', () => { scrolledAt = performance.now(); }, { capture: true, passive: true });
document.addEventListener('lock:freeze', forget);

export default class extends Controller {
    static targets = ['frame', 'close', 'count'];

    connect() {
        this.wide = matchMedia('(min-width: 1024px)');
        this.onAdvance = () => this.advance();
        // Ответ пришёл, а лист ещё выезжает — вставка ждёт конца выезда: тяжёлая раскладка карточки не делит кадры с
        // анимацией.
        this.onRender = (e) => {
            this.rendering = true;
            // Ответ той же карточки (сохранили, оценили с ошибкой, «+15 мин») — прокрутка остаётся, где была: после
            // «Сохранить» лист прыгал в самый верх, и правленое поле внизу терялось (07.10.2026). Другая строка — сверху.
            this.keepScroll = this.swap ? null : this.frameTarget.querySelector('.detail-body')?.scrollTop ?? null;
            this.swap = false;
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
            if (!hit || Date.now() - hit.at >= KEEP) return;
            // Готовый ответ — под отменой запроса Turbo: перешли на другую строку — ответ прежней не встаёт поверх
            // неё (без этого медленный ответ строки A рисовался, когда уже выбрана B) и не пишет «Нет связи» в её заготовку.
            const signal = e.detail.fetchOptions.signal;
            e.detail.fetchRequest = { response: new Promise((resolve, reject) => {
                const abort = () => reject(new DOMException('Перешли на другую строку', 'AbortError'));
                if (signal?.aborted) { abort(); return; }
                signal?.addEventListener('abort', abort, { once: true });
                hit.response.then((r) => resolve(r.clone()), reject);
            }) };
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
        this.onRendered = () => this.restore();
        this.frameTarget.addEventListener('turbo:frame-render', this.onRendered);
        // Нет связи, пока лист ждёт карточку, — вместо вечной заготовки слова и «Повторить».
        this.onFail = (e) => {
            const url = e.detail.request?.url?.href, src = this.frameTarget.getAttribute('src');
            if (e.target === this.frameTarget && (!url || !src || url === new URL(src, location.href).href)) this.failed();
        };
        this.frameTarget.addEventListener('turbo:fetch-request-error', this.onFail);
        // Живое обновление списка (морф страницы, live.js refreshPage) не трогает положение листа: data-full, сдвиг и
        // адрес фрейма знает только браузер, сервер их не рисует — морф снимал их, и лист сам падал на полэкрана.
        // Зовутся на каждый атрибут и элемент страницы (24 тыс. на списке CRM): `this.frameTarget` — поиск по DOM на каждое
        // чтение, и эти две проверки стоили 2,2 с морфа на телефоне (аудит 20.10.2026). Сначала дешёвое — вид узла.
        const SKIP = ['data-full', 'data-moving', 'style', 'src', 'busy', 'aria-busy', 'complete'];
        this.onMorphAttribute = (e) => {
            const t = e.target, name = e.detail.attributeName;
            if (t.localName === 'turbo-frame' ? SKIP.includes(name) && t === this.frameTarget : name === 'data-open' && t === this.element) e.preventDefault();
        };
        this.offMorphAttribute = onMorphAttribute(this.onMorphAttribute);
        // Живой морф страницы не подменяет карточку (16.10.2026, начальник: «Оценить» уходило в прошлую машину). Turbo
        // перечитывает страницу по адресу на начало запроса и с ним же возвращает адрес: ответ, прочитанный ещё со старым
        // ?peek, приходил, когда лист уже на следующей строке (advance, ↓, нажатие), — морф вставлял карточку прежней
        // строки, выделение и адрес уезжали за ней, и «Оценить» сохраняло не ту машину. Какая строка открыта — знает
        // `src` фрейма (его морф не трогает, `chosen`), чья карточка в ответе — `data-peek` у .detail-card (x-ui.detail):
        // чужая или лист ещё грузит свою — фрейм не трогаем, список вокруг обновляется; после морфа адрес — снова на
        // открытой строке (`onMark`). Своя той же строки морфится как раньше.
        this.onMorphElement = (e) => {
            if (e.target.localName !== 'turbo-frame' || e.target !== this.frameTarget) return;
            const incoming = e.detail.newElement?.querySelector?.('.detail-card')?.dataset.peek ?? '';
            if (this.frameTarget.hasAttribute('busy') || this.frameTarget.hasAttribute('aria-busy') || incoming !== (this.chosen ?? '')) e.preventDefault();
        };
        this.offMorphElement = onMorphElement(this.onMorphElement);
        // Уход со страницы с несохранённым в карточке (таб-бар, «Открыть страницу», ссылка) — тот же вопрос, что у листа.
        // Ссылку после «Не сохранять» нажимаем заново — Turbo берёт её действие (replace), фрейм и прочее как было;
        // визит из скрипта повторяется тем же адресом.
        // Карточка открыта — её запись «Назад» снимается до перехода, иначе она осталась бы под новой страницей лишним шагом.
        this.onLink = async (e) => {
            if (this.passing || (!this.dirty && !overlays.isOpen('detail'))) return;
            const link = e.target;
            // Отмена turbo:click отменяет только визит Turbo — сам щелчок браузер довёл бы до обычного перехода.
            e.preventDefault();
            e.detail.originalEvent?.preventDefault();
            // Остались — нажатое (is-pending, app.js pressFeedback) снимается: экран никуда не едет.
            if (!(await this.leave())) { link.closest('.is-pending')?.classList.remove('is-pending'); return; }
            await overlays.shut('detail');
            this.passing = true;
            link.click();
            this.passing = false;
        };
        document.addEventListener('turbo:click', this.onLink);
        this.onVisit = async (e) => {
            if (this.passing || !this.dirty) return;
            e.preventDefault();
            if (!(await this.leave())) return;
            await overlays.shut('detail');
            window.Turbo?.visit(e.detail.url);
        };
        document.addEventListener('turbo:before-visit', this.onVisit);
        // Нажали поле, пока лист ехал (keyboard.js ставит его во весь экран сам): прежнее движение отменяется —
        // иначе его запасной таймер доехал бы своё и опустил лист на полэкрана или закрыл его под кареткой.
        this.onHold = () => {
            if (this.motion?.closing) this.shut = false;
            this.motion = null;
        };
        this.frameTarget.addEventListener('detail:hold', this.onHold);
        document.addEventListener('detail:advance', this.onAdvance);
        document.addEventListener('turbo:before-stream-render', this.onStream);
        // Морф, загрузка страницы и свежая строка приносят строки без выделения (его знает только адрес) — вернуть. Свежую
        // строку ловит наблюдатель: поток из карточки, пришедшей со страницей, рисуется раньше, чем подключится контроллер,
        // и turbo:before-stream-render сюда не доходит — после перезагрузки строка стояла невыделенной.
        this.onMark = () => { this.keepAddress(); this.mark(); };
        ['turbo:load', 'turbo:morph'].forEach((n) => document.addEventListener(n, this.onMark));
        // После морфа раскладка — по тому, что в фрейме на самом деле: карточку убрали с сервера (предложение удалили) —
        // data-open снимается, иначе список оставался бледным с отступом под листом, которого нет.
        this.onMorphed = () => this.settle(this.element.hasAttribute('data-open'));
        document.addEventListener('turbo:morph', this.onMorphed);
        this.rowsSeen = new MutationObserver((records) => {
            if (records.some((r) => [...r.addedNodes].some((n) => n.nodeType === 1 && n.matches('[data-detail-key]')))) this.mark();
        });
        this.element.querySelectorAll('.split-main').forEach((main) => this.rowsSeen.observe(main, { childList: true, subtree: true }));
        this.settle(false);
    }

    disconnect() {
        document.documentElement.classList.remove('detail-open');
        this.frameTarget.removeEventListener('turbo:before-frame-render', this.onRender);
        this.frameTarget.removeEventListener('turbo:frame-render', this.onRendered);
        this.frameTarget.removeEventListener('turbo:fetch-request-error', this.onFail);
        this.offMorphAttribute?.();
        this.offMorphElement?.();
        document.removeEventListener('turbo:before-visit', this.onVisit);
        document.removeEventListener('turbo:click', this.onLink);
        this.frameTarget.removeEventListener('detail:hold', this.onHold);
        this.frameTarget.removeEventListener('turbo:before-fetch-request', this.onFetch);
        this.unprefetch?.();
        document.removeEventListener('detail:advance', this.onAdvance);
        document.removeEventListener('turbo:before-stream-render', this.onStream);
        ['turbo:load', 'turbo:morph'].forEach((n) => document.removeEventListener(n, this.onMark));
        document.removeEventListener('turbo:morph', this.onMorphed);
        this.rowsSeen.disconnect();
    }

    get open() { return this.frameTarget.childElementCount > 0; }

    // Вписали в карточке и не сохранили: видна «Сохранить изменения» (поля карточки) или в форме действия сверху
    // (закупочная, цена «Оценить», вывоз, гараж) набрано новое. Формы в шторках внутри карточки не в счёт — шторка
    // спрашивает сама, а брошенная в ней сумма не держала потом каждую смену строки. После «Не сохранять» — чисто до
    // следующей карточки (released).
    get dirty() {
        return !this.released && this.open && ([...this.frameTarget.querySelectorAll('.save-bar')].some((b) => !b.hidden) || this.actionDirty);
    }

    // Набрано в форме действия сверху карточки (вывоз, гараж) — у неё не сохранение, а действие.
    get actionDirty() {
        return [...this.frameTarget.querySelectorAll('form[data-dirty]:not([data-controller~="save-bar"])')].some((form) => !form.closest('dialog')
            && [...form.elements].some((el) => el.matches?.('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), textarea') && el.value.trim() !== '' && el.value !== el.defaultValue));
    }

    // Уйти с карточки с несохранённым — только переспросив, как у шторок (07.10.2026, начальник вводил данные, нажимал
    // другую строку или «Закрыть» — правки молча пропадали). Как в iOS — три ответа (08.10.2026, владелец: «невозможно
    // выйти без сохранения, приходится закрывать приложение»): «Сохранить» (тихо, той же отправкой, что перед чужой
    // формой; не прошло проверку — остаёмся у поля с ошибкой), «Не сохранять», «Остаться». Набрано и в форме действия
    // сверху (вывоз, гараж) — без «Сохранить»: у неё не сохранение, а действие, и «Сохранить» молча бросило бы набранное.
    // Сохранили — список перечитывается (строка могла уйти во вкладку дальше, числа пилюль). true — можно уходить; запись
    // «Назад» к этому времени снята.
    async leave() {
        if (!this.dirty) return true;
        const bar = this.actionDirty ? null : this.saveBar;
        const answer = await confirmSheet(bar ? 'Сохранить изменения?' : 'Не сохранять?', bar
            ? { label: 'Сохранить', alt: { label: 'Не сохранять', danger: true }, cancel: 'Остаться', danger: false }
            : { label: 'Не сохранять', cancel: 'Остаться', danger: true });
        if (!answer) return false;
        if (answer === 'ok' && bar) {
            // Сохранили строку, с которой уходим, — перечитывается она, а не весь список.
            const key = this.current?.dataset.detailKey;
            const done = await bar.quiet();
            if (done === 'offline') window.toast?.('Нет связи', { kind: 'danger' });
            if (done !== 'ok') return false;
            forget();
            setTimeout(() => (key ? refreshRows([key]) : refreshPage()), 400);
        } else this.released = true;
        return true;
    }

    // Форма карточки с выехавшей «Сохранить изменения» — её контроллер.
    get saveBar() {
        const bar = [...this.frameTarget.querySelectorAll('.save-bar')].find((b) => !b.hidden);
        const form = bar && (bar.dataset.saveBar ? document.getElementById(bar.dataset.saveBar) : bar.closest('form'));
        return form ? this.application.getControllerForElementAndIdentifier(form, 'save-bar') : null;
    }

    // Карточка встала: та же — прокрутка на прежнем месте; вернулась с ошибкой — первое поле с ошибкой посреди листа
    // (прокруткой самого листа: scrollIntoView двигал бы и экран под fixed-листом — на iOS каретка рисовалась мимо).
    restore() {
        const body = this.frameTarget.querySelector('.detail-body');
        if (body) {
            if (this.keepScroll != null) body.scrollTop = this.keepScroll;
            this.keepScroll = null;
            const bad = body.querySelector('.field-invalid, .field-error');
            if (bad) {
                const r = bad.getBoundingClientRect(), b = body.getBoundingClientRect();
                body.scrollTop += r.top - b.top - Math.max(0, (b.height - r.height) / 2);
            }
        }
    }

    // Карточка не пришла (нет сети): заготовка не мигает вечно — слова и «Повторить» на месте полос.
    failed() {
        const body = this.frameTarget.querySelector('.detail-blank .detail-body');
        if (!body) return;
        body.closest('.detail-blank').removeAttribute('aria-busy');
        body.classList.add('detail-failed');
        body.innerHTML = '<p class="text-ink-muted">Нет связи, карточка не загрузилась</p><button type="button" class="btn btn-quiet btn-s mt-3" data-action="detail#retry">Повторить</button>';
    }

    retry() {
        const src = this.frameTarget.getAttribute('src');
        const row = this.current;
        if (!src || !row) return;
        this.frameTarget.replaceChildren(this.blank(row));
        this.frameTarget.reload();
    }

    // Адрес карточки строки — ссылка самой строки, если она есть: в ней бывает больше ключа (вкладка «По ссылкам» —
    // `tab=links`, ключи ссылок и счетов там свои; без неё карточка открывалась и тут же закрывалась, 09.10.2026). Нет
    // ссылки — от текущего адреса списка (как show).
    urlFor(row) {
        const link = row.matches('a[data-detail-link]') ? row : row.querySelector('a[data-detail-link]');
        if (link?.href) {
            const own = new URL(link.href, location.href);
            own.searchParams.delete('q');
            own.searchParams.delete('page');
            own.searchParams.set('peek', row.dataset.detailKey);
            return own;
        }
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
            const r = performance.now() - scrolledAt < 150 ? null : row(e), t = e.touches[0];
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
    // Свёрнутые группы (`group_fold_controller`) листанием ↑/↓ пропускаются.
    get rows() { return [...this.element.querySelectorAll('[data-detail-key]')].filter((r) => !r.hidden); }
    get current() { return this.element.querySelector('[data-detail-key][aria-selected="true"]'); }

    // Нажатие по строке мимо её кнопок — её ссылка; по открытой — закрыть.
    async tap(event) {
        const row = event.target.closest('[data-detail-key]');
        if (!row || !this.element.contains(row)) return;
        const link = event.target.closest('a[data-detail-link]');
        if (!link && event.target.closest('a, button, input, select, textarea, label, form')) return;
        event.preventDefault();
        if (row === this.current && this.open) {
            this.close();
            return;
        }
        if (!(await this.leave())) return;
        this.wantFocus = matchMedia('(pointer: fine)').matches;
        this.show(row);
    }

    // Адрес строки — от текущего адреса списка: строки «Наличия» лежат в кэше готовыми, их ссылка могла быть собрана
    // при других чипах. Поиск лупой и номер подгруженной страницы не едут (как App\Support\Detail::url).
    show(row) {
        this.shut = false;
        this.swap = true;
        this.released = false;
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
            // Лист открыт — своя запись «Назад» (overlays.js): «Назад» и свайп от края закрывают карточку, а не список;
            // набранное — с вопросом.
            overlays.open('detail', { close: () => this.close(undefined, true), guard: () => this.dirty && this.leave() });
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
            // Список без кадров у строк («Оплаты») — заготовка без ленты плиток.
            + `<div class="detail-body">${row.closest('[data-detail-shape="plain"]') ? '' : `<div class="blank-strip">${bars(4, 'blank-tile')}</div>`}`
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

    // Открытая строка — по адресу карточки во фрейме (`src` ставит show(), морф его не трогает); без него — по адресу
    // страницы (карточка пришла с ней). Закрыт лист — никакой.
    get chosen() {
        if (!this.open) return null;
        const src = this.frameTarget.getAttribute('src');
        return new URL(src || location.href, location.href).searchParams.get('peek');
    }

    // Морф по живому обновлению, прочитанный со старым ?peek, вернул бы адрес на прежнюю строку — возвращаем открытую.
    keepAddress() {
        const key = this.chosen;
        if (!key || new URL(location.href).searchParams.get('peek') === key) return;
        const url = new URL(location.href);
        url.searchParams.set('peek', key);
        this.address(url);
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
            // Карточка пришла без нажатия — клавиатуры не будет: на телефоне поле только в видимую часть листа, без
            // фокуса (поле в фокусе без клавиатуры потом не ловит своё нажатие, и iOS сдвигал лист).
            if (matchMedia('(hover: none)').matches) focusField(el, { keyboard: false });
            else { el.focus({ preventScroll: true }); el.select?.(); }
        }
    }

    settle(was) {
        const open = this.open;
        this.element.toggleAttribute('data-open', open);
        // Плашка действий страницы на ПК отходит от карточки (`html.detail-open` в app.css; не `body:has(.split[data-open])`:
        // такое правило пересчитывало стили на каждую вставку узла, 29.10.2026).
        document.documentElement.classList.toggle('detail-open', open);
        this.frameTarget.querySelectorAll('form:not([data-turbo-frame])').forEach((f) => { f.dataset.turboFrame = 'detail'; });
        this.mark();
        // Лист только что открылся — под ним появилось место (padding снизу), и выбранная строка встаёт над листом.
        if (open && !was) this.reveal(this.current);
        // Лист открывается на полэкрана; при смене строки остаётся, каким был.
        if (!open || !was) this.frameTarget.removeAttribute('data-full');
        if (!open) this.still();
        // Карточка ушла сама (строка ушла из вкладки, поток убрал фрейм) — её запись «Назад» тоже.
        if (!open && overlays.isOpen('detail')) overlays.shut('detail');
        this.rendering = false;
    }

    // Сдвиг полэкрана в px — из CSS (--sheet-half зарегистрирован длиной, app.css), чтобы снап кончался ровно там, где
    // лист встанет без сдвига в стиле.
    get half() { return parseFloat(getComputedStyle(this.frameTarget).getPropertyValue('--sheet-half')) || 0; }

    // Лист трогается с места: из покоя (коробка = видимая часть, без transform) — во весь экран и сдвиг transform на то
    // же место, кадр в кадр (data-moving, app.css). Выезд при открытии или прежний переход сняты, их место — в стиле:
    // иначе анимация открытия перебивала бы стиль жеста, а новый переход начинался бы не оттуда. y — px вниз от «во весь
    // экран»: место покоя плюс текущий сдвиг — из стиля, без раскладки; раскладка одна, в конце.
    pin() {
        const frame = this.frameTarget;
        const rest = frame.hasAttribute('data-moving') || frame.hasAttribute('data-full') || document.documentElement.classList.contains('kb-open') ? 0 : this.half;
        const y = rest + new DOMMatrixReadOnly(getComputedStyle(frame).transform).m42;
        frame.getAnimations().forEach((a) => a.cancel());
        frame.style.transition = 'none';
        frame.setAttribute('data-moving', '');
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

    // Движение кончилось: лист снова в покое — коробкой там, где его ставит CSS (data-full или полэкрана), без transform;
    // своего перехода у листа в CSS нет, поэтому встаёт без анимации.
    still() {
        this.motion = null;
        this.frameTarget.removeAttribute('data-moving');
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
        const y0 = event.clientY, wasFull = frame.hasAttribute('data-full'), half = this.half;
        let h, off0, off, moving = false;
        const track = fling();
        // Палец лёг на полосу покоящегося листа — лист сразу во весь экран со сдвигом на то же место: раскладка и слой
        // готовы до первого кадра жеста, а не в нём. Не поехал — обратно в покой (still в up). Лист в движении (снап,
        // выезд) не трогаем, пока палец не поехал: нажатие не должно его останавливать.
        const ready = !this.motion && !frame.getAnimations().length;
        const take = () => {
            off0 = off = this.pin();
            // Высота — во весь экран, уже в движении: в покое на полэкрана коробка листа вдвое ниже.
            h = frame.offsetHeight;
        };
        if (ready) take();
        const start = () => {
            moving = true;
            this.motion = null;
            if (!ready) take();
        };
        const move = (e) => {
            if (!moving) { if (Math.abs(e.clientY - y0) < 8) return; start(); }
            off = Math.min(h, Math.max(0, off0 + e.clientY - y0));
            frame.style.transform = `translateY(${off}px)`;
            track.add(e.timeStamp, e.clientY);
        };
        const up = () => {
            removeEventListener('pointermove', move);
            removeEventListener('pointerup', up);
            removeEventListener('pointercancel', up);
            if (!moving) { if (ready) this.still(); return; }
            const speed = track.speed();
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
    async close(ms, viaHistory = false) {
        if (!this.open) { if (!viaHistory) overlays.shut('detail'); return; }
        if (!(await this.leave())) {
            // Отпустили бросок вниз и остались — лист возвращается на место.
            if (this.frameTarget.hasAttribute('data-moving')) this.glide(`${this.frameTarget.hasAttribute('data-full') ? 0 : this.half}px`, () => this.still());
            return;
        }
        this.opening = null;
        this.shut = true;
        if (!viaHistory) overlays.shut('detail');
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

    async step(delta) {
        const rows = this.rows, i = rows.indexOf(this.current);
        const row = rows[Math.min(rows.length - 1, Math.max(0, i + delta))];
        if (row && row !== this.current && await this.leave()) this.show(row);
    }

    // Оценили — следующий черновик без цены: дальше по таблице, иначе с начала; кончились — тост, со следующей
    // страницей списка — ссылкой на неё.
    // Оценённая строка могла уйти из списка («Без цены») — тогда дальше с её места.
    advance() {
        const rows = this.rows, cur = this.current;
        const from = cur ? rows.indexOf(cur) + 1 : (this.lastIndex ?? 0);
        const next = rows.slice(from).find((r) => r.hasAttribute('data-todo')) ?? rows.slice(0, from).find((r) => r.hasAttribute('data-todo') && r !== cur);
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
