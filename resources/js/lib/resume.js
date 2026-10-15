// Уход в фон и возврат — один слушатель с порядком (16.10.2026: семь своих visibilitychange отправляли разом визит
// страницы, /live/badges, проверку воркера и токен). onResume(fn, order) — fn(away) после возврата, away — сколько мс
// приложение было в фоне; меньший order раньше: страница (она приносит и токен, и бейджи), токен, живые обновления,
// воркер последним (он может попросить перезагрузку). onHide(fn) — перед уходом в фон. Обе возвращают отписку.
const hide = new Set();
const show = [];
let hiddenAt = 0;

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        hiddenAt = Date.now();
        hide.forEach((fn) => fn());
        return;
    }
    const away = hiddenAt ? Date.now() - hiddenAt : 0;
    for (const { fn } of [...show]) fn(away);
});

export function onHide(fn) {
    hide.add(fn);
    return () => hide.delete(fn);
}

export function onResume(fn, order = 50) {
    const entry = { fn, order };
    show.push(entry);
    show.sort((a, b) => a.order - b.order);
    return () => { const i = show.indexOf(entry); if (i >= 0) show.splice(i, 1); };
}

// Порядок шагов возврата.
export const RESUME = { page: 10, token: 20, live: 30, queue: 40, worker: 90 };
