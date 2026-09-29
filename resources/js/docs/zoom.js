// Масштаб документа в шторке: щипок двумя пальцами, двойное касание (по ширине ↔ 2,5×), на компьютере —
// ctrl + колесо (щипок трекпада) и двойной клик. Меняется не CSS-масштаб, а раскладка — ширина содержимого
// (`layout(z)`), поэтому прокрутка при любом увеличении обычная. Пока пальцы на экране — живой transform вокруг
// точки щипка, отпустили — раскладка в новый масштаб, прокрутка так, чтобы точка осталась под пальцами.
// Страницу целиком не зумит: у прокрутки touch-action pan-x pan-y, iOS-жест gesturestart гасится.
export class Zoom {
    constructor(scroll, content, layout, { max = 5, settled = () => {} } = {}) {
        Object.assign(this, { scroll, content, layout, max, settled, z: 1, width: scroll.clientWidth });
        this.handlers = {
            touchstart: (e) => this.touchStart(e),
            touchmove: (e) => this.touchMove(e),
            touchend: (e) => this.touchEnd(e),
            touchcancel: (e) => this.touchEnd(e),
            gesturestart: (e) => e.preventDefault(),
            gesturechange: (e) => e.preventDefault(),
            wheel: (e) => this.wheel(e),
            dblclick: (e) => this.dblclick(e),
        };
        for (const [name, fn] of Object.entries(this.handlers)) scroll.addEventListener(name, fn, { passive: false });
        this.resize = new ResizeObserver(() => {
            const w = scroll.clientWidth;
            if (w > 0 && w !== this.width) { this.width = w; this.relayout(); }
        });
        this.resize.observe(scroll);
        this.relayout();
    }

    destroy() {
        this.resize.disconnect();
        for (const [name, fn] of Object.entries(this.handlers)) this.scroll.removeEventListener(name, fn);
    }

    relayout() {
        this.layout(this.z);
        this.settled(this.z);
    }

    // fx, fy — точка в окне прокрутки, которая остаётся на месте.
    set(z, fx = this.scroll.clientWidth / 2, fy = 0) {
        z = Math.min(this.max, Math.max(1, z));
        if (Math.abs(z - this.z) < .001) return;
        const k = z / this.z, s = this.scroll;
        const x = s.scrollLeft + fx, y = s.scrollTop + fy;
        this.z = z;
        this.layout(z);
        s.scrollLeft = x * k - fx;
        s.scrollTop = y * k - fy;
        this.settled(z);
    }

    point(x, y) {
        const r = this.scroll.getBoundingClientRect();
        return [x - r.left, y - r.top];
    }

    touchStart(e) {
        this.touchedAt = Date.now();
        if (e.touches.length === 2) {
            e.preventDefault();
            const [a, b] = e.touches;
            const [fx, fy] = this.point((a.clientX + b.clientX) / 2, (a.clientY + b.clientY) / 2);
            this.pinch = { d: Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY) || 1, fx, fy, s: 1 };
            this.content.style.transformOrigin = `${this.scroll.scrollLeft + fx}px ${this.scroll.scrollTop + fy}px`;
            this.tap = null;
        } else if (e.touches.length === 1) {
            const t = e.touches[0];
            this.tap = { x: t.clientX, y: t.clientY, moved: false };
        }
    }

    touchMove(e) {
        if (this.pinch && e.touches.length === 2) {
            e.preventDefault();
            const [a, b] = e.touches;
            const s = Math.min(this.max / this.z, Math.max(1 / this.z, Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY) / this.pinch.d));
            this.pinch.s = s;
            this.content.style.transform = `scale(${s})`;
        } else if (this.tap) {
            const t = e.touches[0];
            if (Math.hypot(t.clientX - this.tap.x, t.clientY - this.tap.y) > 10) this.tap.moved = true;
        }
    }

    touchEnd(e) {
        if (this.pinch) {
            if (e.touches.length >= 2) return;
            const { s, fx, fy } = this.pinch;
            this.pinch = null;
            this.content.style.transform = '';
            this.content.style.transformOrigin = '';
            this.set(this.z * s, fx, fy);
            return;
        }
        const tap = this.tap;
        this.tap = null;
        if (!tap || tap.moved || e.touches.length) return;
        const now = e.timeStamp, last = this.lastTap;
        if (last && now - last.t < 320 && Math.hypot(tap.x - last.x, tap.y - last.y) < 32) {
            e.preventDefault();
            this.lastTap = null;
            this.toggle(tap.x, tap.y);
        } else this.lastTap = { t: now, x: tap.x, y: tap.y };
    }

    toggle(x, y) {
        const [fx, fy] = this.point(x, y);
        this.set(this.z > 1.2 ? 1 : 2.5, fx, fy);
    }

    wheel(e) {
        if (!e.ctrlKey) return;
        e.preventDefault();
        const [fx, fy] = this.point(e.clientX, e.clientY);
        this.set(this.z * Math.exp(-e.deltaY / 120), fx, fy);
    }

    dblclick(e) {
        if (Date.now() - (this.touchedAt ?? 0) < 800 || e.target.closest?.('.textLayer span')) return;
        this.toggle(e.clientX, e.clientY);
    }
}
