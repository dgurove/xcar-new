import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Окно писем: одна шторка tall на странице с фреймом letters-frame. Событие letters:open
// с url в detail ставит фрейму src и открывает шторку (сосед-контроллер sheet на том же
// элементе); тот же адрес, уже загруженный, перечитывается. url в значении — открыть сразу (?window=).
// Отправили письмо из окна — после закрытия страница перечитывается: шаг «Отчёт вендору» становится сделанным.
// Кнопки предмета в заголовок окна — шаблоном data-window-tools во фрейме (✨ у писем ТС и предложения).
// reload=false — окно перечитывает страницу само (✨ Распознать: итог scan_controller бережёт карточка строки).
export default class extends Controller {
    static targets = ['frame', 'tools'];
    static values = { url: String, reload: { type: Boolean, default: true } };

    connect() {
        this.dirty = false;
        this.onEnd = (e) => { if (this.reloadValue && e.detail?.success && this.element.contains(e.target)) this.dirty = true; };
        this.onClose = () => { if (this.dirty) { this.dirty = false; Turbo.visit(location.href, { action: 'replace' }); } };
        this.onLoad = (e) => { if (e.target === this.frameTarget) this.tools(); };
        document.addEventListener('turbo:submit-end', this.onEnd);
        document.addEventListener('turbo:frame-load', this.onLoad);
        this.element.querySelector('dialog')?.addEventListener('close', this.onClose);
        if (this.urlValue) this.load(this.urlValue);
    }

    disconnect() {
        document.removeEventListener('turbo:submit-end', this.onEnd);
        document.removeEventListener('turbo:frame-load', this.onLoad);
    }

    tools() {
        if (!this.hasToolsTarget) return;
        // Ответ без шаблона (редактор письма в том же фрейме) — кнопки окна остаются прежними.
        const tools = this.frameTarget.querySelector('template[data-window-tools]');
        if (tools) this.toolsTarget.replaceChildren(...tools.content.cloneNode(true).childNodes);
    }

    open(event) {
        const url = event.detail?.url;
        if (url) this.load(url); else this.sheet?.open();
    }

    load(url) {
        const frame = this.frameTarget;
        // Тот же адрес ещё грузится — не трогаем; загруженный — перечитываем: могло прийти письмо.
        if (frame.getAttribute('src') !== url || frame.complete) {
            if (frame.getAttribute('src') !== url && this.hasToolsTarget) this.toolsTarget.replaceChildren();
            frame.innerHTML = this.skeleton;
            frame.removeAttribute('src');
            frame.setAttribute('src', url);
        }
        this.sheet?.open();
    }

    get sheet() { return this.application.getControllerForElementAndIdentifier(this.element, 'sheet'); }
    get skeleton() { return this.element.querySelector('template[data-skeleton]')?.innerHTML ?? ''; }
}
