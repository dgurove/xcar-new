import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { liveOpen } from '../live.js';

// Кадры прикрепляются (x-mail.attach-line с data-attach-busy): ряд фото, строка хода и документы переспрашиваются потоком
// (`AttachController`), когда ход машины шагнул — событие `files` хаба (`Cars\Progress`): заглушки становятся кадрами,
// «12 из 56» растёт. Хаб недоступен — запасной опрос раз в 2 с, как у читалки. Строки хода не стало — всё прикреплено.
export default class extends Controller {
    static values = { url: String, car: Number };

    connect() {
        this.onFiles = (e) => {
            if (Number(e.detail?.car) === this.carValue) this.load();
        };
        document.addEventListener('live:files', this.onFiles);
        this.tick();
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('live:files', this.onFiles);
    }

    tick() {
        clearTimeout(this.timer);
        if (!this.element.querySelector('[data-attach-busy]') || liveOpen()) return;
        this.timer = setTimeout(() => this.load(), 2000);
    }

    async load() {
        if (this.loading) return;
        this.loading = true;
        try {
            const r = await fetch(this.urlValue, { headers: { Accept: 'text/vnd.turbo-stream.html' } });
            if (r.ok) Turbo.renderStreamMessage(await r.text());
        } catch {}
        this.loading = false;
        // Поток рисуется в следующем кадре — смотрим строку хода после него.
        requestAnimationFrame(() => this.tick());
    }
}
