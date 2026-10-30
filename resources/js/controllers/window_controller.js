import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Окно писем: одна шторка tall на странице с фреймом letters-frame. Событие letters:open
// с url в detail ставит фрейму src и открывает шторку (сосед-контроллер sheet на том же
// элементе); тот же адрес, уже загруженный, перечитывается. url в значении — открыть сразу (?window=).
// Отправили письмо из окна — после закрытия страница перечитывается: шаг «Отчёт вендору» становится сделанным.
// Письмо из черновика после «Принять» (then=close) — окно закрывается само, без перечитывания (letters:close).
// Кнопки предмета в заголовок окна — шаблоном data-window-tools во фрейме (✨ у писем ТС и предложения).
// reload=false — окно перечитывает страницу само (✨ Распознать: итог scan_controller бережёт карточка строки).
// Окно действия парковки (x-park.act-window): заголовок шага — шаблоном data-window-title, формы шага шлют свой адрес
// заголовком X-Window — ошибка проверки возвращается в окно, а не на страницу под ним (ActWindowBack).
export default class extends Controller {
    static targets = ['frame', 'tools'];
    static values = { url: String, reload: { type: Boolean, default: true } };

    connect() {
        this.dirty = false;
        this.onEnd = (e) => { if (this.reloadValue && e.detail?.success && this.element.contains(e.target)) this.dirty = true; };
        this.onClose = () => { if (this.dirty) { this.dirty = false; Turbo.visit(location.href, { action: 'replace' }); } };
        this.onLoad = (e) => { if (e.target === this.frameTarget) this.tools(); };
        // Окно не загрузилось (нет связи) — словами и «Повторить», а не вечная заготовка.
        this.onError = (e) => {
            if (e.target !== this.frameTarget) return;
            const url = this.frameTarget.getAttribute('src');
            this.frameTarget.innerHTML = '<div class="flex flex-col items-center gap-3 py-10 text-center"><p class="text-ink-muted">Не загрузилось</p><button type="button" class="btn btn-s btn-quiet" data-action="window#retry">Повторить</button></div>';
            this.retryUrl = url;
        };
        this.onFetch = (e) => {
            if (!this.element.contains(e.target) || String(e.detail.fetchOptions.method).toUpperCase() === 'GET') return;
            const step = this.frameTarget.querySelector('[data-window-url]')?.dataset.windowUrl;
            if (step) e.detail.fetchOptions.headers['X-Window'] = step;
        };
        document.addEventListener('turbo:before-fetch-request', this.onFetch);
        document.addEventListener('turbo:submit-end', this.onEnd);
        document.addEventListener('turbo:frame-load', this.onLoad);
        document.addEventListener('turbo:fetch-request-error', this.onError);
        this.element.querySelector('dialog')?.addEventListener('close', this.onClose);
        this.ready = true;
        if (this.urlValue) this.load(this.urlValue);
    }

    // Адрес пришёл морфом той же страницы (приняли подтверждение в редакторе — session letters): контроллер не
    // переподключается. До connect() Stimulus тоже зовёт этот метод — первое значение открывает connect().
    urlValueChanged(url) {
        if (this.ready && url) this.load(url);
    }

    disconnect() {
        this.ready = false;
        document.removeEventListener('turbo:before-fetch-request', this.onFetch);
        document.removeEventListener('turbo:submit-end', this.onEnd);
        document.removeEventListener('turbo:frame-load', this.onLoad);
        document.removeEventListener('turbo:fetch-request-error', this.onError);
    }

    retry() {
        const url = this.retryUrl || this.frameTarget.getAttribute('src');
        if (!url) return;
        this.frameTarget.removeAttribute('src');
        this.load(url);
    }

    tools() {
        const title = this.frameTarget.querySelector('template[data-window-title]');
        const head = this.element.querySelector('dialog .sheet-bar h2');
        if (title && head) head.replaceChildren(...title.content.cloneNode(true).childNodes);
        if (!this.hasToolsTarget) return;
        // Ответ без шаблона (редактор письма в том же фрейме) — кнопки окна остаются прежними.
        const tools = this.frameTarget.querySelector('template[data-window-tools]');
        if (tools) this.toolsTarget.replaceChildren(...tools.content.cloneNode(true).childNodes);
    }

    // Письмо ушло из черновика после «Принять» (поток letters без url): окно закрыть, страницу не перечитывать — строка
    // уже ушла, карточка уже следующая.
    close() {
        this.dirty = false;
        const d = this.element.querySelector('dialog');
        if (d?.open) this.sheet?.close();
    }

    open(event) {
        const url = event.detail?.url;
        if (url) this.load(url); else this.sheet?.open();
    }

    load(url) {
        const frame = this.frameTarget;
        // Тот же адрес ещё грузится (`busy` у фрейма; `complete` Turbo при этом не снимает — по нему не судить) — не
        // трогаем, хоть сеть и медленная: повторное нажатие не начинает заново. Загруженный — перечитываем: могло прийти
        // письмо. Оборвавшийся (связь, ошибка) — грузим снова, а не оставляем заготовку (30.10.2026, «с 3–4 раза
        // прогружается на выдачу»).
        if (frame.getAttribute('src') !== url || !frame.hasAttribute('busy')) {
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
