// Камера космоса главной CRM (04.11.2026): точка мира в середине сцены (x, y) и масштаб z. Одна на звёзды (`sky.js`,
// каждая на своей глубине) и слой схемы (глубина 1) — схема потом поедет вместе с фоном.
// Жесты — как у схемы парковки (`park/plan/viewport.js`): палец или мышь — сдвиг, два пальца — щипок со сдвигом,
// ctrl/⌘ + колесо и щипок трекпада — масштаб, двойной тап — ближе. Сверх того физика: брошенный сдвиг катится и
// затухает, колесо мыши масштабирует плавно к курсору, за пределами масштаба щипок упирается резинкой и отпускает
// обратно, как в iOS. Колесо без ctrl: трекпад двумя пальцами — сдвиг, мышь — масштаб, как в картах.
// Кадры камера не заводит: `step(now)` зовёт цикл `space_controller`, `wake` — чтобы он проснулся.

const MOVE = 6;           // порог сдвига, px: касание без сдвига остаётся нажатием
export const MIN = 0.25;
export const MAX = 4;
const FRICTION = 0.004;   // затухание броска, 1/мс: скорость × e^(−k·dt), за полсекунды остаётся 13 %
const STOP = 0.012;       // px/мс — медленнее не катится
const SAMPLE = 90;        // скорость броска — по последним мс движения
const SMOOTH = 0.016;     // масштаб колесом догоняет цель: за ~150 мс
const FLY = 700;          // «К схеме», мс
const DOUBLE = 300;       // двойной тап: мс и px между касаниями
const DOUBLE_GAP = 30;

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
// За пределом масштаба щипок не обрывается, а упирается: каждая следующая доля — всё меньше.
const soft = (z) => (z > MAX ? MAX * (z / MAX) ** 0.3 : z < MIN ? MIN * (z / MIN) ** 0.3 : z);
const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2);

