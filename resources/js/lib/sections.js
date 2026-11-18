// Память разделов таб-бара — одна дверь (17.11.2026, владелец: «при переключении между разделами сбрасывается то, где ты
// был в другом разделе… вернулся в раздел с предложениями и продолжил там, где остановился, на том же месте страницы»,
// «переключение должно быть моментальным»).
//
// Раздел — таб, которому принадлежит экран (`<body data-nav-tab>`, считает сервер: `Nav::currentTab`). У каждого раздела
// помнится последний экран с таб-баром:
// - место — адрес и прокрутка: sessionStorage и, на `KEEP`, localStorage (приложение выгрузили из памяти — место живо).
//   Пишется при любом уходе с экрана (таб, всплывающее сообщение, «Назад», сворачивание), а не только нажатием таба;
// - снимок — страница целиком, как её снял Turbo при уходе: открытое окно, догруженные строки, набранное. В памяти,
//   по одному на раздел, мимо общего кэша Turbo на десять страниц — другие экраны его не вытесняют.
//
// Возврат в раздел (`open`): есть снимок — он встаёт сразу, без запроса к серверу, тем же путём, что «Назад» (визит
// `restore`), прокрутка ставится до первой отрисовки; сервер спрашивают потом и тихо (морф), только если раздел устарел:
// пока его не было на экране, по его адресам пришло событие (`markDirty` из `live.js`), или снимку больше двух минут
// (`freshness` в `app.js`). Снимка нет — обычный визит по запомненному адресу.
//
// Открытый чат — такое же место раздела «Чаты» (18.11.2026, владелец: «написать в чате, переключиться в предложения или
// сделки, нажать на чаты — и вернуться туда же, в тот же чат»; до того чат прятал таб-бар и местом раздела не был).
// Ленту после возврата догоняет сам чат (`chat_controller` при подключении), её прокрутку он же ставит по метке в снимке.
import * as Turbo from '@hotwired/turbo';
import { holdY, knownY, scrollTop } from './scroller';
import * as overlays from '../overlays';
import { arm, hide as hideGhost } from './shape';
import { leaving, stopVisit } from './visit-guard';
import { prefetch } from '../touch-prefetch';
import { shown as shownNow } from './perf';

const KEY = 'tabs:';
const KEEP = 12 * 3600_000;
// Снимок тяжелее — раздел откроется обычным визитом: десятки тысяч узлов в памяти телефона дороже одного запроса.
const HEAVY = 30_000;

const pins = new Map();   // раздел → { url, snapshot, at }
const dirty = new Map();  // раздел → когда по нему пришло событие, пока он был скрыт
const left = [];          // последние покинутые экраны { tab, url }: их снимки Turbo кладёт в кэш чуть позже ухода
let here = null;          // экран на виду: { tab, url }
let pendingScroll;        // прокрутка, что встанет сразу после ближайшей отрисовки
let reopened = null;      // раздел, открытый из снимка: после показа проверить, не устарел ли
let refresh = () => {};
let user = null;         // чьи снимки лежат в памяти
let action = null;       // вид идущего визита
let warm = 0;            // отложенный на такт запрос раздела по касанию
let returned = null;     // адрес экрана, открытого возвратом в раздел (`open`): «‹» с него — не шаг истории

const pathOf = (href) => new URL(href, location.origin).pathname;
const keyOf = (url) => { const u = new URL(url, location.origin); u.hash = ''; return u.href; };
const tabNow = () => document.body.dataset.navTab || null;

// Место — своё у каждого человека: под «Войти как» и после смены пользователя чужой адрес раздела не подставляется.
const who = () => document.querySelector('meta[name="user-id"]')?.content ?? '0';
const slot = (tab) => `${KEY}${who()}:${pathOf(tab)}`;
const read = (store, tab) => { try { return JSON.parse(store.getItem(slot(tab)) || 'null'); } catch { return null; } };
const write = (store, tab, value) => { try { value ? store.setItem(slot(tab), JSON.stringify(value)) : store.removeItem(slot(tab)); } catch {} };

