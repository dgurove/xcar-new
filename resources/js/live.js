import * as Turbo from '@hotwired/turbo';

// Живые обновления — один EventSource на весь сеанс, а не на каждую страницу:
// Turbo меняет тело, а соединение живёт в модуле; темы перечитываются на
// turbo:load (к этому моменту head уже слит) и пересоздают соединение только
// после входа или выхода. Пропущенное за время переподключения хаб отдаёт по
// lastEventID. Cookie подписчика истекла (401 — источник закрыт навсегда) —
// любой GET её переиздаёт, дальше открываем заново с нарастающей паузой.
// По каналу приходит только событие; за содержимым идём на сервер:
//   card    {number, only} — плитка оффера в каталоге: заменить или добавить; only (правка машины) — только заменить
//   refresh {paths, request} — если открыта одна из страниц, перечитать её (morph); request — запрос Turbo, из
//           которого пришла правка: у её автора Turbo его помнит и второй раз страницу не перечитывает
//   rows    {paths: {путь: [ключи]}, request} — в открытом списке перечитать только эти строки (data-detail-key):
//           тот же адрес с заголовком X-Live-Rows (`Live\Rows`), строки, числа пилюль и таб-бара — из ответа (patch)
//   notice  {id, subject, important, surface, html} — карточка уведомления (banner_controller, window.notify)
//   notices-read {subjects} — объекты открыли: их карточки закрываются
//   toast   {message, href} — прежний вид, пока в очереди события старой выкладки
//   badges           — счётчики таб-бара
//   chat    {chat, seq, author, from, text, href, banner} — live:chat на document для chat_controller;
//           если ни одна лента этого чата не на экране (handled) и это не своё — карточка banner
//   chat-edit / chat-read / chat-typing {chat, ...} — тем же путём, без тоста
//   telegram {state} — live:telegram для telegram_controller: чат привязан, вход подтверждён или отклонён
//   scan {candidate} — live:scan для scan_controller: «✨ Распознать» прочитал файл или закончил
//   tg-chat / tg-chat-edit {chat, seq} — переписка бота (админам): live:tg-chat* для той же ленты chat_controller
//   lock {state} — замок менеджера (lock_controller): approved / denied — кнопка под кодом в боте, kicked — вошли с другого
//           устройства. live:retopic {topics} от замка — пересоздать поток: '' — закрыть (заморозка), иначе — с новым
//           пропуском (после подтверждения у cookie хаба новый срок)
let source = null;
let topics = '';
let lastEventId = '';
let attempts = 0;
let timer = null;
let openedAt = 0;

// Канал живой — страницам не нужно опрашивать сервер самим (чат).
export const liveOpen = () => source?.readyState === EventSource.OPEN;

export function live() {
    document.addEventListener('turbo:load', retopic);
    // «Назад» показывает снимок из кэша Turbo — с теми бейджами, что были до прочтения: перечитываем.
    let restoring = false;
    document.addEventListener('turbo:visit', (e) => { restoring = e.detail.action === 'restore'; });
    document.addEventListener('turbo:load', () => { if (restoring) badges(); restoring = false; });
    // Бейджи при возврате — только после паузы дольше 30 с: переключились на секунду в другое приложение — не повод.
    let hiddenAt = 0;
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') { hiddenAt = Date.now(); return; }
        if (source?.readyState === EventSource.CLOSED) reopen(0);
        else if (source && Date.now() - hiddenAt > 30_000) badges();
    });
    window.addEventListener('online', () => source?.readyState === EventSource.CLOSED && reopen(0));
    // Человек что-то печатает — refresh от хаба страницу под руками не дёргает.
    document.addEventListener('input', (e) => e.target.form && (e.target.form.dataset.dirty = '1'));
    document.addEventListener('live:retopic', (e) => {
        topics = e.detail?.topics || '';
        clearTimeout(timer);
        source?.close();
        source = null;
        if (!topics) lastEventId = '';
        open();
    });
    retopic();
}

function retopic() {
    const next = document.querySelector('meta[name="mercure-topics"]')?.content || '';
    if (next === topics && source) return;
    topics = next;
    source?.close();
    source = null;
    lastEventId = '';
    open();
}

