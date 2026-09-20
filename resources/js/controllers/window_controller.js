import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Окно писем: одна шторка tall на странице с фреймом letters-frame. Событие letters:open
// с url в detail ставит фрейму src и открывает шторку (сосед-контроллер sheet на том же
// элементе); тот же адрес, уже загруженный, перечитывается. url в значении — открыть сразу (?window=).
// Отправили письмо из окна — после закрытия страница перечитывается: шаг «Отчёт вендору» становится сделанным.
export default class extends Controller {
    static targets = ['frame'];
    static values = { url: String };

    connect() {
        this.dirty = false;
        this.onEnd = (e) => { if (e.detail?.success && this.element.contains(e.target)) this.dirty = true; };
        this.onClose = () => { if (this.dirty) { this.dirty = false; Turbo.visit(location.href, { action: 'replace' }); } };
        document.addEventListener('turbo:submit-end', this.onEnd);
        this.element.querySelector('dialog')?.addEventListener('close', this.onClose);
        if (this.urlValue) this.load(this.urlValue);
    }

    disconnect() {
        document.removeEventListener('turbo:submit-end', this.onEnd);
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
