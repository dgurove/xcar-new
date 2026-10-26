import { Controller } from '@hotwired/stimulus';

// Капсула «С Мигторга N новых ТС» (x-migtorg.island): событие `migtorg` хаба {count, cars} — `Offers\Intake\MigtorgDrafts`
// завёл черновики. Точка → раскрытие вширь (ширина — Web Animations от 44 px до своей) → текст и отсчёт числа → через
// SHOW мс обратно в точку. Пришло ещё, пока видна, — число прибавляется, капсула вздрагивает, срок заново. Наведение
// держит; свайп вверх убирает; нажатие ведёт в «Без закупочной цены». Вкладка в фоне — показ, когда вернутся (не позже
// STALE). Без движения (prefers-reduced-motion) — только проявление.
const SHOW = 6000;
const STALE = 10 * 60 * 1000;
const DOT = 44;

export default class extends Controller {
    static targets = ['count', 'noun', 'cars'];

    connect() {
        this.total = 0;
        this.names = [];
        this.onFound = (e) => this.found(e.detail || {});
        this.onVisible = () => {
            if (document.visibilityState === 'visible' && this.waiting) {
                const { at, detail } = this.waiting;
                this.waiting = null;
                if (Date.now() - at < STALE) this.found(detail);
            }
        };
        document.addEventListener('live:migtorg', this.onFound);
        document.addEventListener('visibilitychange', this.onVisible);
    }

    disconnect() {
        document.removeEventListener('live:migtorg', this.onFound);
        document.removeEventListener('visibilitychange', this.onVisible);
        clearTimeout(this.timer);
    }

    found({ count = 0, cars = [] }) {
        if (!count) return;
        if (document.visibilityState !== 'visible') {
            // В фоне копим: вернулись — одна капсула на всё, что нашлось.
            const was = this.waiting?.detail || { count: 0, cars: [] };
            this.waiting = { at: Date.now(), detail: { count: was.count + count, cars: [...cars, ...was.cars] } };
            return;
        }
        const shown = !this.element.hidden && !this.leaving;
        const from = shown ? this.total : 0;
        this.total = from + count;
        this.names = [...cars, ...(shown ? this.names : [])];
        this.render(from);
        shown ? this.bump() : this.enter();
        this.arm();
    }

    render(from) {
        this.nounTarget.textContent = `${noun(this.total)}`;
        const rest = this.total - this.names.slice(0, 2).length;
        this.carsTarget.textContent = this.names.slice(0, 2).join(', ') + (rest > 0 && this.names.length ? ` и ещё ${rest}` : '');
        this.tick(from, this.total);
    }

    // Отсчёт числа: 0 → N за ~0,6 с; без движения — сразу.
    tick(from, to) {
        cancelAnimationFrame(this.frame);
        if (still() || to - from <= 1) {
            this.countTarget.textContent = to;
            return;
        }
        const start = performance.now();
        const step = (now) => {
            const t = Math.min(1, (now - start) / 600);
            this.countTarget.textContent = Math.round(from + (to - from) * (1 - (1 - t) ** 3));
            if (t < 1) this.frame = requestAnimationFrame(step);
        };
        this.frame = requestAnimationFrame(step);
    }

    enter() {
        const el = this.element;
        this.leaving = false;
        this.width?.cancel();
        el.hidden = false;
        el.classList.remove('is-open');
        el.classList.add('is-in', 'spark-busy');
        if (still()) {
            el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });
            el.classList.add('is-open');
            return;
        }
        const full = el.scrollWidth;
        el.animate([{ opacity: 0, scale: 0.4, translate: '-50% -6px' }, { opacity: 1, scale: 1, translate: '-50% 0' }],
            { duration: 260, easing: 'cubic-bezier(.2,.8,.2,1)' });
        this.width = el.animate([{ width: `${DOT}px` }, { width: `${full}px` }], { duration: 420, delay: 140, easing: 'cubic-bezier(.32,.72,0,1)', fill: 'backwards' });
        this.width.finished.then(() => el.classList.add('is-open')).catch(() => {});
        setTimeout(() => el.classList.add('is-open'), 300);
        setTimeout(() => el.classList.remove('spark-busy'), 1600);
    }

    bump() {
        const el = this.element;
        el.classList.remove('is-bump');
        void el.offsetWidth;
        el.classList.add('is-bump', 'spark-busy');
        setTimeout(() => el.classList.remove('spark-busy'), 1600);
    }

    async leave() {
        const el = this.element;
        if (el.hidden || this.leaving) return;
        this.leaving = true;
        clearTimeout(this.timer);
        el.classList.remove('is-open');
        if (!still()) {
            await wait(160);
            const shrink = el.animate([{ width: `${el.offsetWidth}px` }, { width: `${DOT}px` }], { duration: 300, easing: 'cubic-bezier(.4,0,1,1)', fill: 'forwards' });
            await shrink.finished.catch(() => {});
            await el.animate([{ opacity: 1, scale: 1 }, { opacity: 0, scale: 0.4, translate: '-50% -6px' }], { duration: 200, easing: 'cubic-bezier(.4,0,1,1)', fill: 'forwards' }).finished.catch(() => {});
            shrink.cancel();
        }
        // Пока сворачивалась, пришло новое — оно уже раскрыло капсулу заново.
        if (!this.leaving) return;
        el.getAnimations().forEach((a) => a.cancel());
        el.hidden = true;
        el.classList.remove('is-in', 'is-bump', 'spark-busy');
        this.leaving = false;
        this.total = 0;
        this.names = [];
    }

    arm() {
        clearTimeout(this.timer);
        if (!this.held) this.timer = setTimeout(() => this.leave(), SHOW);
    }

    hold() {
        this.held = true;
        clearTimeout(this.timer);
    }

    release() {
        this.held = false;
        if (!this.element.hidden) this.arm();
    }

    // Свайп вверх — убрать (как баннер iOS): палец ушёл вверх на 16 px — капсула уходит, ссылка не срабатывает.
    press(e) {
        const y = e.clientY;
        this.swiped = false;
        const move = (m) => {
            if (y - m.clientY > 16) {
                this.swiped = true;
                off();
                this.leave();
            }
        };
        const off = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', off);
            window.removeEventListener('pointercancel', off);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', off);
        window.addEventListener('pointercancel', off);
    }

    open(e) {
        if (this.swiped) {
            e.preventDefault();
            return;
        }
        this.leave();
    }
}

function noun(n) {
    const d = n % 10;
    const h = n % 100;
    return d === 1 && h !== 11 ? 'новое ТС' : 'новых ТС';
}

function still() {
    return matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function wait(ms) {
    return new Promise((r) => setTimeout(r, ms));
}