// Чей это адрес: раздел, названный в нём самом (`in=`, контекст галереи), иначе — по путям разделов из таб-бара.
// undefined — путей нет (экран без таб-бара): судить не по чему.
function ownerOf(url) {
    const tabs = tabMatches();
    if (!tabs.length) return undefined;
    const u = new URL(url, location.origin);
    const stated = u.searchParams.get('in') ?? (u.searchParams.get('list') === 'gallery' ? '/gallery' : null);
    return stated && tabs.some(([href]) => pathOf(href) === stated) ? stated : tabOfPath(u.pathname, tabs);
}
// Место раздела — только его адрес (18.11.2026, «застревает в разделе»): когда на экране оказывалась страница одного
// раздела под адресом другого (отменённый переход дорисовался — `lib/visit-guard.js`), память записывала разделу чужой
// адрес и чужой снимок, и он открывал другой раздел до перезапуска приложения. Чужое не пишется и не читается.
const belongs = (url, tab) => { const owner = ownerOf(url); return owner === undefined || owner === pathOf(tab); };

/** Запомнить место раздела, что сейчас на экране. */
export function remember() {
    if (!here?.tab || !belongs(here.url, here.tab)) return;
    const place = { url: here.url, y: Math.round(knownY()), at: Date.now() };
    write(sessionStorage, here.tab, place);
    write(localStorage, here.tab, place);
}

/** Где раздел оставили: { url, y } или null, если там его корень сверху и снимка нет — тогда откроет сама ссылка таба. */
export function recall(tab) {
    let saved = read(sessionStorage, tab);
    if (!saved) {
        saved = read(localStorage, tab);
        if (saved && Date.now() - (saved.at ?? 0) > KEEP) saved = null;
    }
    try {
        if (!saved || new URL(saved.url).origin !== location.origin) return null;
    } catch { return null; }
    if (!belongs(saved.url, tab)) { forget(tab); return null; }
    // Корень раздела с самого верха — и так то, что откроет ссылка таба; но со снимком он откроется сразу, без сервера.
    const pin = pins.get(pathOf(tab));
    const root = saved.url === new URL(tab, location.origin).href && !(saved.y > 0);
    return !root || pin?.url === keyOf(saved.url) ? saved : null;
}

/** Куда ведёт раздел: `data-go` (вкладка по умолчанию, без круга через редирект) или сама ссылка. */
export const goOf = (link) => new URL(link.dataset.go || link.getAttribute('href'), location.origin).href;

export function forget(tab) {
    write(sessionStorage, tab, null);
    write(localStorage, tab, null);
    pins.delete(pathOf(tab));
}

/**
 * Открыть раздел там, где его оставили. Из снимка — сразу и без сети; иначе обычным визитом.
 * false — переход запретили («Сохранить изменения?», обновление после выкладки): остаёмся, где были.
 */
export function open(tab, saved) {
    const url = new URL(saved.url);
    returned = keyOf(url);
    let pin = pins.get(pathOf(tab));
    // Снимок — этого раздела: страница в нём знает свой раздел сама (`<body data-nav-tab>`).
    if (pin && pin.snapshot.element?.dataset.navTab !== tab) { pins.delete(pathOf(tab)); pin = null; }
    if (pin && pin.url === keyOf(url)) {
        if (!Turbo.session.applicationAllowsVisitingLocation(url)) return false;
        pins.delete(pathOf(tab));
        Turbo.session.view.snapshotCache.put(url, pin.snapshot);
        pendingScroll = saved.y;
        reopened = pathOf(tab);
        // Адрес ставим сами: визит restore историю не пишет, а таб — замена записи, как и прежде. Запись — новая, со своим
        // ключом восстановления: с ключом покинутой страницы `keepPosition` (lib/scroller.js) счёл бы показ её «Назад» и
        // поставил бы её прокрутку поверх места раздела (Почта на 900 → «Предложения» вставали на 900, а не на 2500).
        const id = crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        Turbo.session.history.replace(url, id);
        Turbo.navigator.startVisit(url, id, { action: 'restore', historyChanged: true, direction: 'none' });
        return true;
    }
    pendingScroll = saved.y;
    // Снимка нет (первое открытие после запуска) — пока едет страница, на её месте силуэт экрана (`lib/shape.js`).
    arm(url.href);
    Turbo.visit(url.href, { action: 'replace' });
    return true;
}

