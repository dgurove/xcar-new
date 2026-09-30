import { Controller } from '@hotwired/stimulus';

// Часы. until — обратный отсчёт до срока, since — сколько прошло; human — словами
// («только что», «2 мин. назад»), тогда серверный текст остаётся первым кадром.
// Один такт на все таймеры страницы, поправка на часы сервера (live.js читает
// заголовок Date), при возврате из фона — тик сразу. На нуле шлёт timer:done
// (bubbles) — экран закрывает приём сам, не дожидаясь серверного тика. coarse — без секунд, «21 ч 22 мин»: часы этапа
// в строках списков и в шаге пути не тикают секундами.
// Текст пишется только у видимых таймеров и только когда он поменялся: в длинном списке таймеры в каждой строке
// (часть — скрытые копии для другой ширины) иначе каждую секунду перекладывали таблицу. Срок «вышел» проверяется у всех.
const timers = new Set();
let interval = null;
const tickAll = () => timers.forEach((t) => t.tick());
const owners = new WeakMap();
const sight = typeof IntersectionObserver === 'function' ? new IntersectionObserver((entries) => entries.forEach((entry) => {
    const timer = owners.get(entry.target);
    if (!timer) return;
    timer.visible = entry.isIntersecting;
    if (timer.visible) timer.tick();
}), { rootMargin: '200px 0px' }) : null;
document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && tickAll());

export const serverNow = () => Date.now() + (window.clockOffset || 0);

export default class extends Controller {
    static values = { until: String, since: String, done: { type: String, default: 'закрыт' }, human: Boolean, coarse: Boolean };
    static classes = ['last'];

    connect() {
        this.finished = false;
        this.visible = true; // до первого ответа наблюдателя — как видимый: первый кадр рисуется сразу
        this.tick();
        timers.add(this);
        owners.set(this.element, this);
        sight?.observe(this.element);
        interval ??= setInterval(tickAll, 1000);
    }

    disconnect() {
        timers.delete(this);
        sight?.unobserve(this.element);
        if (!timers.size) { clearInterval(interval); interval = null; }
    }

    // Сверяем с тем, что стоит в DOM (морф мог подставить серверный текст), — чтение раскладку не трогает.
    write(text) {
        if (this.visible && this.element.textContent !== text) this.element.textContent = text;
    }

    tick() {
        if (this.hasUntilValue && this.untilValue) {
            const left = Math.floor((new Date(this.untilValue) - serverNow()) / 1000);
            if (left <= 0) {
                this.write(this.doneValue === '-' ? '−' + this.format(-left) : this.doneValue);
                if (this.doneValue !== '-' && !this.finished) {
                    this.finished = true;
                    this.dispatch('done', { bubbles: true });
                }
                return;
            }
            if (this.visible) this.element.classList.toggle('is-last', left < 60);
            this.write(this.coarseValue ? this.rough(left) : this.format(left));
        } else if (this.hasSinceValue && this.sinceValue) {
            const passed = Math.max(0, Math.floor((serverNow() - new Date(this.sinceValue)) / 1000));
            this.write(this.humanValue ? this.human(passed) : this.coarseValue ? this.rough(passed) : this.format(passed));
        }
    }

    format(total) {
        const d = Math.floor(total / 86400), h = Math.floor((total % 86400) / 3600), m = Math.floor((total % 3600) / 60), s = total % 60;
        return d > 0 ? `${d} д ${h} ч` : h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
    }

    rough(total) {
        const d = Math.floor(total / 86400), h = Math.floor((total % 86400) / 3600), m = Math.floor((total % 3600) / 60);
        return d > 0 ? `${d} д ${h} ч` : h > 0 ? `${h} ч ${m} мин` : `${Math.max(1, m)} мин`;
    }

    human(seconds) {
        if (seconds < 45) return 'только что';
        const rtf = new Intl.RelativeTimeFormat('ru', { numeric: 'auto' });
        if (seconds < 3600) return rtf.format(-Math.round(seconds / 60), 'minute');
        if (seconds < 86400) return rtf.format(-Math.round(seconds / 3600), 'hour');
        return rtf.format(-Math.round(seconds / 86400), 'day');
    }
}
