import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Кадры письма прикрепляются (x-mail.attach-line с data-attach-busy): ряд фото, строка хода и документы переспрашиваются
// раз в 2 с потоком (`AttachController`) — заглушки становятся кадрами, «12 из 56» растёт. Строки хода не стало —
// всё прикреплено, опрос замолкает. Опрос, а не хаб: задача пишет ход в кеш на каждом кадре.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.tick();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    tick() {
        clearTimeout(this.timer);
        if (!this.element.querySelector('[data-attach-busy]')) return;
        this.timer = setTimeout(() => this.load(), 2000);
    }

    async load() {
        try {
            const r = await fetch(this.urlValue, { headers: { Accept: 'text/vnd.turbo-stream.html' } });
            if (r.ok) Turbo.renderStreamMessage(await r.text());
        } catch {}
        // Поток рисуется в следующем кадре — смотрим строку хода после него.
        requestAnimationFrame(() => this.tick());
    }
}
