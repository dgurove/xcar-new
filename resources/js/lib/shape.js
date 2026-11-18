// Силуэт экрана — скелетон раздела в момент нажатия (17.11.2026, владелец: «сейчас когда нажимаешь на кнопку на нижнем
// тулбаре, сначала ничего не происходит… серо-белые переливающиеся заглушки, чтобы силуэты и на ПК, и на телефоне
// соответствовали тому, что появится»).
//
// Рисовать заглушку руками на каждый раздел, вкладку и ширину — значит, вечно её догонять. Силуэт снимается с настоящего
// экрана, когда он встал и стоит в покое: первый экран `#main` — прямоугольниками на месте строк текста, картинок и
// плашек. Текста в силуэте нет (в списках это имена и цены), кроме заголовка экрана `h1` — это название раздела. Лежит в
// памяти и в localStorage по ключу «хост, путь без номеров, вкладка, ширина, тема»: телефон и ПК, светлая и тёмная — у
// каждого свой, тот, что был на этом экране в прошлый раз. Силуэта ещё нет — общая заготовка списка.
//
// Показ — слоем поверх `#scroller`, ребёнком `<html>` вне `<body>` (как колесо вкладок): содержимое страницы не
// подменяется — иначе заготовка попала бы в снимок уходящего экрана и сбила память его прокрутки. Шапка и таб-бар
// остаются живыми. Слой снимают отрисовка новой страницы, ошибка запроса и запасной таймер.
//
// Заготовка страницы (18.11.2026, сайт: «нажал на машину — полсекунды ничего»; главное нажатие там не таб, а плитка).
// Силуэт взводится и на ссылку вглубь — плитка, строка сделки, пункт кабинета. У страницы объекта (номер в пути) в
// силуэте есть места: заголовок, обложка, цена (`h1` и `data-shape-slot`), своего текста и картинок в них нет — их
// заполняет нажатая плитка тем, что уже на экране: названием, ценой и кадром, который браузер уже держит в памяти.
// Настоящая страница встаёт под слой, и её первый кадр получает ту же обложку фоном, пока грузится свой.
import { shown as markShown } from './perf';
import { colored } from './ghost';
import { prefetchable } from '../touch-prefetch';

const PREFIX = 'shape:';
const LIMIT = 30_000;     // знаков на силуэт
const MAX_NODES = 2500;   // сколько элементов смотрим: первый экран списка, а не 300 строк
const MAX_RECTS = 500;
const shapes = new Map();
let layer = null;
let timer = 0;
let armed = null;
let cover = null;        // кадр нажатой плитки: первый кадр новой страницы получит его фоном
const taken = new Map(); // ключ силуэта → когда снят в этой сессии

const theme = () => (document.documentElement.classList.contains('dark') ? 'd' : 'l');
// Ширина окна — из памяти: `innerWidth` на свежей странице заставляет браузер тут же посчитать её стили и раскладку
// (18.11.2026, трассировка «плитка → машина»: 90 мс на телефоне в обработчике загрузки).
let viewport = innerWidth;
addEventListener('resize', () => { viewport = innerWidth; }, { passive: true });
// Вкладка экрана: колесо CRM, ящик почты, вкладка — и пилюли сайта (`view`: «Новые», «Горящие», заявки и архив сделок).
const tabOf = (url) => url.searchParams.get('preset') ?? url.searchParams.get('box') ?? url.searchParams.get('tab') ?? url.searchParams.get('view') ?? '';

// Свой ключ экрана и запасной — «последний экран этого пути»: ссылка таба ведёт на корень раздела без вкладки.
function keysOf(href) {
    const url = new URL(href, location.href);
    const base = `${url.host}${url.pathname.replace(/\d+/g, 'N')}`;
    const tail = `${Math.round(viewport / 8) * 8}${theme()}`;
    return [`${base}|${tabOf(url)}|${tail}`, `${base}||${tail}`];
}

