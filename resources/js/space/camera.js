// Камера космоса главной CRM (04.11.2026; листание по этапам — 05.11.2026): точка мира в середине сцены (x, y) и масштаб z.
// Одна на звёзды (`sky.js`, каждая на своей глубине) и слой конвейера (глубина 1): схема едет вместе с фоном.
//
// Мир ограничен (05.11.2026, владелец: «космос уже не бесконечный, а ограниченный по высоте и ширине»):
// - **вбок — по этапам.** Камера правит не x, а фокус `fx` — точку мира в `FOCUS` ширины экрана (золотое сечение, чуть левее
//   середины). Палец ведёт доску 1:1, отпустил — пружина к этапу по проекции броска, как листание страниц iOS: сильный
//   взмах проскакивает сразу несколько этапов, касание посреди полёта ловит доску. За первым и последним этапом — резинка;
// - **вверх-вниз — свободно** с броском, но в рамке доски: за краем резинка, долетевший бросок отскакивает пружиной;
// - **масштаб** — щипок, ctrl/⌘ + колесо, двойной тап, `+`/`−`: по горизонтали якорь всегда в фокусе — этап остаётся на
//   золотом сечении, по вертикали — под пальцем.
// Ось жеста — по первому движению (владелец: «не по диагонали»). Кадры камера не заводит: `step(now)` зовёт цикл
// `space_controller`, `wake` — чтобы он проснулся.

const MOVE = 6;           // порог сдвига, px: касание без сдвига остаётся нажатием
export const MIN = 0.4;
export const MAX = 2.5;
export const FOCUS = 0.382; // этап в фокусе — центром на 1/φ² ширины экрана
const FRICTION = 0.004;   // затухание броска по вертикали, 1/мс: за полсекунды остаётся 13 %
const STOP = 0.012;       // px/мс — медленнее не катится
const SAMPLE = 90;        // скорость пальца — по последним мс движения
const FLING_FROM = 0.45;  // px/мс: медленнее — вертикальный бросок без инерции
const FLING_FULL = 1.2;
const FLING_GAP = 80;     // мс между последним движением и отпусканием — дольше: палец замер, броска нет
const PROJECT = 450;      // мс: куда долетел бы взмах вбок — цель листания (iOS считает так же)
const NEXT = 0.33;        // доля шага: дотянули дальше — следующий этап, даже без взмаха
const SNAP = 0.02;        // жёсткость пружины к этапу, 1/мс: шаг ≈ 300 мс, критическое затухание — без перелёта
const BOUNCE = 0.022;     // пружина края по вертикали
const SMOOTH = 0.016;     // масштаб и колесо мыши догоняют цель: за ~150 мс
const BAND = 0.55;        // резинка iOS: (1 − 1/(d·k/D + 1))·D
const WHEEL_GAP = 150;    // мс тишины колеса — новый жест трекпада
const WHEEL_REST = 80;    // мс тишины вбок — пальцы сняты, листаем к этапу
const DOUBLE = 300;       // двойной тап: мс и px между касаниями
const DOUBLE_GAP = 30;

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
// За пределом масштаба щипок не обрывается, а упирается: каждая следующая доля — всё меньше.
const soft = (z) => (z > MAX ? MAX * (z / MAX) ** 0.3 : z < MIN ? MIN * (z / MIN) ** 0.3 : z);
// Резинка: за краем [lo, hi] смещение растёт всё медленнее, не дальше D.
const band = (v, lo, hi, D) => {
    const r = (d) => (1 - 1 / ((d * BAND) / D + 1)) * D;
    return v < lo ? lo - r(lo - v) : v > hi ? hi + r(v - hi) : v;
};

// Пружина с критическим затуханием за dt, точным решением: кадры любой частоты едут одинаково. Возвращает [отклонение, скорость].
function spring(x0, v0, w, dt) {
    const e = Math.exp(-w * dt);
    const k = v0 + w * x0;
    return [(x0 + k * dt) * e, (v0 - w * k * dt) * e];
}

