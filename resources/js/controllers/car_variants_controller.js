import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { post } from '../lib/http';

// Спорное поле машины (x-car.variants, `Cars\Sources`, 07.11.2026): «Выбрать» — ровно этот вариант в карточку,
// «Оставить» — остальные варианты отклонены. Запрос — fetch, не форма: блок стоит в форме карточки. Ответ — тост и
// свежая карточка: поменяли, пока смотрел (`stale`), — тоже свежая, с тем, что стоит теперь, а не «обновите страницу».
export default class extends Controller {
    static values = { url: String };

    pick(event) {
        const { field, key, seen } = event.params;
        this.send(event.currentTarget, field, { key, seen: String(seen ?? '') });
    }

    keep(event) {
        const { field, seen } = event.params;
        this.send(event.currentTarget, field, { keep: 1, seen: String(seen ?? '') });
    }

    async send(button, field, body) {
        if (button.disabled) return;
        button.disabled = true;
        const r = await post(`${this.urlValue}/${encodeURIComponent(field)}`, body);
        const data = await r?.json().catch(() => null);
        button.disabled = false;
        if (!data) return;
        if (data.toast) window.toast?.(data.toast, data.stale ? 'danger' : undefined);
        button.closest('dialog')?.close();
        this.reload();
    }

    // Карточка строки (фрейм `detail`) перечитывается сама; страница редактора и дела — заново тем же адресом.
    reload() {
        const frame = this.element.closest('turbo-frame');
        if (frame?.id === 'detail' && frame.src) return frame.reload();
        Turbo.visit(location.href, { action: 'replace' });
    }
}