function load(key) {
    if (shapes.has(key)) return shapes.get(key);
    let shape = null;
    try { shape = JSON.parse(localStorage.getItem(PREFIX + key)); } catch {}
    if (Array.isArray(shape)) shapes.set(key, shape);
    return Array.isArray(shape) ? shape : null;
}

// Серое — не цветное (`lib/ghost.js`: то же правило у силуэта окна строки): лайм и красный в силуэте серым.
const grey = (value) => !colored(value);
const solid = (value) => {
    if (!value || value === 'transparent') return false;
    const n = (value.match(/[\d.]+/g) ?? []).map(Number);
    return value.startsWith('oklch') ? (n[3] ?? 1) > 0.9 : (n[3] ?? 1) > 0.9;
};

/** Снять силуэт экрана, что на виду: только сверху страницы и в покое (без окна строки и шторок). */
export function capture() {
    const scroller = document.getElementById('scroller');
    const main = document.getElementById('main');
    if (!scroller || !main || scroller.scrollTop > 4 || document.querySelector('dialog:modal')
        || document.documentElement.classList.contains('detail-open') || document.body.classList.contains('chat-open')) return;
    const box = scroller.getBoundingClientRect();
    if (box.width < 200 || box.height < 200) return;
    const page = getComputedStyle(document.body).backgroundColor;
    const out = [];
    let seen = 0;
    const add = (kind, r, radius, extra) => {
        if (out.length >= MAX_RECTS || r.width < 3 || r.height < 3 || r.bottom <= box.top || r.top >= box.bottom || r.right <= box.left || r.left >= box.right) return;
        const row = [kind, Math.round(r.left - box.left), Math.round(r.top - box.top), Math.round(r.width), Math.round(r.height), Math.round(radius) || 0];
        if (extra !== undefined) row.push(extra);
        out.push(row);
    };
    // Страница объекта (номер в пути): её заголовок и размеченные места — не текст, а место под данные нажатой плитки.
    const object = /\d/.test(location.pathname);
    const range = document.createRange();
    // Обрезка по предку с `overflow`: у строки с «…» коробки текста идут за видимый край.
    const cut = (r, clip) => {
        const left = Math.max(r.left, clip.left), right = Math.min(r.right, clip.right);
        const top = Math.max(r.top, clip.top), bottom = Math.min(r.bottom, clip.bottom);
        return { left, right, top, bottom, width: right - left, height: bottom - top };
    };
    const walk = (el, clip) => {
        if (++seen > MAX_NODES || out.length >= MAX_RECTS) return;
        const tag = el.localName;
        // Шторка в силуэт не идёт — кроме открытой в потоке страницы (на ПК блок цены и действий машины — `<dialog open>`
        // без модальности: `sheet` `inflow`), это часть экрана.
        if (tag === 'script' || tag === 'template' || tag === 'dialog' && (!el.open || el.matches(':modal')) || tag === 'turbo-frame' && el.id === 'detail') return;
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return;
        const boxless = !r.width && !r.height;
        // Ниже первого экрана — ни сам, ни дети (у `contents` коробки нет — смотрим детей).
        if (!boxless && (r.top >= box.bottom || r.bottom <= box.top - 200)) return;
        let radius = parseFloat(cs.borderTopLeftRadius) || 0;
        const slot = el.dataset.shapeSlot ?? (object && tag === 'h1' ? 'title' : null);
        if (slot) {
            if (!boxless) add('s', cut(r, clip), Math.min(radius, r.height / 2), [slot, Math.round(parseFloat(cs.fontSize)), cs.fontWeight, Math.round(parseFloat(cs.lineHeight)) || 0, parseFloat(cs.letterSpacing) || 0]);
            return;
        }
        if (tag === 'img' || tag === 'svg' || tag === 'video' || tag === 'canvas' || tag === 'picture') {
            // Скругление аватара и логотипа часто держит обёртка того же размера.
            const wrap = el.parentElement?.getBoundingClientRect();
            if (!radius && wrap && Math.abs(wrap.width - r.width) <= 2 && Math.abs(wrap.height - r.height) <= 2) radius = parseFloat(getComputedStyle(el.parentElement).borderTopLeftRadius) || 0;
            if (r.width >= 12 && r.height >= 12) add('b', cut(r, clip), Math.min(radius || (tag === 'svg' ? 4 : 0), r.height / 2));
            return;
        }
        if (!boxless && (cs.overflowX !== 'visible' || cs.overflowY !== 'visible')) clip = cut(r, clip);
        if (tag === 'h1') {
            const text = el.textContent.trim().replace(/\s+/g, ' ');
            if (text && text.length <= 40) { add('h', r, 0, [text, Math.round(parseFloat(cs.fontSize)), cs.fontWeight]); return; }
        }
        // Плашка: карточка списка, пилюля, кнопка, поле. Цвет страницы — не плашка; цветное (лайм, красный) — серым.
        if (!boxless && solid(cs.backgroundColor) && cs.backgroundColor !== page && r.width * r.height >= 600) {
            add(grey(cs.backgroundColor) ? 'c' : 'f', cut(r, clip), Math.min(radius, r.height / 2), grey(cs.backgroundColor) ? cs.backgroundColor : undefined);
        }
        const size = parseFloat(cs.fontSize) || 15;
        for (const node of el.childNodes) {
            if (node.nodeType === 1) { walk(node, clip); continue; }
            if (node.nodeType !== 3 || !node.nodeValue.trim()) continue;
            range.selectNodeContents(node);
            for (const line of range.getClientRects()) {
                // Полоса тоньше строки: по высоте букв, а не межстрочного интервала.
                const h = Math.max(6, Math.round(size * 0.62));
                const seen = cut(line, clip);
                if (seen.width < 3 || seen.height < 3) continue;
                add('t', { left: seen.left, right: seen.right, top: line.top + (line.height - h) / 2, bottom: line.top + (line.height + h) / 2, width: seen.width, height: h }, Math.min(4, h / 2));
            }
        }
    };
    walk(main, box);
    if (out.length < 4) return;
    const shape = [[Math.round(box.width), Math.round(box.height), page], ...out];
    if (JSON.stringify(shape).length > LIMIT) return;
    for (const key of keysOf(location.href)) {
        shapes.set(key, shape);
        try { localStorage.setItem(PREFIX + key, JSON.stringify(shape)); } catch {}
    }
    return true;
}

