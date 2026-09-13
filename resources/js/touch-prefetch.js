// Turbo подгружает страницу по ссылке при наведении. На телефоне наведения
// нет, а tap на iOS даёт mouseenter и click почти одновременно — 100 мс
// задержки Turbo не успевают. Поэтому запрос уходит на touchstart, пока палец
// ещё на экране, а Turbo при переходе забирает уже идущий ответ. Начало
// прокрутки по ссылке — не тап: запрос отменяется через AbortController.
// Штатный hover-префетч на телефоне глушится (эмулированный mouseenter давал
// второй запрос). Соседи по списку (rel=prev/next) подгружаются заранее.
import { fetch as turboFetch } from '@hotwired/turbo';

const TTL = 10_000;
const pending = new Map();

export function prefetch(href, ttl = TTL) {
    const url = new URL(href, location.href);
    const hit = pending.get(url.href);
    if (hit && Date.now() - hit.at < ttl) return hit;
    if (navigator.onLine === false || navigator.connection?.saveData) return null;
    const controller = new AbortController();
    const response = turboFetch(url.href, {
        credentials: 'same-origin',
        redirect: 'follow',
        signal: controller.signal,
        headers: { Accept: 'text/html, application/xhtml+xml', 'X-Sec-Purpose': 'prefetch' },
    });
    const entry = { response, at: Date.now(), controller };
    pending.set(url.href, entry);
    response.catch(() => pending.delete(url.href));
    setTimeout(() => { if (pending.get(url.href) === entry) pending.delete(url.href); }, ttl);
    return entry;
}

export function touchPrefetch() {
    let touch = null;

    document.addEventListener('touchstart', (event) => {
        const link = event.target.closest?.('a[href]');
        if (!link || !prefetchable(link)) return;
        // Касание у самого края — это жест «назад» iOS, а не тап по карточке.
        const x = event.touches[0].clientX;
        if (x < 24 || x > innerWidth - 24) return;
        const entry = prefetch(link.href);
        const t = event.touches[0];
        touch = entry ? { entry, x: t.clientX, y: t.clientY, at: Date.now(), fresh: Date.now() - entry.at < 50 } : null;
    }, { capture: true, passive: true });

    document.addEventListener('touchmove', (event) => {
        if (!touch) return;
        const t = event.touches[0];
        if (Math.hypot(t.clientX - touch.x, t.clientY - touch.y) > 10 && Date.now() - touch.at < 150 && touch.fresh) {
            touch.entry.controller.abort();
            touch = null;
        }
    }, { capture: true, passive: true });

    document.addEventListener('turbo:before-fetch-request', (event) => {
        const { fetchOptions, url } = event.detail;
        if (fetchOptions.method !== 'GET' || event.target.tagName === 'FORM') return;
        const hit = pending.get(url.href);
        pending.delete(url.href);
        if (hit && Date.now() - hit.at < TTL && !hit.controller.signal.aborted) {
            event.detail.fetchRequest = { response: hit.response };
        }
    }, true);

    if (matchMedia('(hover: none)').matches) {
        document.addEventListener('turbo:before-prefetch', (event) => event.preventDefault());
    }

    // Стрелки к соседям и «Вперёд» — ответ уже в руках к моменту тапа.
    document.addEventListener('turbo:load', () => setTimeout(() => {
        document.querySelectorAll('a[rel="prev"], a[rel="next"]').forEach((a) => prefetchable(a) && prefetch(a.href, 60_000));
    }, 800));
}

function prefetchable(link) {
    if (link.origin !== location.origin) return false;
    if (link.hasAttribute('download') || link.target?.startsWith('_')) return false;
    if (link.closest('[data-turbo="false"], [data-turbo-prefetch="false"], [data-turbo-method], [data-turbo-frame]')) return false;
    if (link.href === location.href || link.hash && link.pathname === location.pathname) return false;
    if (document.querySelector('meta[name="turbo-prefetch"][content="false"]')) return false;
    return true;
}
