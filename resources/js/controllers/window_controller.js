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
        // Окно закрылось — `letters:closed`: сделано ли в нём дело (письмо ушло, «Без письма» — поток letters-close) и что в
        // нём было. Письмо шага не ушло и окно закрыл не человек крестиком или «Отменой» — отчёт (15.11.2026, начальник:
        // «жму „Написать письмо“ — открывается „Отдано“», письма неделю не уходили, причину на сервере не видно).
        this.onClose = () => {
            const url = this.frameTarget.getAttribute('src') || '';
            const handled = !!this.handled, byUser = !!this.byUser;
            this.handled = this.byUser = false;
            // Закрыли сами или дело сделано — окно забыто; закрылось уходом со страницы — вернутся к открытому (`keep`).
            if (handled || byUser || !this.leaving) delete this.element.dataset.windowKeep;
            this.leaving = false;
            window.dispatchEvent(new CustomEvent('letters:closed', { detail: { handled, url } }));
            if (!handled && !byUser && /\/letter-ask|\/work\/mail\/new/.test(url)) {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                navigator.sendBeacon?.('/client/failed', new Blob([JSON.stringify({ _token: token, kind: 'letter-closed', url: url.slice(0, 500), frame: 'letters-frame' })], { type: 'application/json' }));
            }
            if (this.dirty) { this.dirty = false; Turbo.visit(location.href, { action: 'replace' }); }
        };
        // Закрыл человек: «×», «Отмена» окна или редактора, Esc.
        this.onUser = (e) => { if (e.type === 'cancel' || e.target.closest?.('.sheet-close, .compose-cancel, [data-action~="window#close"]')) this.byUser = true; };
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
        this.element.querySelector('dialog')?.addEventListener('click', this.onUser, true);
        this.element.querySelector('dialog')?.addEventListener('cancel', this.onUser, true);
        // Уходят со страницы (таб, ссылка, «Назад») — окно закроется не рукой человека; тронул окно снова — остался.
        this.leaving = false;
        this.onLeave = () => { this.leaving = true; };
        this.onStay = () => { this.leaving = false; };
        for (const name of ['turbo:click', 'turbo:before-visit', 'turbo:visit']) document.addEventListener(name, this.onLeave, true);
        window.addEventListener('section:leave', this.onLeave);
        this.element.querySelector('dialog')?.addEventListener('pointerdown', this.onStay, true);
        this.ready = true;
        if (this.urlValue) this.load(this.urlValue);
        else this.reopen();
    }

    // Страница вернулась из снимка (раздел оставили с открытым письмом, `lib/sections.js`): окно снова открыто на том же
    // письме — содержимое фрейма в снимке, перечитывать нечего; фрейм пуст — грузим.
    reopen() {
        const url = this.element.dataset.windowKeep;
        if (!url) return;
        const frame = this.frameTarget;
        if (frame.getAttribute('src') === url && frame.childElementCount && !frame.querySelector('.skeleton')) this.sheet?.open();
        else this.load(url);
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
        for (const name of ['turbo:click', 'turbo:before-visit', 'turbo:visit']) document.removeEventListener(name, this.onLeave, true);
        window.removeEventListener('section:leave', this.onLeave);
    }

    retry() {
        const url = this.retryUrl || this.frameTarget.getAttribute('src');
        if (!url) return;
        this.frameTarget.removeAttribute('src');
        this.load(url);
    }

    tools() {
        // Вопрос перед письмом (`letter-ask`) — окно низкое, по содержимому; редактор и ветка — снова во весь экран.
        this.element.querySelector('dialog')?.classList.toggle('sheet-compact', !!this.frameTarget.querySelector('[data-window-compact]'));
        // Тема переписки (`x-mail.chain`, 17.11.2026) или заголовок шага — вместо «Письма»; без шаблона — снова свой заголовок окна.
        const title = this.frameTarget.querySelector('template[data-window-title]');
        const head = this.element.querySelector('dialog .sheet-bar h2');
        if (head) this.title ??= head.textContent;
        if (title && head) head.replaceChildren(...title.content.cloneNode(true).childNodes);
        else if (head && this.frameTarget.childElementCount) head.textContent = this.title;
        if (!this.hasToolsTarget) return;
        // Ответ без шаблона (редактор письма в том же фрейме) — кнопки окна остаются прежними.
        const tools = this.frameTarget.querySelector('template[data-window-tools]');
        if (tools) this.toolsTarget.replaceChildren(...tools.content.cloneNode(true).childNodes);
    }

    // Письмо ушло из черновика после «Принять» (поток letters без url): окно закрыть, страницу не перечитывать — строка
    // уже ушла, карточка уже следующая.
    close() {
        this.dirty = false;
        this.handled = true;
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
        // Письма читают — к ним возвращаются; редактор письма и вопрос шага заново сами не открываются.
        if (frame.id === 'letters-frame' && /\/letters(\?|$)|\/window(\?|$)/.test(url)) this.element.dataset.windowKeep = url;
        else delete this.element.dataset.windowKeep;
        this.sheet?.open();
    }

    get sheet() { return this.application.getControllerForElementAndIdentifier(this.element, 'sheet'); }
    get skeleton() { return this.element.querySelector('template[data-skeleton]')?.innerHTML ?? ''; }
}
