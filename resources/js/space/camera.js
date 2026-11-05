// Камера космоса главной CRM (04.11.2026; листание по этапам — 05.11.2026): точка мира в середине сцены (x, y) и масштаб z.
// Одна на звёзды (`sky.js`, каждая на своей глубине) и слой конвейера (глубина 1): схема едет вместе с фоном.
//
// - **вбок — переключатель этапов** (05.11.2026, владелец: «не скролл с тормозами, а именно свайпы»; из двух вариантов —
//   «Переключатель»). Камера правит не x, а фокус `fx` — точку мира в `FOCUS` ширины экрана, чуть левее середины: слева
//   заглядывает прошлый этап, справа — следующий. Палец доску не тащит: до срабатывания она только откликается на несколько
//   px (`nudge`, сильная резинка). Палец ушёл вбок на `COMMIT` — сразу, не дожидаясь отпускания, одна анимация `SWITCH` к
//   соседнему этапу; остаток жеста не считается: один свайп — один этап. Отпустили раньше, но взмахом — тоже переход, иначе
//   отклик обратно в ноль (`BACK`, сразу полной скоростью). Свайп посреди перехода не ловит доску, а продлевает переход к
//   следующему: пять быстрых свайпов — пять этапов. Свайп за первый или последний этап — короткий упор туда и обратно (`BUMP`);
// - **вверх-вниз — свободно** с броском; только чтобы доску не потерять, она не уходит с экрана целиком (`EDGE`);
// - **масштаб** — щипок, ctrl/⌘ + колесо, двойной тап, `+`/`−`: по горизонтали якорь всегда в фокусе, по вертикали — под
//   пальцем.
// Ось жеста — по первому движению (владелец: «не по диагонали»). Кадры камера не заводит: `step(now)` зовёт цикл
// `space_controller`, `wake` — чтобы он проснулся.

