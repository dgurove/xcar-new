import { Controller } from '@hotwired/stimulus';
import { morphChildren } from '@hotwired/turbo';
import { bump } from '../lib/bump';
import { request } from '../lib/http';
import { reducedMotion } from '../lib/motion';
import { Camera, FOCUS } from '../space/camera';
import { Filter } from '../space/filter';
import { Backdrop, FlatSky, Sky, fits, pixelRatio, stars } from '../space/sky';

// Космос главной CRM (`admin/space`, 04.11.2026): звёзды (`space/sky.js`), камера (`space/camera.js`) и слой схемы
// `.space-world` — одним циклом кадров. Кадр рисуется, только когда есть что менять: жест, бросок, масштаб,
// пружина к этапу и метеор — каждый кадр экрана; в покое — 30 кадров/с ради мерцания, после 30 с без касаний — 20; скрытая
// вкладка и уход со страницы — ни одного, контекст видеокарты отдаётся. Без движения (prefers-reduced-motion) и без
// WebGL — ни мерцания, ни метеоров: кадр только на сдвиг. Медленные кадры в движении — холст мельче (dpr 2 → 1,5 → 1).
// Фон светлой темы — свой холст (`Backdrop`): рисуется один раз и едет за камерой transform'ом, в кадр звёзд не входит.
// Конвейер (`App\Space\Conveyor`): этапы столбцами в слое схемы, обычной раскладкой сетки. Числа живые: событие
// сотрудникам — кусок `#conveyor` перечитывается морфом (`morphChildren`) не чаще раза в 2 с, сменившееся число
// докручивается, подэтап коротко загорается. Вбок камера листает по этапам (центры видимых этапов — ей), мир — в рамке
// доски. Фильтр «Ответственный» (`space/filter.js`) — плашка в углу: выбор и тумблер помнит браузер.
const PICK = 'space:who';
const IDLE = 1000 / 30;
const DEEP_IDLE = 1000 / 20;
const DEEP_AFTER = 30000;
const METEOR = [25000, 60000];
const SLOW = 24;             // мс кадра в движении: дольше — снизить dpr
// Вид помнится под ключом раскладки: вид прежней схемы (змейка вокруг начала мира) на новой доске — пустое небо.
const KEY = 'space:camera:v3';
const CALM = 2000;           // мс: события пачкой — одно перечитывание
const STALE = 9000;          // мс: сервер отдал прежний снимок — ещё раз через столько (+ CALM)
const TWEEN = 700;           // мс докрутки числа
let sky = null;              // звёзды одни на сеанс: второй заход их не считает заново

const read = (key) => { try { return JSON.parse(sessionStorage.getItem(key) || 'null') ?? undefined; } catch { return undefined; } };
const save = (key, value) => { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch {} };
const recall = () => { try { return JSON.parse(localStorage.getItem(PICK) || 'null'); } catch { return null; } };
const remember = (value) => { try { localStorage.setItem(PICK, JSON.stringify(value)); } catch {} };

export default class extends Controller {
    static targets = ['back', 'sky', 'world', 'conveyor', 'filter', 'who', 'toggle'];
    static values = { url: String, me: String };

