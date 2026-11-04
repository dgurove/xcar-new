import { Controller } from '@hotwired/stimulus';
import { morphChildren } from '@hotwired/turbo';
import { bump } from '../lib/bump';
import { request } from '../lib/http';
import { reducedMotion } from '../lib/motion';
import { Camera } from '../space/camera';
import { bounds, curves, measure, paint, particles, pulse } from '../space/flow';
import { Backdrop, FlatSky, Sky, fits, pixelRatio, stars } from '../space/sky';

// Космос главной CRM (`admin/space`, 04.11.2026): звёзды (`space/sky.js`), камера (`space/camera.js`) и слой схемы
// `.space-world` — одним циклом кадров. Кадр рисуется, только когда есть что менять: жест, бросок, масштаб, полёт
// «К схеме» и метеор — каждый кадр экрана; в покое — 30 кадров/с ради мерцания, после 30 с без касаний — 20; скрытая
// вкладка и уход со страницы — ни одного, контекст видеокарты отдаётся. Без движения (prefers-reduced-motion) и без
// WebGL — ни мерцания, ни метеоров: кадр только на сдвиг. Медленные кадры в движении — холст мельче (dpr 2 → 1,5 → 1).
// Фон светлой темы — свой холст (`Backdrop`): рисуется один раз и едет за камерой transform'ом, в кадр звёзд не входит.
// Конвейер (`App\Space\Conveyor`): карточки этапов в слое схемы, линии — SVG там же, поток — проход того же WebGL
// (`space/flow.js`). Раскладка по форме сцены: широкая (ПК) или высокая (телефон). Числа живые: событие сотрудникам —
// кусок `#conveyor` перечитывается морфом (`morphChildren`) не чаще раза в 2 с, сменившееся число докручивается, карточка загорается, а по
// линии, где у одного этапа убыло, а у следующего прибыло, пролетает импульс.
const IDLE = 1000 / 30;
const DEEP_IDLE = 1000 / 20;
const DEEP_AFTER = 30000;
const METEOR = [25000, 60000];
const SLOW = 24;             // мс кадра в движении: дольше — снизить dpr
const KEY = 'space:camera';
const CALM = 2000;           // мс: события пачкой — одно перечитывание
const STALE = 9000;          // мс: сервер отдал прежний снимок — ещё раз через столько (+ CALM)
const TWEEN = 700;           // мс докрутки числа
let sky = null;              // звёзды одни на сеанс: второй заход их не считает заново

const read = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || 'null') ?? undefined; } catch { return undefined; } };
const save = (value) => { try { sessionStorage.setItem(KEY, JSON.stringify(value)); } catch {} };

export default class extends Controller {
    static targets = ['back', 'sky', 'world', 'links', 'conveyor', 'home'];
    static values = { url: String, links: Array };

