import * as Turbo from '@hotwired/turbo';

// Сеть и сессия без белых страниц.
// 419 (токен истёк: приложение лежало в фоне, вход в соседнем приложении сменил
// сессию) — форма не рисует «Страница устарела»: токен тихо обновляется со
// свежей страницы, и отправка повторяется сама. 429 — тост с временем ожидания.
// Обрыв связи — Turbo не перезагружает окно на /offline: тост «Нет связи» с
// «Повторить», при возврате сети повтор уходит сам.
export function netGuards() {
    const submitters = new WeakMap();
    document.addEventListener('turbo:submit-start', (e) => submitters.set(e.target, e.detail.formSubmission.submitter));

    document.addEventListener('turbo:before-fetch-response', async (event) => {
        const { fetchResponse } = event.detail;
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (fetchResponse.statusCode === 419 && form && !form.dataset.retried) {
            event.preventDefault();
            form.dataset.retried = '1';
            if (await refreshCsrf()) form.requestSubmit(submitters.get(form) ?? undefined);
            else window.toast?.('Сессия обновилась — отправьте ещё раз', 'danger');
            setTimeout(() => delete form.dataset.retried, 5000);
            return;
        }
        if (fetchResponse.statusCode === 429) {
            event.preventDefault();
            if (!form) Turbo.navigator.stop();
            const wait = Number(fetchResponse.header('Retry-After') || 0);
            window.toast?.(wait ? `Слишком много попыток — через ${wait} с` : 'Слишком много попыток', 'danger');
        }
    });

    let retry = null;
    document.addEventListener('turbo:fetch-request-error', (event) => {
        event.preventDefault();
        const { request } = event.detail;
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form) Turbo.navigator.stop();
        // Мелкие действия (закладка, прочитано) — в очередь: уйдут сами, когда появится сеть.
        if (form?.hasAttribute('data-queue')) { enqueue(form); window.toast?.('Нет связи — отправим, когда появится'); return; }
        retry = form ? () => form.requestSubmit(submitters.get(form) ?? undefined) : () => Turbo.visit(request.url.href);
        window.toast?.('Нет связи', { kind: 'danger', action: { label: 'Повторить', run: retry } });
    });
    window.addEventListener('online', () => { document.documentElement.removeAttribute('data-net'); const r = retry; retry = null; r?.(); flush(); });
    document.addEventListener('turbo:load', () => navigator.onLine !== false && flush());
    window.addEventListener('offline', () => document.documentElement.setAttribute('data-net', 'offline'));
    if (navigator.onLine === false) document.documentElement.setAttribute('data-net', 'offline');

    // Фрейм пришёл без фрейма: редирект на вход — идём на вход целиком, иначе тост.
    document.addEventListener('turbo:frame-missing', (event) => {
        event.preventDefault();
        const { response } = event.detail;
        if (response.redirected && /\/login/.test(response.url)) { Turbo.visit('/login'); return; }
        window.toast?.('Не получилось', 'danger');
    });

    // Обрыв уходит в консоль как rejection — страница в порядке, тост уже показан.
    window.addEventListener('unhandledrejection', (event) => {
        if (event.reason instanceof TypeError && /load failed|failed to fetch|networkerror/i.test(event.reason.message)) event.preventDefault();
    });

    // Вернулись из фона после долгой паузы — обновить токен до того, как нажмут «Сохранить».
    let hiddenAt = 0;
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') { hiddenAt = Date.now(); return; }
        if (hiddenAt && Date.now() - hiddenAt > 20 * 60 * 1000) refreshCsrf();
    });
}

// Свежий токен со своей же страницы: meta и все _token в формах.
export async function refreshCsrf() {
    try {
        const html = await (await fetch(location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })).text();
        const token = new DOMParser().parseFromString(html, 'text/html').querySelector('meta[name="csrf-token"]')?.content;
        if (!token) return null;
        document.querySelector('meta[name="csrf-token"]').content = token;
        document.querySelectorAll('input[name="_token"]').forEach((i) => { i.value = token; });
        return token;
    } catch {
        return null;
    }
}

// Очередь действий без сети: форма с data-queue (закладка, прочитано) запоминается
// в localStorage и отправляется при возврате сети или на следующей загрузке; ответ-стрим
// применяется, если строка ещё на экране. 4xx — выбросить, обрыв — оставить.
const QUEUE = 'net:queue';
function enqueue(form) {
    const body = [...new FormData(form)].filter(([k, v]) => k !== '_token' && !(v instanceof File));
    try {
        const list = JSON.parse(localStorage.getItem(QUEUE) || '[]');
        list.push({ url: new URL(form.action, location.href).href, method: (form.method || 'post').toUpperCase(), body, at: Date.now() });
        localStorage.setItem(QUEUE, JSON.stringify(list.slice(-50)));
    } catch {}
}

let flushing = false;
export async function flush() {
    if (flushing) return;
    let list;
    try { list = JSON.parse(localStorage.getItem(QUEUE) || '[]'); } catch { return; }
    if (!list.length) return;
    flushing = true;
    const keep = [];
    for (const item of list) {
        if (Date.now() - item.at > 24 * 3600 * 1000) continue;
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        item.body.forEach(([k, v]) => body.append(k, v));
        try {
            const r = await fetch(item.url, { method: item.method, body, credentials: 'same-origin', headers: { Accept: 'text/vnd.turbo-stream.html, text/html' } });
            if (r.status === 419) { await refreshCsrf(); keep.push(item); continue; }
            if (r.ok && (r.headers.get('Content-Type') || '').includes('turbo-stream')) Turbo.renderStreamMessage(await r.text());
        } catch {
            keep.push(item);
        }
    }
    try { localStorage.setItem(QUEUE, JSON.stringify(keep)); } catch {}
    flushing = false;
    if (keep.length < list.length) window.toast?.('Отправлено');
}