    connect() {
        this.still = reducedMotion();
        sky ??= stars();
        this.dark = document.documentElement.classList.contains('dark');
        this.build();
        this.backdrop = new Backdrop(this.backTarget);
        this.camera = new Camera(this.element, { wake: () => this.wake(), still: this.still });
        this.camera.restore(read(KEY));
        this.filter = new Filter(this.worldTarget, this.camera, { still: this.still, relayout: (keep) => this.layout(keep) });
        this.pickInit();
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
            save(KEY, this.camera.snapshot());
        };
        document.addEventListener('visibilitychange', this.onVisible);
        this.onLive = () => this.soon();
        document.addEventListener('live:changed', this.onLive);
        document.addEventListener('badges:updated', this.onLive);
        this.planMeteor();
        this.fit();
        // Шрифт приехал позже — доска другого размера, рамка камеры заново.
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
        save(KEY, this.camera.snapshot());
        this.renderer.dispose();
    }

    // Холст каждый раз свежий: контекст прежнего отдан (`dispose`), а WebGL, отказавший на полпути, держит холст за собой —
    // 2D на нём не получить. В светлой теме он прозрачный — под ним фон `Backdrop`.
    build() {
        this.renderer = Sky.create(this.canvas(), sky, !this.dark) ?? new FlatSky(this.canvas(), sky);
        this.renderer.onRestore = () => { this.drawn = -1; this.wake(); };
        this.lively = this.renderer instanceof Sky && !this.still;
        if (this.camera) this.planMeteor();
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

    // Рамка камере: центры видимых этапов и высота доски; `keep` — фокус не трогать (его ставит фильтр).
    layout(keep = false) {
        const { w } = this.camera;
        const board = this.worldTarget.querySelector('[data-board]');
        if (w <= 1 || !board) return;
        const frame = frameOf(board, this.worldTarget);
        const stages = [...board.querySelectorAll('.conveyor-stage:not([hidden])')];
        const columns = stages.map((s) => frame.x0 + s.offsetLeft + s.offsetWidth / 2);
        // Снизу — место плашке фильтра: последний подэтап не встаёт под неё.
        const pad = w < 700 ? { top: 16, bottom: 80 } : { top: 32, bottom: 96 };
        if (keep) { const fx = this.camera.fx; this.camera.frame(columns, { y0: frame.y0, y1: frame.y1 }, pad, this.start(stages[0], w)); this.camera.fx = fx; }
        else this.camera.frame(columns, { y0: frame.y0, y1: frame.y1 }, pad, this.start(stages[0], w));
        this.drawn = -1;
        this.wake();
    }

    // Вид при первом заходе: первый этап на золотом сечении. ПК — масштаб 1; телефон — этап целиком левее середины, его
    // левый край на отступе, справа заглядывает следующий: видно, что листать вбок.
    start(stage, w) {
        const col = stage?.offsetWidth || 300;
        const z = w < 700 ? Math.min(1, Math.max(0.5, (2 * (FOCUS * w - 16)) / col)) : 1;
        return { i: 0, z };
    }

    // Фильтр «Ответственный»: выбор и тумблер из памяти браузера, иначе — сам вошедший; включён, если у него есть работа
    // на доске (у владельца и модератора её нет — пустой доски при входе не будет).
    pickInit() {
        const has = (key) => !!this.filterTarget.querySelector(`[data-key="${CSS.escape(key)}"][role="menuitemradio"]`);
        const saved = recall();
        const key = saved && has(saved.key) ? saved.key : this.meValue;
        const on = saved && has(saved.key) ? !!saved.on : has(this.meValue);
        this.pick(key);
        this.toggleTarget.checked = on && !this.toggleTarget.disabled;
        this.filter.set(this.toggleTarget.checked ? key : null, { animate: false });
    }

    // У выбранного без работы на доске (вошедший владелец) тумблер не включается: пустой доски не бывает.
    pick(key) {
        this.chosen = key;
        const item = this.filterTarget.querySelector(`[data-key="${CSS.escape(key)}"][role="menuitemradio"]`);
        for (const el of this.filterTarget.querySelectorAll('[role="menuitemradio"]')) el.toggleAttribute('aria-current', el === item);
        if (item) this.whoTarget.innerHTML = item.innerHTML;
        this.toggleTarget.disabled = !item;
        if (!item) this.toggleTarget.checked = false;
    }

    // Выбрали человека — фильтр включается сам: выбор и есть просьба показать его.
    choose(event) {
        this.pick(event.currentTarget.dataset.key);
        this.toggleTarget.checked = true;
        this.apply();
    }

    toggle() {
        this.apply();
    }

    apply() {
        const on = this.toggleTarget.checked;
        remember({ key: this.chosen, on });
        this.filter.set(on ? this.chosen : null);
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
                // Морф вернул все подэтапы и общие числа — отбор заново, без анимации; фокус — на том же этапе.
                const focus = this.filter.focusStage();
                const was = focus ? this.filter.center(focus) : this.camera.fx;
                this.filter.reapply();
                this.layout(true);
                this.filter.refocus(focus, was);
                this.counted(before);
            }
        } finally {
            this.loading = false;
            if (this.again) { this.again = false; this.soon(); }
        }
    }

    // Показанные числа у каждого подэтапа и этапа (с фильтром — доля выбранного, `data-shown`).
    counts() {
        const n = (el) => (el ? Number(el.dataset.shown ?? el.dataset.count) || 0 : 0);
        return new Map([...this.worldTarget.querySelectorAll('[data-step]')].map((el) => [el.dataset.step, n(el.matches('[data-count]') ? el : el.querySelector('[data-count]'))]));
    }

    // После перечитывания: сменившееся число докручивается от прежнего, подэтап коротко загорается.
    counted(before) {
        for (const [key, n] of this.counts()) {
            const was = before.get(key);
            if (was === undefined || was === n) continue;
            const el = this.worldTarget.querySelector(`[data-step="${CSS.escape(key)}"]`);
            const count = el?.matches('[data-count]') ? el : el?.querySelector('[data-count]');
            if (count && !this.still) tween(count, was, n);
            if (el?.classList.contains('conveyor-step')) bump(el);
        }
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

// Рамка элемента в координатах слоя схемы: `{ x0, y0, x1, y1 }`. Смещения складываются до слоя — transform слоя offset не
// трогает.
function frameOf(el, world) {
    let x = 0, y = 0;
    for (let n = el; n && n !== world; n = n.offsetParent) { x += n.offsetLeft; y += n.offsetTop; }
    return { x0: x, y0: y, x1: x + el.offsetWidth, y1: y + el.offsetHeight };
}
