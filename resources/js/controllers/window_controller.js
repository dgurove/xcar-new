import { Controller } from '@hotwired/stimulus';

// Окно писем: одна шторка tall на странице с фреймом letters-frame. Событие letters:open
// с url в detail ставит фрейму src и открывает шторку (сосед-контроллер sheet на том же
// элементе); тот же url — фрейм не перегружается. url в значении — открыть сразу (?window=).
export default class extends Controller {
    static targets = ['frame'];
    static values = { url: String };

    connect() {
        if (this.urlValue) this.load(this.urlValue);
    }

    open(event) {
        const url = event.detail?.url;
        if (url) this.load(url); else this.sheet?.open();
    }

    load(url) {
        const frame = this.frameTarget;
        // Тот же адрес ещё грузится — не трогаем; загруженный — перечитываем: могло прийти письмо.
        if (frame.getAttribute('src') !== url || frame.complete) {
            frame.innerHTML = this.skeleton;
            frame.removeAttribute('src');
            frame.setAttribute('src', url);
        }
        this.sheet?.open();
    }

    get sheet() { return this.application.getControllerForElementAndIdentifier(this.element, 'sheet'); }
    get skeleton() { return this.element.querySelector('template[data-skeleton]')?.innerHTML ?? ''; }
}
