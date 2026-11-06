// Знаки бренда на главной CRM (06.11.2026, владелец: звёздное небо «по брендбуку не вписывалось» — вместо звёзд векторные
// элементы брендбука, фоном фото паркинга). Знаки — сетки квадратиков (план паркинга сверху: контур, заливка, серые, белые,
// лаймовые, тёмные), столбик крестиков (знак XCar) и ряд треугольников ▶▶▶ (движение, вывоз). Лежат группами в глубине за
// схемой и едут за камерой, как звёзды: знак на глубине d ∈ (0, 1) стоит на экране в `(p − камера·d) · z^d`.
// Оживление — «индикаторное», не случайное: по сеткам медленно идёт диагональная волна лайма, часть клеток дышит между двумя
// состояниями за десятки секунд, в столбике X и ряду ▶ лайм шагает по очереди, X и ▶ чуть покачиваются, сетки стоят.
// Производительность — главное (владелец: «это очень-очень важно»): один буфер WebGL, один вызов отрисовки точками, форму
// рисует фрагментный шейдер, окраску и покачивание — вершинный от времени; по кадру JS не считает ничего. Знаков сотни.
// Небо бесконечно: знаки слоя лежат в плитке и заворачиваются по модулю в шейдере. Без WebGL — `FlatMarks` на 2D, без
// оживления. Раскладка одна и та же при каждом заходе — генератор с постоянным зерном.
import { MIN } from './camera';

// Цвета — по номеру: 0 серый, 1 белый (в светлой теме — графит), 2 лайм, 3 тёмный синий, 4 пусто. Состояние знака — номер
// цвета + 8, если залит. Палитра по теме — uniform: смена темы ничего не пересчитывает.
const PALETTE = {
    dark: [[0.72, 0.74, 0.78, 0.42], [1, 1, 1, 0.82], [0.66, 0.8, 0.12, 1], [0.1, 0.13, 0.3, 0.75], [0, 0, 0, 0]],
    light: [[0.34, 0.37, 0.41, 0.34], [0.16, 0.17, 0.2, 0.62], [0.4, 0.59, 0.04, 0.95], [0.16, 0.2, 0.38, 0.28], [0, 0, 0, 0]],
};
const GRAY = 0, WHITE = 1, LIME = 2, NAVY = 3, NONE = 4, FILL = 8;
const SQUARE = 0, CROSS = 1, PLAY = 2;
// Оживление: 0 — стоит, 1 — волна по диагонали сетки, 2 — дышит, 3 — очередь в столбике или ряду.
const STILL = 0, WAVE = 1, BREATH = 2, TURN = 3;

// Слои: глубины, групп на миллион px² плитки, доли видов групп, размер знака и шаг (css px), сетка — столбцов и строк. Из
// трёх раскладок владелец выбрал «По краям» (06.11.2026): знаков много, но там, где доска, их нет — см. `mask`.
const LAYERS = [
    { d: [0.02, 0.045], groups: 6, kinds: { grid: 0.7, play: 0.3 }, size: [8, 10], step: [24, 28], grid: [[4, 7], [4, 7]] },
    { d: [0.06, 0.12], groups: 3.2, kinds: { grid: 0.35, cross: 0.2, play: 0.45 }, size: [10, 12], step: [28, 32], grid: [[3, 5], [3, 5]] },
    { d: [0.2, 0.3], groups: 0.6, kinds: { cross: 0.4, play: 0.6 }, size: [16, 20], step: [38, 44] },
];
// Доска гасит знаки под собой: рамки шапок и столбцов подэтапов (в координатах слоя схемы), знак ближе `CLEAR` px к рамке
// гаснет, за `CLEAR + FADE` — виден целиком. Рамок не больше `MAX_RECTS` (этапов девять, по две рамки).
const MAX_RECTS = 32;
const CLEAR = 10;
const FADE = 30;

const STRIDE = 16;         // x, y, d, сторона плитки · размер, вид, состояние A, B · оживление, номер, скорость, фаза · качание x, y, скорость, сколько в очереди
const MARGIN = 96;         // css px запаса за краем: группа у края не выскакивает
const MAX_PIXELS = 8.3e6;  // холст не больше 4K