export class Camera {
    constructor(el, { wake = () => {}, still = false } = {}) {
        Object.assign(this, { el, wake, still, fx: 0, y: 0, z: 1, w: 1, h: 1, version: 0 });
        this.columns = [];                // центры этапов в мире, слева направо
        this.rows = { lo: 0, hi: 0 };     // где может стоять y (рамка доски)
        this.pointers = new Map();
        this.target = null;               // этап, к которому едет пружина
        this.vfx = 0;                     // скорость фокуса, px мира в мс
        this.vy = 0;                      // бросок по вертикали, px мира в мс
        this.goal = null;                 // масштаб к цели: { z, oy }
        this.yGoal = null;                // колесо мыши — к цели по вертикали
        this.raw = 1;                     // масштаб щипка без резинки
        this.settleAt = 0;                // щипок или трекпад кончились — когда отпустить резинку
        this.handlers = {
            pointerdown: (e) => this.down(e),
            pointermove: (e) => this.move(e),
            pointerup: (e) => this.up(e),
            pointercancel: (e) => this.up(e, true),
            // Только свой захват: забирая палец у холста (неявный захват касания), сцена получает чужой — это не конец жеста.
            lostpointercapture: (e) => e.target === this.el && this.up(e, true),
            wheel: (e) => this.wheel(e),
            gesturestart: (e) => this.gesture(e, 'start'),
            gesturechange: (e) => this.gesture(e, 'change'),
            gestureend: (e) => this.gesture(e, 'end'),
            click: (e) => this.click(e),
        };
        for (const [name, fn] of Object.entries(this.handlers)) el.addEventListener(name, fn, { passive: false, capture: name === 'click' });
        this.onKey = (e) => this.key(e);
        window.addEventListener('keydown', this.onKey);
    }

    detach() {
        for (const [name, fn] of Object.entries(this.handlers)) this.el.removeEventListener(name, fn, { capture: name === 'click' });
        window.removeEventListener('keydown', this.onKey);
    }

    // Середина экрана в мире: фокус стоит на `FOCUS` ширины, а не посередине.
    get x() {
        return this.fx + ((0.5 - FOCUS) * this.w) / this.z;
    }

    get busy() {
        return this.pointers.size > 0;
    }

    resize(w, h) {
        this.w = w || 1; this.h = h || 1;
        this.changed();
    }

    changed() {
        this.version++;
        this.wake();
    }

    // Точка экрана (clientY) — от середины сцены.
    offsetY(cy) {
        const r = this.el.getBoundingClientRect();
        return cy - r.top - r.height / 2;
    }

    // Новый масштаб вокруг строки oy от середины: фокус по горизонтали и точка мира под oy остаются на месте.
    zoomTo(z, oy = 0) {
        const wy = this.y + oy / this.z;
        this.z = z;
        this.y = wy - oy / z;
    }

    // ── Рамка ────────────────────────────────────────────────────────────────────────────────────────────────────────

    // Этапы и доска: `columns` — центры видимых этапов, `board` — `{ y0, y1 }` в мире, `pad` — `{ top, bottom }` экрана.
    // Первый раз — сразу на вид `view` ({ i, z }); дальше этап в фокусе остаётся тем же, если его не сдвинули жестом.
    frame(columns, board, pad, view) {
        const had = this.columns.length;
        const focus = had ? this.index() : null;
        this.columns = columns;
        this.board = board;
        this.pad = pad;
        if (!columns.length) return;
        if (!had) {
            const v = this.pending ?? view;
            this.pending = null;
            this.z = clamp(v.z, MIN, MAX);
            this.raw = this.z;
            this.fx = columns[clamp(v.i, 0, columns.length - 1)];
            this.limits();
            this.y = Number.isFinite(v.y) ? clamp(v.y, this.rows.lo, this.rows.hi) : this.rows.lo;
        } else if (!this.pointers.size && this.target === null) {
            this.fx = columns[clamp(focus, 0, columns.length - 1)];
        }
        this.limits();
        this.changed();
    }