function open() {
    const hub = document.querySelector('meta[name="mercure-hub"]')?.content;
    if (!hub || !topics || typeof EventSource === 'undefined') return;
    const url = new URL(hub, location.origin);
    topics.split(',').filter(Boolean).forEach((t) => url.searchParams.append('topic', t));
    if (lastEventId) url.searchParams.set('lastEventID', lastEventId);
    source = new EventSource(url, { withCredentials: true });
    const on = (name, handler) => source.addEventListener(name, (e) => { lastEventId = e.lastEventId || lastEventId; handler(JSON.parse(e.data || '{}')); });
    on('card', card);
    on('refresh', refresh);
    on('rows', rows);
    on('toast', ({ message, href }) => window.toast?.(message, href ? { href } : undefined));
    on('notice', (detail) => window.notify?.(detail));
    on('notices-read', ({ subjects }) => window.noticesRead?.(subjects));
    on('badges', badges);
    on('chat', chat);
    ['telegram', 'offers-bot', 'scan', 'lock'].forEach((name) => on(name, (detail) => document.dispatchEvent(new CustomEvent(`live:${name}`, { detail }))));
    ['chat-edit', 'chat-read', 'chat-typing', 'tg-chat', 'tg-chat-edit'].forEach((name) => on(name, (detail) => document.dispatchEvent(new CustomEvent(`live:${name}`, { detail }))));
    source.onopen = () => {
        document.documentElement.removeAttribute('data-net');
        if (openedAt) badges();
        openedAt = Date.now();
        attempts = 0;
    };
    source.onerror = () => {
        if (source.readyState === EventSource.CLOSED) reopen();
        else setTimeout(() => source?.readyState === EventSource.CONNECTING && document.documentElement.setAttribute('data-net', 'reconnecting'), 2000);
    };
}

async function reopen(delay) {
    clearTimeout(timer);
    delay ??= Math.min(30_000, 1000 * 2 ** attempts++);
    timer = setTimeout(async () => {
        try { await fetch('/live/badges', { headers: { Accept: 'text/vnd.turbo-stream.html' } }); } catch { reopen(); return; }
        source?.close();
        open();
    }, delay);
}

// Сообщение чата: лента на экране забирает его сама (ставит handled), иначе — карточка уведомления.
function chat(detail) {
    document.dispatchEvent(new CustomEvent('live:chat', { detail }));
    if (detail.handled || !detail.from || String(detail.author) === document.querySelector('meta[name="user-id"]')?.content) return;
    if (detail.banner && window.notify) window.notify(detail.banner);
    else window.toast?.(`${detail.from}: ${detail.text}`, detail.href ? { href: detail.href } : undefined);
}

async function card({ number, only }) {
    const present = !!document.getElementById(`offer-${number}`);
    const list = document.getElementById('catalog');
    if (!present && (!list || only)) return;
    const r = await fetch(`/offers/${number}/card?present=${present ? 1 : 0}&list=${list?.dataset.list || ''}`, { headers: { Accept: 'text/vnd.turbo-stream.html' } });
    if (!r.ok) return;
    const html = await r.text();
    // Новая плитка въезжает наверх, только если человек стоит в начале списка, а список — новыми сверху: иначе
    // (по сроку, с подписями пачек, таблицей) он получит N новых под кнопкой, а не плитку не на своём месте.
    if (!present && html.includes('action="prepend"') && (list?.dataset.prepend === 'false' || window.scrollY > 200 || location.search)) {
        pending();
        return;
    }
    Turbo.renderStreamMessage(html);
    if (document.querySelector('#catalog .card, #catalog tr')) document.getElementById('catalog-empty')?.remove();
}

function pending() {
    let pill = document.getElementById('live-pending');
    if (!pill) {
        pill = document.createElement('button');
        pill.id = 'live-pending';
        pill.type = 'button';
        pill.className = 'btn btn-s btn-accent fixed left-1/2 z-40 -translate-x-1/2 shadow-(--shadow-drop)';
        pill.style.top = 'calc(var(--spacing-header) + env(safe-area-inset-top) + .75rem)';
        pill.dataset.count = '0';
        pill.dataset.turboTemporary = '';
        // Новыми сверху — морф на месте с фильтрами, новое сверху. По сроку новое встаёт в свою пачку, а не наверх: ведём
        // на пилюлю «Новые за 12ч».
        pill.addEventListener('click', () => {
            const url = new URL(location.href);
            if (document.getElementById('catalog')?.dataset.prepend === 'false') { url.searchParams.set('view', 'fresh'); url.searchParams.delete('page'); }
            Turbo.visit(url.toString(), { action: 'replace' });
            scrollTo({ top: 0, behavior: 'smooth' });
        });
        document.body.appendChild(pill);
    }
    pill.dataset.count = String(Number(pill.dataset.count) + 1);
    pill.textContent = `Показать новые ${pill.dataset.count}`;
}