// Общая заготовка списка, пока своего силуэта нет: заголовок, ряд кружков, плашка со строками в два этажа.
function fallback(w, h) {
    const phone = w < 768;
    const pad = phone ? 16 : 32;
    const out = [['t', pad, 30, Math.min(200, w * 0.45), 22, 6], ['b', pad, 74, 36, 36, 18], ['b', pad + 46, 74, 36, 36, 18], ['b', pad + 92, 74, 36, 36, 18]];
    const left = phone ? 0 : pad;
    const width = phone ? w : w - pad * 2;
    out.push(['c', left, 132, width, h, 24, 'var(--color-surface)']);
    for (let i = 0, y = 152; y < h - 20 && i < 16; i++, y += 50) {
        out.push(['t', left + 16, y, Math.round(width * (0.34 + (i % 3) * 0.09)), 10, 4]);
        out.push(['t', left + 16, y + 20, Math.round(width * (0.22 + (i % 2) * 0.08)), 8, 4]);
        out.push(['t', left + width - 16 - 56, y, 56, 10, 4]);
    }
    return out;
}

// Страница объекта, чьего силуэта ещё нет: заголовок, кадр 4:3 и строки фактов — места те же, что у настоящей.
// Кадр — только когда нажата плитка с большим фото: у строки (сделка, интерес) страница начинается не с кадра.
function fallbackPage(w, h, photo) {
    const pad = w < 768 ? 16 : 32;
    const width = Math.min(w - pad * 2, 720);
    const frame = photo ? Math.round(width * 0.75) : 0;
    const out = [['s', pad, 26, width, 30, 0, ['title', 22, '500', 28]]];
    if (frame) out.push(['s', pad, 118, width, frame, 16, ['cover']]);
    else out.push(['c', w < 768 ? 0 : pad, 100, w < 768 ? w : width, h, 24, 'var(--color-surface)']);
    const left = pad + (frame ? 0 : w < 768 ? 4 : 20);
    for (let i = 0, y = frame ? 118 + frame + 28 : 130; y < h - 20 && i < (frame ? 8 : 14); i++, y += 34) out.push(['t', left, y, Math.round(width * (0.5 - (i % 3) * 0.1)), 10, 4]);
    return out;
}