    // Где может стоять y при этом масштабе: верх доски не ниже верхнего отступа, низ не выше нижнего; доска ниже экрана — у верха.
    limits() {
        if (!this.board) return this.rows;
        const { y0, y1 } = this.board;
        const lo = y0 + (this.h / 2 - this.pad.top) / this.z;
        const hi = y1 - (this.h / 2 - this.pad.bottom) / this.z;
        this.rows = { lo, hi: Math.max(lo, hi) };
        return this.rows;
    }

    // Ближайший этап к точке мира (по умолчанию — к фокусу).
    index(at = this.fx) {
        let best = 0;
        for (let i = 1; i < this.columns.length; i++) if (Math.abs(this.columns[i] - at) < Math.abs(this.columns[best] - at)) best = i;
        return best;
    }

    // Положение в долях этапов: 2,4 — между третьим и четвёртым, ближе к третьему.
    frac(at) {
        const c = this.columns;
        if (c.length < 2) return 0;
        if (at <= c[0]) return (at - c[0]) / (c[1] - c[0]);
        for (let i = 1; i < c.length; i++) if (at <= c[i]) return i - 1 + (at - c[i - 1]) / (c[i] - c[i - 1]);
        return c.length - 1 + (at - c[c.length - 1]) / (c[c.length - 1] - c[c.length - 2]);
    }

    // Этап, от которого считается следующий шаг: куда уже летим, иначе — ближайший. Быстрые взмахи подряд складываются.
    get here() {
        return this.target ?? this.index();
    }

    // К этапу i пружиной; `v` — скорость фокуса в мс (взмах), без неё — с места.
    goTo(i, v = this.vfx) {
        if (!this.columns.length) return;
        this.target = clamp(i, 0, this.columns.length - 1);
        const x0 = this.fx - this.columns[this.target];
        // Без перелёта: скорость к цели не больше той, что пружина гасит без пересечения (v0 ≤ ω·|x0|).
        this.vfx = Math.sign(v) === -Math.sign(x0) ? Math.sign(v) * Math.min(Math.abs(v), SNAP * Math.abs(x0)) : v;
        if (this.still) { this.fx = this.columns[this.target]; this.vfx = 0; this.target = null; }
        this.changed();
    }

    // Сразу на этап, без полёта (фильтр поставил этап на новое место — на экране он стоит, где стоял).
    jumpTo(i) {
        if (!this.columns.length) return;
        this.target = null; this.vfx = 0;
        this.fx = this.columns[clamp(i, 0, this.columns.length - 1)];
        this.changed();
    }

    stop() {
        this.vfx = 0; this.vy = 0;
        this.target = null;
        this.goal = null;
        this.yGoal = null;
    }

    // Вид — в sessionStorage: «Назад» на главную возвращает к тому же этапу.
    snapshot() {
        return { i: this.here, y: Math.round(this.y * 10) / 10, z: Math.round(this.z * 1000) / 1000 };
    }

    // true — вид был: применится, когда придут этапы (`frame`).
    restore({ i, y, z } = {}) {
        if (![i, y, z].every(Number.isFinite)) return false;
        this.pending = { i, y, z };
        return true;
    }

    // ── Физика ───────────────────────────────────────────────────────────────────────────────────────────────────────