function refresh({ paths, request }) {
    if (paths?.includes(location.pathname)) refreshPage(request);
}

// Правка своей формой: автор свою строку уже получил ответом (потоки карточки) — как refresh, второй раз не тянем.
function rows({ paths, request }) {
    const keys = paths?.[location.pathname];
    if (!keys?.length || (request && Turbo.session.recentRequests?.has(request))) return;
    refreshRows(keys);
}

// Перечитать открытую страницу морфом (или только строки — refreshRows) — но не под рукой человека (15.10.2026, владелец: «постоянно всё лагает»): пока
// палец на экране или страница едет (и 600 мс после), печатают, ищут, открыта модальная шторка или страница скрыта —
// перечитывание ждёт и потом идёт одно на все пришедшие (раньше такое молча пропадало, а под пальцем морф всей
// страницы дёргал прокрутку). Блок в потоке (sheet--inflow) открыт всегда — мешает только модальная шторка. Зовёт и
// строка заявки по ходу чтения (arrive_controller).
let waiting = null;   // { request } — страница целиком (request пуст, если правок несколько); { keys } — только строки
let waitTimer = null;
let movedAt = 0;
let fingers = 0;
addEventListener('touchstart', (e) => { fingers = e.touches.length; movedAt = performance.now(); }, { capture: true, passive: true });
addEventListener('touchend', (e) => { fingers = e.touches.length; movedAt = performance.now(); }, { capture: true, passive: true });
addEventListener('touchcancel', () => { fingers = 0; }, { capture: true, passive: true });
addEventListener('scroll', () => { movedAt = performance.now(); }, { capture: true, passive: true });
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') flush(); });
// Новая страница пришла целиком — ждущее перечитывание ей не нужно.
document.addEventListener('turbo:load', () => { waiting = null; clearTimeout(waitTimer); });

const busy = () => document.visibilityState !== 'visible'
    || fingers > 0 || performance.now() - movedAt < 600
    || document.querySelector('form[data-dirty], [data-searching], dialog:modal')
    || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);

export function refreshPage(request) {
    const id = typeof request === 'string' && /^[\w-]+$/.test(request) ? request : null;
    waiting = waiting ? { request: null } : { request: id };
    flush();
}

// Строки списка по ключу: ждут того же, что и страница, копятся в одну пачку; ждёт страница целиком — она их и покроет.
export function refreshRows(keys) {
    if (waiting && !waiting.keys) return;
    waiting ??= { keys: new Set() };
    keys.forEach((k) => waiting.keys.add(String(k)));
    flush();
}

function flush() {
    clearTimeout(waitTimer);
    if (!waiting) return;
    if (busy()) { if (document.visibilityState === 'visible') waitTimer = setTimeout(flush, 700); return; }
    const { request, keys } = waiting;
    waiting = null;
    if (keys && keys.size <= ROWS_MAX) { patching = patching.then(() => patch([...keys])).catch(() => {}); return; }
    Turbo.renderStreamMessage(`<turbo-stream action="refresh"${request ? ` request-id="${request}"` : ''}></turbo-stream>`);
}

// Строки перечитываются по одной пачке за раз: ответ прежней не ляжет поверх свежей.
const ROWS_MAX = 40;
let patching = Promise.resolve();
const ROW_OUT = 'turbo-frame#detail, dialog';
const rowsOf = (root, key) => [...root.querySelectorAll(`[data-detail-key="${CSS.escape(key)}"]`)].filter((el) => !el.closest(ROW_OUT));

