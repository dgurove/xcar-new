import { Controller } from '@hotwired/stimulus';

// Пустой черновик «+ Новый»: ушли из редактора, ничего не набрав, — черновика нет (`POST /offers/{n}/drop-empty`,
// sendBeacon — запрос доходит и при уходе со страницы). Уход ловится и переходом Turbo, и уходом страницы (назад
// браузером, закрыли вкладку); обновление страницы сервер отличает сам — открытый снова редактор черновик оставляет.
// Набрали хоть что-то — не трогаем: набранное хранит draft, к нему вернутся. Сохранили — тоже. Фото, письма и
// прочее сервер проверит сам.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.dirty = false;
        this.onInput = () => { this.dirty = true; };
        this.onSubmit = () => { this.dirty = true; };
        this.onVisit = (e) => this.leave(e.detail?.url);
        this.onHide = () => this.leave();
        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('change', this.onInput);
        this.element.addEventListener('submit', this.onSubmit);
        document.addEventListener('turbo:visit', this.onVisit);
        window.addEventListener('pagehide', this.onHide);
    }

    disconnect() {
        this.element.removeEventListener('input', this.onInput);
        this.element.removeEventListener('change', this.onInput);
        this.element.removeEventListener('submit', this.onSubmit);
        document.removeEventListener('turbo:visit', this.onVisit);
        window.removeEventListener('pagehide', this.onHide);
    }

    leave(url) {
        if (this.dirty || this.sent) return;
        try { if (url && new URL(url, location.href).pathname === location.pathname) return; } catch {}
        this.sent = true;
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        navigator.sendBeacon(this.urlValue, body);
    }
}