// Адреса разделов из таб-бара (`data-match`): какому разделу принадлежит путь — самое точное совпадение.
const tabMatches = () => [...document.querySelectorAll('#tabbar a.tab[data-match]')].map((a) => [a.getAttribute('href'), a.dataset.match.split(' ')]);
function tabOfPath(path, tabs = tabMatches()) {
    let best = null;
    for (const [href, matches] of tabs) {
        for (const m of matches) if (m && (m === '/' ? path === '/' : path.startsWith(m)) && (!best || m.length > best[1])) best = [href, m.length];
    }
    return best ? pathOf(best[0]) : null;
}

/** Событие по адресам (`refresh`, `rows`, `card`): разделы, которых нет на экране, перечитаются при возврате. */
export function markDirty(paths) {
    const tabs = tabMatches();
    for (const path of paths ?? []) {
        if (path === location.pathname) continue;
        const tab = tabOfPath(path, tabs);
        if (tab) dirty.set(tab, Date.now());
    }
}

/**
 * Экран открыт возвратом в раздел (таб, капсула шапки), а не переходом вглубь: запись истории перед ним — чужая (её
 * заменил адрес раздела), и «‹» шагом истории увёл бы в другой раздел («чат → Предложения → машина → таб Чаты → ‹»
 * открывал список предложений). `back_controller` с такого экрана идёт по ссылке, к корню своего раздела.
 */
export const cameBack = () => returned !== null && returned === keyOf(location.href);

/**
 * Страница объекта, открытая из чужого раздела, остаётся в нём (18.11.2026, сайт: машина из «Галереи» и «Избранного»
 * считалась «Предложениями» — подсветка прыгала, и «Предложения» потом открывали её вместо своего списка). Как в
 * приложении: ссылка внутри раздела ведёт вглубь раздела. Ссылке на страницу с номером, чей путь — другого раздела,
 * в момент касания (до запроса по касанию) дописывается `in=<раздел>`; сервер ставит по нему раздел страницы
 * (`Nav::origin`), а с ним — подсветку, «назад» и память раздела. Корни разделов не трогаются: ссылка на список — переход.
 * Раздел, чьи экраны всегда свои (`data-own` у таба: «Чаты» — чат из «Предложений» остаётся чатом), не усыновляется.
 * `target="_top"` — та же навигация (ссылка изнутри фрейма: машина из полосы чата), внешней её делает только другое окно.
 */
function adopt(link) {
    const here = tabNow();
    if (!here || (link.target && link.target !== '_top' && link.target !== '_self') || link.hasAttribute('download') || link.origin !== location.origin || link.closest('#tabbar, a[data-section]')) return;
    const url = new URL(link.href);
    const target = tabOfPath(url.pathname);
    if (target && target !== pathOf(here) && document.querySelector(`#tabbar a.tab[data-own][href="${CSS.escape(target)}"]`)) return;
    const mine = link.dataset.in; // раздел, что дописали мы: ссылка в несменяемом куске (карточка уведомления) переживает переход
    if (!/\/\d+(\/|$)/.test(url.pathname) || url.searchParams.has('list') || (url.searchParams.has('in') && mine === undefined)) return;
    const foreign = (target ?? pathOf(here)) !== pathOf(here);
    if (foreign ? mine === here : mine === undefined) return;
    if (foreign) { url.searchParams.set('in', pathOf(here)); link.dataset.in = here; } else { url.searchParams.delete('in'); delete link.dataset.in; }
    link.setAttribute('href', url.pathname + url.search + url.hash);
}

