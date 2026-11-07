import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { post } from '../lib/http';

// Спорное поле машины (x-car.variants, `Cars\Sources`, 07.11.2026): метка у поля (`x-car.variant-hint`) открывает шторку
// поля — `open`; «Выбрать» — ровно этот вариант в карточку,
// «Оставить» — остальные варианты отклонены. Запрос — fetch, не форма: блок стоит в форме карточки. Ответ — тост и
// свежая карточка: поменяли, пока смотрел (`stale`), — тоже свежая, с тем, что стоит теперь, а не «обновите страницу».
export default class extends Controller {
    static values = { url: String };

    // Шторка стоит в блоке отдельно от метки (метка — в ячейке поля и под полем ввода): по id, её же `sheet`-контроллером.
    open(event) {
        const wrap = document.getElementById(event.params.sheet)?.closest('[data-controller~="sheet"]');
        if (wrap) this.application.getControllerForElementAndIdentifier(wrap, 'sheet')?.open(event);
    }

    pick(event) {
        const { field, key, seen } = event.params;
        // Stimulus читает «125» параметром-числом: ключи — строками (объём 12,5 л иначе 422).
        this.send(event.currentTarget, field, { key: String(key ?? ''), seen: String(seen ?? '') });
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

    // Карточка строки (фрейм `detail`) и машины «Парсинга» перечитываются сами; страница редактора и дела — заново тем же адресом.
    reload() {
        const frame = this.element.closest('turbo-frame');
        if (frame?.id === 'detail' && frame.src) return frame.reload();
        // Карточка машины на «Парсинге» — свой фрейм без src (иначе сто запросов при загрузке): адрес — data-reload-src.
        if (frame?.dataset.reloadSrc) {
            if (frame.src) return frame.reload();
            frame.src = frame.dataset.reloadSrc;
            return;
        }
        Turbo.visit(location.href, { action: 'replace' });
    }
}
