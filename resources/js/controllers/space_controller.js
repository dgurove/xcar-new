import { Controller } from '@hotwired/stimulus';
import { reducedMotion } from '../lib/motion';
import { Camera } from '../space/camera';
import { Backdrop, FlatSky, Sky, fits, pixelRatio, stars } from '../space/sky';

// Космос главной CRM (`admin/space`, 04.11.2026): звёзды (`space/sky.js`), камера (`space/camera.js`) и слой схемы
// `.space-world` — одним циклом кадров. Кадр рисуется, только когда есть что менять: жест, бросок, масштаб, полёт
// «К схеме» и метеор — каждый кадр экрана; в покое — 30 кадров/с ради мерцания, после 30 с без касаний — 20; скрытая
// вкладка и уход со страницы — ни одного, контекст видеокарты отдаётся. Без движения (prefers-reduced-motion) и без
// WebGL — ни мерцания, ни метеоров: кадр только на сдвиг. Медленные кадры в движении — холст мельче (dpr 2 → 1,5 → 1).
// Фон светлой темы — свой холст (`Backdrop`): рисуется один раз и едет за камерой transform'ом, в кадр звёзд не входит.
const IDLE = 1000 / 30;
const DEEP_IDLE = 1000 / 20;
const DEEP_AFTER = 30000;
const METEOR = [25000, 60000];
const SLOW = 24;             // мс кадра в движении: дольше — снизить dpr
const KEY = 'space:camera';
let sky = null;              // звёзды одни на сеанс: второй заход их не считает заново

const read = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || 'null') ?? undefined; } catch { return undefined; } };
const save = (value) => { try { sessionStorage.setItem(KEY, JSON.stringify(value)); } catch {} };

export default class extends Controller {
    static targets = ['back', 'sky', 'world', 'home'];

    connect() {
        this.still = reducedMotion();
        sky ??= stars();
        this.dark = document.documentElement.classList.contains('dark');
        this.build();
        this.backdrop = new Backdrop(this.backTarget);
        this.camera = new Camera(this.element, { wake: () => this.wake(), still: this.still });
        this.camera.restore(read());
        // Тема — класс `dark` у <html> (`theme.js`): переключили — фон другой сразу, без перезагрузки. Холст звёзд — новый:
        // прозрачность контекста задаётся только при создании.
        this.theme = () => {
            const dark = document.documentElement.classList.contains('dark');
            if (dark === this.dark) return;
            this.dark = dark;
            this.renderer.dispose();
            this.build();
            if (dark) this.backdrop.clear();
            this.fit();
        };
        this.themes = new MutationObserver(this.theme);
        this.themes.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        Object.assign(this, { cap: 2, slow: [], drawn: -1, lastDraw: 0, t0: performance.now(), touchedAt: performance.now() });
        this.resize = new ResizeObserver(() => this.fit());
        this.resize.observe(this.element);
        this.onVisible = () => {
            if (document.visibilityState === 'visible') { this.touchedAt = performance.now(); this.wake(); return; }
            this.sleep();
            save(this.camera.snapshot());
        };
        document.addEventListener('visibilitychange', this.onVisible);
        this.planMeteor();
        this.fit();
    }

    disconnect() {
        this.sleep();
        clearTimeout(this.calm);
        this.resize.disconnect();
        this.themes.disconnect();
        document.removeEventListener('visibilitychange', this.onVisible);
        this.camera.detach();
        save(this.camera.snapshot());
        this.renderer.dispose();
    }

    // Холст каждый раз свежий: контекст прежнего отдан (`dispose`), а WebGL, отказавший на полпути, держит холст за собой —
    // 2D на нём не получить. В светлой теме он прозрачный — под ним фон `Backdrop`.
    build() {
        this.renderer = Sky.create(this.fresh(), sky, !this.dark) ?? new FlatSky(this.fresh(), sky);
        this.renderer.onRestore = () => { this.drawn = -1; this.wake(); };
        this.lively = this.renderer instanceof Sky && !this.still;
        if (this.camera) this.planMeteor();
    }

