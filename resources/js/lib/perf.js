// Замер с настоящих телефонов (17.11.2026, «нажал — и ничего не происходит»): сколько прошло от нажатия до того, как экран
// ответил (`shown`), и до готовых данных (`ready`). Уходит маячком на `/live/perf` в журнал `perf-*.log`, сводка —
// `php artisan perf:pages`. Мерить, а не чувствовать: числа «до» и «после» по разделам и по людям.
//
// Отклик — первая отрисовка нового экрана; кто показывает экран раньше ответа сервера (снимок раздела, скелетон), зовёт
// `shown()` сам. В адресах — только путь без номеров и вкладка: ни имён, ни цен, ни поиска.
import { csrf } from './http';

let press = 0;       // когда палец или мышь нажали в последний раз
let where = '';      // что нажали: таб, колесо вкладок, строку списка
let visit = null;    // идущий переход: { t0, what, from, to, shown }
let here = '';       // экран, что сейчас показан
let lifted = 0;      // когда нажатие стало кликом: палец на экране — не ожидание (18.11.2026)

const now = () => performance.now();

const screen = (url = location) => {
    // Вкладка экрана: колесо CRM (`preset`), ящик почты, вкладка — и пилюли сайта (`view`: «Новые», «Горящие», архив сделок).
    const query = url.searchParams ?? new URLSearchParams(url.search);
    const tab = query.get('preset') ?? query.get('box') ?? query.get('tab') ?? query.get('view');
    return url.pathname.replace(/\d+/g, 'N') + (tab ? ` [${tab}]` : '');
};

const kindOf = (target) => {
    if (!(target instanceof Element)) return 'visit';
    if (target.closest('#tabbar')) return 'tab';
    if (target.closest('.tab-wheel')) return 'wheel';
    if (target.closest('[data-detail-key]')) return 'row';
    // Плитка и строка-карточка сайта ведут на страницу машины — главное нажатие менеджера и покупателя.
    if (target.closest('.card')) return 'card';
    return 'visit';
};

function send(row) {
    if (!(row.ready > 0) || row.ready > 60_000 || document.visibilityState !== 'visible') return;
    const body = JSON.stringify({ _token: csrf(), ...row, app: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true });
    try { navigator.sendBeacon?.('/live/perf', new Blob([body], { type: 'application/json' })); } catch {}
}

/** Экран ответил на нажатие раньше сервера: снимок раздела или скелетон уже на месте. */
export function shown() {
    if (visit && visit.shown === undefined) visit.shown = Math.round(now() - visit.t0);
}

export function perf() {
    here = screen();
    document.addEventListener('pointerdown', (event) => { press = now(); lifted = 0; where = kindOf(event.target); }, { capture: true, passive: true });
    document.addEventListener('click', () => { if (!lifted) lifted = now(); }, { capture: true, passive: true });

    document.addEventListener('turbo:visit', (event) => {
        // Переход начат нажатием — считаем от нажатия; сам (живое обновление, «Назад» жестом) — от начала перехода.
        const pressed = now() - press < 1500;
        if (!pressed && event.detail.action !== 'restore') { visit = null; return; }
        visit = { t0: pressed ? press : now(), what: event.detail.action === 'restore' && (!pressed || where === 'visit' || where === 'card') ? 'back' : where, from: here, to: screen(new URL(event.detail.url)),
            finger: pressed && lifted > press ? Math.round(lifted - press) : undefined };
    });
    document.addEventListener('turbo:render', shown);
    document.addEventListener('turbo:load', () => {
        const done = visit;
        visit = null;
        here = screen();
        if (!done || done.from === done.to && done.what === 'visit') return;
        send({ what: done.what, from: done.from, to: done.to, shown: done.shown ?? Math.round(now() - done.t0), ready: Math.round(now() - done.t0), finger: done.finger });
    });

    // Окно строки (машина, письмо, платёж): от нажатия строки до карточки во фрейме.
    document.addEventListener('turbo:frame-load', (event) => {
        if (event.target.id !== 'detail' || where !== 'row' || now() - press > 30_000) return;
        send({ what: 'window', from: here, to: here, ready: Math.round(now() - press) });
        where = '';
    });
}