const MOVE = 6;           // порог сдвига, px: касание без сдвига остаётся нажатием
export const MIN = 0.4;
export const MAX = 2.5;
export const FOCUS = 0.46;  // этап в фокусе — центром чуть левее середины экрана
const FRICTION = 0.004;   // затухание броска по вертикали, 1/мс: за полсекунды остаётся 13 %
const STOP = 0.012;       // px/мс — медленнее не катится
const SAMPLE = 90;        // скорость пальца — по последним мс движения
const FLING_FROM = 0.45;  // px/мс: медленнее — вертикальный бросок без инерции
const FLING_FULL = 1.2;
const FLING_GAP = 80;     // мс между последним движением и отпусканием — дольше: палец замер, броска нет
const COMMIT = 32;        // px пальца вбок — свайп сработал, сразу, до отпускания
const COMMIT_WHEEL = 40;  // то же трекпадом
const FLICK = 0.25;       // px/мс: отпустили раньше порога, но взмахом — тоже свайп
const FLICK_MIN = 10;     // px — и хоть настолько
const GIVE = 28;          // px: предел отклика доски пальцу до срабатывания
const BACK = 180;         // мс: отпустили без свайпа — отклик обратно в ноль, сразу полной скоростью и с замедлением
const BUMP = 22;          // px: свайп за первый или последний этап — доска упирается сама: туда за `BUMP_OUT`, обратно за `BUMP_BACK`
const BUMP_OUT = 90;
const BUMP_BACK = 200;
const SWITCH = 230;       // мс перехода на соседний этап; дальше — по корню из числа шагов
const SWITCH_MIN = 140;
const SWITCH_MAX = 420;
const EASE = 2.6;         // начальный наклон перехода: 2 — ease-out quad, 3 — cubic; выше 3 — перелёт
const EDGE = 120;         // px: доска не уходит с экрана вверх или вниз дальше, чем оставив столько
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
// Отклик пальцу: сдвиг d px растёт всё медленнее, не дальше R.
const give = (d, R) => Math.sign(d) * (1 - 1 / ((Math.abs(d) * BAND) / R + 1)) * R;
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
        this.target = null;               // этап, к которому едет довод
        this.anim = null;                 // переход: { a, b, t0, D, m0 }
        this.nudge = 0;                   // отклик пальцу до срабатывания свайпа, px экрана
        this.back = null;                 // отклик едет сам: { a, peak, t0 } — обратно в ноль или упор у края
        this.nudging = false;             // отклик держит палец или трекпад (иначе — пружиной в ноль)
        this.swiped = false;              // свайп этого жеста уже сработал
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
        return this.fx + ((0.5 - FOCUS) * this.w - this.nudge) / this.z;
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
            this.y = Number.isFinite(v.y) ? clamp(v.y, this.rows.lo, this.rows.hi) : this.rest();
        } else if (!this.pointers.size && this.target === null) {
            this.fx = columns[clamp(focus, 0, columns.length - 1)];
        }
        this.limits();
        this.changed();
    }

    // Где может стоять y: свободно, лишь бы доска не ушла с экрана целиком — у края остаётся `EDGE` px её верха или низа.
    limits() {
        if (!this.board) return this.rows;
        const { y0, y1 } = this.board;
        const m = (this.h / 2 - EDGE) / this.z;
        this.rows = { lo: y0 - m, hi: Math.max(y0 - m, y1 + m) };
        return this.rows;
    }

    // Вид по вертикали при первом заходе: доска ниже середины экрана не уезжает, а выше верха не липнет — короткая стоит
    // чуть выше середины, высокая — верхом на десятой части экрана.
    rest() {
        const { y0, y1 } = this.board;
        const top = Math.max(this.h * 0.1, (this.h - (y1 - y0) * this.z) * 0.42, this.pad.top);
        return y0 + (this.h / 2 - top) / this.z;
    }

    // Шаг между соседними этапами в мире.
    pitch() {
        const c = this.columns;
        return c.length > 1 ? (c[c.length - 1] - c[0]) / (c.length - 1) : this.w / this.z;
    }

    // Ближайший этап к точке мира (по умолчанию — к фокусу).
    index(at = this.fx) {
        let best = 0;
        for (let i = 1; i < this.columns.length; i++) if (Math.abs(this.columns[i] - at) < Math.abs(this.columns[best] - at)) best = i;
        return best;
    }

    // Этап, от которого считается следующий шаг: куда уже летим, иначе — ближайший. Быстрые взмахи подряд складываются.
    get here() {
        return this.target ?? this.index();
    }

    // К этапу i переходом: кубическая кривая Эрмита от места к этапу, конечный наклон — ноль. Начальный наклон 2 — ровное
    // замедление (ease-out quad): быстрый старт, конец без долгого доезда. Переход посреди перехода начинается с текущей
    // скорости (`v`, px мира в мс), если она больше, — без рывка; наклон до 3 — без перелёта.
    goTo(i, v = this.vfx) {
        if (!this.columns.length) return;
        this.target = clamp(i, 0, this.columns.length - 1);
        const to = this.columns[this.target];
        const ds = Math.abs(to - this.fx) * this.z;
        if (this.still || ds < 0.5) { this.fx = to; this.vfx = 0; this.target = null; this.anim = null; this.changed(); return; }
        const steps = Math.abs(to - this.fx) / this.pitch();
        const D = clamp(SWITCH * Math.sqrt(steps), SWITCH_MIN, SWITCH_MAX);
        const vs = v * Math.sign(to - this.fx) * this.z;
        const m0 = clamp((vs * D) / ds, EASE, 3);
        this.anim = { a: this.fx, b: to, t0: performance.now(), D, m0 };
        this.changed();
    }

    // Свайп сработал: отклик вливается в фокус (на экране ничего не прыгает), переход к соседнему этапу в сторону d.
    commit(d) {
        const i = this.here + d;
        this.swiped = true;
        this.nudging = false;
        this.fx -= this.nudge / this.z;
        this.nudge = 0; this.back = null;
        this.goTo(i);
    }

    // Палец (или трекпад) ушёл вбок на total px экрана от начала жеста: дошёл до порога `at` — свайп, иначе — отклик.
    // true — сработал.
    swipeX(total, at = COMMIT) {
        if (this.swiped || !this.columns.length) return false;
        const d = total < 0 ? 1 : -1;
        const i = this.here + d;
        const edge = i < 0 || i >= this.columns.length;
        if (Math.abs(total) >= at) {
            if (!edge) { this.commit(d); return true; }
            // Дальше некуда: не резинка за пальцем, а один короткий упор — как переход, только туда и обратно.
            this.swiped = true;
            this.nudging = false;
            this.back = { a: this.nudge, peak: Math.sign(total) * BUMP, t0: performance.now() };
            this.changed();
            return true;
        }
        this.nudging = true;
        this.back = null;
        this.nudge = give(total, GIVE);
        this.changed();
        return false;
    }

    // Сразу на этап, без полёта (фильтр поставил этап на новое место — на экране он стоит, где стоял).
    jumpTo(i) {
        if (!this.columns.length) return;
        this.target = null; this.vfx = 0; this.anim = null; this.nudge = 0; this.back = null;
        this.fx = this.columns[clamp(i, 0, this.columns.length - 1)];
        this.changed();
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
        if (this.anim) {
            const { a, b, t0, D, m0 } = this.anim;
            const t = clamp((now - t0) / D, 0, 1);
            const p = m0 * (t ** 3 - 2 * t * t + t) + 3 * t * t - 2 * t ** 3;
            const dp = m0 * (3 * t * t - 4 * t + 1) + 6 * t - 6 * t * t;
            this.fx = a + (b - a) * p;
            this.vfx = ((b - a) * dp) / D;
            if (t >= 1) { this.fx = b; this.vfx = 0; this.anim = null; this.target = null; }
            moving = true;
        }
        if (!this.nudging && this.nudge && !this.back) this.back = { a: this.nudge, peak: null, t0: now };
        if (this.back) {
            // Без упора — обратно сразу полной скоростью и с замедлением (ease-out cubic). С упором — туда с замедлением,
            // обратно плавно с места (smoothstep): в точке упора скорость ноль, излома нет.
            const { a, peak, t0 } = this.back;
            let t = now - t0;
            if (peak !== null && t < BUMP_OUT) {
                const p = 1 - (1 - t / BUMP_OUT) ** 2;
                this.nudge = a + (peak - a) * p;
            } else {
                const from = peak ?? a;
                if (peak !== null) t -= BUMP_OUT;
                const q = clamp(t / (peak !== null ? BUMP_BACK : BACK), 0, 1);
                const p = peak !== null ? 3 * q * q - 2 * q ** 3 : 1 - (1 - q) ** 3;
                this.nudge = from * (1 - p);
                if (q >= 1) { this.nudge = 0; this.back = null; }
            }
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
        const { wheeling } = this;
        this.wheeling = null; this.coasting = null;
        // Трекпад вбок затих, свайп не сработал — отклик обратно в ноль.
        if (wheeling === 'x') this.nudging = false;
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
        // Касание ловит вертикальный бросок и масштаб, но не переход вбок: новый свайп его продлит.
        this.vy = 0; this.yGoal = null; this.goal = null;
        this.swiped = false;
        this.settleAt = 0;
        if (this.pointers.size === 1) {
            this.moved = false;
            this.axis = null;
            this.start = { x: e.clientX, y: e.clientY };
            this.samples = [{ t: e.timeStamp, x: e.clientX, y: e.clientY }];
            this.rawY = this.y;
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

    // Сдвиг пальцем по вертикали на dy px экрана, за краем — резинкой.
    dragY(dy) {
        this.rawY -= dy / this.z;
        const { lo, hi } = this.limits();
        this.y = band(this.rawY, lo, hi, this.h / this.z);
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
            if (this.axis === 'x') this.swipeX(e.clientX - this.start.x);
            else this.dragY(e.clientY - p.y);
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
            this.rawY = this.y;
            this.swiped = true;          // после щипка свайпа в этом жесте нет
            this.nudging = false;
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
            if (this.axis === 'x') {
                const total = e.clientX - this.start.x;
                // Не дотянули до порога, но взмахнули в ту же сторону — тоже свайп.
                if (!cancel && Math.abs(v.x) >= FLICK && Math.abs(total) >= FLICK_MIN && Math.sign(v.x) === Math.sign(total)) this.swipeX(total, 0);
                this.nudging = false;
            } else this.throwY(v.y);
        } else {
            this.nudging = false;
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
            if (e.shiftKey || dx) { this.goTo(this.here + Math.sign(dx || dy)); return; }
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
        this.wheelAt = e.timeStamp;
        if (this.wheeling === 'x') this.wheelX(dx);
        else if (this.wheeling === 'y') {
            this.axis = 'y';
            this.dragY(-dy);
            this.settleAt = performance.now() + WHEEL_REST;
        }
        this.changed();
    }

    wheelStart(axis) {
        this.vy = 0; this.yGoal = null;
        this.wheeling = axis;
        this.axis = axis;
        this.rawY = this.y;
        this.wheelSum = 0;
        this.swiped = false;
        this.coasting = null;
    }

    // Вбок трекпадом: дельты жеста копятся откликом; сумма дошла до `COMMIT_WHEEL` — свайп. Дальше жест заперт: остаток
    // пальцев и инерция macOS не листают. Отпирает новый взмах — дельты сначала пошли на убыль (инерция), потом снова выросли
    // (пальцы взмахнули ещё раз), — или пауза `WHEEL_GAP` (новый жест).
    wheelX(dx) {
        const a = Math.abs(dx);
        const c = this.coasting;
        if (c) {
            if (c.fell && a > Math.max(4, c.prev * 1.4)) this.wheelStart('x');
            else {
                c.peak = Math.max(c.peak, a);
                c.fell ||= a < c.peak * 0.6;
                c.prev = a;
                this.settleAt = performance.now() + WHEEL_GAP;
                return;
            }
        }
        this.wheelSum += dx;
        if (this.swipeX(-this.wheelSum, COMMIT_WHEEL)) {
            this.coasting = { peak: a, fell: false, prev: a };
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
            ArrowLeft: () => this.goTo(this.here - 1),
            ArrowRight: () => this.goTo(this.here + 1),
            Home: () => this.goTo(0),
            End: () => this.goTo(this.columns.length - 1),
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
