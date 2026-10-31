// Геометрия схемы парковки — та же, что `App\Park\Plan`: ряд `{id, name, from, n, x, y, dir, w, l, holes, rev}` в метрах,
// места бок о бок вправо (h) или вниз (v), убранные — по номеру. Здесь — для редактора: коды, прямоугольники, прилипание.
export const W = 2.5;
export const L = 5;
export const AISLE = 6;
export const MAX_N = 200;
export const STEP = 0.25;

export const snap = (v, step = STEP) => Math.round(v / step) * step;
export const code = (name, num) => (name ? `${name}-${num}` : String(num));
export const num = (row, k) => (row.rev ? row.from + row.n - 1 - k : row.from + k);

// Место по порядку k: номер, код, прямоугольник, убрано ли.
export function spot(row, k) {
    const n = num(row, k);
    const rect = row.dir === 'h'
        ? { x: row.x + k * row.w, y: row.y, w: row.w, h: row.l }
        : { x: row.x, y: row.y + k * row.w, w: row.l, h: row.w };
    return { k, num: n, code: code(row.name, n), hole: row.holes.includes(n), ...rect };
}

export const spots = (row) => Array.from({ length: row.n }, (_, k) => spot(row, k));

export function box(row) {
    const long = row.n * row.w;
    return row.dir === 'h'
        ? { x0: row.x, y0: row.y, x1: row.x + long, y1: row.y + row.l }
        : { x0: row.x, y0: row.y, x1: row.x + row.l, y1: row.y + long };
}

// Прямоугольники рядов (`box`) налезают друг на друга — касание краем не в счёт.
export const overlaps = (a, b) => a.x0 < b.x1 - 0.01 && b.x0 < a.x1 - 0.01 && a.y0 < b.y1 - 0.01 && b.y0 < a.y1 - 0.01;

export function bounds(rows) {
    if (!rows.length) return null;
    const b = rows.map(box);
    return { x0: Math.min(...b.map((r) => r.x0)), y0: Math.min(...b.map((r) => r.y0)), x1: Math.max(...b.map((r) => r.x1)), y1: Math.max(...b.map((r) => r.y1)) };
}

// Следующая свободная буква ряда: A…Z, потом AA…
export function nextName(rows) {
    const used = new Set(rows.map((r) => r.name));
    for (let i = 0; i < 26 * 27; i++) {
        const name = i < 26 ? String.fromCharCode(65 + i) : String.fromCharCode(64 + Math.floor(i / 26)) + String.fromCharCode(65 + (i % 26));
        if (!used.has(name)) return name;
    }
    return '';
}

export const newId = () => `r${Date.now().toString(36).slice(-6)}${Math.random().toString(36).slice(2, 5)}`;

// Прилипание к краям и серединам других рядов: ближайшее по каждой оси в пределах tol. → { dx, dy, guides }
export function magnet(moving, others, tol) {
    const b = box(moving);
    const xs = [b.x0, (b.x0 + b.x1) / 2, b.x1];
    const ys = [b.y0, (b.y0 + b.y1) / 2, b.y1];
    let best = { x: null, y: null };
    for (const o of others) {
        const ob = box(o);
        for (const t of [ob.x0, (ob.x0 + ob.x1) / 2, ob.x1]) {
            for (const v of xs) if (Math.abs(t - v) <= tol && (best.x === null || Math.abs(t - v) < Math.abs(best.x.d))) best.x = { d: t - v, at: t, o: ob };
        }
        for (const t of [ob.y0, (ob.y0 + ob.y1) / 2, ob.y1]) {
            for (const v of ys) if (Math.abs(t - v) <= tol && (best.y === null || Math.abs(t - v) < Math.abs(best.y.d))) best.y = { d: t - v, at: t, o: ob };
        }
    }
    const guides = [];
    const nb = { x0: b.x0 + (best.x?.d ?? 0), x1: b.x1 + (best.x?.d ?? 0), y0: b.y0 + (best.y?.d ?? 0), y1: b.y1 + (best.y?.d ?? 0) };
    if (best.x) guides.push({ x1: best.x.at, x2: best.x.at, y1: Math.min(nb.y0, best.x.o.y0), y2: Math.max(nb.y1, best.x.o.y1) });
    if (best.y) guides.push({ y1: best.y.at, y2: best.y.at, x1: Math.min(nb.x0, best.y.o.x0), x2: Math.max(nb.x1, best.y.o.x1) });
    return { dx: best.x?.d ?? 0, dy: best.y?.d ?? 0, guides };
}

// Проезд до ближайшего ряда напротив (перекрываются по другой оси): { x1, y1, x2, y2, gap } или null.
export function aisle(moving, others) {
    const b = box(moving);
    let best = null;
    for (const o of others) {
        const ob = box(o);
        const overlapX = Math.min(b.x1, ob.x1) - Math.max(b.x0, ob.x0);
        const overlapY = Math.min(b.y1, ob.y1) - Math.max(b.y0, ob.y0);
        const cands = [];
        if (overlapX > 0) {
            const mx = (Math.max(b.x0, ob.x0) + Math.min(b.x1, ob.x1)) / 2;
            if (ob.y0 >= b.y1) cands.push({ gap: ob.y0 - b.y1, x1: mx, x2: mx, y1: b.y1, y2: ob.y0 });
            if (ob.y1 <= b.y0) cands.push({ gap: b.y0 - ob.y1, x1: mx, x2: mx, y1: ob.y1, y2: b.y0 });
        }
        if (overlapY > 0) {
            const my = (Math.max(b.y0, ob.y0) + Math.min(b.y1, ob.y1)) / 2;
            if (ob.x0 >= b.x1) cands.push({ gap: ob.x0 - b.x1, y1: my, y2: my, x1: b.x1, x2: ob.x0 });
            if (ob.x1 <= b.x0) cands.push({ gap: b.x0 - ob.x1, y1: my, y2: my, x1: ob.x1, x2: b.x0 });
        }
        for (const c of cands) if (c.gap > 0.01 && c.gap < 30 && (!best || c.gap < best.gap)) best = c;
    }
    return best;
}

// Машина на месте — значок её типа, как `x-park.plan-car`: квадрат 256 вписан в место стоя, без поворота. cat — тип
// (`passenger`, `truck`…) или пусто.
const ICONS = { passenger: 'cat-car', light: 'cat-van', truck: 'cat-truck', long: 'cat-long', trailer: 'cat-trailer', special: 'cat-special', moto: 'cat-moto' };
export function carIcon(s, cat, sprite) {
    const size = Math.min(s.w * 0.84, (s.h * 0.8) / 0.56);
    const cx = s.x + s.w / 2;
    const cy = s.y + s.h / 2;
    return `<g class="plan-car cat-${cat || 'unknown'}"><svg class="i-s" viewBox="0 0 256 256" x="${cx - size / 2}" y="${cy - size / 2}" width="${size}" height="${size}"><use href="${sprite}#i-${ICONS[cat] || 'cat-car'}"/></svg></g>`;
}