// mulberry32 — короткий генератор с зерном.
function random(seed) {
    return () => {
        seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const lerp = (a, b, t) => a + (b - a) * t;
const pickBy = (rand, weights) => {
    let r = rand() * Object.values(weights).reduce((s, v) => s + v, 0);
    for (const [k, v] of Object.entries(weights)) if ((r -= v) <= 0) return k;
    return Object.keys(weights)[0];
};

// Клетка сетки: чаще серый контур; часть дышит в другое состояние, часть ловит волну лайма.
function cell(rand) {
    const r = rand();
    if (r < 0.24) return { a: rand() < 0.5 ? GRAY : WHITE, b: rand() < 0.55 ? LIME : LIME + FILL, anim: WAVE };
    if (r < 0.52) {
        const b = pickBy(rand, { [WHITE]: 3, [WHITE + FILL]: 2, [NAVY + FILL]: 2, [NONE]: 2, [GRAY + FILL]: 1 });
        return { a: GRAY, b: Number(b), anim: BREATH };
    }
    const a = pickBy(rand, { [GRAY]: 14, [WHITE]: 2, [NAVY + FILL]: 2, [NONE]: 2 });
    return { a: Number(a), b: Number(a), anim: STILL };
}

// Знаки под самый большой экран устройства; окно шире — `fits` говорит «мало», и раскладка считается заново.
export function marks(least = 0) {
    const side = Math.ceil((Math.max(screen.width || 0, screen.height || 0, innerWidth, innerHeight, least) + 2 * MARGIN) / 512) * 512;
    const rand = random(20261106);
    const out = [];
    const put = (x, y, d, tile, size, kind, st, anim, k, speed, phase, move = [0, 0, 0], n = 1) =>
        out.push(x, y, d, tile, size, kind, st.a, st.b, anim, k, speed, phase, move[0], move[1], move[2], n);

    for (const layer of LAYERS) {
        const tile = Math.ceil(side / MIN ** layer.d[1] / 64) * 64;
        // Телефон: экран мал, а доска закрывает его почти весь — групп гуще, иначе на свободном месте пусто.
        const count = Math.round((layer.groups * (side <= 1536 ? 1.6 : 1) * tile * tile) / 1e6);
        const placed = [];
        for (let g = 0, tries = 0; g < count && tries < count * 30; tries++) {
            const kind = pickBy(rand, layer.kinds);
            const size = lerp(layer.size[0], layer.size[1], rand());
            const step = lerp(layer.step[0], layer.step[1], rand());
            const cols = kind === 'grid' ? Math.round(lerp(layer.grid[0][0], layer.grid[0][1], rand())) : kind === 'play' ? 3 : 1;
            const rows = kind === 'grid' ? Math.round(lerp(layer.grid[1][0], layer.grid[1][1], rand())) : kind === 'cross' ? 3 : 1;
            const w = cols * step, h = rows * step;
            const x = rand() * tile, y = rand() * tile;
            // Группы слоя не налезают друг на друга (с запасом в шаг, по завёрнутой плитке).
            const near = (a, b, span) => Math.abs(((a - b + tile * 1.5) % tile) - tile / 2) < span;
            if (placed.some((p) => near(x + w / 2, p.x + p.w / 2, (w + p.w) / 2 + 2 * step) && near(y + h / 2, p.y + p.h / 2, (h + p.h) / 2 + 2 * step))) continue;
            placed.push({ x, y, w, h });
            g++;
            const d = lerp(layer.d[0], layer.d[1], rand());
            // Покачивание всей группой: X — вверх-вниз, ▶ — вперёд-назад; сетки стоят (владелец: «пусть квадратики не двигаются»).
            const sway = kind !== 'grid' && rand() < 0.7;
            const amp = lerp(3, 6, rand());
            const move = sway ? [kind === 'play' ? amp : 0, kind === 'play' ? 0 : amp, lerp(0.5, 0.9, rand())] : [0, 0, 0];
            const phase = rand() * 6.283;
            if (kind === 'grid') {
                const speed = lerp(0.25, 0.4, rand());
                const K = lerp(0.45, 0.7, rand());
                for (let j = 0; j < rows; j++) for (let i = 0; i < cols; i++) {
                    const c = cell(rand);
                    put(x + i * step, y + j * step, d, tile, size, SQUARE, c, c.anim, (i + j) * K,
                        c.anim === WAVE ? speed : lerp(0.2, 0.55, rand()), c.anim === WAVE ? phase : rand() * 6.283, move);
                }
            } else {
                // Очередь: лайм шагает по знакам — ▶ слева направо, X сверху вниз.
                const shape = kind === 'play' ? PLAY : CROSS;
                const speed = lerp(0.3, 0.45, rand());
                // Чаще серые: крупные ближние знаки не спорят со схемой, белые — изредка.
                const base = (rand() < 0.7 ? GRAY : WHITE) + FILL;
                for (let i = 0; i < 3; i++) {
                    put(x + (kind === 'play' ? i * step : 0), y + (kind === 'cross' ? i * step : 0), d, tile, size, shape,
                        { a: base, b: LIME + FILL }, TURN, i, speed, phase, move, 3);
                }
            }
        }
    }
    const data = new Float32Array(out);
    return { data, count: data.length / STRIDE, side };
}

export const fits = (set, w, h) => Math.max(w, h) + 2 * MARGIN <= set.side;

// Сколько физических пикселей на css: не больше 2 и не больше 4K на весь холст; `cap` — снижение по медленным кадрам.
export function pixelRatio(w, h, cap = 2) {
    return Math.max(1, Math.min(devicePixelRatio || 1, cap, Math.sqrt(MAX_PIXELS / Math.max(1, w * h))));
}

const VS = `
attribute vec4 aPos;
attribute vec4 aLook;
attribute vec4 aAnim;
attribute vec4 aMove;
uniform vec2 uCam;
uniform float uLogZ;
uniform vec2 uHalf;
uniform float uDpr;
uniform float uTime;
uniform float uMaxSize;
uniform vec4 uRects[${MAX_RECTS}];
uniform float uRectN;
uniform vec4 uP0;
uniform vec4 uP1;
uniform vec4 uP2;
uniform vec4 uP3;
uniform vec4 uP4;
varying vec4 vColor;
varying float vFill;
varying float vKind;
varying float vHalf;
varying float vShape;
vec4 pal(float i) {
    if (i < 0.5) return uP0;
    if (i < 1.5) return uP1;
    if (i < 2.5) return uP2;
    if (i < 3.5) return uP3;
    return uP4;
}
void main() {
    float d = aPos.z;
    float side = aPos.w;
    float t = uTime;
    // Покачивание группой — до заворачивания: группа на шве плитки качается целиком.
    vec2 sway = aMove.xy * sin(t * aMove.z + aAnim.w);
    vec2 rel = mod(aPos.xy + sway - uCam * d + 0.5 * side, side) - 0.5 * side;
    float k = exp(uLogZ * d);
    vec2 p = rel * k;
    gl_Position = vec4(p.x / uHalf.x, -p.y / uHalf.y, 0.0, 1.0);
    // Доля состояния B: волна — короткий проход по диагонали, дыхание — медленно туда-обратно, очередь — шаг за шагом.
    float m = 0.0;
    if (aAnim.x > 0.5 && aAnim.x < 1.5) {
        m = smoothstep(0.62, 0.95, sin(t * aAnim.z - aAnim.y + aAnim.w));
    } else if (aAnim.x < 2.5 && aAnim.x > 1.5) {
        m = smoothstep(0.15, 0.85, 0.5 + 0.5 * sin(t * aAnim.z + aAnim.w));
    } else if (aAnim.x > 2.5) {
        float n = aMove.w;
        float c = mod(t * aAnim.z + aAnim.w, n);
        float i = floor(c);
        float f = fract(c);
        float out_ = 1.0 - smoothstep(0.78, 1.0, f);
        if (abs(i - aAnim.y) < 0.5) m = out_;
        else if (abs(mod(i + 1.0, n) - aAnim.y) < 0.5) m = 1.0 - out_;
    }
    float sa = aLook.z, sb = aLook.w;
    vec4 ca = pal(mod(sa, 8.0)), cb = pal(mod(sb, 8.0));
    // Пустое состояние — тот же цвет прозрачным: переход гасит знак, а не перекрашивает в чёрное.
    if (ca.a == 0.0) ca = vec4(cb.rgb, 0.0);
    if (cb.a == 0.0) cb = vec4(ca.rgb, 0.0);
    vColor = mix(ca, cb, m);
    vFill = mix(floor(sa / 8.0), floor(sb / 8.0), m);
    // Доска гасит знаки под собой: расстояние от края знака до ближайшей рамки на экране (рамки — в мире схемы, глубина 1).
    float z = exp(uLogZ);
    float clear = 1e5;
    for (int i = 0; i < ${MAX_RECTS}; i++) {
        if (float(i) >= uRectN) break;
        vec4 r = uRects[i];
        vec2 q = max((r.xy - uCam) * z - p, p - (r.zw - uCam) * z);
        clear = min(clear, length(max(q, 0.0)) + min(max(q.x, q.y), 0.0));
    }
    vColor.a *= smoothstep(${CLEAR.toFixed(1)}, ${(CLEAR + FADE).toFixed(1)}, clear - 0.5 * aLook.x * clamp(k, 0.6, 2.0));
    // Ближние при приближении чуть растут, дальние почти нет; мельче полутора пикселей — тусклее, а не меньше.
    float h = 0.5 * aLook.x * clamp(k, 0.6, 2.0) * uDpr;
    if (h < 1.5) { vColor.a *= h / 1.5; h = 1.5; }
    float size = min(2.0 * h + 3.0, uMaxSize);
    gl_PointSize = size;
    vHalf = 0.5 * size;
    vShape = h;
    vKind = aLook.y;
}`;

// Форма — расстояние до края в пикселях экрана, край сглажен на пиксель. Квадрат — контур или заливка (переход — заливка
// проявляется), X — две диагонали в квадрате, ▶ — треугольник.
const FS = `
precision mediump float;
varying vec4 vColor;
varying float vFill;
varying float vKind;
varying float vHalf;
varying float vShape;
void main() {
    vec2 q = (gl_PointCoord * 2.0 - 1.0) * vHalf;
    float h = vShape;
    float a;
    if (vKind < 0.5) {
        float box = max(abs(q.x), abs(q.y)) - h;
        float w = max(1.0, h * 0.36);
        float line = clamp(0.5 - (abs(box + 0.5 * w) - 0.5 * w), 0.0, 1.0);
        float fill = clamp(0.5 - box, 0.0, 1.0);
        a = mix(line, fill, vFill);
    } else if (vKind < 1.5) {
        float bar = min(abs(q.x - q.y), abs(q.x + q.y)) * 0.7071 - h * 0.3;
        float box = max(abs(q.x), abs(q.y)) - h;
        a = clamp(0.5 - max(bar, box), 0.0, 1.0);
    } else {
        float back = -0.8 * h - q.x;
        float edge = ((q.x - h) * h + abs(q.y) * 1.8 * h) / (2.059 * h);
        a = clamp(0.5 - max(back, edge), 0.0, 1.0);
    }
    a *= vColor.a;
    if (a <= 0.003) discard;
    gl_FragColor = vec4(vColor.rgb * a, a);
}`;

function program(gl, vs, fs, uniforms) {
    const p = gl.createProgram();
    for (const [type, src] of [[gl.VERTEX_SHADER, vs], [gl.FRAGMENT_SHADER, fs]]) {
        const s = gl.createShader(type);
        gl.shaderSource(s, src);
        gl.compileShader(s);
        if (!gl.getShaderParameter(s, gl.COMPILE_STATUS) && !gl.isContextLost()) throw new Error(gl.getShaderInfoLog(s) || 'шейдер');
        gl.attachShader(p, s);
    }
    gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS) && !gl.isContextLost()) throw new Error(gl.getProgramInfoLog(p) || 'программа');
    return { p, u: Object.fromEntries(uniforms.map((name) => [name, gl.getUniformLocation(p, name)])) };
}

