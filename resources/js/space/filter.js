// Фильтр «Ответственный» на конвейере главной CRM (05.11.2026, владелец: «чтобы остальные плавно исчезли, а оставшиеся
// сопоставились и фокус корректно сместился»). Отбор — на клиенте, по `data-by` подэтапа (`{ "user:4": [число, горящих] }`,
// `Conveyor::by`): подэтап виден, когда выбранный его делает, число и горящие — его доля; этап без видимых подэтапов
// скрыт. Без выбора — все и их общие числа (`data-count`).
//
// Анимация — только transform и opacity (Web Animations, слой собирает видеокарта):
// 1. уходящие растворяются и чуть сжимаются;
// 2. новая раскладка; оставшиеся едут из прежнего места в новое (FLIP), приходящие проявляются следом;
// 3. камера: этап в фокусе остался — фокус сразу на его новом месте, а сдвиг FLIP считается с поправкой на камеру — на
//    экране он стоит, остальные съезжаются к нему; ушёл — пружина к ближайшему оставшемуся тем же темпом.
// Повторное нажатие посреди анимации начинает с того места, где всё стоит сейчас (замер по экрану, а не по раскладке).

const OUT = 160;            // мс растворения уходящих
const MOVE = 420;           // мс съезда оставшихся
const IN = 280;             // мс проявления приходящих
const IN_DELAY = 120;
const EASE = 'cubic-bezier(.2, .8, .2, 1)';
const TWEEN = 500;

const by = (el) => { try { return JSON.parse(el.dataset.by || '{}'); } catch { return {}; } };

export class Filter {
    // `relayout()` — заново рамку камеры по видимым этапам (`space_controller#layout`), `keep` — не трогая фокус.
    constructor(world, camera, { still = false, relayout }) {
        Object.assign(this, { world, camera, still, relayout, key: null, run: 0 });
    }

    get board() {
        return this.world.querySelector('[data-board]');
    }

    // Что видно при ключе: этапы и подэтапы; числа подэтапа — его доля у выбранного.
    plan(key) {
        const steps = new Map();
        const stages = new Map();
        for (const stage of this.board.querySelectorAll('.conveyor-stage')) {
            let any = false;
            for (const step of stage.querySelectorAll('.conveyor-step')) {
                const total = Number(step.querySelector('.step-n')?.dataset.count) || 0;
                const share = key ? by(step)[key] : null;
                const show = !key || !!share;
                steps.set(step, { show, n: key ? share?.[0] ?? 0 : total, hot: key ? share?.[1] ?? 0 : Number(step.dataset.hotAll ?? step.querySelector('[data-hot]')?.textContent) || 0 });
                any ||= show;
            }
            stages.set(stage, any);
        }
        return { steps, stages };
    }

    // Числа и вид подэтапов и этапов под отбор; `tween` — докрутить сменившиеся.
    paint({ steps }, tween) {
        for (const [step, s] of steps) {
            const hot = step.querySelector('[data-hot]');
            // Общее число горящих запоминается до первой подмены — фильтр снят, вернуть его.
            step.dataset.hotAll ??= hot?.textContent ?? '0';
            this.number(step.querySelector('.step-n'), s.n, tween);
            if (hot) { hot.textContent = String(s.hot); hot.parentElement.hidden = !s.hot; }
            step.classList.toggle('is-idle', !s.n);
            step.classList.toggle('is-hot', !!s.hot);
        }
        for (const stage of this.board.querySelectorAll('.conveyor-stage')) {
            let n = 0, hot = 0;
            for (const step of stage.querySelectorAll('.conveyor-step')) {
                const s = steps.get(step);
                if (s?.show) { n += s.n; hot += s.hot; }
            }
            this.number(stage.querySelector('.stage-n [data-count]'), n, tween);
            const pill = stage.querySelector('.stage-line .hot-pill');
            if (pill) { pill.querySelector('[data-hot]').textContent = String(hot); pill.hidden = !hot; }
            for (const face of stage.querySelectorAll('.stage-person')) face.classList.toggle('is-dim', !!this.key && face.dataset.who !== this.key);
        }
    }

