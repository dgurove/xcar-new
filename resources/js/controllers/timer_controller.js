import { Controller } from '@hotwired/stimulus';

// Обратный отсчёт до срока. Тикает раз в секунду, пока элемент на странице.
export default class extends Controller {
    static values = { until: String, done: { type: String, default: 'закрыт' } };

    connect() {
        this.tick();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    tick() {
        const left = Math.floor((new Date(this.untilValue) - Date.now()) / 1000);
        if (left <= 0) {
            this.element.textContent = this.doneValue;
            clearInterval(this.interval);
            return;
        }
        const d = Math.floor(left / 86400), h = Math.floor((left % 86400) / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
        this.element.textContent = d > 0 ? `${d} д ${h} ч` : h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
    }
}