export class Marks {
    // null — WebGL нет или он отказал: звать FlatMarks.
    static create(canvas, set, dark) {
        try {
            return new Marks(canvas, set, dark);
        } catch (e) {
            console.warn('Знаки без WebGL:', e?.message || e);
            return null;
        }
    }

    constructor(canvas, set, dark) {
        // Холст прозрачный, свет с предумноженной альфой — ложится поверх фото. Встроенная видеокарта — экономнее.
        const options = { alpha: true, antialias: false, depth: false, stencil: false, premultipliedAlpha: true, preserveDrawingBuffer: false, powerPreference: 'low-power' };
        const gl = canvas.getContext('webgl2', options) || canvas.getContext('webgl', options);
        if (!gl) throw new Error('нет WebGL');
        Object.assign(this, { canvas, gl, set, dark, lost: false });
        canvas.addEventListener('webglcontextlost', (e) => { e.preventDefault(); this.lost = true; });
        canvas.addEventListener('webglcontextrestored', () => { this.setup(); this.lost = false; this.onRestore?.(); });
        this.setup();
    }

    setup() {
        const gl = this.gl;
        this.prog = program(gl, VS, FS, ['uCam', 'uLogZ', 'uHalf', 'uDpr', 'uTime', 'uMaxSize', 'uRects', 'uRectN', 'uP0', 'uP1', 'uP2', 'uP3', 'uP4']);
        this.buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, this.buffer);
        gl.bufferData(gl.ARRAY_BUFFER, this.set.data, gl.STATIC_DRAW);
        this.maxSize = gl.getParameter(gl.ALIASED_POINT_SIZE_RANGE)?.[1] || 64;
        const p = this.prog.p;
        this.loc = ['aPos', 'aLook', 'aAnim', 'aMove'].map((name) => gl.getAttribLocation(p, name));
        gl.useProgram(p);
        // Атрибуты одни на всё время: программа одна, буфер один — каждый кадр их не переставлять.
        this.loc.forEach((loc, i) => {
            gl.enableVertexAttribArray(loc);
            gl.vertexAttribPointer(loc, 4, gl.FLOAT, false, STRIDE * 4, i * 16);
        });
        gl.disable(gl.DEPTH_TEST);
        gl.enable(gl.BLEND);
        gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
        gl.clearColor(0, 0, 0, 0);
        this.theme(this.dark);
        this.mask(this.rects ?? []);
    }

    // Тема — только палитра: uniform, без пересчёта и нового контекста.
    theme(dark) {
        this.dark = dark;
        if (this.lost) return;
        const { gl, prog } = this;
        gl.useProgram(prog.p);
        PALETTE[dark ? 'dark' : 'light'].forEach((c, i) => gl.uniform4fv(prog.u[`uP${i}`], c));
    }

    // Рамки доски `[x0, y0, x1, y1]` в координатах слоя схемы: под ними знаков нет.
    mask(rects) {
        this.rects = rects.slice(0, MAX_RECTS);
        if (this.lost) return;
        const { gl, prog } = this;
        gl.useProgram(prog.p);
        gl.uniform4fv(prog.u.uRects, new Float32Array(MAX_RECTS * 4).fill(0).map((_, i) => this.rects[i >> 2]?.[i & 3] ?? 0));
        gl.uniform1f(prog.u.uRectN, this.rects.length);
    }

    load(set) {
        this.set = set;
        if (this.lost) return;
        this.gl.bindBuffer(this.gl.ARRAY_BUFFER, this.buffer);
        this.gl.bufferData(this.gl.ARRAY_BUFFER, set.data, this.gl.STATIC_DRAW);
    }

    resize(w, h, dpr) {
        Object.assign(this, { w, h, dpr });
        const W = Math.max(1, Math.round(w * dpr)), H = Math.max(1, Math.round(h * dpr));
        if (this.canvas.width !== W || this.canvas.height !== H) { this.canvas.width = W; this.canvas.height = H; }
        this.gl.viewport(0, 0, W, H);
    }

    draw(cam, time) {
        const { gl } = this;
        if (this.lost || !this.w) return;
        gl.clear(gl.COLOR_BUFFER_BIT);
        const u = this.prog.u;
        gl.uniform2f(u.uCam, cam.x, cam.y);
        gl.uniform1f(u.uLogZ, Math.log(cam.z));
        gl.uniform2f(u.uHalf, this.w / 2, this.h / 2);
        gl.uniform1f(u.uDpr, this.dpr);
        gl.uniform1f(u.uTime, time);
        gl.uniform1f(u.uMaxSize, this.maxSize);
        gl.drawArrays(gl.POINTS, 0, this.set.count);
    }

    // Уход со страницы: память видеокарты отдаём сразу.
    dispose() {
        const { gl } = this;
        gl.deleteBuffer(this.buffer);
        gl.deleteProgram(this.prog.p);
        gl.getExtension('WEBGL_lose_context')?.loseContext();
    }
}

