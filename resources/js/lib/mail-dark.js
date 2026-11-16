// Свёрстанное письмо в тёмной теме (17.11.2026, владелец: «фон тоже в чёрный, текст обрабатывать должным образом, не выкидывая
// форматирование»). Готового скрипта нет — почтовики держат свои алгоритмы при себе (Outlook — `fixContrast`, Apple Mail —
// обращение светлоты HSL), здесь то же: по вычисленным стилям каждого узла письма
// - фон: почти белый — прозрачный (письмо ложится на тёмный лист окна), остальной светлый — той же краски, но тёмный
//   (светлота отражается: бледно-жёлтый → тёмно-жёлтый), тёмный и средний — как был (жёлтая заливка-выделение остаётся);
// - текст: если на итоговом фоне читается (контраст 4,5) — как был, иначе той же краски с отражённой светлотой (тёмно-синий →
//   светло-синий), и, если всё ещё не читается, — светлее до читаемого;
// - рамки — светлые темнеют так же, как фон. Картинки не трогаются.
// Прежний `style` узла помнится в `data-xl-style`: светлая тема возвращает письмо как было, без перезагрузки.

const ORIGINAL = 'data-xl-style';

function parse(value) {
    const m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)/i.exec(value || '');
    if (!m) return null;
    let a = m[4] === undefined ? 1 : parseFloat(m[4]);
    if (m[4]?.endsWith('%')) a /= 100;
    return { r: +m[1], g: +m[2], b: +m[3], a };
}

const css = ({ r, g, b, a = 1 }) => (a < 1 ? `rgba(${r}, ${g}, ${b}, ${a})` : `rgb(${r}, ${g}, ${b})`);

function luminance({ r, g, b }) {
    const ch = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
}

function contrast(x, y) {
    const [a, b] = [luminance(x), luminance(y)].sort((p, q) => q - p);
    return (a + 0.05) / (b + 0.05);
}

function toHsl({ r, g, b }) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
    if (max === min) return { h: 0, s: 0, l };
    const d = max - min, s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    const h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
    return { h: h / 6, s, l };
}

function fromHsl({ h, s, l }, a = 1) {
    if (s === 0) { const v = Math.round(l * 255); return { r: v, g: v, b: v, a }; }
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
    const f = (t) => {
        t = (t + 1) % 1;
        if (t < 1 / 6) return p + (q - p) * 6 * t;
        if (t < 1 / 2) return q;
        if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
        return p;
    };
    return { r: Math.round(f(h + 1 / 3) * 255), g: Math.round(f(h) * 255), b: Math.round(f(h - 1 / 3) * 255), a };
}

// Светлый фон → тёмный той же краски; почти белый — прозрачный. null — фона нет.
function darkBackground(c) {
    if (!c || c.a === 0) return null;
    const hsl = toHsl(c);
    if (hsl.l > 0.9 && hsl.s < 0.25) return { r: 0, g: 0, b: 0, a: 0 };
    if (hsl.l <= 0.55) return c;
    return fromHsl({ ...hsl, l: Math.max(0.1, 1 - hsl.l) }, c.a);
}

// Текст на тёмном фоне: читается — как был; нет — отражённая светлота; всё ещё нет — светлее до контраста 4,5.
function readable(c, bg) {
    if (!c || c.a === 0 || contrast(c, bg) >= 4.5) return c;
    const hsl = toHsl(c);
    const dark = luminance(bg) < 0.18;
    let l = dark ? Math.max(hsl.l, 1 - hsl.l) : Math.min(hsl.l, 1 - hsl.l);
    let out = fromHsl({ ...hsl, l }, c.a);
    for (let i = 0; i < 20 && contrast(out, bg) < 4.5; i++) {
        l = dark ? Math.min(1, l + 0.04) : Math.max(0, l - 0.04);
        out = fromHsl({ ...hsl, l }, c.a);
    }
    return out;
}

// Непрозрачный фон над рамкой письма в приложении — основа, на которой письмо читается.
function pageBackground(frame) {
    for (let el = frame; el; el = el.parentElement) {
        const c = parse(getComputedStyle(el).backgroundColor);
        if (c && c.a > 0.5) return c;
    }
    return { r: 28, g: 28, b: 30, a: 1 };
}

export function darken(doc, frame) {
    if (!doc?.body || doc.documentElement.dataset.xlDark === '1') return;
    const win = doc.defaultView;
    const base = pageBackground(frame);
    const walk = (el, under) => {
        const cs = win.getComputedStyle(el);
        const set = [];
        const bg = parse(cs.backgroundColor);
        const nbg = darkBackground(bg);
        let here = under;
        if (nbg) {
            set.push(['background-color', css(nbg)]);
            if (nbg.a > 0.5) here = nbg;
        }
        const color = parse(cs.color);
        const ncolor = readable(color, here);
        if (ncolor && ncolor !== color) set.push(['color', css(ncolor)]);
        for (const side of ['top', 'right', 'bottom', 'left']) {
            if (cs.getPropertyValue(`border-${side}-style`) === 'none' || parseFloat(cs.getPropertyValue(`border-${side}-width`)) === 0) continue;
            const b = parse(cs.getPropertyValue(`border-${side}-color`));
            if (b && toHsl(b).l > 0.55) set.push([`border-${side}-color`, css(fromHsl({ ...toHsl(b), l: Math.max(0.2, 1 - toHsl(b).l) }, b.a))]);
        }
        if (set.length) {
            if (!el.hasAttribute(ORIGINAL)) el.setAttribute(ORIGINAL, el.getAttribute('style') ?? '');
            for (const [prop, value] of set) el.style.setProperty(prop, value, 'important');
        }
        for (const child of el.children) {
            if (child.localName === 'img' || child.localName === 'svg' || child.localName === 'style' || child.localName === 'script') continue;
            walk(child, here);
        }
    };
    walk(doc.documentElement, base);
    doc.documentElement.dataset.xlDark = '1';
}

export function restore(doc) {
    if (!doc?.documentElement || doc.documentElement.dataset.xlDark !== '1') return;
    for (const el of doc.querySelectorAll(`[${ORIGINAL}]`)) {
        const was = el.getAttribute(ORIGINAL);
        if (was) el.setAttribute('style', was); else el.removeAttribute('style');
        el.removeAttribute(ORIGINAL);
    }
    delete doc.documentElement.dataset.xlDark;
}