    // Показанное число — в `data-shown` (общее остаётся в `data-count`: по нему живое перечитывание видит перемену).
    number(el, n, tween) {
        if (!el) return;
        const was = Number(el.dataset.shown ?? el.textContent) || 0;
        el.dataset.shown = String(n);
        if (!tween || this.still || was === n) { el.textContent = String(n); return; }
        const t0 = performance.now();
        const step = (now) => {
            if (el.dataset.shown !== String(n)) return;
            const t = Math.min(1, (now - t0) / TWEEN);
            el.textContent = String(Math.round(was + (n - was) * (1 - (1 - t) ** 3)));
            if (t < 1 && el.isConnected) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    }

    // Сразу, без анимации: первый заход и после живого перечитывания (морф вернул все подэтапы и общие числа).
    reapply() {
        const plan = this.plan(this.key);
        for (const [step, s] of plan.steps) step.hidden = !s.show;
        for (const [stage, show] of plan.stages) stage.hidden = !show;
        this.paint(plan, false);
    }

    set(key, { animate = true } = {}) {
        if (key === this.key && animate) return;
        if (!animate || this.still || !this.board) {
            const focus = this.focusStage();
            const was = focus ? this.center(focus) : this.camera.fx;
            this.key = key;
            this.reapply();
            this.relayout(true);
            this.refocus(focus, was);
            return;
        }
        this.key = key;
        this.animate(++this.run);
    }

    // Этап в фокусе камеры — элемент (по видимым этапам, как их считает камера).
    focusStage() {
        const shown = [...this.board.querySelectorAll('.conveyor-stage:not([hidden])')];
        return shown[this.camera.here] ?? null;
    }

    // Фокус после смены раскладки: тот же этап на его новом месте; ушёл — ближайший оставшийся. Возвращает сдвиг камеры.
    refocus(focus, was, fly = false, olds = null) {
        const cam = this.camera;
        const shown = [...this.board.querySelectorAll('.conveyor-stage:not([hidden])')];
        if (!shown.length) return 0;
        // По ключу этапа: живой морф мог заменить сам элемент.
        const i = focus ? shown.findIndex((s) => s.dataset.stage === focus.dataset.stage) : -1;
        const before = cam.fx;
        if (i >= 0) {
            // Остался: фокус сохраняет свой сдвиг от центра этапа (камера могла быть в полёте) и долетает до него.
            const offset = before - was;
            cam.jumpTo(i);
            cam.fx += offset;
            if (offset) cam.goTo(i, cam.vfx);
            return cam.fx - before;
        }
        // Ушёл: ближайший к фокусу по прежнему месту среди оставшихся (без прежних мест — по новому), камера едет к нему пружиной.
        let near = cam.index(before);
        if (olds) {
            let best = Infinity;
            shown.forEach((stage, j) => {
                const o = olds.get(stage);
                if (o !== undefined && Math.abs(o - before) < best) { best = Math.abs(o - before); near = j; }
            });
        }
        fly ? cam.goTo(near, 0) : cam.jumpTo(near);
        return 0;
    }

    // Рамка элемента на доске — по экрану (с текущим transform анимации), в координатах мира.
    rect(el, base, z) {
        const r = el.getBoundingClientRect();
        return { x: (r.left - base.left) / z, y: (r.top - base.top) / z };
    }

    async animate(run) {
        const board = this.board;
        const cam = this.camera;
        const z = cam.z;
        const all = [...board.querySelectorAll('.conveyor-stage, .conveyor-step')];
        const base = board.getBoundingClientRect();
        const focus = this.focusStage();
        const focusWas = focus ? this.center(focus) : 0;
        // Замер «до» — где всё стоит на экране сейчас (и прозрачность: прошлая анимация могла не кончиться).
        const first = new Map();
        for (const el of all) {
            if (el.hidden || el.closest('[hidden]')) continue;
            first.set(el, { ...this.rect(el, base, z), o: Number(getComputedStyle(el).opacity) });
        }
        for (const el of all) el.getAnimations().forEach((a) => a.cancel());
        const plan = this.plan(this.key);
        const show = (el) => (el.classList.contains('conveyor-stage') ? plan.stages.get(el) : plan.steps.get(el)?.show && plan.stages.get(el.closest('.conveyor-stage')));
        const leaving = [...first.keys()].filter((el) => !show(el));
        // 1. Уходящие растворяются (этап целиком — один слой, подэтапы внутри него не по отдельности).
        const fade = leaving.filter((el) => !(el.classList.contains('conveyor-step') && leaving.includes(el.closest('.conveyor-stage'))));
        if (fade.length) {
            await Promise.all(fade.map((el) => el.animate(
                [{ opacity: first.get(el).o, scale: 1 }, { opacity: 0, scale: 0.96 }],
                { duration: OUT, easing: 'ease-in', fill: 'forwards' },
            ).finished.catch(() => {})));
            if (run !== this.run) return;
        }
        // 2. Новая раскладка, числа — докруткой, рамка камеры.
        for (const [step, s] of plan.steps) step.hidden = !s.show;
        for (const [stage, v] of plan.stages) stage.hidden = !v;
        for (const el of fade) el.getAnimations().forEach((a) => a.cancel());
        this.paint(plan, true);
        this.relayout(true);
        const olds = new Map([...first].filter(([el]) => el.classList.contains('conveyor-stage')).map(([el, r]) => [el, r.x + el.offsetWidth / 2]));
        const dx = this.refocus(focus, focusWas, true, olds);
        // 3. FLIP: из прежнего места в новое; сдвиг камеры (`dx`) — внутри, чтобы на экране всё начинало с того же места.
        // Подэтап — относительно своего этапа: этап уже едет сам. Сначала все замеры, потом анимации: начатая анимация
        // этапа сдвинула бы замер его подэтапов.
        const base2 = board.getBoundingClientRect();
        const moves = [];
        const shift = new Map();
        for (const el of all) {
            if (el.hidden || el.closest('[hidden]')) continue;
            const was = first.get(el);
            const step = el.classList.contains('conveyor-step');
            if (!was) {
                // Приходит: проявляется на новом месте (подэтап пришедшего этапа — вместе с ним).
                if (!step || first.has(el.closest('.conveyor-stage'))) moves.push([el, null]);
                continue;
            }
            const now = this.rect(el, base2, cam.z);
            const parent = step ? shift.get(el.closest('.conveyor-stage')) : null;
            const d = { x: was.x - now.x + (parent ? -parent.x : dx), y: was.y - now.y - (parent?.y ?? 0) };
            if (!step) shift.set(el, { x: d.x - dx, y: d.y });
            moves.push([el, d, was.o]);
        }
        for (const [el, d, o] of moves) {
            if (!d) {
                el.animate([{ opacity: 0, scale: 0.96 }, { opacity: 1, scale: 1 }], { duration: IN, delay: IN_DELAY, easing: EASE, fill: 'backwards' });
                continue;
            }
            if (Math.abs(d.x) > 0.5 || Math.abs(d.y) > 0.5) el.animate([{ transform: `translate(${d.x}px, ${d.y}px)` }, { transform: 'none' }], { duration: MOVE, easing: EASE });
            if (o < 0.99) el.animate([{ opacity: o }, { opacity: 1 }], { duration: IN, easing: EASE });
        }
    }

    // Центр этапа в мире — как его считает рамка камеры (`space_controller#layout`).
    center(stage) {
        return this.camera.columns[[...this.board.querySelectorAll('.conveyor-stage:not([hidden])')].indexOf(stage)] ?? this.camera.fx;
    }
}
