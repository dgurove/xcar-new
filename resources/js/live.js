import * as Turbo from '@hotwired/turbo';

// Живые обновления — один EventSource на весь сеанс, а не на каждую страницу:
// Turbo меняет тело, а соединение живёт в модуле; темы перечитываются на
// turbo:load (к этому моменту head уже слит) и пересоздают соединение только
// после входа или выхода. Пропущенное за время переподключения хаб отдаёт по
// lastEventID. Cookie подписчика истекла (401 — источник закрыт навсегда) —
// любой GET её переиздаёт, дальше открываем заново с нарастающей паузой.
// По каналу приходит только событие; за содержимым идём на сервер:
//   card    {number} — плитка оффера в каталоге: заменить или добавить
//   refresh {paths}  — если открыта одна из страниц, перечитать её (morph)
//   toast   {message, href}
//   badges           — счётчики таб-бара
//   chat    {...}    — событие live:chat на document для chat_controller
let source = null;
let topics = '';
let lastEventId = '';
let attempts = 0;
let timer = null;
let openedAt = 0;

export function live() {
    document.addEventListener('turbo:load', retopic);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        if (source?.readyState === EventSource.CLOSED) reopen(0);
        else if (source) badges();
    });
    window.addEventListener('online', () => source?.readyState === EventSource.CLOSED && reopen(0));
    // Человек что-то печатает — refresh от хаба страницу под руками не дёргает.
    document.addEventListener('input', (e) => e.target.form && (e.target.form.dataset.dirty = '1'));
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
    on('toast', ({ message, href }) => window.toast?.(message, href ? { href } : undefined));
    on('badges', badges);
    on('chat', (detail) => document.dispatchEvent(new CustomEvent('live:chat', { detail })));
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

async function card({ number }) {
    const present = !!document.getElementById(`offer-${number}`);
    const list = document.getElementById('catalog');
    if (!present && !list) return;
    const r = await fetch(`/offers/${number}/card?present=${present ? 1 : 0}&list=${list?.dataset.list || ''}`, { headers: { Accept: 'text/vnd.turbo-stream.html' } });
    if (!r.ok) return;
    const html = await r.text();
    // Новая плитка въезжает наверх, только если человек стоит в начале
    // списка: иначе он получит N новых под кнопкой, а не под пальцем.
    if (!present && html.includes('action="prepend"') && (window.scrollY > 200 || location.search)) {
        pending();
        return;
    }
    Turbo.renderStreamMessage(html);
    if (document.getElementById('catalog')?.children.length) document.getElementById('catalog-empty')?.remove();
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
        // Морф на месте с фильтрами и прокруткой, без слайда; новое — сверху.
        pill.addEventListener('click', () => { Turbo.visit(location.href, { action: 'replace' }); scrollTo({ top: 0, behavior: 'smooth' }); });
        document.body.appendChild(pill);
    }
    pill.dataset.count = String(Number(pill.dataset.count) + 1);
    pill.textContent = `Новых: ${pill.dataset.count} — показать`;
}

function refresh({ paths }) {
    if (!paths?.includes(location.pathname)) return;
    if (document.querySelector('form[data-dirty]') || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
    // Блок в потоке (sheet--inflow) открыт всегда — мешает только модальная шторка.
    if (document.querySelector('dialog:modal')) return;
    Turbo.renderStreamMessage('<turbo-stream action="refresh"></turbo-stream>');
}

async function badges() {
    if (!topics.includes('user/')) return;
    try {
        const r = await fetch('/live/badges', { headers: { Accept: 'text/vnd.turbo-stream.html' } });
        const date = Date.parse(r.headers.get('Date') || '');
        if (date) window.clockOffset = date - Date.now();
        if (r.ok) Turbo.renderStreamMessage(await r.text());
        document.dispatchEvent(new CustomEvent('badges:updated'));
    } catch {}
}
