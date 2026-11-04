// Звёздное небо главной CRM (04.11.2026, владелец: «ОЧЕНЬ красивый чёрный фон с мелкими звёздами… объёмный КРАСИВЫЙ
// космос», «полный фокус на красоте и оптимизации»; выбрал только звёзды, чисто белые, в покое мерцание и метеоры).
// Звезда — точка WebGL со своей глубиной d ∈ (0, 1): на экране она в `середина + (p − камера·d) · z^d`. Дальние едут и
// растут медленнее ближних, при приближении ближние разлетаются, как при полёте; схема — глубина 1, звёзды всегда
// медленнее её и читаются фоном. Небо бесконечно: звёзды каждой группы лежат в своей плитке и заворачиваются по модулю
// в шейдере; сторона плитки больше видимого при самом дальнем масштабе, а стороны групп разные — повтора не видно.
// Один буфер, один вызов отрисовки на все звёзды и один на метеор, без текстур; мерцание считает вершинный шейдер.
// Стоит по числу звёзд (2–11 тыс.), а не по пикселям: шейдер по пикселю (хеш-сетка) считал бы на iPhone миллионы
// точек каждый кадр. Без WebGL — `FlatSky` на canvas 2D: те же звёзды, без мерцания и метеоров.
// Небо одно и то же при каждом заходе — генератор с постоянным зерном.
import { MIN } from './camera';

// Группы: глубина, звёзд на миллион px² при масштабе 1, размер ядра (css px), яркость, доля мерцающих, лучи; `patchy` —
// плотность пятнами (пыль и дальние гуще местами — небо не ровный «снег»). Звёзд вдвое меньше первых (04.11.2026,
// владелец).
const GROUPS = [
    { d: [0.02, 0.07], density: 280, size: [0.6, 0.95], light: [0.2, 0.5], twinkle: 0.12, patchy: 0.7 },
    { d: [0.07, 0.18], density: 150, size: [0.75, 1.2], light: [0.3, 0.75], twinkle: 0.25, patchy: 0.5 },
    { d: [0.18, 0.35], density: 70, size: [1, 1.9], light: [0.45, 0.95], twinkle: 0.35 },
    { d: [0.35, 0.6], density: 19, size: [1.5, 2.8], light: [0.7, 1.15], twinkle: 0.4 },
    { d: [0.3, 0.75], density: 2.5, size: [2.8, 4.2], light: [1, 1.3], twinkle: 0.5, spikes: true },
];
const STRIDE = 10;         // x, y, d, сторона плитки · размер, яркость, лучи, мерцание · скорость, фаза
const MARGIN = 96;         // css px запаса за краем: звезда с лучами не выскакивает у края
const MAX_PIXELS = 8.3e6;  // холст не больше 4K: на 5K-экране dpr 2 — 60 МБ кадра ради точек
const METEOR_DEPTH = 0.5;