// Экран чата (без шапки и таб-бара, во весь экран): полоса собеседника, пузыри переписки, поле ввода. Своего силуэта у чата нет — лента у каждого своя, а каркас один.
function chatShape(w, h) {
    // Имени и кадра из строки тут нет: строка списка названа машиной, а шапка чата — собеседником.
    const out = [['c', 0, 0, w, 56, 0, 'var(--color-bar)'], ['b', 12, 10, 36, 36, 18], ['b', 58, 8, 40, 40, 20],
        ['t', 110, 14, Math.min(180, w - 150), 10, 4], ['t', 110, 35, 110, 8, 4]];
    const sides = [0, 1, 1, 0, 1, 0, 0];
    for (let i = 0, y = h - 96; y > 90 && i < sides.length; i++) {
        const width = Math.round(w * (0.34 + ((i * 7) % 4) * 0.09));
        const height = 40 + ((i * 5) % 3) * 22;
        y -= height;
        out.push(['b', sides[i] ? w - 16 - width : 16, y, width, height, 18]);
        y -= 10;
    }
    out.push(['c', 0, h - 72, w, 72, 0, 'var(--color-bar)'], ['b', 56, h - 60, w - 124, 44, 22], ['b', w - 56, h - 60, 44, 44, 22]);
    return out;
}
const isChat = (href) => /\/chats\/(offer\/|support)?\d*$/.test(new URL(href, location.href).pathname) && /\/chats\/./.test(new URL(href, location.href).pathname);

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

function draw(rects, fill) {
    return rects.map(([kind, x, y, w, h, r, extra]) => {
        const at = `left:${x}px;top:${y}px;width:${w}px;height:${h}px;border-radius:${r}px`;
        if (kind === 'h') return `<b style="left:${x}px;top:${y}px;height:${h}px;line-height:${h}px;font-size:${extra[1]}px;font-weight:${extra[2]}">${esc(extra[0])}</b>`;
        if (kind === 'c') return `<i style="${at};background:${esc(extra)}"></i>`;
        if (kind === 's') {
            const [name, size = 15, weight = '400', line = 0, spacing = 0] = extra;
            const value = fill?.[name];
            if (name === 'cover') return `<i class="g" style="${at}${value ? `;background:center / cover no-repeat url('${encodeURI(value).replace(/'/g, '%27')}'), var(--color-surface-3)` : ''}"></i>`;
            // Данных нет (пришли не с плитки) — полоса на месте первой строки, как у остального текста силуэта.
            const bar = Math.max(6, Math.round(size * 0.62));
            if (!value) return `<i class="g" style="left:${x}px;top:${y + Math.round(((line || h) - bar) / 2)}px;width:${Math.round(w * 0.6)}px;height:${bar}px;border-radius:4px"></i>`;
            // Ширина — до края экрана, а не коробки прежнего текста: у другой машины название длиннее.
            return `<b class="s" style="left:${x}px;top:${y}px;max-width:calc(100% - ${x + 16}px);font-size:${size}px;font-weight:${weight};letter-spacing:${spacing}px;line-height:${line ? `${line}px` : '1.25'}">${esc(value)}</b>`;
        }
        return `<i class="${kind === 'f' ? 'f' : 'g'}" style="${at}"></i>`;
    }).join('');
}