    // Шаг к моменту now; true — ещё движется, нужен следующий кадр.
    step(now) {
        // После сна цикла (покой, скрытая вкладка) первый шаг — как обычный кадр, а не прыжок на всю паузу.
        const dt = this.at && now - this.at < 100 ? now - this.at : 16;
        this.at = now;
        let moving = this.pointers.size > 0;
        if (this.settleAt && now >= this.settleAt && !this.pointers.size) this.settle();
        if (this.goal) {
            const k = 1 - Math.exp(-SMOOTH * dt);
            const z = Math.exp(Math.log(this.z) + (Math.log(this.goal.z) - Math.log(this.z)) * k);
            const done = Math.abs(Math.log(this.goal.z / z)) < 0.002;
            this.zoomTo(done ? this.goal.z : z, this.goal.oy);
            if (done) this.goal = null;
            this.limits();
            moving = true;
        }
        if (this.target !== null) {
            const to = this.columns[this.target];
            const [x0, v] = spring(this.fx - to, this.vfx, SNAP, dt);
            this.fx = to + x0; this.vfx = v;
            if (Math.abs(x0) * this.z < 0.3 && Math.abs(v) * this.z < 0.02) { this.fx = to; this.vfx = 0; this.target = null; }
            moving = true;
        }
        if (this.yGoal !== null) {
            const k = 1 - Math.exp(-SMOOTH * dt);
            this.y += (this.yGoal - this.y) * k;
            if (Math.abs(this.yGoal - this.y) * this.z < 0.3) { this.y = this.yGoal; this.yGoal = null; }
            moving = true;
        }
        if (!this.pointers.size && !this.wheeling) moving = this.drift(dt) || moving;
        if (moving) this.version++;
        return moving || !!this.settleAt;
    }

    // По вертикали: бросок затухает; за краем рамки — пружина к краю (долетевший бросок отскакивает, как список iOS).
    drift(dt) {
        const { lo, hi } = this.limits();
        const edge = this.y < lo ? lo : this.y > hi ? hi : null;
        if (edge !== null && this.yGoal === null) {
            const [y0, v] = spring(this.y - edge, this.vy, BOUNCE, dt);
            this.y = edge + y0; this.vy = v;
            if (Math.abs(y0) * this.z < 0.3 && Math.abs(v) * this.z < 0.02) { this.y = edge; this.vy = 0; }
            return true;
        }
        if (!this.vy) return false;
        const decay = Math.exp(-FRICTION * dt);
        this.y += (this.vy * (1 - decay)) / FRICTION;
        this.vy *= decay;
        if (Math.abs(this.vy) * this.z < STOP) this.vy = 0;
        return true;
    }

    // Щипок или трекпад кончились: масштаб за пределом — резинкой к пределу, листание вбок — к этапу.
    settle() {
        this.settleAt = 0;
        const { wheeling, coasting } = this;
        this.wheeling = null; this.coasting = false;
        // Трекпад вбок затих, а убывания дельт не было (пальцы замерли и сняты) — к этапу.
        if (wheeling === 'x' && !coasting) this.release(this.wheelV ?? 0);
        const z = clamp(this.z, MIN, MAX);
        this.raw = z;
        if (z === this.z) { this.changed(); return; }
        const oy = this.anchor ?? 0;
        if (this.still) { this.zoomTo(z, oy); this.changed(); return; }
        this.goal = { z, oy };
        this.changed();
    }

    pinchBy(f, oy) {
        this.raw = clamp(this.raw * f, MIN / 4, MAX * 4);
        this.anchor = oy;
        this.zoomTo(soft(this.raw), oy);
        this.limits();
    }

    zoomGoal(z, oy = 0) {
        z = clamp(z, MIN, MAX);
        if (this.still) { this.zoomTo(z, oy); this.changed(); return; }
        this.goal = { z, oy };
        this.changed();
    }

    // ── Палец и мышь ─────────────────────────────────────────────────────────────────────────────────────────────────

    down(e) {
        // Подэтап конвейера — ссылка, но сдвиг с него тоже сдвиг: нажатие после него гасит `click`.
        if (e.button > 0 || e.target.closest('button, input, textarea, select, label, [contenteditable], [data-space-ui]')) return;
        this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        this.dragged = false;
        // Касание ловит доску на месте; взмах дальше считается от этапа, к которому она летела.
        this.from = this.here;
        this.stop();
        this.settleAt = 0;
        if (this.pointers.size === 1) {
            this.moved = false;
            this.axis = null;
            this.start = { x: e.clientX, y: e.clientY };
            this.samples = [{ t: e.timeStamp, x: e.clientX, y: e.clientY }];
            this.rawFx = this.fx; this.rawY = this.y;
        } else {
            this.moved = true;
        }
        this.raw = this.z;
        this.pinch = this.pointers.size === 2 ? this.measure() : null;
        this.wake();
    }

