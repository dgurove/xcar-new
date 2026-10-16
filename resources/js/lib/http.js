// Запросы из скриптов — одна дверь (16.10.2026: двадцать с лишним контроллеров сами читали токен из меты, половина не
// проверяла ответ, обрыв связи пропадал молча). Формы и ссылки по-прежнему ведёт Turbo, это — для fetch.
//
// request(url, { method, body, json, headers, accept, signal, quiet, statuses }) → Response или null:
// - POST и прочие — с токеном CSRF; json — тело объектом (Content-Type ставится сам), body — FormData или строка;
// - 419 (токен устарел, приложение лежало в фоне) — токен обновляется со своей страницы, запрос повторяется один раз;
// - обрыв — тост «Нет связи», 429 — «Слишком много попыток», другой не-2xx — «Не получилось»; quiet — без тоста;
// - statuses — коды, которые вызывающий разбирает сам (422 с ошибками полей): ответ отдаётся как есть;
// - отменённый (signal, устаревший ответ поиска) — null без тоста;
// - ответа нет timeout мс (35 с — дольше серверных 30, иначе PDF и снятие знака под нагрузкой обрывались на полпути,
//   16.10.2026; у загрузки файлов — 120 с) — как обрыв, «Нет связи».
import { refreshCsrf } from '../net';

const withFiles = (body) => body instanceof FormData && [...body.values()].some((v) => v instanceof File && v.size);

export const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export async function request(url, { method = 'GET', body, json, headers = {}, accept = 'application/json', signal, quiet = false, statuses = [], timeout } = {}) {
    const wait = timeout ?? (withFiles(body) ? 120_000 : 35_000);
    const send = () => {
        const h = { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', ...headers };
        if (method.toUpperCase() !== 'GET') h['X-CSRF-TOKEN'] = csrf();
        if (json !== undefined) h['Content-Type'] = 'application/json';
        const limit = AbortSignal.timeout?.(wait);
        const sig = signal && limit && AbortSignal.any ? AbortSignal.any([signal, limit]) : (signal ?? limit);
        return fetch(url, { method, headers: h, body: json !== undefined ? JSON.stringify(json) : body, signal: sig, credentials: 'same-origin' });
    };
    const say = (text) => { if (!quiet) window.toast?.(text, 'danger'); return null; };
    let r;
    try {
        r = await send();
        if (r.status === 419 && method.toUpperCase() !== 'GET' && (await refreshCsrf())) r = await send();
    } catch (e) {
        if (e?.name === 'AbortError') return null;
        return say('Нет связи');
    }
    if (r.ok || statuses.includes(r.status)) return r;
    if (r.status === 429) {
        const wait = Number(r.headers.get('Retry-After') || 0);
        return say(wait ? `Слишком много попыток, через ${wait} с` : 'Слишком много попыток');
    }
    return say('Не получилось');
}

// Ответ JSON или null (сбой уже показан тостом).
export async function getJson(url, options = {}) {
    const r = await request(url, options);
    if (!r) return null;
    try { return await r.json(); } catch { return null; }
}

export const post = (url, data, options = {}) => request(url, {
    method: 'POST',
    ...(data instanceof FormData || typeof data === 'string' ? { body: data } : data === undefined ? {} : { json: data }),
    ...options,
});