/**
 * Что нажатая плитка или строка уже показывает о странице, куда ведёт: название, цена, кадр. Только то, что на экране:
 * кадр — загруженный (его адрес браузер отдаст из памяти), текст — как написан. Ничего не хранится.
 */
function fillFrom(link) {
    const box = link.closest('.card, .row, .chat-row, tr') ?? link;
    const text = (el) => el?.textContent.replace(/\s+/g, ' ').trim() ?? '';
    const title = text(box.querySelector('[data-fill="title"], .card-name, .cell-name, .cell-title, .font-medium'));
    const price = text(box.querySelector('[data-fill="price"], .card-price'));
    const img = [...box.querySelectorAll('img')].find((i) => i.complete && i.naturalWidth > 40 && i.currentSrc);
    const fill = {};
    if (title && title.length <= 90) fill.title = title;
    if (price && price.length <= 40) fill.price = price;
    if (img) fill.cover = img.currentSrc;
    // Плитка с большим кадром — и страница начнётся с кадра (запасная заготовка, пока своего силуэта нет).
    if (img && box.matches('.card')) fill.large = true;
    return Object.keys(fill).length ? fill : null;
}

// Дом слоя — постоянный пустой узел в <html> рядом с <body>, заведённый при запуске. Сам слой прямо в <html> менял
// соседей у <body>: тот переставал быть последним ребёнком, и браузер пересчитывал стили сотен узлов страницы по
// правилам с `:last-child` и `:has()` — до первого кадра после нажатия (18.11.2026, трассировка: 800 узлов).
let host = null;
function home() {
    if (!host?.isConnected) {
        host = document.createElement('div');
        host.setAttribute('aria-hidden', 'true');
        document.documentElement.append(host);
    }
    return host;
}

/** Показать силуэт экрана по адресу, куда идём. false — показывать не на чем (страница без `#scroller`). */
export function show(href, fill = null) {
    const scroller = document.getElementById('scroller');
    if (!scroller) return false;
    hide();
    const chat = isChat(href);
    // Чат — во весь экран под строкой состояния: у него нет ни шапки, ни таб-бара.
    const box = chat ? { top: 0, left: 0, width: document.documentElement.clientWidth, height: document.documentElement.clientHeight } : scroller.getBoundingClientRect();
    const shape = chat ? null : keysOf(href).map(load).find(Boolean);
    const object = /\d/.test(new URL(href, location.href).pathname);
    // Силуэт снят при этой ширине (ключ) — высота экрана могла смениться (клавиатура, поворот): лишнее обрежет слой.
    const rects = shape ? shape.slice(1) : chat ? chatShape(box.width, box.height) : object ? fallbackPage(box.width, box.height, !!fill?.large) : fallback(box.width, box.height);
    layer = document.createElement('div');
    layer.className = chat ? 'screen-ghost screen-ghost--full' : 'screen-ghost';
    layer.dataset.sectionGhost = '';
    layer.setAttribute('aria-hidden', 'true');
    layer.style.cssText = chat ? '' : `top:${box.top}px;left:${box.left}px;width:${box.width}px;height:${box.height}px;background:${shape ? shape[0][2] : getComputedStyle(document.body).backgroundColor}`;
    layer.innerHTML = draw(rects, fill);
    home().append(layer);
    cover = chat ? null : fill?.cover ?? null;
    // Полоса загрузки Turbo под шапкой при силуэте лишняя: экран уже ответил.
    document.documentElement.classList.add('ghost-on');
    clearTimeout(timer);
    timer = setTimeout(hide, 20_000);
    return true;
}

export function hide() {
    clearTimeout(timer);
    cover = null;
    if (layer) document.documentElement.classList.remove('ghost-on');
    layer?.remove();
    layer = null;
}

export const shown = () => !!layer;

/**
 * Нажали раздел, вкладку или пилюлю — переход вот-вот начнётся: силуэт встанет, когда визит и правда пошёл (`turbo:visit`),
 * а не раньше — переход могут запретить («Сохранить изменения?»), и слой остался бы висеть.
 * exact — только свой силуэт экрана: общая заготовка списка пилюле не годится.
 */