// mulberry32 — короткий генератор с зерном: небо одинаковое при каждом заходе.
function random(seed) {
    return () => {
        seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const lerp = (a, b, t) => a + (b - a) * t;

// Звёзды под самый большой экран устройства (а не окна: поворот и смена размера неба не меняют). Окно всё же шире —
// перетащили на большой монитор — `fits` говорит «мало», и небо считается заново под него (иначе виден повтор плиток).
export function stars(least = 0) {
    const side = Math.ceil((Math.max(screen.width || 0, screen.height || 0, innerWidth, innerHeight, least) + 2 * MARGIN) / 512) * 512;
    const rand = random(20261104);
    const groups = GROUPS.map((g) => {
        const tile = Math.ceil(side / MIN ** g.d[1] / 64) * 64;
        const count = Math.round((g.density * tile * tile) / 1e6);
        // Пятна плотности — сумма синусов целых частот: периодична по плитке, шва на заворачивании нет.
        const waves = Array.from({ length: 3 }, (_, i) => ({ fx: 1 + i, fy: 2 - (i % 2), px: rand() * 6.283, py: rand() * 6.283 }));
        const dense = (x, y) => waves.reduce((s, w) => s + Math.sin((x / tile) * 6.283 * w.fx + w.px) * Math.sin((y / tile) * 6.283 * w.fy + w.py), 0) / 3;
        return { ...g, tile, count, dense };
    });
    const total = groups.reduce((s, g) => s + g.count, 0);
    const data = new Float32Array(total * STRIDE);
    let o = 0;
    for (const g of groups) {
        for (let n = 0; n < g.count;) {
            const x = rand() * g.tile, y = rand() * g.tile;
            if (g.patchy && rand() > 1 - g.patchy + g.patchy * (0.5 + 0.5 * g.dense(x, y))) continue;
            // Много тусклых, мало ярких — как на настоящем небе: яркость и размер — степенью от случайного.
            const u = rand() ** 1.8;
            const twinkles = rand() < g.twinkle;
            data.set([
                x, y, lerp(g.d[0], g.d[1], rand()), g.tile,
                lerp(g.size[0], g.size[1], u * 0.7 + rand() * 0.3), lerp(g.light[0], g.light[1], u), g.spikes ? 1 : 0,
                twinkles ? lerp(0.08, 0.32, rand() ** 1.5) * (rand() < 0.15 ? 2 : 1) : 0,
                lerp(0.5, 2.1, rand()), rand() * 6.283,
            ], o);
            o += STRIDE;
            n++;
        }
    }
    return { data, count: total, side };
}

export const fits = (sky, w, h) => Math.max(w, h) + 2 * MARGIN <= sky.side;

// Сколько физических пикселей на css: не больше 2 и не больше 4K на весь холст; `cap` — снижение по медленным кадрам.
export function pixelRatio(w, h, cap = 2) {
    return Math.max(1, Math.min(devicePixelRatio || 1, cap, Math.sqrt(MAX_PIXELS / Math.max(1, w * h))));
}

// Светлая тема (05.11.2026, владелец: чёрный под белой шапкой «бьёт по глазам»): космос тёмно-синий, к краям темнее, с еле
// видными свечениями; тёмная — чистый чёрный AMOLED, только звёзды. Синий — почти чёрный #0b0f19 (владелец, вариант «C»:
// прежний #171d2c — «перестарались с высветлением»). Свечения считаются один раз при заходе в бесшовную плитку 128×128
// (каждое пятно — по ближайшей копии в завёрнутой плитке, швов нет); 128 точек на 2400 px хватает — они очень плавные.
// Фон — свой холст под звёздами (`Backdrop`), нарисованный один раз, а не проход во весь экран каждый кадр: так было, и
// светлая тема лагала даже на мощном макбуке, а чёрная — нет (04.11.2026: кадр в 20 раз дороже, в покое тоже — мерцание
// перерисовывает всё). Глубина `NEBULA_DEPTH` — почти стоит: сдвиг и масштаб — transform готовой картинки.
const NEBULA = {
    base: [11, 15, 25],                      // #0b0f19
    glows: [                                 // цвет, сила, радиус (доля плитки), сколько
        [[64, 96, 178], 0.078, 0.41, 3],     // глубокий синий
        [[98, 82, 172], 0.052, 0.47, 2],     // индиго
        [[150, 118, 104], 0.03, 0.33, 1],    // еле тёплое — для живости
    ],
    band: [[118, 138, 205], 0.09, 0.06],     // полоса «Млечного пути»: цвет, сила, полуширина (доля плитки)
};
const NEBULA_SIZE = 128;
const NEBULA_DEPTH = 0.02;
const NEBULA_TILE = 2400;                    // css px мира на плитку при масштабе 1

// RGBA 0…1 по точкам плитки.
export function nebula(look = NEBULA) {
    const N = NEBULA_SIZE;
    const rand = random(1105);
    const spots = look.glows.flatMap(([color, a, r, n]) => Array.from({ length: n }, () => ({
        x: rand(), y: rand(), r: r * lerp(0.75, 1.25, rand()), a: a * lerp(0.75, 1.2, rand()), color,
    })));
    const wrap = (d) => d - Math.round(d);   // разница в завёрнутой плитке: ближайшая копия пятна
    const data = new Float32Array(N * N * 4);
    for (let j = 0; j < N; j++) for (let i = 0; i < N; i++) {
        const u = (i + 0.5) / N, v = (j + 0.5) / N;
        let [r, g, b] = look.base;
        const over = (color, k) => { r += (color[0] - r) * k; g += (color[1] - g) * k; b += (color[2] - b) * k; };
        for (const s of spots) {
            const d = Math.hypot(wrap(u - s.x), wrap(v - s.y)) / s.r;
            if (d < 1.6) over(s.color, s.a * Math.exp(-d * d * 3.2));
        }
        // Полоса по диагонали (u + v ≡ 1): завёрнутая, она продолжается в соседней плитке — лента через всё небо.
        if (look.band) {
            const [color, a, w] = look.band;
            const along = u - v;
            const d = Math.abs(wrap(u + v - 1 + 0.025 * Math.sin(6.283 * along))) / Math.SQRT2;
            over(color, a * Math.exp(-((d / w) ** 2)) * (0.7 + 0.3 * Math.sin(6.283 * 3 * along + 1.3)));
        }
        data.set([r / 255, g / 255, b / 255, 1], (j * N + i) * 4);
    }
    return { size: N, data };
}

const STAR_VS = `
attribute vec4 aPos;
attribute vec4 aLook;
attribute vec2 aTw;
uniform vec2 uCam;
uniform float uLogZ;
uniform vec2 uHalf;
uniform float uDpr;
uniform float uTime;
uniform float uMaxSize;
varying float vLight;
varying float vSpike;
varying float vRatio;
void main() {
    float d = aPos.z;
    float side = aPos.w;
    // Место в своей плитке от середины вида, завёрнутое в [−сторона/2, сторона/2): небо без края.
    vec2 rel = mod(aPos.xy - uCam * d + 0.5 * side, side) - 0.5 * side;
    vec2 p = rel * exp(uLogZ * d);
    gl_Position = vec4(p.x / uHalf.x, -p.y / uHalf.y, 0.0, 1.0);
    // Ближние при приближении чуть растут, дальние почти нет; мельче пикселя — не меньше, а тусклее: не рябит в движении
    // (тусклее по радиусу, а не по площади — иначе мелкие на экране без ретины пропадают совсем).
    float r = 0.5 * aLook.x * clamp(exp(uLogZ * d * 0.6), 0.6, 2.0) * uDpr;
    float energy = 1.0;
    if (r < 0.75) { energy = r / 0.75; r = 0.75; }
    float tw = 1.0 - aLook.w * (0.5 + 0.5 * sin(uTime * aTw.x + aTw.y)) * (0.65 + 0.35 * sin(uTime * aTw.x * 0.43 + aTw.y * 2.3));
    float size = min(r * (aLook.z > 0.5 ? 18.0 : 8.0), uMaxSize);
    gl_PointSize = size;
    vRatio = 0.5 * size / r;
    vLight = aLook.y * energy * tw;
    vSpike = aLook.z;
}`;

const STAR_FS = `
precision mediump float;
varying float vLight;
varying float vSpike;
varying float vRatio;
void main() {
    vec2 p = gl_PointCoord * 2.0 - 1.0;
    float e = length(p);
    vec2 q = p * vRatio;
    float q2 = dot(q, q);
    float core = exp(-q2 * 1.6);
    float halo = 0.16 * exp(-sqrt(q2) * 0.85) + vSpike * 0.06 * exp(-sqrt(q2) * 0.35);
    float rays = vSpike * 0.42 * (exp(-abs(q.y) * 2.4) + exp(-abs(q.x) * 2.4)) * (1.0 - e) * (1.0 - e);
    float a = (core + (halo + rays) * (1.0 - smoothstep(0.7, 1.0, e))) * vLight;
    gl_FragColor = vec4(a, a, a, a);
}`;

// Метеор — полоса: u вдоль (0 — голова, 1 — конец хвоста), v поперёк.
const METEOR_VS = `
attribute vec2 aCorner;
uniform vec2 uHead;
uniform vec2 uDir;
uniform float uLen;
uniform float uWidth;
uniform vec2 uHalf;
varying vec2 vUv;
void main() {
    vec2 across = vec2(-uDir.y, uDir.x);
    vec2 p = uHead - uDir * aCorner.x * uLen + across * aCorner.y * uWidth * 3.0;
    vUv = aCorner;
    gl_Position = vec4(p.x / uHalf.x, -p.y / uHalf.y, 0.0, 1.0);
}`;

// Общих uniform у шейдеров нет: точность по умолчанию у них разная (highp и mediump), и строгий драйвер такую пару не
// связывает — `uSharp` (длина к ширине головы) считается заранее.
const METEOR_FS = `
precision mediump float;
uniform float uAlpha;
uniform float uSharp;
varying vec2 vUv;
void main() {
    float u = vUv.x;
    float v = vUv.y * 3.0 / mix(1.0, 0.35, clamp(u, 0.0, 1.0));
    float tail = pow(clamp(1.0 - u, 0.0, 1.0), 1.8) * step(0.0, u);
    float head = exp(-pow(u * uSharp, 2.0));
    float a = (tail * 0.85 + head) * exp(-v * v * 1.2) * uAlpha;
    gl_FragColor = vec4(a, a, a, a);
}`;

// Фон светлой темы — 2D-холст под звёздами, точкой на 2 css px (свечения плавные, ретина не нужна) и с запасом `BACK_MARGIN`
// за краем: камера двигает его transform, и только когда запаса не хватит — рисуется заново (на 1000 px сдвига — 20 px фона).
// Свечения из плитки — выборка с плавным переходом, к краям темнее; поверх — шум в полтона, иначе плавный тёмный градиент в
// 8 битах идёт полосами.
const BACK_MARGIN = 96;
const BACK_STEP = 2;
const VIGNETTE = 0.18;

export class Backdrop {
    constructor(canvas) {
        Object.assign(this, { canvas, ctx: canvas.getContext('2d', { alpha: false }), tile: null, at: null });
    }

    // `side` — сторона плиток звёзд: плитка свечений не меньше её, повтора не видно. Точка картинки — `BACK_STEP` css px:
    // свечения плавные, а рисуется это в JS (на макбуке ~1,8 млн точек на css-пиксель — десятки мс).
    paint(w, h, cam, side) {
        if (!this.ctx || !w || !h) return;
        this.tile ??= nebula();
        const { size: N, data } = this.tile;
        const M = BACK_MARGIN, S = BACK_STEP;
        const W = Math.ceil((w + 2 * M) / S), H = Math.ceil((h + 2 * M) / S);
        const tile = Math.max(NEBULA_TILE, side), k = cam.z ** NEBULA_DEPTH, span = tile * k;
        const fx = (cam.x * NEBULA_DEPTH) / tile, fy = (cam.y * NEBULA_DEPTH) / tile;
        Object.assign(this.canvas, { width: W, height: H });
        Object.assign(this.canvas.style, { left: `${w / 2 - (W * S) / 2}px`, top: `${h / 2 - (H * S) / 2}px`, width: `${W * S}px`, height: `${H * S}px` });
        const img = this.ctx.createImageData(W, H);
        const px = img.data;
        // По столбцу и строке: две точки плитки, доля и край — одни на всю строку и весь столбец.
        const axis = (n, half, f) => {
            const a = new Int32Array(n * 2), t = new Float32Array(n), e = new Float32Array(n);
            for (let i = 0; i < n; i++) {
                const s = ((i + 0.5) * S - (n * S) / 2);
                let x = (s / span + f) % 1;
                x = (x < 0 ? x + 1 : x) * N - 0.5;
                const x0 = Math.floor(x);
                a[2 * i] = (x0 + N) % N; a[2 * i + 1] = (x0 + 1) % N; t[i] = x - x0;
                e[i] = (s / half) ** 2;
            }
            return { a, t, e };
        };
        const X = axis(W, w / 2, fx), Y = axis(H, h / 2, fy);
        let seed = 7;
        for (let j = 0, o = 0; j < H; j++) {
            const r0 = Y.a[2 * j] * N, r1 = Y.a[2 * j + 1] * N, ty = Y.t[j], ey = Y.e[j];
            for (let i = 0; i < W; i++, o += 4) {
                const c0 = X.a[2 * i], c1 = X.a[2 * i + 1], tx = X.t[i];
                const a = (r0 + c0) * 4, b = (r0 + c1) * 4, c = (r1 + c0) * 4, d = (r1 + c1) * 4;
                const shade = 255 * (1 - VIGNETTE * (X.e[i] + ey));
                seed = (seed * 1664525 + 1013904223) >>> 0;
                const dither = seed / 4294967296 - 0.5;
                for (let ch = 0; ch < 3; ch++) {
                    const top = data[a + ch] + (data[b + ch] - data[a + ch]) * tx;
                    const bottom = data[c + ch] + (data[d + ch] - data[c + ch]) * tx;
                    px[o + ch] = (top + (bottom - top) * ty) * shade + dither;
                }
                px[o + 3] = 255;
            }
        }
        this.ctx.putImageData(img, 0, 0);
        this.at = { x: cam.x, y: cam.y, k, w, h, mx: (W * S - w) / 2, my: (H * S - h) / 2 };
    }

    // Сдвиг готовой картинки за камерой; запаса не хватает или сменился размер — false: звать `paint`.
    place(cam, w, h) {
        const at = this.at;
        if (!at || at.w !== w || at.h !== h) return false;
        const k = cam.z ** NEBULA_DEPTH, r = k / at.k;
        const tx = -(cam.x - at.x) * NEBULA_DEPTH * k, ty = -(cam.y - at.y) * NEBULA_DEPTH * k;
        if ((w / 2 + at.mx) * r - Math.abs(tx) < w / 2 + 1 || (h / 2 + at.my) * r - Math.abs(ty) < h / 2 + 1) return false;
        this.canvas.style.transform = `translate(${tx}px, ${ty}px) scale(${r})`;
        return true;
    }

    // Тёмная тема: картинка не нужна — память отдаём, при возврате нарисуется заново.
    clear() {
        Object.assign(this.canvas, { width: 1, height: 1 });
        this.at = null;
    }
}

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

export class Sky {
    // null — WebGL нет или он отказал: звать FlatSky.
    // `see` — холст прозрачный, под ним `Backdrop` светлой темы; в тёмной — непрозрачный, композитору дешевле.
    static create(canvas, sky, see = false) {
        try {
            return new Sky(canvas, sky, see);
        } catch (e) {
            console.warn('Звёзды без WebGL:', e?.message || e);
            return null;
        }
    }

    constructor(canvas, sky, see) {
        // Свет звёзд — с предумноженной альфой: на прозрачном холсте он ложится поверх `Backdrop`, на непрозрачном чёрном —
        // как раньше. Встроенная видеокарта ноутбука — экономнее дискретной.
        const options = { alpha: see, antialias: false, depth: false, stencil: false, premultipliedAlpha: true, preserveDrawingBuffer: false, powerPreference: 'low-power' };
        const gl = canvas.getContext('webgl2', options) || canvas.getContext('webgl', options);
        if (!gl) throw new Error('нет WebGL');
        Object.assign(this, { canvas, gl, sky, lost: false, meteor: null });
        canvas.addEventListener('webglcontextlost', (e) => { e.preventDefault(); this.lost = true; });
        canvas.addEventListener('webglcontextrestored', () => { this.setup(); this.lost = false; this.onRestore?.(); });
        this.setup();
    }

    setup() {
        const gl = this.gl;
        this.stars = program(gl, STAR_VS, STAR_FS, ['uCam', 'uLogZ', 'uHalf', 'uDpr', 'uTime', 'uMaxSize']);
        this.meteors = program(gl, METEOR_VS, METEOR_FS, ['uHead', 'uDir', 'uLen', 'uWidth', 'uHalf', 'uAlpha', 'uSharp']);
        this.buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, this.buffer);
        gl.bufferData(gl.ARRAY_BUFFER, this.sky.data, gl.STATIC_DRAW);
        this.quad = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, this.quad);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-0.06, -1, -0.06, 1, 1, -1, 1, 1]), gl.STATIC_DRAW);
        this.maxSize = gl.getParameter(gl.ALIASED_POINT_SIZE_RANGE)?.[1] || 64;
        this.loc = {
            pos: gl.getAttribLocation(this.stars.p, 'aPos'),
            look: gl.getAttribLocation(this.stars.p, 'aLook'),
            tw: gl.getAttribLocation(this.stars.p, 'aTw'),
            corner: gl.getAttribLocation(this.meteors.p, 'aCorner'),
        };
        gl.disable(gl.DEPTH_TEST);
        gl.enable(gl.BLEND);
        gl.blendFunc(gl.ONE, gl.ONE);
        gl.clearColor(0, 0, 0, 0);
    }

    load(sky) {
        this.sky = sky;
        if (this.lost) return;
        this.gl.bindBuffer(this.gl.ARRAY_BUFFER, this.buffer);
        this.gl.bufferData(this.gl.ARRAY_BUFFER, sky.data, this.gl.STATIC_DRAW);
    }

    resize(w, h, dpr) {
        Object.assign(this, { w, h, dpr });
        const W = Math.max(1, Math.round(w * dpr)), H = Math.max(1, Math.round(h * dpr));
        if (this.canvas.width !== W || this.canvas.height !== H) { this.canvas.width = W; this.canvas.height = H; }
        this.gl.viewport(0, 0, W, H);
    }

    draw(cam, time) {
        const { gl } = this;
        // Рисовать некуда (контекст потерян, сцены нет) — метеор гаснет, иначе `flying` держал бы цикл кадров каждый кадр,
        // пока контекст не вернётся.
        if (this.lost || !this.w) { this.meteor = null; return; }
        gl.clear(gl.COLOR_BUFFER_BIT);
        const s = this.stars;
        gl.useProgram(s.p);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.buffer);
        const F = 4;
        gl.enableVertexAttribArray(this.loc.pos);
        gl.vertexAttribPointer(this.loc.pos, 4, gl.FLOAT, false, STRIDE * F, 0);
        gl.enableVertexAttribArray(this.loc.look);
        gl.vertexAttribPointer(this.loc.look, 4, gl.FLOAT, false, STRIDE * F, 4 * F);
        gl.enableVertexAttribArray(this.loc.tw);
        gl.vertexAttribPointer(this.loc.tw, 2, gl.FLOAT, false, STRIDE * F, 8 * F);
        gl.uniform2f(s.u.uCam, cam.x, cam.y);
        gl.uniform1f(s.u.uLogZ, Math.log(cam.z));
        gl.uniform2f(s.u.uHalf, this.w / 2, this.h / 2);
        gl.uniform1f(s.u.uDpr, this.dpr);
        gl.uniform1f(s.u.uTime, time);
        gl.uniform1f(s.u.uMaxSize, this.maxSize);
        gl.drawArrays(gl.POINTS, 0, this.sky.count);
        gl.disableVertexAttribArray(this.loc.look);
        gl.disableVertexAttribArray(this.loc.tw);
        if (this.meteor) this.drawMeteor(cam);
    }

    // Метеор живёт на глубине 0,5: пока летит, сдвиг и масштаб двигают его вместе с соседними звёздами.
    spawn(cam, rand = Math.random) {
        const k = cam.z ** METEOR_DEPTH;
        // Падает наискось вниз — вправо или влево, от 20° до 65° к горизонту; начинается в верхней части неба.
        const right = rand() < 0.5;
        const angle = lerp(0.35, 1.13, rand());
        this.meteor = {
            x: cam.x * METEOR_DEPTH + (lerp(right ? -0.45 : -0.05, right ? 0.05 : 0.45, rand()) * this.w) / k,
            y: cam.y * METEOR_DEPTH + (lerp(-0.45, 0.05, rand()) * this.h) / k,
            dx: (right ? 1 : -1) * Math.cos(angle), dy: Math.sin(angle),
            speed: lerp(0.55, 0.95, rand()) / k,       // единиц глубины 0,5 в мс
            len: lerp(140, 260, rand()) / k,
            width: lerp(0.9, 1.4, rand()),
            life: lerp(700, 1200, rand()),
            t0: performance.now(),
        };
    }

    get flying() {
        return !!this.meteor;
    }

    drawMeteor(cam) {
        const m = this.meteor;
        const t = (performance.now() - m.t0) / m.life;
        if (t >= 1) { this.meteor = null; return; }
        const { gl } = this;
        const k = cam.z ** METEOR_DEPTH;
        const dist = m.speed * m.life * t;
        const head = { x: (m.x + m.dx * dist - cam.x * METEOR_DEPTH) * k, y: (m.y + m.dy * dist - cam.y * METEOR_DEPTH) * k };
        const alpha = Math.min(1, t / 0.12) * (1 - Math.max(0, (t - 0.55) / 0.45)) ** 1.5;
        const len = m.len * k * Math.min(1, t / 0.35 + 0.15);
        const s = this.meteors;
        gl.useProgram(s.p);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.quad);
        gl.enableVertexAttribArray(this.loc.corner);
        gl.vertexAttribPointer(this.loc.corner, 2, gl.FLOAT, false, 0, 0);
        gl.uniform2f(s.u.uHead, head.x, head.y);
        gl.uniform2f(s.u.uDir, m.dx, m.dy);
        gl.uniform1f(s.u.uLen, len);
        gl.uniform1f(s.u.uWidth, m.width);
        gl.uniform2f(s.u.uHalf, this.w / 2, this.h / 2);
        gl.uniform1f(s.u.uAlpha, alpha);
        gl.uniform1f(s.u.uSharp, len / (m.width * 1.6));
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
        gl.disableVertexAttribArray(this.loc.corner);
    }

    // Уход со страницы: память видеокарты отдаём сразу, не дожидаясь сборщика.
    dispose() {
        const { gl } = this;
        gl.deleteBuffer(this.buffer);
        gl.deleteBuffer(this.quad);
        gl.deleteProgram(this.stars.p);
        gl.deleteProgram(this.meteors.p);
        gl.getExtension('WEBGL_lose_context')?.loseContext();
    }
}