    measure() {
        const [a, b] = [...this.pointers.values()];
        return { d: Math.hypot(a.x - b.x, a.y - b.y) || 1, y: (a.y + b.y) / 2 };
    }

    // Сдвиг пальцем на (dx, dy) экрана — по выбранной оси, за краем резинкой.
    drag(dx, dy) {
        if (this.axis === 'x') {
            this.rawFx -= dx / this.z;
            const c = this.columns;
            this.fx = c.length ? band(this.rawFx, c[0], c[c.length - 1], this.w / this.z) : this.rawFx;
        } else {
            this.rawY -= dy / this.z;
            const { lo, hi } = this.limits();
            this.y = band(this.rawY, lo, hi, this.h / this.z);
        }
    }

    move(e) {
        const p = this.pointers.get(e.pointerId);
        if (!p) return;
        e.preventDefault();
        if (this.pointers.size === 1) {
            if (!this.moved && Math.hypot(e.clientX - this.start.x, e.clientY - this.start.y) < (e.pointerType === 'mouse' ? 3 : MOVE)) return;
            // Указатель забираем, только когда тянут: захваченный с касания уводил бы нажатие с подэтапа на сцену.
            if (!this.moved) { this.el.classList.add('is-grabbing'); this.el.setPointerCapture?.(e.pointerId); }
            this.moved = true;
            this.axis ??= Math.abs(e.clientX - this.start.x) >= Math.abs(e.clientY - this.start.y) ? 'x' : 'y';
            this.drag(e.clientX - p.x, e.clientY - p.y);
            p.x = e.clientX; p.y = e.clientY;
            this.samples.push({ t: e.timeStamp, x: e.clientX, y: e.clientY });
            while (this.samples.length > 2 && e.timeStamp - this.samples[0].t > SAMPLE) this.samples.shift();
            this.changed();
            return;
        }
        if (this.pointers.size !== 2 || !this.pinch) return;
        this.el.setPointerCapture?.(e.pointerId);
        p.x = e.clientX; p.y = e.clientY;
        const now = this.measure();
        // Щипок двигает только вертикаль и масштаб: этап в фокусе стоит на месте.
        this.y -= (now.y - this.pinch.y) / this.z;
        this.pinchBy(now.d / this.pinch.d, this.offsetY(now.y));
        this.pinch = now;
        this.changed();
    }

    up(e, cancel = false) {
        if (!this.pointers.delete(e.pointerId)) return;
        if (this.pointers.size === 1) {
            // Из щипка в один палец: сдвиг продолжается от него, без рывка; бросок после щипка не считаем.
            const [q] = this.pointers.values();
            this.start = { ...q };
            this.axis = null;
            this.samples = [];
            this.rawFx = this.fx; this.rawY = this.y;
            this.pinch = null;
            this.settle();
            return;
        }
        this.pinch = this.pointers.size === 2 ? this.measure() : null;
        if (this.pointers.size) return;
        this.el.classList.remove('is-grabbing');
        this.settle();
        if (this.moved) {
            this.dragged = !cancel;
            const v = this.velocity(e.timeStamp);
            if (this.axis === 'x') this.release(-v.x / this.z);
            else this.throwY(v.y);
        } else {
            this.release(0);
            if (!cancel) this.tap(e);
        }
        this.changed();
    }

    // Скорость пальца, px экрана в мс; палец замер перед отпусканием (дольше `FLING_GAP`) — ноль.
    velocity(t) {
        const s = this.samples ?? [];
        if (s.length < 2 || t - s[s.length - 1].t > FLING_GAP) return { x: 0, y: 0 };
        const a = s[0], b = s[s.length - 1];
        const dt = Math.max(16, b.t - a.t);
        return { x: (b.x - a.x) / dt, y: (b.y - a.y) / dt };
    }