export function arm(href, { exact = false, fill = null, strict = false } = {}) {
    armed = { href: new URL(href, location.href).href, exact, fill, strict, at: performance.now() };
}

// Ссылка вглубь приложения: плитка, строка, пункт кабинета, чат (у него свой каркас во весь экран — `chatShape`). Не
// таб и не раздел шапки (их взводит `sections`), не пилюля (ниже), не сосед «‹ ›» (тот же экран).
const pattern = (path) => path.replace(/\d+/g, 'N');
function deep(link) {
    if (!prefetchable(link) || link.closest('#tabbar, a[data-section], .tab-wheel, dialog') || link.matches('.pill, [rel="prev"], [rel="next"]')) return false;
    const url = new URL(link.href);
    // Чат на ПК открывается рядом со списком (фрейм — `prefetchable` его уже отсеял); страницей — на телефоне.
    return pattern(url.pathname) !== pattern(location.pathname);
}

/** Взвести заготовку страницы, на которую ведёт ссылка (строка таблицы без ссылки под пальцем — `row_go_controller`). */
export function armLink(link) {
    if (deep(link)) arm(link.href, { fill: fillFrom(link), strict: true });
}

export function shape() {
    home();
    document.addEventListener('turbo:visit', (event) => {
        const go = armed;
        armed = null;
        if (!go || performance.now() - go.at > 2500 || event.detail.action === 'restore') return;
        // Взвод ссылки — только под свой визит: нажатие могло открыть окно строки или шторку, а визит пришёл следом сам
        // (живое обновление) — заготовка чужой страницы легла бы поверх экрана.
        if (go.strict && new URL(event.detail.url).pathname !== new URL(go.href).pathname) return;
        if (go.exact && !load(keysOf(event.detail.url)[0])) return;
        if (show(event.detail.url, go.fill)) markShown();
    });
    // Пилюли экрана (Входящие / Отправленные, Нам должны / Мы должны): тот же экран с другим списком.
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        const link = event.target.closest?.('a[href]');
        if (!link) return;
        if (link.matches('a.pill')) { if (!link.hasAttribute('aria-current')) arm(link.href, { exact: true }); return; }
        armLink(link);
    });
    // Новая страница рисуется: её первый кадр получает обложку нажатой плитки фоном — пока грузится свой, серого нет.
    document.addEventListener('turbo:before-render', (event) => {
        const at = cover && !event.detail.isPreview ? event.detail.newBody?.querySelector('[data-shape-slot="cover"]') : null;
        if (at) at.style.background = `center / cover no-repeat url('${encodeURI(cover).replace(/'/g, '%27')}')`;
    });
    // Новая страница рисуется — слой больше не нужен; не доехала — тоже.
    document.addEventListener('turbo:before-render', (event) => { if (!event.detail.isPreview) hide(); });
    document.addEventListener('turbo:fetch-request-error', hide);
    // Экран встал — снять его силуэт, когда браузеру нечем заняться: к следующему открытию он уже готов. Не на каждую
    // отрисовку: живое обновление (морф) и «Назад» приносят тот же экран, а снятие — обход тысяч узлов со стилями и запись
    // в localStorage. Один раз за 10 минут на экран (ключ силуэта), и только после настоящей смены страницы.
    let morphed = false;
    document.addEventListener('turbo:render', (event) => { morphed = event.detail.renderMethod === 'morph'; });
    document.addEventListener('turbo:load', () => {
        hide();
        const skip = morphed;
        morphed = false;
        const key = keysOf(location.href)[0];
        if (skip || Date.now() - (taken.get(key) ?? 0) < 600_000) return;
        const snap = () => { try { if (capture()) taken.set(key, Date.now()); } catch {} };
        (window.requestIdleCallback ?? ((fn) => setTimeout(fn, 600)))(snap, { timeout: 4000 });
    });
}
