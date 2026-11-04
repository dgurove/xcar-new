// Линии и поток конвейера главной CRM (04.11.2026, `App\Space\Conveyor`): геометрия связей между карточками этапов. Линия —
// кубическая кривая Безье от края одной карточки к краю другой: по горизонтали — от бока к боку, по вертикали — от низа к
// верху. Встречные связи (оценка туда и обратно) расходятся в стороны. Линии рисует SVG слоя схемы (`paint`), поток по
// ним — WebGL того же холста, что звёзды (`Sky.setFlow`, `particles`): точка едет по той же кривой, положение считает
// шейдер от времени, поэтому кадр стоит одного вызова отрисовки на все точки.

const SPEED = 80;          // мировых px в секунду
const APART = 26;          // px между встречными линиями
const MARGIN = 60;         // px мира вокруг схемы — рамка «К схеме»
export const STRIDE = 12;  // A: p0, c1 · B: c2, p1 · T: фаза, скорость · S: размер, яркость

// Узлы схемы: центр и размер в координатах слоя. Карточка змейки стоит центром в своей точке (left/top — точка,
// translate центрирует), узел доски — где его поставила раскладка: смещения складываются до слоя. Transform слоя offset
// не трогает.
export function measure(world) {
    const boxes = new Map();
    for (const el of world.querySelectorAll('[data-node]')) {
        const { x, y } = offsetIn(el, world);
        const w = el.offsetWidth, h = el.offsetHeight;
        const centered = el.classList.contains('space-node');
        boxes.set(el.dataset.node, { x: centered ? x : x + w / 2, y: centered ? y : y + h / 2, w, h, total: Number(el.dataset.total) || 0, flow: Number(el.dataset.flow) || 0 });
    }
    return boxes;
}

// Рамка элемента в координатах слоя: `{ x0, y0, x1, y1 }` — доска целиком для камеры.
export function frameOf(el, world) {
    const { x, y } = offsetIn(el, world);
    return { x0: x, y0: y, x1: x + el.offsetWidth, y1: y + el.offsetHeight };
}

function offsetIn(el, root) {
    let x = 0, y = 0;
    for (let n = el; n && n !== root; n = n.offsetParent) { x += n.offsetLeft; y += n.offsetTop; }
    return { x, y };
}

export function bounds(boxes) {
    let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    for (const b of boxes.values()) {
        x0 = Math.min(x0, b.x - b.w / 2); x1 = Math.max(x1, b.x + b.w / 2);
        y0 = Math.min(y0, b.y - b.h / 2); y1 = Math.max(y1, b.y + b.h / 2);
    }
    return Number.isFinite(x0) ? { x0: x0 - MARGIN, y0: y0 - MARGIN, x1: x1 + MARGIN, y1: y1 + MARGIN } : null;
}

export function curves(links, boxes) {
    const pairs = new Set(links.map(([a, b]) => `${a}>${b}`));
    return links.flatMap(([from, to, kind]) => {
        const a = boxes.get(from), b = boxes.get(to);
        if (!a || !b) return [];
        const dx = b.x - a.x, dy = b.y - a.y;
        const side = Math.abs(dx) >= Math.abs(dy) * 1.1;
        // Встречная пара — каждая своей стороной: направление задаёт знак, сдвиг поперёк.
        const shift = pairs.has(`${to}>${from}`) ? (from < to ? APART : -APART) : 0;
        let p0, p1, c1, c2;
        if (side) {
            const s = Math.sign(dx) || 1;
            p0 = [a.x + (s * a.w) / 2, a.y + shift];
            p1 = [b.x - (s * b.w) / 2, b.y + shift];
            const bend = Math.max(40, Math.abs(p1[0] - p0[0]) * 0.5);
            c1 = [p0[0] + s * bend, p0[1]];
            c2 = [p1[0] - s * bend, p1[1]];
        } else {
            const s = Math.sign(dy) || 1;
            p0 = [a.x + shift, a.y + (s * a.h) / 2];
            p1 = [b.x + shift, b.y - (s * b.h) / 2];
            const bend = Math.max(40, Math.abs(p1[1] - p0[1]) * 0.5);
            c1 = [p0[0], p0[1] + s * bend];
            c2 = [p1[0], p1[1] - s * bend];
        }
        return [{ from, to, kind, p0, c1, c2, p1, len: length(p0, c1, c2, p1) }];
    });
}

// Длина кривой — ломаной по 16 точкам: точнее не нужно, по ней только скорость потока.
function length(p0, c1, c2, p1) {
    let sum = 0, prev = p0;
    for (let i = 1; i <= 16; i++) {
        const p = at({ p0, c1, c2, p1 }, i / 16);
        sum += Math.hypot(p[0] - prev[0], p[1] - prev[1]);
        prev = p;
    }
    return sum || 1;
}

export function at({ p0, c1, c2, p1 }, t) {
    const u = 1 - t;
    const a = u * u * u, b = 3 * u * u * t, c = 3 * u * t * t, d = t * t * t;
    return [a * p0[0] + b * c1[0] + c * c2[0] + d * p1[0], a * p0[1] + b * c1[1] + c * c2[1] + d * p1[1]];
}

export function paint(svg, list) {
    svg.innerHTML = list.map((c) => `<path class="is-${c.kind}" d="M${c.p0} C${c.c1} ${c.c2} ${c.p1}"/>`).join('');
}

// Точки потока: на линии их тем больше, чем больше машин у этапа-источника (у источников — пришедших сегодня), до шести;
// пусто — линия стоит. Подсказка (пунктир) без потока. Фаза — вразнобой, но ровно по линии.
export function particles(list, boxes) {
    const out = [];
    for (const c of list) {
        if (c.kind === 'hint') continue;
        const src = boxes.get(c.from);
        const load = src ? src.total || src.flow : 0;
        const n = load > 0 ? Math.min(6, 1 + Math.floor(Math.log2(load))) : 0;
        const speed = SPEED / c.len;
        for (let i = 0; i < n; i++) {
            out.push(...point(c, (i + 0.35 * fract(Math.sin((i + 1) * 12.9898 + c.len) * 43758.5453)) / n, speed, 1, c.kind === 'back' ? 0.5 : 0.85));
        }
    }
    return new Float32Array(out);
}

// Импульс — машина переехала: яркая голова и хвост из пяти точек позади, едет за `life` мс. `t` — где голова сейчас.
export function pulse(c, t) {
    const out = [];
    for (let k = 0; k < 6; k++) {
        const tk = t - k * 0.022;
        if (tk <= 0 || tk >= 1) continue;
        out.push(...point(c, tk, 0, 2.2 - k * 0.3, 1.6 - k * 0.25));
    }
    return out;
}

const point = (c, phase, speed, size, bright) => [...c.p0, ...c.c1, ...c.c2, ...c.p1, phase, speed, size, bright];
const fract = (v) => v - Math.floor(v);
