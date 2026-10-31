// Сдвиг и масштаб схемы парковки: меняется viewBox, а не CSS-transform — подписи мест чёткие при любом увеличении.
// Один палец или мышь — сдвиг, два пальца — щипок, колесо — сдвиг (с ctrl или щипок трекпада — масштаб), двойной клик —
// ближе. Касание без сдвига — onTap. grab(e) — указатель забирает вызывающий (перетаскивание машины или ряда).
// Глобально зум выключен (touch-action pan-x pan-y, noZoom) — у холста свой touch-action: none (`.plan-live`).
const MOVE = 6;

export class Viewport {
    constructor(svg, { onTap = () => {}, grab = () => false, min = 5, onChange = () => {} } = {}) {
        Object.assign(this, { onTap, grab, min, onChange, pointers: new Map(), moved: false });
        this.handlers = {
            pointerdown: (e) => this.down(e),
            pointermove: (e) => this.move(e),
            pointerup: (e) => this.up(e),
            pointercancel: (e) => this.up(e, true),
            wheel: (e) => this.wheel(e),
            dblclick: (e) => { if (!this.grab(e)) this.zoom(2, this.point(e)); },
            gesturestart: (e) => e.preventDefault(),
        };
        this.attach(svg);
    }

    // Морф заменил атрибуты (или сам узел) — вид остаётся прежним.
    attach(svg) {
        if (this.svg && this.svg !== svg) this.detach();
        if (this.svg !== svg) {
            this.svg = svg;
            for (const [name, fn] of Object.entries(this.handlers)) svg.addEventListener(name, fn, { passive: false });
        }
        svg.classList.add('plan-live');
        const full = (svg.dataset.box || svg.getAttribute('viewBox')).split(/\s+/).map(Number);
        if (!this.box || full.join() !== this.full?.join()) this.reset(full);
        else this.apply();
        this.resize?.disconnect();
        this.resize = new ResizeObserver(() => this.apply());
        this.resize.observe(svg);
    }

    detach() {
        for (const [name, fn] of Object.entries(this.handlers)) this.svg?.removeEventListener(name, fn);
        this.resize?.disconnect();
    }

    // Границы схемы поменялись (редактор: ряд добавили или увезли) — вид остаётся, меняются только пределы.
    bound(full) {
        this.full = full;
        this.apply();
    }

    // Схема другой величины — вписать заново.
    reset(full) {
        this.full = full;
        this.fit();
    }

    // Высота к ширине у холста: viewBox держит ту же пропорцию, «meet» не оставляет полей.
    ratio() {
        const r = this.svg.getBoundingClientRect();
        return r.width > 0 && r.height > 0 ? r.height / r.width : this.full[3] / this.full[2];
    }

    point(e) {
        const m = this.svg.getScreenCTM();
        if (!m) return { x: 0, y: 0 };
        const p = new DOMPoint(e.clientX, e.clientY).matrixTransform(m.inverse());
        return { x: p.x, y: p.y };
    }

    // Метров в экранном пикселе (meet — по большей стороне).
    scale() {
        return this.box[2] / (this.svg.getBoundingClientRect().width || 1);
    }

    down(e) {
        if (e.button > 0) return;
        if (this.grab(e)) return;
        this.svg.setPointerCapture?.(e.pointerId);
        this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (this.pointers.size === 1) { this.moved = false; this.start = { x: e.clientX, y: e.clientY }; this.downEvent = e; }
        else this.moved = true;
        this.pinch = this.pointers.size === 2 ? this.measure() : null;
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
            if (!this.moved && Math.hypot(e.clientX - this.start.x, e.clientY - this.start.y) < MOVE) return;
            this.moved = true;
            const k = this.scale();
            this.box[0] -= (e.clientX - p.x) * k;
            this.box[1] -= (e.clientY - p.y) * k;
            p.x = e.clientX; p.y = e.clientY;
            this.apply();
            return;
        }
        p.x = e.clientX; p.y = e.clientY;
        const now = this.measure();
        const k = this.scale();
        this.box[0] -= (now.x - this.pinch.x) * k;
        this.box[1] -= (now.y - this.pinch.y) * k;
        this.zoom(now.d / this.pinch.d, this.point({ clientX: now.x, clientY: now.y }));
        this.pinch = now;
    }

    up(e, cancel = false) {
        if (!this.pointers.delete(e.pointerId)) return;
        if (this.pointers.size === 1) { const [q] = this.pointers.values(); this.start = { ...q }; }
        this.pinch = this.pointers.size === 2 ? this.measure() : null;
        if (!this.pointers.size && !this.moved && !cancel) this.onTap(this.downEvent);
    }

    wheel(e) {
        e.preventDefault();
        if (e.ctrlKey || e.metaKey || e.deltaMode === 1) {
            this.zoom(Math.exp(-e.deltaY * (e.deltaMode === 1 ? 0.05 : 0.01)), this.point(e));
            return;
        }
        const k = this.scale();
        this.box[0] += e.deltaX * k;
        this.box[1] += e.deltaY * k;
        this.apply();
    }

    // Ближе в f раз вокруг точки схемы p.
    zoom(f, p = this.center()) {
        const [x, y, w, h] = this.box;
        const nw = Math.min(this.widest() * 1.5, Math.max(this.min, w / f));
        const g = w / nw;
        this.box = [p.x - (p.x - x) / g, p.y - (p.y - y) / g, w / g, h / g];
        this.apply();
    }

    // Ширина вида, при которой схема видна целиком.
    widest() {
        return Math.max(this.full[2], this.full[3] / this.ratio());
    }

    center() {
        return { x: this.box[0] + this.box[2] / 2, y: this.box[1] + this.box[3] / 2 };
    }

    fit() {
        const [x, y, w, h] = this.full;
        const nw = this.widest();
        const nh = nw * this.ratio();
        this.box = [x + (w - nw) / 2, y + (h - nh) / 2, nw, nh];
        this.apply();
    }

    // Подъехать к прямоугольнику схемы: в середину, не дальше «вписать» и не ближе size метров по ширине.
    focus({ x, y, width, height }, size = 18) {
        const w = Math.min(this.widest(), Math.max(size, width * 3));
        const ratio = this.ratio();
        this.box = [x + width / 2 - w / 2, y + height / 2 - (w * ratio) / 2, w, w * ratio];
        this.apply();
    }

    // Не дальше полутора схем от её краёв: улетевшую схему не найти.
    apply() {
        // Холст поменял пропорцию (повернули телефон, открылась плашка) — высота вида вслед, середина на месте.
        const h0 = this.box[3];
        this.box[3] = this.box[2] * this.ratio();
        this.box[1] += (h0 - this.box[3]) / 2;
        const [fx, fy, fw, fh] = this.full;
        const [, , w, h] = this.box;
        this.box[0] = Math.min(fx + fw - w * 0.25, Math.max(fx - w * 0.75, this.box[0]));
        this.box[1] = Math.min(fy + fh - h * 0.25, Math.max(fy - h * 0.75, this.box[1]));
        this.svg.setAttribute('viewBox', this.box.map((v) => Math.round(v * 100) / 100).join(' '));
        this.onChange(this);
    }
}