export class Camera {
    constructor(el, { wake = () => {}, still = false } = {}) {
        Object.assign(this, { el, wake, still, x: 0, y: 0, z: 1, w: 1, h: 1, version: 0 });
        this.pointers = new Map();
        this.vx = 0; this.vy = 0;         // бросок, px экрана в мс
        this.goal = null;                 // масштаб колесом: { z, ox, oy } — к точке от середины сцены
        this.fly = null;                  // «К схеме»: { from, to, t0 }
        this.raw = 1;                     // масштаб щипка без резинки
        this.settleAt = 0;                // щипок трекпада кончился — когда отпустить резинку
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

    resize(w, h) {
        this.w = w || 1; this.h = h || 1;
        this.changed();
    }

    changed() {
        this.version++;
        this.wake();
    }

    // Точка экрана (clientX/Y) — от середины сцены.
    offset(cx, cy) {
        const r = this.el.getBoundingClientRect();
        return { ox: cx - r.left - r.width / 2, oy: cy - r.top - r.height / 2 };
    }

    panBy(dx, dy) {
        this.x -= dx / this.z;
        this.y -= dy / this.z;
    }

    // Новый масштаб z вокруг точки (ox, oy) от середины: точка мира под ней остаётся на месте.
    zoomTo(z, ox = 0, oy = 0) {
        const wx = this.x + ox / this.z;
        const wy = this.y + oy / this.z;
        this.z = z;
        this.x = wx - ox / z;
        this.y = wy - oy / z;
    }

    stop() {
        this.vx = 0; this.vy = 0;
        this.goal = null;
        this.fly = null;
    }

    // Вид — в sessionStorage: «Назад» на главную возвращает туда же.
    snapshot() {
        return { x: Math.round(this.x * 10) / 10, y: Math.round(this.y * 10) / 10, z: Math.round(this.z * 1000) / 1000 };
    }

    restore({ x, y, z } = {}) {
        if (![x, y, z].every(Number.isFinite)) return;
        Object.assign(this, { x, y, z: clamp(z, MIN, MAX) });
        this.changed();
    }

    // «К схеме»: плавно в начало мира, масштаб 1.
    home() {
        this.stop();
        const to = { x: 0, y: 0, z: 1 };
        if (this.still) { Object.assign(this, to); this.changed(); return; }
        this.fly = { from: { x: this.x, y: this.y, z: this.z }, to, t0: performance.now() };
        this.changed();
    }

    // Схема (начало мира) за краем сцены — кнопка «К схеме» нужна.
    lost() {
        const sx = -this.x * this.z;
        const sy = -this.y * this.z;
        return Math.abs(sx) > this.w / 2 || Math.abs(sy) > this.h / 2 || this.z < 0.5 || this.z > 2.5;
    }

    get busy() {
        return this.pointers.size > 0;
    }

    // Шаг физики к моменту now; true — ещё движется, нужен следующий кадр.
    step(now) {
        // После сна цикла (покой, скрытая вкладка) первый шаг — как обычный кадр, а не прыжок на всю паузу.
        const dt = this.at && now - this.at < 100 ? now - this.at : 16;
        this.at = now;
        let moving = this.pointers.size > 0;
        if (this.fly) {
            const t = Math.min(1, (now - this.fly.t0) / FLY);
            const k = ease(t);
            const { from, to } = this.fly;
            this.x = from.x + (to.x - from.x) * k;
            this.y = from.y + (to.y - from.y) * k;
            this.z = Math.exp(Math.log(from.z) + (Math.log(to.z) - Math.log(from.z)) * k);
            this.version++;
            if (t >= 1) this.fly = null;
            return true;
        }
        if (this.vx || this.vy) {
            // Точное решение затухания: путь за dt — v·(1 − e^(−k·dt))/k, кадры любой частоты едут одинаково.
            const decay = Math.exp(-FRICTION * dt);
            const path = (1 - decay) / FRICTION;
            this.panBy(this.vx * path, this.vy * path);
            this.vx *= decay; this.vy *= decay;
            if (Math.hypot(this.vx, this.vy) < STOP) { this.vx = 0; this.vy = 0; }
            this.version++;
            moving = true;
        }
        if (this.settleAt && now >= this.settleAt && !this.pointers.size) this.settle();
        if (this.goal) {
            const k = 1 - Math.exp(-SMOOTH * dt);
            const z = Math.exp(Math.log(this.z) + (Math.log(this.goal.z) - Math.log(this.z)) * k);
            const done = Math.abs(Math.log(this.goal.z / z)) < 0.002;
            this.zoomTo(done ? this.goal.z : z, this.goal.ox, this.goal.oy);
            if (done) this.goal = null;
            this.version++;
            moving = true;
        }
        return moving || !!this.settleAt;
    }

    // Щипок кончился за пределом — резинка отпускает к пределу вокруг той же точки.
    settle() {
        this.settleAt = 0;
        const z = clamp(this.z, MIN, MAX);
        this.raw = z;
        if (z === this.z) return;
        const { ox, oy } = this.anchor ?? { ox: 0, oy: 0 };
        if (this.still) { this.zoomTo(z, ox, oy); this.changed(); return; }
        this.goal = { z, ox, oy };
        this.changed();
    }

    pinchBy(f, ox, oy) {
        this.raw = clamp(this.raw * f, MIN / 4, MAX * 4);
        this.anchor = { ox, oy };
        this.zoomTo(soft(this.raw), ox, oy);
    }

    down(e) {
        if (e.button > 0 || e.target.closest('button, a, input, textarea, select, [contenteditable]')) return;
        this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        this.dragged = false;
        this.stop();
        this.settleAt = 0;
        if (this.pointers.size === 1) {
            this.moved = false;
            this.start = { x: e.clientX, y: e.clientY, t: e.timeStamp };
            this.samples = [{ t: e.timeStamp, x: e.clientX, y: e.clientY }];
        } else {
            this.moved = true;
        }
        this.raw = this.z;
        this.pinch = this.pointers.size === 2 ? this.measure() : null;
        this.wake();
    }

    measure() {
        const [a, b] = [...this.pointers.values()];
        return { d: Math.hypot(a.x - b.x, a.y - b.y) || 1, x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    }

    move(e) {
        const p = this.pointers.get(e.pointerId);
        if (!p) return;
        e.preventDefault();
        if (this.pointers.size === 1) {
            if (!this.moved && Math.hypot(e.clientX - this.start.x, e.clientY - this.start.y) < (e.pointerType === 'mouse' ? 3 : MOVE)) return;
            // Указатель забираем, только когда тянут: захваченный с касания уводил бы нажатие с блока схемы на сцену.
            if (!this.moved) { this.el.classList.add('is-grabbing'); this.el.setPointerCapture?.(e.pointerId); }
            this.moved = true;
            this.panBy(e.clientX - p.x, e.clientY - p.y);
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
        this.panBy(now.x - this.pinch.x, now.y - this.pinch.y);
        const { ox, oy } = this.offset(now.x, now.y);
        this.pinchBy(now.d / this.pinch.d, ox, oy);
        this.pinch = now;
        this.changed();
    }

    up(e, cancel = false) {
        if (!this.pointers.delete(e.pointerId)) return;
        if (this.pointers.size === 1) {
            // Из щипка в один палец: сдвиг продолжается от него, без рывка; бросок после щипка не считаем.
            const [q] = this.pointers.values();
            this.start = { ...q, t: e.timeStamp };
            this.samples = [];
            this.pinch = null;
            this.settle();
            return;
        }
        this.pinch = this.pointers.size === 2 ? this.measure() : null;
        if (this.pointers.size) return;
        this.el.classList.remove('is-grabbing');
        this.settle();
        if (cancel) { this.changed(); return; }
        if (this.moved) {
            this.dragged = true;
            this.fling(e.timeStamp);
        } else {
            this.tap(e);
        }
        this.changed();
    }

    fling(t) {
        const s = this.samples ?? [];
        if (this.still || s.length < 2 || t - s[s.length - 1].t > 100) return;
        const a = s[0], b = s[s.length - 1];
        const dt = Math.max(16, b.t - a.t);
        const vx = (b.x - a.x) / dt, vy = (b.y - a.y) / dt;
        const v = Math.hypot(vx, vy);
        if (v < 0.08) return;
        // Сильный бросок не улетает за тридевять земель: скорость не больше 4 px/мс (~1000 px пути).
        const k = Math.min(1, 4 / v);
        this.vx = vx * k; this.vy = vy * k;
    }

    // Двойное касание или двойной клик — вдвое ближе к этой точке.
    tap(e) {
        const prev = this.lastTap;
        this.lastTap = { t: e.timeStamp, x: e.clientX, y: e.clientY };
        if (!prev || e.timeStamp - prev.t > DOUBLE || Math.hypot(e.clientX - prev.x, e.clientY - prev.y) > DOUBLE_GAP) return;
        this.lastTap = null;
        const { ox, oy } = this.offset(e.clientX, e.clientY);
        this.zoomGoal(this.z * 2, ox, oy);
    }

    zoomGoal(z, ox, oy) {
        z = clamp(z, MIN, MAX);
        if (this.still) { this.zoomTo(z, ox, oy); this.changed(); return; }
        this.goal = { z, ox, oy };
        this.changed();
    }

    // Нажатие после перетаскивания — не нажатие: сдвиг, начатый на блоке схемы, его не открывает.
    click(e) {
        if (!this.dragged) return;
        this.dragged = false;
        e.preventDefault();
        e.stopPropagation();
    }

    wheel(e) {
        e.preventDefault();
        const { ox, oy } = this.offset(e.clientX, e.clientY);
        const line = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? this.h : 1;
        const dx = e.deltaX * line, dy = e.deltaY * line;
        this.fly = null; this.vx = 0; this.vy = 0;
        if (e.ctrlKey || e.metaKey) {
            // Щипок трекпада (браузер шлёт его колесом с ctrl) — сразу, без сглаживания: он и так плавный.
            if (this.safari) return;
            this.goal = null;
            if (!this.settleAt) this.raw = this.z;
            this.pinchBy(Math.exp(-dy * 0.01), ox, oy);
            this.settleAt = performance.now() + 160;
            this.changed();
            return;
        }
        // Мышь — щелчками по строке или крупными целыми шагами без сдвига вбок; трекпад — мелкими и дробными.
        const mouse = e.deltaMode === 1 || (dx === 0 && Number.isInteger(dy) && Math.abs(dy) >= 50 && (e.wheelDeltaY === undefined || e.wheelDeltaY % 120 === 0));
        if (mouse) {
            this.zoomGoal((this.goal?.z ?? this.z) * Math.exp(-dy * 0.0022), ox, oy);
            return;
        }
        this.goal = null;
        this.panBy(-dx, -dy);
        this.changed();
    }

    // Safari: щипок трекпада — свои события жеста (колесо с ctrl он при этом тоже шлёт — его пропускаем).
    gesture(e, phase) {
        e.preventDefault();
        if (phase === 'start') { this.safari = 1; this.raw = this.z; this.stop(); return; }
        if (!this.safari) return;
        const { ox, oy } = this.offset(e.clientX, e.clientY);
        this.pinchBy(e.scale / this.safari, ox, oy);
        this.safari = e.scale;
        if (phase === 'end') { this.safari = 0; this.settle(); }
        this.changed();
    }

    key(e) {
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
        if (e.target.closest?.('input, textarea, select, [contenteditable], dialog') || document.documentElement.classList.contains('sheet-open')) return;
        const push = 0.9;   // px/мс: бросок клавишей — около 220 px пути
        const moves = { ArrowLeft: [push, 0], ArrowRight: [-push, 0], ArrowUp: [0, push], ArrowDown: [0, -push] };
        if (moves[e.key]) {
            e.preventDefault();
            this.goal = null; this.fly = null;
            if (this.still) { this.panBy(moves[e.key][0] * 220, moves[e.key][1] * 220); this.changed(); return; }
            [this.vx, this.vy] = moves[e.key];
            this.changed();
        } else if (e.key === '+' || e.key === '=') {
            e.preventDefault();
            this.zoomGoal((this.goal?.z ?? this.z) * 1.4, 0, 0);
        } else if (e.key === '-' || e.key === '_') {
            e.preventDefault();
            this.zoomGoal((this.goal?.z ?? this.z) / 1.4, 0, 0);
        } else if (e.key === '0') {
            e.preventDefault();
            this.home();
        }
    }
}
