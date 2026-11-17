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
import { shown as markShown } from './perf';
import { colored } from './ghost';

const PREFIX = 'shape:';
const LIMIT = 30_000;     // знаков на силуэт
const MAX_NODES = 2500;   // сколько элементов смотрим: первый экран списка, а не 300 строк
const MAX_RECTS = 500;
const shapes = new Map();
let layer = null;
let timer = 0;
let armed = null;
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
        if (tag === 'script' || tag === 'template' || tag === 'dialog' || tag === 'turbo-frame' && el.id === 'detail') return;
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return;
        const boxless = !r.width && !r.height;
        // Ниже первого экрана — ни сам, ни дети (у `contents` коробки нет — смотрим детей).
        if (!boxless && (r.top >= box.bottom || r.bottom <= box.top - 200)) return;
        let radius = parseFloat(cs.borderTopLeftRadius) || 0;
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

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

function draw(rects) {
    return rects.map(([kind, x, y, w, h, r, extra]) => {
        const at = `left:${x}px;top:${y}px;width:${w}px;height:${h}px;border-radius:${r}px`;
        if (kind === 'h') return `<b style="left:${x}px;top:${y}px;height:${h}px;line-height:${h}px;font-size:${extra[1]}px;font-weight:${extra[2]}">${esc(extra[0])}</b>`;
        if (kind === 'c') return `<i style="${at};background:${esc(extra)}"></i>`;
        return `<i class="${kind === 'f' ? 'f' : 'g'}" style="${at}"></i>`;
    }).join('');
}

/** Показать силуэт экрана по адресу, куда идём. false — показывать не на чем (страница без `#scroller`). */
export function show(href) {
    const scroller = document.getElementById('scroller');
    if (!scroller) return false;
    hide();
    const box = scroller.getBoundingClientRect();
    const shape = keysOf(href).map(load).find(Boolean);
    // Силуэт снят при этой ширине (ключ) — высота экрана могла смениться (клавиатура, поворот): лишнее обрежет слой.
    const rects = shape ? shape.slice(1) : fallback(box.width, box.height);
    layer = document.createElement('div');
    layer.className = 'screen-ghost';
    layer.dataset.sectionGhost = '';
    layer.setAttribute('aria-hidden', 'true');
    layer.style.cssText = `top:${box.top}px;left:${box.left}px;width:${box.width}px;height:${box.height}px;background:${shape ? shape[0][2] : getComputedStyle(document.body).backgroundColor}`;
    layer.innerHTML = draw(rects);
    document.documentElement.append(layer);
    // Полоса загрузки Turbo под шапкой при силуэте лишняя: экран уже ответил.
    document.documentElement.classList.add('ghost-on');
    clearTimeout(timer);
    timer = setTimeout(hide, 20_000);
    return true;
}

export function hide() {
    clearTimeout(timer);
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
export function arm(href, { exact = false } = {}) {
    armed = { href: new URL(href, location.href).href, exact, at: performance.now() };
}

export function shape() {
    document.addEventListener('turbo:visit', (event) => {
        const go = armed;
        armed = null;
        if (!go || performance.now() - go.at > 2500 || event.detail.action === 'restore') return;
        if (go.exact && !load(keysOf(event.detail.url)[0])) return;
        if (show(event.detail.url)) markShown();
    });
    // Пилюли экрана (Входящие / Отправленные, Нам должны / Мы должны): тот же экран с другим списком.
    document.addEventListener('click', (event) => {
        const pill = event.target.closest?.('a.pill[href]');
        if (pill && !pill.hasAttribute('aria-current') && !event.defaultPrevented) arm(pill.href, { exact: true });
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