    connect() {
        this.still = reducedMotion();
        sky ??= stars();
        this.dark = document.documentElement.classList.contains('dark');
        this.build();
        this.backdrop = new Backdrop(this.backTarget);
        this.camera = new Camera(this.element, { wake: () => this.wake(), still: this.still });
        this.fresh = !this.camera.restore(read());
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
            // Вернулись, а пока вкладка была скрыта, пришло событие — числа перечитываются сейчас.
            if (document.visibilityState === 'visible') { this.touchedAt = performance.now(); this.wake(); if (this.missed) this.soon(); return; }
            this.sleep();
            save(this.camera.snapshot());
        };
        document.addEventListener('visibilitychange', this.onVisible);
        this.onLive = () => this.soon();
        document.addEventListener('live:changed', this.onLive);
        document.addEventListener('badges:updated', this.onLive);
        this.planMeteor();
        this.fit();
        // Шрифт приехал позже — карточки другого размера, линии к краям заново.
        document.fonts?.ready.then(() => this.element.isConnected && this.layout());
    }

    disconnect() {
        this.sleep();
        clearTimeout(this.calm);
        this.resize.disconnect();
        this.themes.disconnect();
        document.removeEventListener('visibilitychange', this.onVisible);
        document.removeEventListener('live:changed', this.onLive);
        document.removeEventListener('badges:updated', this.onLive);
        clearTimeout(this.wait);
        clearTimeout(this.retry);
        this.wait = this.retry = null;
        this.camera.detach();
        save(this.camera.snapshot());
        this.renderer.dispose();
    }

    // Холст каждый раз свежий: контекст прежнего отдан (`dispose`), а WebGL, отказавший на полпути, держит холст за собой —
    // 2D на нём не получить. В светлой теме он прозрачный — под ним фон `Backdrop`.
    build() {
        this.renderer = Sky.create(this.canvas(), sky, !this.dark) ?? new FlatSky(this.canvas(), sky);
        this.renderer.onRestore = () => { this.drawn = -1; this.wake(); };
        this.renderer.setFlow(this.flow ?? null);
        this.lively = this.renderer instanceof Sky && !this.still;
        if (this.camera) this.planMeteor();
    }

    home() {
        this.camera.home();
    }

    canvas() {
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
        this.layout();
        this.paintBack();
    }

    // Раскладка схемы под сцену: высокая — телефон и узкое окно, иначе широкая. Карточки меряются заново, линии и поток —
    // по ним; рамка и вид схемы — камере. Первый заход и смена раскладки — сразу на вид схемы.
    layout() {
        const { w, h } = this.camera;
        if (w <= 1) return;
        const tall = w < 700 || w / h < 0.85;
        const switched = this.tall !== undefined && this.tall !== tall;
        this.tall = tall;
        this.worldTarget.classList.toggle('is-tall', tall);
        this.boxes = measure(this.worldTarget);
        this.curves = curves(this.linksValue, this.boxes);
        paint(this.linksTarget, this.curves);
        this.flow = this.still ? null : particles(this.curves, this.boxes);
        this.renderer.setFlow(this.flow);
        const frame = bounds(this.boxes);
        if (frame) this.camera.frame(frame, this.view(frame, w, h, tall));
        if (this.fresh || switched) { this.fresh = false; this.camera.jump(); }
        this.drawn = -1;
        this.wake();
    }

    // Вид «К схеме»: ПК — схема целиком; телефон — начало конвейера, середина читается, ветки выглядывают с боков, дальше
    // листают вниз и вбок.
    view(b, w, h, tall) {
        const clamp = (v, a, c) => Math.min(c, Math.max(a, v));
        if (!tall) {
            const z = clamp(Math.min(w / (b.x1 - b.x0), h / (b.y1 - b.y0)), 0.3, 1);
            return { x: (b.x0 + b.x1) / 2, y: (b.y0 + b.y1) / 2, z };
        }
        const z = clamp(w / 490, 0.6, 1);
        return { x: (b.x0 + b.x1) / 2, y: b.y0 + h / 2 / z, z };
    }

    // Событие пачкой — одно перечитывание через CALM; идёт — ещё одно после него.
    soon() {
        if (this.wait || !this.element.isConnected) return;
        this.wait = setTimeout(() => { this.wait = null; this.reload(); }, CALM);
    }

    async reload() {
        if (!this.urlValue || !this.element.isConnected) return;
        if (document.visibilityState !== 'visible') { this.missed = true; return; }
        this.missed = false;
        if (this.loading) { this.again = true; return; }
        this.loading = true;
        try {
            const before = this.counts();
            const r = await request(this.urlValue, { accept: 'text/html', quiet: true });
            if (r?.ok && this.element.isConnected) {
                // Сервер пересчитывает не чаще раза в 10 с: отдал прежний снимок — спросить ещё раз, когда будет можно.
                if (r.headers.get('X-Conveyor-Stale')) {
                    clearTimeout(this.retry);
                    this.retry = setTimeout(() => { this.retry = null; this.soon(); }, STALE);
                }
                // Морф сразу, без потока Turbo: числа «после» читаются с уже новых карточек.
                const fresh = document.createElement('div');
                fresh.innerHTML = await r.text();
                if (!this.element.isConnected) return;
                morphChildren(this.conveyorTarget, fresh);
                this.layout();
                this.counted(before);
            }
        } finally {
            this.loading = false;
            if (this.again) { this.again = false; this.soon(); }
        }
    }

    // Числа этапов до перечитывания: всего у этапа, его поток и у каждого, кто держит.
    counts() {
        const map = new Map();
        for (const el of this.worldTarget.querySelectorAll('[data-node]')) {
            const who = new Map([...el.querySelectorAll('[data-who]')].map((w) => [w.dataset.who, Number(w.querySelector('[data-count]')?.dataset.count) || 0]));
            map.set(el.dataset.node, { total: Number(el.dataset.total) || 0, flow: Number(el.dataset.flow) || 0, note: el.querySelector('[data-note]')?.textContent ?? '', who });
        }
        return map;
    }

    // После перечитывания: сменившееся число докручивается от прежнего, карточка загорается; где у одного этапа убыло, а
    // у следующего по линии прибыло (или источник принёс новое), — импульс по линии.
    counted(before) {
        const after = this.counts();
        for (const [key, now] of after) {
            const was = before.get(key);
            if (!was) continue;
            let changed = was.note !== now.note;
            for (const [who, n] of now.who) {
                const from = was.who.get(who) ?? 0;
                if (from === n) continue;
                changed = true;
                const el = this.worldTarget.querySelector(`[data-node="${key}"] [data-who="${CSS.escape(who)}"] [data-count]`);
                if (el && !this.still) tween(el, from, n);
            }
            if (changed) bump(this.worldTarget.querySelector(`[data-node="${key}"]`));
        }
        for (const c of this.curves ?? []) {
            if (c.kind === 'hint') continue;
            const a0 = before.get(c.from), a1 = after.get(c.from), b0 = before.get(c.to), b1 = after.get(c.to);
            if (!a0 || !a1 || !b0 || !b1) continue;
            const moved = (a1.total < a0.total && b1.total > b0.total) || (a1.flow > a0.flow && !a1.total);
            if (moved) this.renderer.pulse((t) => pulse(c, t));
        }
        this.wake();
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

// Число докручивается от прежнего к новому — как счётчик, с замедлением к концу.
function tween(el, from, to) {
    const t0 = performance.now();
    const step = (now) => {
        const t = Math.min(1, (now - t0) / TWEEN);
        el.textContent = String(Math.round(from + (to - from) * (1 - (1 - t) ** 3)));
        if (t < 1 && el.isConnected) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
}
