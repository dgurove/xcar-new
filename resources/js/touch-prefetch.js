// Turbo подгружает страницу по ссылке при наведении. На телефоне наведения
// нет, а tap на iOS даёт mouseenter и click почти одновременно — 100 мс
// задержки Turbo не успевают. Поэтому запрос уходит на touchstart, пока палец
// ещё на экране, а Turbo при переходе забирает уже идущий ответ.
import { fetch as turboFetch } from '@hotwired/turbo';

const TTL = 10_000;

export function touchPrefetch() {
    const pending = new Map();

    document.addEventListener('touchstart', (event) => {
        const link = event.target.closest?.('a[href]');
        if (!link || !prefetchable(link)) return;

        const url = new URL(link.href, location.href);
        if (pending.has(url.href)) return;

        const response = turboFetch(url.href, {
            credentials: 'same-origin',
            redirect: 'follow',
            headers: { Accept: 'text/html, application/xhtml+xml', 'X-Sec-Purpose': 'prefetch' },
        });
        pending.set(url.href, { response, at: Date.now() });
        response.catch(() => pending.delete(url.href));
    }, { capture: true, passive: true });

    document.addEventListener('turbo:before-fetch-request', (event) => {
        const { fetchOptions, url } = event.detail;
        if (fetchOptions.method !== 'GET' || event.target.tagName === 'FORM') return;
        const hit = pending.get(url.href);
        pending.delete(url.href);
        if (hit && Date.now() - hit.at < TTL) {
            event.detail.fetchRequest = { response: hit.response };
        }
    }, true);
}

function prefetchable(link) {
    if (link.origin !== location.origin) return false;
    if (link.hasAttribute('download') || link.target?.startsWith('_')) return false;
    if (link.closest('[data-turbo="false"], [data-turbo-prefetch="false"], [data-turbo-method], [data-turbo-frame]')) return false;
    if (link.href === location.href || link.hash && link.pathname === location.pathname) return false;
    if (document.querySelector('meta[name="turbo-prefetch"][content="false"]')) return false;
    return true;
}
