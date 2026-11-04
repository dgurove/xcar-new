// Звёздное небо главной CRM (04.11.2026, владелец: «ОЧЕНЬ красивый чёрный фон с мелкими звёздами… объёмный КРАСИВЫЙ
// космос», «полный фокус на красоте и оптимизации»; выбрал только звёзды, чисто белые, в покое мерцание и метеоры).
// Звезда — точка WebGL со своей глубиной d ∈ (0, 1): на экране она в `середина + (p − камера·d) · z^d`. Дальние едут и
// растут медленнее ближних, при приближении ближние разлетаются, как при полёте; схема — глубина 1, звёзды всегда
// медленнее её и читаются фоном. Небо бесконечно: звёзды каждой группы лежат в своей плитке и заворачиваются по модулю
// в шейдере; сторона плитки больше видимого при самом дальнем масштабе, а стороны групп разные — повтора не видно.
// Один буфер, один вызов отрисовки на все звёзды и один на метеор, без текстур; мерцание считает вершинный шейдер.
// Стоит по числу звёзд (2–11 тыс.), а не по пикселям: шейдер по пикселю (хеш-сетка) считал бы на iPhone миллионы
// точек каждый кадр. Без WebGL — `FlatSky` на canvas 2D: те же звёзды, без мерцания и метеоров. Небо одно и то же при
// каждом заходе — генератор с постоянным зерном.
import { MIN } from './camera';

// Группы: глубина, звёзд на миллион px² при масштабе 1, размер ядра (css px), яркость, доля мерцающих, лучи; `patchy` —
// плотность пятнами (пыль и дальние гуще местами — небо не ровный «снег»).
const GROUPS = [
    { d: [0.02, 0.07], density: 560, size: [0.6, 0.95], light: [0.2, 0.5], twinkle: 0.12, patchy: 0.7 },
    { d: [0.07, 0.18], density: 300, size: [0.75, 1.2], light: [0.3, 0.75], twinkle: 0.25, patchy: 0.5 },
    { d: [0.18, 0.35], density: 140, size: [1, 1.9], light: [0.45, 0.95], twinkle: 0.35 },
    { d: [0.35, 0.6], density: 38, size: [1.5, 2.8], light: [0.7, 1.15], twinkle: 0.4 },
    { d: [0.3, 0.75], density: 5, size: [2.8, 4.2], light: [1, 1.3], twinkle: 0.5, spikes: true },
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

// Звёзды под самый большой экран устройства (а не окна: поворот и смена размера неба не меняют).
export function stars() {
    const side = Math.max(screen.width || 0, screen.height || 0, innerWidth, innerHeight) + 2 * MARGIN;
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
    return { data, count: total };
}

// Сколько физических пикселей на css: не больше 2 и не больше 4K на весь холст; `cap` — снижение по медленным кадрам.
export function pixelRatio(w, h, cap = 2) {
    return Math.max(1, Math.min(devicePixelRatio || 1, cap, Math.sqrt(MAX_PIXELS / Math.max(1, w * h))));
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
    gl_FragColor = vec4(a, a, a, 1.0);
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
    gl_FragColor = vec4(a, a, a, 1.0);
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

export class Sky {
    // null — WebGL нет или он отказал: звать FlatSky.
    static create(canvas, sky) {
        try {
            return new Sky(canvas, sky);
        } catch (e) {
            console.warn('Звёзды без WebGL:', e?.message || e);
            return null;
        }
    }

    constructor(canvas, sky) {
        // Непрозрачный холст дешевле композитору; встроенная видеокарта ноутбука — экономнее дискретной.
        const options = { alpha: false, antialias: false, depth: false, stencil: false, premultipliedAlpha: false, preserveDrawingBuffer: false, powerPreference: 'low-power' };
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
        gl.clearColor(0, 0, 0, 1);
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
        Object.assign(this, { canvas, sky, ctx: canvas.getContext('2d', { alpha: false }), meteor: null });
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
        ctx.fillStyle = '#000';
        ctx.fillRect(-w / 2, -h / 2, w, h);
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