// Без WebGL: те же звёзды на canvas 2D, рисуются только при движении — без мерцания, лучей и метеоров.
export class FlatSky {
    constructor(canvas, sky) {
        Object.assign(this, { canvas, sky, ctx: canvas.getContext('2d'), meteor: null });
    }

    load(sky) {
        this.sky = sky;
    }

    resize(w, h, dpr) {
        Object.assign(this, { w, h, dpr });
        this.canvas.width = Math.max(1, Math.round(w * dpr));
        this.canvas.height = Math.max(1, Math.round(h * dpr));
    }

    draw(cam) {
        const { ctx, w, h, dpr } = this;
        if (!ctx || !w) return;
        ctx.setTransform(dpr, 0, 0, dpr, w * dpr / 2, h * dpr / 2);
        ctx.clearRect(-w / 2, -h / 2, w, h);
        ctx.fillStyle = '#fff';
        const lz = Math.log(cam.z);
        const d = this.sky.data;
        for (let o = 0; o < d.length; o += STRIDE) {
            const side = d[o + 3], depth = d[o + 2];
            const k = Math.exp(lz * depth);
            const half = side / 2;
            const rx = ((((d[o] - cam.x * depth + half) % side) + side) % side) - half;
            const ry = ((((d[o + 1] - cam.y * depth + half) % side) + side) % side) - half;
            const x = rx * k, y = ry * k;
            if (x < -w / 2 - 4 || x > w / 2 + 4 || y < -h / 2 - 4 || y > h / 2 + 4) continue;
            const r = Math.max(0.5, 0.5 * d[o + 4] * Math.min(2, Math.max(0.6, Math.exp(lz * depth * 0.6))));
            ctx.globalAlpha = Math.min(1, d[o + 5]);
            ctx.fillRect(x - r, y - r, r * 2, r * 2);
        }
        ctx.globalAlpha = 1;
    }

    spawn() {}

    get flying() {
        return false;
    }

    dispose() {}
}