    // Отпустили вбок со скоростью фокуса v: цель — этап, куда долетел бы взмах; дотянули больше трети шага — хотя бы
    // соседний; от этапа, с которого начали (`from`), а не от ближайшего.
    release(v) {
        if (!this.columns.length) return;
        const from = this.from ?? this.index();
        const delta = this.frac(this.fx + v * PROJECT) - from;
        const steps = Math.abs(delta) < NEXT ? 0 : Math.sign(delta) * Math.max(1, Math.round(Math.abs(delta)));
        this.from = null;
        this.goTo(from + steps, v);
    }

    // Бросок по вертикали — только быстрым движением (05.11.2026, владелец: «чуть подвинул — и оно всё ещё едет, бесит»):
    // медленнее `FLING_FROM` — встаёт под пальцем, к `FLING_FULL` инерция плавно набирает силу.
    throwY(vy) {
        const v = Math.abs(vy);
        if (this.still || v < FLING_FROM) return;
        const ramp = Math.min(1, (v - FLING_FROM) / (FLING_FULL - FLING_FROM));
        const k = Math.min(1, 4 / v) * ramp * ramp * (3 - 2 * ramp);
        this.vy = (-vy * k) / this.z;
    }

    // Двойное касание или двойной клик по небу — ближе к этой строке; уже близко — обратно к масштабу 1.
    tap(e) {
        if (e.target.closest?.('a')) { this.lastTap = null; return; }
        const prev = this.lastTap;
        this.lastTap = { t: e.timeStamp, x: e.clientX, y: e.clientY };
        if (!prev || e.timeStamp - prev.t > DOUBLE || Math.hypot(e.clientX - prev.x, e.clientY - prev.y) > DOUBLE_GAP) return;
        this.lastTap = null;
        this.zoomGoal(this.z > 1.6 ? 1 : this.z * 2, this.offsetY(e.clientY));
    }

    // Нажатие после перетаскивания — не нажатие: сдвиг, начатый на подэтапе, его не открывает.
    click(e) {
        if (!this.dragged) return;
        this.dragged = false;
        e.preventDefault();
        e.stopPropagation();
    }

    // ── Колесо и трекпад ─────────────────────────────────────────────────────────────────────────────────────────────

    wheel(e) {
        if (e.target.closest?.('[data-space-ui]')) return;
        e.preventDefault();
        const line = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? this.h : 1;
        let dx = e.deltaX * line, dy = e.deltaY * line;
        if (e.ctrlKey || e.metaKey) {
            // Щипок трекпада (браузер шлёт его колесом с ctrl) — сразу, без сглаживания: он и так плавный.
            if (this.safari) return;
            this.goal = null; this.vy = 0;
            if (!this.settleAt) this.raw = this.z;
            this.pinchBy(Math.exp(-dy * 0.01), this.offsetY(e.clientY));
            this.settleAt = performance.now() + 160;
            this.changed();
            return;
        }
        // Мышь — щелчками по строке или крупными целыми шагами без сдвига вбок; трекпад — мелкими и дробными.
        const mouse = e.deltaMode === 1 || (dx === 0 && Number.isInteger(dy) && Math.abs(dy) >= 50 && (e.wheelDeltaY === undefined || e.wheelDeltaY % 120 === 0));
        if (mouse) {
            // Колесо мыши — прокрутка вверх-вниз, с shift — к соседнему этапу (браузер отдаёт его то в deltaX, то в deltaY).
            if (e.shiftKey || dx) { this.goTo(this.here + Math.sign(dx || dy), 0); return; }
            this.vy = 0;
            const { lo, hi } = this.limits();
            this.yGoal = clamp((this.yGoal ?? this.y) + dy / this.z, lo, hi);
            this.changed();
            return;
        }
        if (e.shiftKey && !dx) { dx = dy; dy = 0; }
        // Трекпад: ось выбирает первое событие жеста, пауза дольше `WHEEL_GAP` — новый жест.
        const fresh = !this.wheeling || e.timeStamp - this.wheelAt > WHEEL_GAP;
        if (fresh) this.wheelStart(Math.abs(dx) >= Math.abs(dy) ? 'x' : 'y');
        const gap = e.timeStamp - this.wheelAt;
        this.wheelAt = e.timeStamp;
        if (this.wheeling === 'x') this.wheelX(dx, gap);
        else if (this.wheeling === 'y') {
            this.axis = 'y';
            this.drag(0, -dy);
            this.settleAt = performance.now() + WHEEL_REST;
        }
        this.changed();
    }