    home() {
        this.camera.home();
    }

    fresh() {
        const canvas = this.skyTarget.cloneNode();
        this.skyTarget.replaceWith(canvas);
        return canvas;
    }

    fit() {
        const { width: w, height: h } = this.element.getBoundingClientRect();
        if (!w || !h) return;
        if (!fits(sky, w, h)) { sky = stars(Math.max(w, h)); this.renderer.load(sky); }
        this.renderer.resize(w, h, pixelRatio(w, h, this.cap));
        this.drawn = -1;
        this.camera.resize(w, h);
        this.paintBack();
    }

    paintBack() {
        if (this.dark) return;
        const { width: w, height: h } = this.element.getBoundingClientRect();
        if (!w || !h) return;
        this.backdrop.paint(w, h, this.camera, sky.side);
        this.backdrop.place(this.camera, w, h);
    }

    wake() {
        if (this.frame || document.visibilityState !== 'visible' || !this.element.isConnected) return;
        clearTimeout(this.timer);
        this.timer = null;
        this.frame = requestAnimationFrame((now) => this.tick(now));
    }

    sleep() {
        cancelAnimationFrame(this.frame);
        clearTimeout(this.timer);
        this.frame = null;
        this.timer = null;
    }

    planMeteor() {
        this.meteorAt = this.lively ? performance.now() + METEOR[0] + Math.random() * (METEOR[1] - METEOR[0]) : 0;
    }

    tick(now) {
        this.frame = null;
        const cam = this.camera;
        const moving = cam.step(now);
        if (moving) this.touchedAt = now;
        if (this.meteorAt && now >= this.meteorAt && !this.renderer.flying) {
            this.renderer.spawn(cam);
            this.planMeteor();
        }
        const flying = this.renderer.flying;
        const idle = now - this.touchedAt > DEEP_AFTER ? DEEP_IDLE : IDLE;
        const motion = cam.version !== this.drawn;
        // Медленные кадры считаем только по кадрам движения: палец лежит — кадры идут вхолостую, рисует лишь мерцание.
        if (motion && moving) this.measure(now);
        else this.prevAt = 0;
        if (motion || flying || (this.lively && now - this.lastDraw >= idle - 4)) {
            this.renderer.draw(cam, (now - this.t0) / 1000);
            if (motion) this.place(moving);
            this.drawn = cam.version;
            this.lastDraw = now;
        }
        if (moving || flying) { this.wake(); return; }
        // Покой: следующий кадр — к сроку мерцания (таймером, а не кадрами экрана вхолостую) или к следующему касанию.
        if (this.lively) this.timer = setTimeout(() => { this.timer = null; this.wake(); }, Math.max(0, idle - (performance.now() - this.lastDraw)));
    }

    // Слой схемы — глубина 1: та же камера, одна запись transform. will-change — только пока едет: после остановки
    // браузер перерисует текст схемы чётко при любом масштабе.
    place(moving) {
        const { x, y, z } = this.camera;
        if (!this.dark && !this.backdrop.place(this.camera, this.camera.w, this.camera.h)) this.paintBack();
        this.worldTarget.style.transform = `translate3d(${-x * z}px, ${-y * z}px, 0) scale(${z})`;
        if (moving) {
            this.worldTarget.classList.add('is-moving');
            clearTimeout(this.calm);
            this.calm = setTimeout(() => this.worldTarget.classList.remove('is-moving'), 200);
        }
        const lost = this.camera.lost();
        if (this.homeTarget.hidden === lost) this.homeTarget.hidden = !lost;
    }

    // Медленные кадры в движении (45 подряд в среднем дольше SLOW) — холст мельче, звёзды те же.
    measure(now) {
        if (this.prevAt) this.slow.push(now - this.prevAt);
        this.prevAt = now;
        if (this.slow.length < 45) return;
        const avg = this.slow.reduce((s, v) => s + v, 0) / this.slow.length;
        this.slow = [];
        if (avg <= SLOW || this.cap <= 1) return;
        this.cap = this.cap > 1.5 ? 1.5 : 1;
        this.fit();
    }
}