// Без WebGL: те же знаки на 2D в состоянии A, рисуются только при движении — без оживления.
export class FlatMarks {
    constructor(canvas, set, dark) {
        Object.assign(this, { canvas, set, dark, ctx: canvas.getContext('2d') });
    }

    theme(dark) {
        this.dark = dark;
    }

    mask(rects) {
        this.rects = rects;
    }

    load(set) {
        this.set = set;
    }

    resize(w, h, dpr) {
        Object.assign(this, { w, h, dpr });
        this.canvas.width = Math.max(1, Math.round(w * dpr));
        this.canvas.height = Math.max(1, Math.round(h * dpr));
    }

    draw(cam) {
        const { ctx, w, h, dpr } = this;
        if (!ctx || !w) return;
        ctx.setTransform(dpr, 0, 0, dpr, (w * dpr) / 2, (h * dpr) / 2);
        ctx.clearRect(-w / 2, -h / 2, w, h);
        const pal = PALETTE[this.dark ? 'dark' : 'light'];
        const lz = Math.log(cam.z);
        const d = this.set.data;
        for (let o = 0; o < d.length; o += STRIDE) {
            const st = d[o + 6], c = pal[st % 8];
            if (!c[3]) continue;
            const side = d[o + 3], depth = d[o + 2], half = side / 2;
            const k = Math.exp(lz * depth);
            const x = (((((d[o] - cam.x * depth + half) % side) + side) % side) - half) * k;
            const y = (((((d[o + 1] - cam.y * depth + half) % side) + side) % side) - half) * k;
            const r = 0.5 * d[o + 4] * Math.min(2, Math.max(0.6, k));
            if (x < -w / 2 - r || x > w / 2 + r || y < -h / 2 - r || y > h / 2 + r) continue;
            const z = cam.z, m = CLEAR + r;
            if (this.rects?.some((q) => x > (q[0] - cam.x) * z - m && x < (q[2] - cam.x) * z + m && y > (q[1] - cam.y) * z - m && y < (q[3] - cam.y) * z + m)) continue;
            const color = `rgb(${c[0] * 255} ${c[1] * 255} ${c[2] * 255} / ${c[3]})`;
            const kind = d[o + 5];
            ctx.fillStyle = ctx.strokeStyle = color;
            if (kind === SQUARE && st < FILL) {
                const lw = Math.max(1 / dpr, r * 0.36);
                ctx.lineWidth = lw;
                ctx.strokeRect(x - r + lw / 2, y - r + lw / 2, 2 * r - lw, 2 * r - lw);
            } else if (kind === SQUARE) {
                ctx.fillRect(x - r, y - r, 2 * r, 2 * r);
            } else if (kind === CROSS) {
                ctx.lineWidth = r * 0.6;
                ctx.save(); ctx.beginPath(); ctx.rect(x - r, y - r, 2 * r, 2 * r); ctx.clip();
                ctx.beginPath();
                ctx.moveTo(x - r, y - r); ctx.lineTo(x + r, y + r);
                ctx.moveTo(x + r, y - r); ctx.lineTo(x - r, y + r);
                ctx.stroke();
                ctx.restore();
            } else {
                ctx.beginPath();
                ctx.moveTo(x - 0.8 * r, y - r); ctx.lineTo(x + r, y); ctx.lineTo(x - 0.8 * r, y + r);
                ctx.fill();
            }
        }
    }

    dispose() {}
}