    wheelStart(axis) {
        this.from = this.here;
        this.stop();
        this.wheeling = axis;
        this.axis = axis;
        this.rawFx = this.fx; this.rawY = this.y;
        this.wheelDeltas = [];
        this.coasting = false;
    }

    // Вбок трекпадом: пока пальцы на трекпаде — доска едет 1:1. Дельты начали убывать — это уже инерция macOS: пальцы сняли,
    // листаем к этапу по скорости последних дельт, а хвост инерции пропускаем, пока дельты снова не вырастут (новый взмах).
    wheelX(dx, gap) {
        const d = this.wheelDeltas;
        if (this.coasting) {
            const prev = d[d.length - 1] ?? 0;
            d.push(Math.abs(dx));
            if (Math.abs(dx) > Math.max(4, prev * 1.4)) this.wheelStart('x');
            else { this.settleAt = performance.now() + WHEEL_GAP; return; }
        }
        d.push(Math.abs(dx));
        this.drag(-dx, 0);
        // Скорость — по последним 4 дельтам: px за мс.
        this.wheelV = (dx / Math.max(8, Math.min(gap, 40))) / this.z;
        const n = d.length;
        const falling = n >= 4 && d[n - 1] < d[n - 2] && d[n - 2] < d[n - 3] && d[n - 1] < Math.max(...d) * 0.6;
        if (falling) {
            this.coasting = true;
            this.release(this.wheelV);
            this.wheelDeltas = [Math.abs(dx)];
            this.settleAt = performance.now() + WHEEL_GAP;
            return;
        }
        this.settleAt = performance.now() + WHEEL_REST;
    }

    // Safari: щипок трекпада — свои события жеста (колесо с ctrl он при этом тоже шлёт — его пропускаем). На iPhone те же
    // события идут и при щипке пальцами, вместе с указателями: щипок там уже считает `move`, жест — мимо.
    gesture(e, phase) {
        e.preventDefault();
        if (this.pointers.size) { this.safari = 0; return; }
        if (phase === 'start') { this.safari = 1; this.raw = this.z; this.goal = null; return; }
        if (!this.safari) return;
        this.pinchBy(e.scale / this.safari, this.offsetY(e.clientY));
        this.safari = e.scale;
        if (phase === 'end') { this.safari = 0; this.settle(); }
        this.changed();
    }

    key(e) {
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
        // Только когда фокус ни на чём или на самой сцене: стрелки у ссылки шапки или таб-бара — их собственные.
        const focus = document.activeElement;
        if ((focus && focus !== document.body && !this.el.contains(focus)) || document.documentElement.classList.contains('sheet-open')) return;
        const keys = {
            ArrowLeft: () => this.goTo(this.here - 1, 0),
            ArrowRight: () => this.goTo(this.here + 1, 0),
            Home: () => this.goTo(0, 0),
            End: () => this.goTo(this.columns.length - 1, 0),
            ArrowUp: () => { this.yGoal = null; this.vy = this.still ? 0 : -0.9 / this.z; if (this.still) this.y -= 220 / this.z; this.changed(); },
            ArrowDown: () => { this.yGoal = null; this.vy = this.still ? 0 : 0.9 / this.z; if (this.still) this.y += 220 / this.z; this.changed(); },
            '+': () => this.zoomGoal((this.goal?.z ?? this.z) * 1.4),
            '=': () => this.zoomGoal((this.goal?.z ?? this.z) * 1.4),
            '-': () => this.zoomGoal((this.goal?.z ?? this.z) / 1.4),
            _: () => this.zoomGoal((this.goal?.z ?? this.z) / 1.4),
            0: () => { this.zoomGoal(1); this.goTo(0, 0); },
        };
        if (!keys[e.key]) return;
        e.preventDefault();
        keys[e.key]();
    }
}
