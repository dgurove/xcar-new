import { Controller } from '@hotwired/stimulus';

// Часы. until — обратный отсчёт до срока, since — сколько прошло.
// Тикают раз в секунду, пока элемент на странице.
export default class extends Controller {
    static values = { until: String, since: String, done: { type: String, default: 'закрыт' } };

    connect() {
        this.tick();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    tick() {
        if (this.hasUntilValue && this.untilValue) {
            const left = Math.floor((new Date(this.untilValue) - Date.now()) / 1000);
            if (left <= 0) {
                this.element.textContent = this.doneValue === '-' ? '−' + this.format(-left) : this.doneValue;
                if (this.doneValue !== '-') clearInterval(this.interval);
                return;
            }
            this.element.textContent = this.format(left);
        } else if (this.hasSinceValue && this.sinceValue) {
            this.element.textContent = this.format(Math.max(0, Math.floor((Date.now() - new Date(this.sinceValue)) / 1000)));
        }
    }

    format(total) {
        const d = Math.floor(total / 86400), h = Math.floor((total % 86400) / 3600), m = Math.floor((total % 3600) / 60), s = total % 60;
        return d > 0 ? `${d} д ${h} ч` : h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
    }
}