/**
 * Нажали раздел, что на экране, пока едет переход в другой: человек передумал — переход останавливается, раздел
 * остаётся как был (18.11.2026: нажатие считалось «к началу раздела», а переход доезжал и уводил). true — остановили.
 */
export function stay() {
    if (!leaving()) return false;
    stopVisit();
    hideGhost();
    pendingScroll = undefined;
    reopened = null;
    action = null;
    clearTimeout(warm);
    return true;
}

// Переход по ссылке не начался: Turbo его запретил (`turbo:before-visit` отменён — несохранённое в окне). Слушатель на window
// в фазе всплытия — после всех слушателей document, отмена к этому моменту уже видна.
function stayed(revert) {
    const off = () => { removeEventListener('turbo:before-visit', check); document.removeEventListener('turbo:visit', off); };
    const check = (event) => { off(); if (event.defaultPrevented) revert(); };
    addEventListener('turbo:before-visit', check);
    document.addEventListener('turbo:visit', off);
    setTimeout(off, 3000);
}

export function sections({ onStale } = {}) {
    refresh = onStale ?? refresh;
    user = who();
    here = { tab: tabNow(), url: location.href };
    document.addEventListener('address:change', (event) => { if (here) here.url = event.detail.url; });

    // Уходим с экрана: старая страница ещё на месте — её прокрутка и адрес и есть место раздела.
    document.addEventListener('turbo:before-render', (event) => {
        if (event.detail.isPreview || !here) return;
        remember();
        if (here.tab) { left.unshift({ tab: here.tab, url: keyOf(here.url) }); left.length = Math.min(left.length, 4); }
    });
    // Визит уже пошёл (нажатие без задержки) — он сам и есть запрос, второго заранее не шлём.
    document.addEventListener('turbo:visit', (event) => { action = event.detail.action; clearTimeout(warm); });
    document.addEventListener('turbo:render', () => {
        if (pendingScroll === undefined) return;
        holdY(pendingScroll);
        if (reopened) shownNow();
    });
    document.addEventListener('turbo:load', () => {
        // Вошёл другой человек («Войти как», выход и вход) — снимки прежнего не показываем.
        if (user !== who()) { user = who(); pins.clear(); dirty.clear(); }
        here = { tab: tabNow(), url: location.href };
        if (returned !== null && returned !== keyOf(location.href)) returned = null;
        if (pendingScroll !== undefined) {
            // Отрисовка может дорасти (картинки, шрифт, куски за экраном) — место держит `holdY`, пока экран не тронули.
            pendingScroll = undefined;
        }
        // «Назад» на место раздела (из чата, из карточки): прокрутку ставит `keepPosition` по записи истории; не встала
        // (окно строки перехватило, запись сменилась) — место раздела то же, ставим по нему.
        if (action === 'restore' && !reopened && here.tab) {
            const place = read(sessionStorage, here.tab);
            if (place?.url === here.url && place.y > 0) {
                requestAnimationFrame(() => requestAnimationFrame(() => { if (scrollTop() < 2) holdY(place.y); }));
            }
        }
        action = null;
        const tab = reopened;
        reopened = null;
        if (here.tab) {
            const stale = dirty.has(pathOf(here.tab));
            dirty.delete(pathOf(here.tab));
            if (tab && stale) refresh();
        }
    });
    // Разделы в шапке ПК (`a[data-section]`) — тем же правилом, что табы телефона: где оставили, сразу из снимка.
    // Ссылка раздела, что уже на экране, — обычная: к его корню.
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[data-section]');
        if (!link || event.defaultPrevented || event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const href = link.getAttribute('href');
        // Раздел загорается под кликом, не дожидаясь страницы: шапка не постоянная и пришла бы только с ответом. Переход
        // не пошёл («Сохранить изменения?» → «Остаться», запрет перехода) — подсветка возвращается на раздел экрана.
        const was = [...document.querySelectorAll('a[data-section][aria-current]')];
        const light = (on) => { for (const a of document.querySelectorAll('a[data-section]')) on.includes(a) ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current'); };
        // Раздел экрана, пока едет переход в другой, — «остаться»: переход останавливается, подсветка возвращается.
        if (here?.tab === href && stay()) { event.preventDefault(); light([...document.querySelectorAll('a[data-section]')].filter((a) => a.getAttribute('href') === href)); return; }
        light([link]);
        const revert = () => light(was);
        // Раздел с `data-go` ведём сами — сразу на его вкладку; без него переход по ссылке делает Turbo.
        const direct = (to) => {
            if (!link.dataset.go) { arm(link.href); stayed(revert); return; }
            event.preventDefault();
            arm(to);
            stayed(revert);
            Turbo.visit(to);
        };
        if (here?.tab === href) { forget(href); direct(goOf(link)); return; }
        const saved = recall(href);
        if (!saved) { direct(goOf(link)); return; }
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('section:leave'));
        overlays.clear().then((ok) => { if (!ok || !open(href, saved)) revert(); });
    });
    // Раздел с памятью открывается из снимка или по запомненному адресу — заранее тянуть его корень незачем
    // (на ПК наведение на раздел в шапке стоило серверу двух страниц: `/offers` и редирект на вкладку).
    document.addEventListener('turbo:before-prefetch', (event) => {
        const link = event.target.closest?.('a[data-section], #tabbar a.tab');
        // Раздел со своей вкладкой (`data-go`) Turbo тянул бы по ссылке — корень с редиректом, который не понадобится.
        if (link && (link.dataset.go || recall(link.getAttribute('href')))) event.preventDefault();
    });
    // Раздел без снимка откроется с сервера — запрос уходит по касанию, не дожидаясь отпускания пальца.
    document.addEventListener('pointerdown', (event) => {
        const link = event.target.closest?.('#tabbar a.tab, a[data-section]');
        const href = link?.getAttribute('href');
        if (!href || here?.tab === href || href.startsWith('http')) return;
        const saved = recall(href);
        if (saved && pins.get(pathOf(href))?.url === keyOf(saved.url)) return;
        // Следующим тактом: сначала экран отвечает на нажатие (силуэт), запрос стартует сразу за ним.
        const url = saved?.url ?? goOf(link);
        clearTimeout(warm);
        warm = setTimeout(() => prefetch(url), 0);
    }, { capture: true, passive: true });
    addEventListener('pagehide', remember);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') remember(); });
    // Раздел ссылки — до всего, что читает её адрес: `pointerover` раньше касания (запрос по касанию) и наведения Turbo.
    if (document.querySelector('meta[name="surface"]')?.content === 'site') {
        const own = (event) => { const link = event.target.closest?.('a[href]'); if (link) adopt(link); };
        for (const name of ['pointerover', 'pointerdown', 'focusin']) document.addEventListener(name, own, { capture: true, passive: true });
    }

    // Снимок покинутого экрана Turbo кладёт в свой кэш через такт после ухода — забираем его себе, по разделу.
    const cache = Turbo.session.view?.snapshotCache;
    if (!cache) return;
    const put = cache.put.bind(cache);
    cache.put = (location, snapshot) => {
        const result = put(location, snapshot);
        const from = left.find((l) => l.url === keyOf(location));
        if (from && snapshot?.element && snapshot.element.dataset.navTab === from.tab
            && snapshot.element.getElementsByTagName('*').length <= HEAVY) {
            pins.set(pathOf(from.tab), { url: from.url, snapshot, at: Date.now() });
        }
        return result;
    };
    // Кэш Turbo стирают, когда снимки показывать нельзя (замок устройства) — наши вместе с ним.
    const clear = cache.clear.bind(cache);
    cache.clear = () => { pins.clear(); return clear(); };
}