// Тот же адрес с заголовком X-Live-Rows: сервер рисует страницу только с этими строками (числа пилюль и итог — настоящие).
// Строка есть и тут, и в ответе — морф одной строки; в ответе её нет — ушла из списка: убрать (и число у её группы);
// в ответе есть, а на экране нет (вошла в список) или раскладка не сошлась — страница целиком, как раньше. Открытая
// карточка той же строки — морфом фрейма из ответа (сторожа detail_controller те же: события Turbo морфа).
async function patch(keys) {
    const href = location.href;
    const url = new URL(href);
    const card = document.querySelector('turbo-frame#detail .detail-card')?.dataset.peek;
    const withCard = !!card && keys.includes(card);
    if (!withCard) url.searchParams.delete('peek');
    let doc;
    try {
        const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Live-Rows': keys.join(',') } });
        if (!r.ok || r.redirected) return;
        doc = new DOMParser().parseFromString(await r.text(), 'text/html');
    } catch { return; }
    // Пока шёл ответ, ушли на другую страницу или открыли другую строку — он устарел; взялись за экран — подождём.
    if (location.href !== href) return;
    if (busy()) { refreshRows(keys); return; }
    const plan = [];
    for (const key of keys) {
        const olds = rowsOf(document, key), next = rowsOf(doc, key);
        if (next.length ? olds.length !== next.length : olds.some((el) => !el.matches('tr'))) { refreshPage(); return; }
        plan.push([olds, next]);
    }
    let gone = 0;
    for (const [olds, next] of plan) {
        if (next.length) olds.forEach((el, i) => morphRow(el, next[i]));
        else { olds.forEach(dropRow); gone += olds.length; }
    }
    if (gone && !document.querySelector('[data-detail-key]:not(turbo-frame#detail *)')) { refreshPage(); return; }
    syncCounts(doc, gone);
    const frame = document.querySelector('turbo-frame#detail'), fresh = withCard && doc.querySelector('turbo-frame#detail');
    if (frame && fresh) Turbo.morphElements(frame, fresh);
    document.dispatchEvent(new CustomEvent('live:rows', { detail: { keys } }));
}

// Выделение строки знает только адрес (detail_controller), сервер его не рисует — морф его не снимает.
function morphRow(el, next) {
    const selected = el.getAttribute('aria-selected');
    Turbo.morphElements(el, next);
    if (selected) el.setAttribute('aria-selected', selected);
}

// Строка таблицы ушла: число у заголовка её группы на одну меньше, пустая группа уходит вместе с заголовком.
function dropRow(el) {
    let head = el.previousElementSibling;
    while (head && !head.matches('.table-group')) head = head.previousElementSibling;
    const next = el.nextElementSibling;
    el.remove();
    const count = head?.querySelector('.nums');
    if (!count || !/^\d+$/.test(count.textContent.trim())) return;
    const left = Number(count.textContent) - 1;
    if (left > 0) count.textContent = String(left);
    else if (!next || next.matches('.table-group')) head.remove();
}

// Числа из ответа: пилюли вкладок (вспыхивает выросшая — StreamActions.count), бейджи и капсулы таб-бара. Итог в
// заголовке экрана ответ знает только суженным — уходящие строки вычитаются здесь.
function syncCounts(doc, gone) {
    const streams = [];
    for (const el of doc.querySelectorAll('[data-pill-count]')) {
        const key = el.dataset.pillCount, n = Number(el.textContent.replace(/\D/g, '')) || 0;
        const targets = `[data-pill-count="${CSS.escape(key)}"]`;
        if ([...document.querySelectorAll(targets)].some((own) => (Number(own.textContent.replace(/\D/g, '')) || 0) !== n)) {
            streams.push(`<turbo-stream action="count" count="${n}" targets='${targets}'></turbo-stream>`);
        }
    }
    if (streams.length) Turbo.renderStreamMessage(streams.join(''));
    for (const attr of ['data-total', 'data-badge']) {
        for (const el of doc.querySelectorAll(`[${attr}]`)) {
            const own = document.querySelector(`[${attr}="${CSS.escape(el.getAttribute(attr))}"]`);
            if (own && own.innerHTML !== el.innerHTML) own.innerHTML = el.innerHTML;
        }
    }
    if (gone) document.querySelectorAll('[data-shell-count]').forEach((el) => {
        const n = Number(el.textContent.replace(/\D/g, ''));
        if (n) el.textContent = String(Math.max(0, n - gone));
    });
}

export async function badges() {
    if (!topics.includes('user/')) return;
    try {
        const r = await fetch('/live/badges', { headers: { Accept: 'text/vnd.turbo-stream.html' } });
        const date = Date.parse(r.headers.get('Date') || '');
        if (date) window.clockOffset = date - Date.now();
        if (r.ok) Turbo.renderStreamMessage(await r.text());
        document.dispatchEvent(new CustomEvent('badges:updated'));
    } catch {}
}
