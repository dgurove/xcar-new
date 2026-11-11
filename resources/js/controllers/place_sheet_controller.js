import { Controller } from '@hotwired/stimulus';

// Шторка стоянки в «Заявках» (11.11.2026): одна на список, форма грузится по нажатию на стоянку строки
// (`x-park.request-place` шлёт `place:open` с url `/requests/{id}/place`). Прежняя форма убирается сразу — иначе на
// мгновение видна чужая машина; тот же url — перезагрузка (места могли занять).
export default class extends Controller {
    static targets = ['frame'];

    // Сохранили (поток `refresh` от `savePlace`) — шторку закрыть; ошибка (422) — форма с ней остаётся.
    saved(event) {
        if (!event.detail.success) return;
        this.application.getControllerForElementAndIdentifier(this.element, 'sheet')?.close();
    }

    load({ detail }) {
        if (!detail?.url) return;
        const frame = this.frameTarget;
        frame.replaceChildren(Object.assign(document.createElement('div'), { className: 'skeleton h-32 w-full' }));
        if (frame.getAttribute('src') === detail.url) frame.reload();
        else frame.src = detail.url;
    }
}
